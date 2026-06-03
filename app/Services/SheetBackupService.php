<?php

namespace App\Services;

use App\Models\PaymentMethod;
use App\Models\SmsLog;
use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SheetBackupService
{
    public function __construct(private SettingsService $settings)
    {
    }

    public function push(Transaction $transaction, string $event): void
    {
        $url = $this->settings->get('google_sheet_webhook_url');
        if (! $url) {
            return;
        }

        $send = function () use ($transaction, $event, $url) {
            $this->sendPush($transaction, $event, $url);
        };

        if (! app()->runningInConsole()) {
            app()->terminating($send);
            return;
        }

        $send();
    }

    private function sendPush(Transaction $transaction, string $event, string $url): void
    {
        try {
            Http::connectTimeout(2)->timeout(5)->post($url, [
                'secret' => $this->settings->get('google_sheet_secret'),
                'event' => $event,
                'generated_at' => now()->toIso8601String(),
                'transaction' => $this->transactionPayload($transaction->fresh(['paymentMethod', 'smsDevice', 'smsLog'])),
                'summary' => $this->summaryPayload(),
                'account_balances' => $this->accountBalancePayload(),
                'recent_sms_logs' => $this->recentSmsPayload(),
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Google Sheet backup failed', ['error' => $exception->getMessage()]);
        }
    }

    public function pushMany(Collection $transactions, string $event): void
    {
        $url = $this->settings->get('google_sheet_webhook_url');
        if (! $url || $transactions->isEmpty()) {
            return;
        }

        try {
            Http::connectTimeout(3)->timeout(15)->post($url, [
                'secret' => $this->settings->get('google_sheet_secret'),
                'event' => $event,
                'generated_at' => now()->toIso8601String(),
                'transactions' => $transactions
                    ->map(fn (Transaction $transaction) => $this->transactionPayload($transaction->fresh(['paymentMethod', 'smsDevice', 'smsLog'])) + [
                        'backup_event' => match ($transaction->status) {
                            Transaction::STATUS_SUCCESS => 'backfill_successful',
                            Transaction::STATUS_PENDING => 'backfill_pending',
                            default => 'backfill_unsuccessful',
                        },
                    ])
                    ->values()
                    ->all(),
                'summary' => $this->summaryPayload(),
                'account_balances' => $this->accountBalancePayload(),
                'recent_sms_logs' => $this->recentSmsPayload(),
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Google Sheet bulk backup failed', ['error' => $exception->getMessage()]);
        }
    }

    private function transactionPayload(Transaction $transaction): array
    {
        $metadata = $transaction->metadata ?? [];
        $smsLog = $transaction->smsLog;

        return [
            'id' => $transaction->id,
            'invoice_id' => $transaction->invoice_id,
            'order_id' => $transaction->order_id,
            'status' => $transaction->status,
            'status_group' => $this->statusGroup($transaction),
            'amount' => (float) $transaction->amount,
            'paid_amount' => $transaction->paidAmount(),
            'discount_amount' => $transaction->discountAmount(),
            'due_amount' => $transaction->dueAmount(),
            'currency' => $transaction->currency,
            'method' => $transaction->method_slug,
            'method_name' => $transaction->paymentMethod?->name,
            'method_option' => $transaction->method_option,
            'customer_name' => $metadata['customer_name'] ?? null,
            'customer_number' => $transaction->officialSenderNumber() ?: $transaction->customer_number,
            'input_customer_number' => $transaction->customer_number,
            'normalized_customer_number' => $transaction->normalized_customer_number,
            'trx_id' => $transaction->trx_id,
            'manual_note' => $transaction->manual_note,
            'device_id' => $transaction->sms_device_id,
            'device_name' => $transaction->smsDevice?->name,
            'sms_log_id' => $transaction->sms_log_id,
            'sms_sender' => $smsLog?->official_sender ?: $smsLog?->raw_sender,
            'sms_customer_number' => $smsLog?->parsed_customer_number,
            'sms_amount' => $smsLog ? (float) $smsLog->parsed_amount : null,
            'sms_type' => $smsLog?->sms_type,
            'sms_received_at' => $smsLog?->received_at?->toIso8601String(),
            'created_at' => $transaction->created_at?->toIso8601String(),
            'expires_at' => $transaction->expires_at?->toIso8601String(),
            'verified_at' => $transaction->verified_at?->toIso8601String(),
            'success_url' => $transaction->success_url,
            'failed_url' => $transaction->failed_url,
            'callback_url' => $metadata['callback_url'] ?? null,
            'metadata_json' => json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    }

    private function summaryPayload(): array
    {
        return [
            'total_transactions' => Transaction::count(),
            'pending_transactions' => Transaction::where('status', Transaction::STATUS_PENDING)->count(),
            'successful_transactions' => Transaction::where('status', Transaction::STATUS_SUCCESS)->count(),
            'failed_transactions' => Transaction::whereIn('status', [Transaction::STATUS_FAILED, Transaction::STATUS_EXPIRED])->count(),
            'successful_amount_total' => (float) Transaction::where('status', Transaction::STATUS_SUCCESS)->sum('amount'),
            'successful_paid_total' => round(Transaction::where('status', Transaction::STATUS_SUCCESS)->get()->sum(fn (Transaction $transaction) => $transaction->paidAmount()), 2),
            'pending_amount_total' => (float) Transaction::where('status', Transaction::STATUS_PENDING)->sum('amount'),
            'today_successful_amount' => (float) Transaction::where('status', Transaction::STATUS_SUCCESS)->whereDate('verified_at', today())->sum('amount'),
            'pending_unmatched_sms' => SmsLog::whereNull('matched_transaction_id')->where('sms_type', 'credit')->where('is_fraud', false)->count(),
        ];
    }

    private function accountBalancePayload(): array
    {
        return PaymentMethod::with(['transactions' => fn ($query) => $query->where('status', Transaction::STATUS_SUCCESS)])
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->flatMap(function (PaymentMethod $method) {
                $config = $method->config ?? [];
                $accountBases = is_array($config['account_balance_bases'] ?? null) ? $config['account_balance_bases'] : [];
                $baseSetAt = ! empty($config['balance_base_set_at'])
                    ? Carbon::parse($config['balance_base_set_at'])
                    : null;
                $balanceAccounts = $this->balanceAccountOptions($method);

                return collect($balanceAccounts)->map(function (array $account) use ($method, $config, $accountBases, $baseSetAt, $balanceAccounts) {
                    $baseAmount = round((float) ($accountBases[$account['account']] ?? $config['balance_base_amount'] ?? 0), 2);
                    $receivedAmount = $baseSetAt
                        ? round($method->transactions
                            ->filter(fn (Transaction $transaction) => $this->transactionBelongsToBalanceAccount($transaction, $account, $baseSetAt))
                            ->sum(fn (Transaction $transaction) => $transaction->paidAmount()), 2)
                        : 0.0;
                    $debitAmount = $baseSetAt
                        ? $this->debitAmountForBalanceAccount($method, $baseSetAt, $account, count($balanceAccounts))
                        : 0.0;

                    return [
                        'method' => $method->slug,
                        'method_name' => $method->name,
                        'account' => $account['account'],
                        'options' => implode(', ', $account['options']),
                        'currency' => $method->slug === 'binance' ? 'USDT' : 'BDT',
                        'base_amount' => $baseAmount,
                        'received_amount' => $receivedAmount,
                        'debit_amount' => $debitAmount,
                        'balance' => round($baseAmount + $receivedAmount - $debitAmount, 2),
                        'base_set_at' => $baseSetAt?->toIso8601String(),
                    ];
                });
            })
            ->values()
            ->all();
    }

    private function recentSmsPayload(): array
    {
        return SmsLog::with('smsDevice')
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn (SmsLog $log) => [
                'id' => $log->id,
                'method' => $log->method_slug,
                'sms_type' => $log->sms_type,
                'sender' => $log->official_sender ?: $log->raw_sender,
                'amount' => $log->parsed_amount ? (float) $log->parsed_amount : null,
                'customer_number' => $log->parsed_customer_number,
                'trx_id' => $log->parsed_trx_id,
                'received_at' => $log->received_at?->toIso8601String(),
                'device_name' => $log->smsDevice?->name,
                'matched_transaction_id' => $log->matched_transaction_id,
                'matched_invoice' => $log->matched_transaction_id
                    ? Transaction::whereKey($log->matched_transaction_id)->value('invoice_id')
                    : null,
                'is_duplicate' => $log->is_duplicate,
                'is_fraud' => $log->is_fraud,
            ])
            ->all();
    }

    private function statusGroup(Transaction $transaction): string
    {
        return match ($transaction->status) {
            Transaction::STATUS_SUCCESS => 'successful',
            Transaction::STATUS_PENDING => 'pending',
            default => 'unsuccessful',
        };
    }

    private function balanceAccountOptions(PaymentMethod $method): array
    {
        $config = $method->config ?? [];
        $optionNumbers = $config['option_numbers'] ?? [];
        $accounts = [];

        if ($method->send_money_enabled) {
            $this->pushBalanceAccount($accounts, $optionNumbers['send_money'] ?? $method->payment_number, 'send_money');
        }

        if ($method->slug === 'bkash' && $method->payment_enabled) {
            $this->pushBalanceAccount($accounts, $optionNumbers['payment'] ?? $method->payment_number, 'payment');
        }

        if (in_array($method->slug, ['bkash', 'nagad'], true) && $method->remittance_enabled) {
            $this->pushBalanceAccount($accounts, $optionNumbers['remittance'] ?? $method->remittance_number ?? $method->payment_number, 'remittance');
        }

        if ($method->slug === 'binance') {
            $this->pushBalanceAccount($accounts, $config['binance_uid'] ?? $config['account_number'] ?? 'Binance account', 'binance');
        }

        return array_values($accounts);
    }

    private function pushBalanceAccount(array &$accounts, mixed $account, string $option): void
    {
        $account = trim((string) $account);
        if ($account === '') {
            return;
        }

        $key = preg_replace('/\s+/', '', strtolower($account));
        $accounts[$key] ??= [
            'account' => $account,
            'options' => [],
        ];

        if (! in_array($option, $accounts[$key]['options'], true)) {
            $accounts[$key]['options'][] = $option;
        }
    }

    private function transactionBelongsToBalanceAccount(Transaction $transaction, array $account, Carbon $baseSetAt): bool
    {
        if (! $transaction->verified_at || ! $transaction->verified_at->greaterThanOrEqualTo($baseSetAt)) {
            return false;
        }

        if ($transaction->method_option) {
            return in_array($transaction->method_option, $account['options'], true);
        }

        return count($account['options']) === 1;
    }

    private function debitAmountForBalanceAccount(PaymentMethod $method, Carbon $baseSetAt, array $account, int $accountCount): float
    {
        if ($method->slug === 'binance') {
            return 0.0;
        }

        $query = SmsLog::query()
            ->where('method_slug', $method->slug)
            ->where('sms_type', 'debit')
            ->where('is_fraud', false)
            ->where('is_duplicate', false)
            ->where('received_at', '>=', $baseSetAt);

        if ($accountCount !== 1) {
            $query->whereIn('method_option', $account['options']);
        }

        return round((float) $query->sum('parsed_amount'), 2);
    }
}
