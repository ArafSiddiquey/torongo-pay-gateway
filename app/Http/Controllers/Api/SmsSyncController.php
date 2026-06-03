<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OutgoingSms;
use App\Models\SmsDevice;
use App\Models\SmsLog;
use App\Services\PaymentVerifierService;
use App\Services\SmsParserService;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class SmsSyncController extends Controller
{
    public function heartbeat(Request $request)
    {
        $device = $this->deviceFromRequest($request);
        $device->update(['last_sync_at' => now()]);

        return response()->json([
            'ok' => true,
            'device' => $device->name,
            'last_sync_at' => $device->last_sync_at?->toIso8601String(),
            'last_sms_received_at' => $device->last_sms_received_at?->toIso8601String(),
        ]);
    }

    public function sync(Request $request, SmsParserService $parser, PaymentVerifierService $verifier)
    {
        $device = $this->deviceFromRequest($request);

        $data = $request->validate([
            'messages' => ['required', 'array', 'max:100'],
            'messages.*.sender' => ['required', 'string', 'max:80'],
            'messages.*.body' => ['required', 'string'],
            'messages.*.received_at' => ['nullable', 'date'],
            'messages.*.method_option' => ['nullable', 'in:send_money,payment,remittance'],
            'messages.*.android_subscription_id' => ['nullable', 'integer'],
            'messages.*.android_sim_slot' => ['nullable', 'integer'],
        ]);

        $accepted = 0;
        $verified = 0;
        $ignored = 0;
        $lastSmsReceivedAt = $device->last_sms_received_at;
        $messages = collect($data['messages'])
            ->map(function (array $message) use ($parser) {
                return [
                    'message' => $message,
                    'parsed' => $parser->parse($message['sender'], $message['body'], $message['received_at'] ?? null),
                ];
            })
            ->sortBy(fn (array $item) => optional($item['parsed']['received_at'])->getTimestamp() ?? 0)
            ->values();

        foreach ($messages as $item) {
            $message = $item['message'];
            $parsed = $item['parsed'];
            if ($parsed['received_at'] && $parsed['received_at']->lt($device->created_at)) {
                $ignored++;
                continue;
            }

            if ($parsed['received_at'] && $lastSmsReceivedAt && $parsed['received_at']->lte($lastSmsReceivedAt)) {
                $ignored++;
                continue;
            }

            $allowed = empty($device->allowed_methods) || in_array($parsed['method_slug'], $device->allowed_methods, true);

            if (! $allowed) {
                $ignored++;
                continue;
            }

            if (! empty($parsed['parsed_trx_id']) && SmsLog::where('method_slug', $parsed['method_slug'])
                ->where('parsed_trx_id', $parsed['parsed_trx_id'])
                ->exists()) {
                if (! empty($parsed['method_slug']) && $parsed['received_at'] && (! $lastSmsReceivedAt || $parsed['received_at']->gt($lastSmsReceivedAt))) {
                    $lastSmsReceivedAt = $parsed['received_at'];
                }
                $ignored++;
                continue;
            }

            $hash = hash('sha256', strtolower(trim($message['sender'])) . '|' . trim($message['body']) . '|' . ($parsed['parsed_trx_id'] ?? ''));

            $sms = SmsLog::firstOrCreate(
                ['sms_hash' => $hash],
                $parsed + [
                    'sms_device_id' => $device->id,
                    'android_subscription_id' => $message['android_subscription_id'] ?? null,
                    'android_sim_slot' => $message['android_sim_slot'] ?? null,
                    'method_option' => $message['method_option'] ?? null,
                    'raw_sender' => $message['sender'],
                    'raw_body' => $message['body'],
                    'is_fraud' => $parsed['is_fraud'],
                ]
            );

            if (! $sms->wasRecentlyCreated) {
                $sms->update(['is_duplicate' => true]);
                $ignored++;
                continue;
            }

            if (! empty($parsed['method_slug']) && $parsed['received_at'] && (! $lastSmsReceivedAt || $parsed['received_at']->gt($lastSmsReceivedAt))) {
                $lastSmsReceivedAt = $parsed['received_at'];
            }

            $accepted++;
            if ($verifier->verifySms($sms)) {
                $verified++;
            }
        }

        $device->update([
            'last_sync_at' => now(),
            'last_sms_received_at' => $lastSmsReceivedAt,
        ]);

        return response()->json([
            'accepted' => $accepted,
            'verified' => $verified,
            'ignored' => $ignored,
            'last_sms_received_at' => $device->last_sms_received_at?->toIso8601String(),
        ]);
    }

    public function fetchOutgoing(Request $request)
    {
        $device = $this->deviceFromRequest($request);
        $device->update(['last_sync_at' => now()]);

        $messages = DB::transaction(function () use ($device) {
            OutgoingSms::query()
                ->where('status', OutgoingSms::STATUS_PROCESSING)
                ->where('last_attempted_at', '<', now()->subMinutes(10))
                ->update(['status' => OutgoingSms::STATUS_PENDING]);

            $messages = OutgoingSms::query()
                ->where('status', OutgoingSms::STATUS_PENDING)
                ->where('attempts', '<', 5)
                ->orderBy('created_at')
                ->limit(10)
                ->lockForUpdate()
                ->get();

            $messages->each(function (OutgoingSms $sms) use ($device) {
                $sms->update([
                    'status' => OutgoingSms::STATUS_PROCESSING,
                    'sms_device_id' => $device->id,
                    'attempts' => $sms->attempts + 1,
                    'last_attempted_at' => now(),
                ]);
            });

            return $messages;
        });

        return response()->json([
            'messages' => $messages->map(fn (OutgoingSms $sms) => [
                'id' => $sms->id,
                'recipient' => $sms->recipient,
                'message' => $sms->message,
            ])->values(),
        ]);
    }

    public function reportOutgoing(Request $request)
    {
        $device = $this->deviceFromRequest($request);

        $data = $request->validate([
            'messages' => ['required', 'array', 'max:50'],
            'messages.*.id' => ['required', 'integer', 'exists:outgoing_sms,id'],
            'messages.*.status' => ['required', 'in:sent,failed'],
            'messages.*.error' => ['nullable', 'string', 'max:500'],
        ]);

        $sent = 0;
        $failed = 0;

        foreach ($data['messages'] as $item) {
            $sms = OutgoingSms::whereKey($item['id'])
                ->where('status', OutgoingSms::STATUS_PROCESSING)
                ->where('sms_device_id', $device->id)
                ->first();

            if (! $sms) {
                continue;
            }

            if ($item['status'] === OutgoingSms::STATUS_SENT) {
                $sms->update([
                    'status' => OutgoingSms::STATUS_SENT,
                    'sms_device_id' => $device->id,
                    'sent_at' => now(),
                    'last_error' => null,
                ]);
                $sent++;
            } else {
                $status = $sms->attempts >= 5 ? OutgoingSms::STATUS_FAILED : OutgoingSms::STATUS_PENDING;
                $sms->update([
                    'status' => $status,
                    'sms_device_id' => $device->id,
                    'last_error' => $item['error'] ?? 'SMS send failed on Android device.',
                ]);
                $failed++;
            }
        }

        $device->update(['last_sync_at' => now()]);

        return response()->json([
            'sent' => $sent,
            'failed' => $failed,
        ]);
    }

    private function deviceFromRequest(Request $request): SmsDevice
    {
        $plainKey = $request->header('X-Device-Key') ?: $request->input('api_key');
        $keyHash = hash('sha256', (string) $plainKey);
        $device = SmsDevice::where('is_active', true)
            ->where('api_key_hash', $keyHash)
            ->first();

        if (! $device || ! hash_equals($device->api_key_hash, $keyHash)) {
            abort(403, 'Invalid device key.');
        }

        return $device;
    }
}
