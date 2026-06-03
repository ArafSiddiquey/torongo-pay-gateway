<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\SettingsService;
use App\Services\SheetBackupService;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function store(Request $request, SettingsService $settings, SheetBackupService $sheet)
    {
        $secret = $settings->get('webhook_secret');
        if (! $secret) {
            abort(500, 'Webhook secret is not configured.');
        }

        if (! hash_equals((string) $secret, (string) $request->header('X-Webhook-Secret'))) {
            abort(403, 'Invalid webhook secret.');
        }

        $data = $request->validate([
            'invoice_id' => ['nullable', 'string', 'max:80', 'unique:transactions,invoice_id'],
            'order_id' => ['nullable', 'string', 'max:80'],
            'amount' => ['required', 'numeric', 'min:1'],
            'success_url' => ['nullable', 'url'],
            'failed_url' => ['nullable', 'url'],
            'callback_url' => ['nullable', 'url'],
            'metadata' => ['nullable', 'array'],
        ]);

        $invoiceId = $data['invoice_id'] ?? $this->generateInvoiceId();
        $token = hash_hmac('sha256', $invoiceId . '|' . number_format((float) $data['amount'], 2, '.', ''), (string) $secret);

        $metadata = $data['metadata'] ?? [];
        $metadata['verification_ready'] = false;
        if (! empty($data['callback_url'])) {
            $metadata['callback_url'] = $data['callback_url'];
        }

        $transaction = Transaction::create([
            'invoice_id' => $invoiceId,
            'order_id' => $data['order_id'] ?? null,
            'signed_token' => $token,
            'amount' => $data['amount'],
            'currency' => 'BDT',
            'status' => Transaction::STATUS_PENDING,
            'created_ip' => $request->ip(),
            'expires_at' => now()->addMinutes((int) $settings->get('countdown_minutes', '15')),
            'success_url' => $data['success_url'] ?? $settings->get('success_redirect_url'),
            'failed_url' => $data['failed_url'] ?? $settings->get('failed_redirect_url'),
            'metadata' => $metadata,
        ]);

        $sheet->push($transaction, 'created');

        return response()->json([
            'invoice_id' => $transaction->invoice_id,
            'payment_url' => route('payment.invoice', ['transaction' => $transaction->invoice_id, 'token' => $token]),
            'expires_at' => $transaction->expires_at,
        ]);
    }

    public function status(Request $request, Transaction $transaction, SettingsService $settings)
    {
        $secret = $settings->get('webhook_secret');
        if (! $secret) {
            abort(500, 'Webhook secret is not configured.');
        }

        if (! hash_equals((string) $secret, (string) $request->header('X-Webhook-Secret'))) {
            abort(403, 'Invalid webhook secret.');
        }

        return response()->json([
            'invoice_id' => $transaction->invoice_id,
            'order_id' => $transaction->order_id,
            'amount' => $transaction->amount,
            'currency' => $transaction->currency,
            'method' => $transaction->method_slug,
            'status' => $transaction->status,
            'trx_id' => $transaction->trx_id,
            'verified_at' => optional($transaction->verified_at)->toIso8601String(),
            'expires_at' => optional($transaction->expires_at)->toIso8601String(),
        ]);
    }

    private function generateInvoiceId(): string
    {
        do {
            $prefix = chr(random_int(65, 90)) . chr(random_int(65, 90));
            $invoiceId = $prefix . now()->format('YmdHi');
        } while (Transaction::where('invoice_id', $invoiceId)->exists());

        return $invoiceId;
    }
}
