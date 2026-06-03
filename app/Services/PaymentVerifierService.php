<?php

namespace App\Services;

use App\Models\SmsLog;
use App\Models\ManualVerification;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaymentVerifierService
{
    public function __construct(
        private SheetBackupService $sheet,
        private SettingsService $settings,
        private PhoneSmsQueueService $sms
    )
    {
    }

    public function verifySms(SmsLog $smsLog): ?Transaction
    {
        if (($smsLog->sms_type ?? 'credit') !== 'credit' || $smsLog->is_fraud || $smsLog->is_duplicate || ! $smsLog->parsed_trx_id) {
            return null;
        }

        return DB::transaction(function () use ($smsLog) {
            if (Transaction::where('trx_id', $smsLog->parsed_trx_id)->exists()) {
                $smsLog->update(['is_duplicate' => true]);
                return null;
            }

            $manualFallback = null;
            $transaction = $this->findAutoMatch($smsLog);

            if (! $transaction) {
                $manualFallback = $this->findManualFallback($smsLog);
                $transaction = $manualFallback?->transaction()->lockForUpdate()->first();
            }

            if (! $transaction) {
                return null;
            }

            $metadata = $this->appendPaymentReceipt($transaction, $smsLog);
            $paidAmount = (float) ($metadata['paid_amount'] ?? 0);
            $discountAmount = (float) ($metadata['discount_amount'] ?? 0);
            $dueAmount = max(round((float) $transaction->amount - $paidAmount - $discountAmount, 2), 0);
            $isFullyPaid = $dueAmount <= 0;

            $update = [
                'metadata' => $metadata,
                'sms_device_id' => $smsLog->sms_device_id,
                'sms_log_id' => $smsLog->id,
            ];

            if ($isFullyPaid) {
                $update += [
                    'status' => Transaction::STATUS_SUCCESS,
                    'trx_id' => $smsLog->parsed_trx_id,
                    'verified_at' => now(),
                ];
            } else {
                $update['manual_note'] = sprintf('Partial payment received: %.2f BDT. Due: %.2f BDT.', $paidAmount, $dueAmount);
            }

            $manualFallbackNumberMismatchedSms = $manualFallback?->customer_number
                && $smsLog->normalized_customer_number
                && NumberNormalizer::mobile($manualFallback->customer_number) !== $smsLog->normalized_customer_number;

            if ($manualFallbackNumberMismatchedSms) {
                $update['metadata']['manual_success_sms_suppressed'] = true;
                $update['metadata']['manual_success_sms_suppressed_reason'] = 'customer_number_mismatch';
                $update['metadata']['manual_processing_sms_pending'] = false;
            }

            if ($manualFallback?->customer_number || $smsLog->parsed_customer_number) {
                $update['customer_number'] = $smsLog->parsed_customer_number ?: $manualFallback->customer_number;
                $update['normalized_customer_number'] = $smsLog->normalized_customer_number ?: NumberNormalizer::mobile($manualFallback->customer_number);
            }

            $transaction->update($update);
            $smsLog->update(['matched_transaction_id' => $transaction->id]);

            if (! $isFullyPaid) {
                $this->sheet->push($transaction->fresh(), 'partial_payment');
                return null;
            }

            ManualVerification::where('transaction_id', $transaction->id)
                ->where('trx_id', $smsLog->parsed_trx_id)
                ->update(['status' => 'approved']);
            $fresh = $transaction->fresh();
            $this->sheet->push($fresh, 'verified');
            $this->notifyCallback($fresh, 'verified');
            if (! $manualFallbackNumberMismatchedSms) {
                $this->sms->queueSuccessfulPaymentConfirmed($fresh);
            }

            return $fresh;
        });
    }

    private function findAutoMatch(SmsLog $smsLog): ?Transaction
    {
        $receivedWindowEnd = $this->smsReceivedWindowEnd($smsLog);

        $query = Transaction::query()
            ->where('status', Transaction::STATUS_PENDING)
            ->where('method_slug', $smsLog->method_slug)
            ->where('method_option', '!=', 'remittance')
            ->where('metadata->verification_ready', true)
            ->where('created_at', '<=', $receivedWindowEnd)
            ->where(function ($query) use ($smsLog) {
                $query->where('expires_at', '>=', $smsLog->received_at)
                    ->orWhere('metadata->manual_hold', true);
            })
            ->whereNotNull('normalized_customer_number');

        if ($smsLog->method_option) {
            $query->where('method_option', $smsLog->method_option);
        }

        if ($smsLog->method_slug === 'rocket') {
            $suffix = $smsLog->normalized_customer_number;
            if (! $suffix || strlen($suffix) !== 3) {
                return null;
            }
            $query->where('normalized_customer_number', 'like', '%' . $suffix);
        } else {
            $query->where('normalized_customer_number', $smsLog->normalized_customer_number);
        }

        return $query->orderBy('created_at')
            ->lockForUpdate()
            ->get()
            ->filter(fn (Transaction $transaction) => $this->canApplySmsAmount($transaction, (float) $smsLog->parsed_amount))
            ->sortBy(fn (Transaction $transaction) => [
                abs($this->remainingDueAmount($transaction) - (float) $smsLog->parsed_amount) < 0.01 ? 0 : 1,
                $transaction->created_at?->getTimestamp() ?? 0,
            ])
            ->first();
    }

    private function findManualFallback(SmsLog $smsLog): ?ManualVerification
    {
        $receivedWindowEnd = $this->smsReceivedWindowEnd($smsLog);

        return ManualVerification::query()
            ->where('status', 'submitted')
            ->where('trx_id', $smsLog->parsed_trx_id)
            ->whereHas('transaction', function ($query) use ($smsLog, $receivedWindowEnd) {
                $query->where('status', Transaction::STATUS_PENDING)
                    ->where('method_slug', $smsLog->method_slug)
                    ->where('method_option', '!=', 'remittance')
                    ->where('created_at', '<=', $receivedWindowEnd)
                    ->where(function ($nested) use ($smsLog) {
                        $nested->where('expires_at', '>=', $smsLog->received_at)
                            ->orWhere('metadata->manual_hold', true);
                    });
                if ($smsLog->method_option) {
                    $query->where('method_option', $smsLog->method_option);
                }
            })
            ->orderBy('created_at')
            ->lockForUpdate()
            ->get()
            ->first(fn (ManualVerification $manual) => $this->canApplySmsAmount($manual->transaction, (float) $smsLog->parsed_amount));
    }

    public function verifyPendingRemittance(Transaction $transaction): ?Transaction
    {
        if ($transaction->status !== Transaction::STATUS_PENDING || $transaction->method_option !== 'remittance') {
            return null;
        }

        return DB::transaction(function () use ($transaction) {
            $transaction = Transaction::whereKey($transaction->id)->lockForUpdate()->first();
            if (! $transaction || $transaction->status !== Transaction::STATUS_PENDING) {
                return null;
            }
            if (($transaction->metadata['verification_ready'] ?? false) !== true) {
                return null;
            }
            if (! $transaction->payment_proof_path) {
                return null;
            }

            $createdWindowStart = $transaction->created_at->copy()->startOfMinute();
            $smsLog = SmsLog::query()
                ->where('method_slug', $transaction->method_slug)
                ->where(fn ($query) => $query->where('sms_type', 'credit')->orWhereNull('sms_type'))
                ->where('is_fraud', false)
                ->where('is_duplicate', false)
                ->whereNotNull('parsed_trx_id')
                ->whereNull('matched_transaction_id')
                ->where('received_at', '>=', $createdWindowStart)
                ->whereBetween('parsed_amount', [
                    $this->remittancePayableAmount($transaction),
                    round($this->remittancePayableAmount($transaction) * 1.025, 2),
                ])
                ->orderBy('received_at')
                ->lockForUpdate()
                ->first();

            if (! $smsLog) {
                return null;
            }

            if (Transaction::where('trx_id', $smsLog->parsed_trx_id)->whereKeyNot($transaction->id)->exists()) {
                $smsLog->update(['is_duplicate' => true]);
                return null;
            }

            $transaction->update([
                'status' => Transaction::STATUS_SUCCESS,
                'trx_id' => $smsLog->parsed_trx_id,
                'sms_device_id' => $smsLog->sms_device_id,
                'sms_log_id' => $smsLog->id,
                'verified_at' => now(),
                'manual_note' => 'Remittance matched SMS amount.',
            ]);

            $smsLog->update(['matched_transaction_id' => $transaction->id]);
            $fresh = $transaction->fresh();
            $this->sheet->push($fresh, 'remittance_verified');
            $this->notifyCallback($fresh, 'remittance_verified');

            return $fresh;
        });
    }

    public function notifyCallback(Transaction $transaction, string $event): void
    {
        $callbackUrl = $transaction->metadata['callback_url'] ?? null;
        if (! $callbackUrl) {
            return;
        }

        try {
            Http::connectTimeout(2)->timeout(5)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'X-Webhook-Secret' => (string) $this->settings->get('webhook_secret'),
                ])
                ->post($callbackUrl, [
                    'event' => $event,
                    'invoice_id' => $transaction->invoice_id,
                    'order_id' => $transaction->order_id,
                    'amount' => $transaction->amount,
                    'currency' => $transaction->currency,
                    'method' => $transaction->method_slug,
                    'method_option' => $transaction->method_option,
                    'status' => $transaction->status,
                    'trx_id' => $transaction->trx_id,
                    'verified_at' => optional($transaction->verified_at)->toIso8601String(),
                ]);
        } catch (\Throwable $exception) {
            Log::warning('Payment callback failed', [
                'invoice_id' => $transaction->invoice_id,
                'url' => $callbackUrl,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function verifyManualAttempt(Transaction $transaction, string $trxId, ?string $customerNumber = null): ?Transaction
    {
        $trxId = strtoupper(trim($trxId));
        if ($trxId === '' || $transaction->status !== Transaction::STATUS_PENDING) {
            return null;
        }

        return DB::transaction(function () use ($transaction, $trxId, $customerNumber) {
            if (Transaction::where('trx_id', $trxId)->whereKeyNot($transaction->id)->exists()) {
                return null;
            }

            $smsLog = SmsLog::query()
                ->where('parsed_trx_id', $trxId)
                ->where('method_slug', $transaction->method_slug)
                ->where(fn ($query) => $query->where('sms_type', 'credit')->orWhereNull('sms_type'))
                ->where('is_fraud', false)
                ->where(function ($query) use ($transaction) {
                    $query->whereNull('matched_transaction_id')
                        ->orWhere('matched_transaction_id', $transaction->id);
                })
                ->lockForUpdate()
                ->first();

            if (! $smsLog) {
                $smsLog = $this->findManualBankFallbackSms($transaction, $trxId);
            }

            if (! $smsLog) {
                return null;
            }

            if (! $this->canApplySmsAmount($transaction, (float) $smsLog->parsed_amount)) {
                return null;
            }

            if ($smsLog->parsed_trx_id && Transaction::where('trx_id', $smsLog->parsed_trx_id)->whereKeyNot($transaction->id)->exists()) {
                $smsLog->update(['is_duplicate' => true]);
                return null;
            }

            $normalizedCustomerNumber = $customerNumber
                ? NumberNormalizer::mobile($customerNumber)
                : $transaction->normalized_customer_number;

            $metadata = $this->appendPaymentReceipt($transaction, $smsLog);
            $submittedNumberMismatchedSms = $normalizedCustomerNumber
                && $smsLog->normalized_customer_number
                && $normalizedCustomerNumber !== $smsLog->normalized_customer_number;
            if ($submittedNumberMismatchedSms) {
                $metadata['manual_success_sms_suppressed'] = true;
                $metadata['manual_success_sms_suppressed_reason'] = 'customer_number_mismatch';
                $metadata['manual_processing_sms_pending'] = false;
            }
            $paidAmount = (float) ($metadata['paid_amount'] ?? 0);
            $dueAmount = max(round((float) $transaction->amount - $paidAmount - (float) ($metadata['discount_amount'] ?? 0), 2), 0);

            $update = [
                'customer_number' => $smsLog->parsed_customer_number ?: ($customerNumber ?: $transaction->customer_number),
                'normalized_customer_number' => $smsLog->normalized_customer_number ?: $normalizedCustomerNumber,
                'sms_device_id' => $smsLog->sms_device_id,
                'sms_log_id' => $smsLog->id,
                'metadata' => $metadata,
            ];

            if ($dueAmount <= 0) {
                $update += [
                    'status' => Transaction::STATUS_SUCCESS,
                    'trx_id' => $smsLog->parsed_trx_id ?: $trxId,
                    'verified_at' => now(),
                    'manual_note' => $this->bankNameFromIbankingSms($smsLog->raw_body)
                        ? 'Manual bank-name verification matched SMS log.'
                        : 'Manual customer verification matched SMS log.',
                ];
            } else {
                $update['manual_note'] = sprintf('Manual customer verification found partial payment: %.2f BDT. Due: %.2f BDT.', $paidAmount, $dueAmount);
            }

            $transaction->update($update);

            $smsLog->update(['matched_transaction_id' => $transaction->id]);

            if ($dueAmount > 0) {
                $this->sheet->push($transaction->fresh(), 'manual_partial_payment');
                return null;
            }

            ManualVerification::where('transaction_id', $transaction->id)
                ->where('trx_id', $trxId)
                ->update(['status' => 'approved']);
            $fresh = $transaction->fresh();
            $this->sheet->push($fresh, 'manual_verified_by_trx_id');
            $this->notifyCallback($fresh, 'manual_verified_by_trx_id');
            if (! $submittedNumberMismatchedSms) {
                $this->sms->queueSuccessfulPaymentConfirmed($fresh);
            }

            return $fresh;
        });
    }

    public function expireOldPending(): int
    {
        return Transaction::where('status', Transaction::STATUS_PENDING)
            ->where('method_option', '!=', 'remittance')
            ->where('expires_at', '<', now())
            ->where(function ($query) {
                $query->whereNull('metadata->manual_hold')
                    ->orWhere('metadata->manual_hold', false);
            })
            ->update([
                'status' => Transaction::STATUS_EXPIRED,
                'updated_at' => now(),
            ]);
    }

    private function remittancePayableAmount(Transaction $transaction): float
    {
        return round((float) ($transaction->metadata['remittance_payable_amount'] ?? $transaction->amount), 2);
    }

    private function canApplySmsAmount(Transaction $transaction, float $smsAmount): bool
    {
        return $smsAmount > 0 && $smsAmount <= ($this->remainingDueAmount($transaction) + 0.009);
    }

    private function remainingDueAmount(Transaction $transaction): float
    {
        $metadata = $transaction->metadata ?? [];
        $paidAmount = (float) ($metadata['paid_amount'] ?? 0);
        $discountAmount = (float) ($metadata['discount_amount'] ?? 0);

        return max(round((float) $transaction->amount - $paidAmount - $discountAmount, 2), 0);
    }

    private function appendPaymentReceipt(Transaction $transaction, SmsLog $smsLog): array
    {
        $metadata = $transaction->metadata ?? [];
        $receipts = collect($metadata['payment_receipts'] ?? []);

        if ($receipts->contains(fn ($receipt) => ($receipt['trx_id'] ?? null) === $smsLog->parsed_trx_id)) {
            return $metadata;
        }

        $receipts->push([
            'trx_id' => $smsLog->parsed_trx_id,
            'amount' => (float) $smsLog->parsed_amount,
            'method_option' => $smsLog->method_option,
            'bank_name' => $this->bankNameFromIbankingSms($smsLog->raw_body),
            'customer_number' => $smsLog->parsed_customer_number,
            'normalized_customer_number' => $smsLog->normalized_customer_number,
            'sms_log_id' => $smsLog->id,
            'received_at' => $smsLog->received_at?->toIso8601String(),
        ]);

        $metadata['payment_receipts'] = $receipts->values()->all();
        $metadata['paid_amount'] = round($receipts->sum(fn ($receipt) => (float) ($receipt['amount'] ?? 0)), 2);
        $metadata['due_amount'] = max(round((float) $transaction->amount - (float) $metadata['paid_amount'] - (float) ($metadata['discount_amount'] ?? 0), 2), 0);

        return $metadata;
    }

    private function findManualBankFallbackSms(Transaction $transaction, string $submittedBankName): ?SmsLog
    {
        $normalizedBankName = $this->normalizeBankName($submittedBankName);
        if ($normalizedBankName === '') {
            return null;
        }

        $createdWindowStart = $transaction->created_at?->copy()->startOfMinute() ?? now()->subMinutes(30);

        $query = SmsLog::query()
            ->where('method_slug', $transaction->method_slug)
            ->when($transaction->method_option, fn ($query) => $query->where(function ($nested) use ($transaction) {
                $nested->whereNull('method_option')->orWhere('method_option', $transaction->method_option);
            }))
            ->where(fn ($query) => $query->where('sms_type', 'credit')->orWhereNull('sms_type'))
            ->where('is_fraud', false)
            ->where('is_duplicate', false)
            ->whereNotNull('parsed_trx_id')
            ->where(function ($query) use ($transaction) {
                $query->whereNull('matched_transaction_id')
                    ->orWhere('matched_transaction_id', $transaction->id);
            })
            ->where('received_at', '>=', $createdWindowStart)
            ->when(($transaction->metadata['manual_hold'] ?? false) !== true, fn ($query) => $query->where('received_at', '<=', $transaction->expires_at))
            ->orderBy('received_at');

        return $query
            ->lockForUpdate()
            ->get()
            ->first(function (SmsLog $smsLog) use ($transaction, $normalizedBankName) {
                return $this->canApplySmsAmount($transaction, (float) $smsLog->parsed_amount)
                    && $this->normalizeBankName($this->bankNameFromIbankingSms($smsLog->raw_body)) === $normalizedBankName;
            });
    }

    private function bankNameFromIbankingSms(?string $body): ?string
    {
        $body = (string) $body;
        if (! preg_match('/received\s+deposit\s+from\s+iBanking\s+of\s+Tk\s*[0-9,]+(?:\.[0-9]{1,2})?\s+from\s+(.+?)\s+Internet\s+Banking/i', $body, $match)) {
            return null;
        }

        return trim(preg_replace('/\s+/', ' ', $match[1]));
    }

    private function normalizeBankName(?string $bankName): string
    {
        $bankName = strtoupper(trim((string) $bankName));
        $bankName = preg_replace('/\b(INTERNET|I\s*BANKING|IBANKING|BANKING)\b/i', '', $bankName);
        $bankName = preg_replace('/[^A-Z0-9]+/', '', $bankName);

        return $bankName ?: '';
    }

    private function smsReceivedWindowEnd(SmsLog $smsLog)
    {
        return $smsLog->received_at?->copy()->endOfMinute() ?? now();
    }

}
