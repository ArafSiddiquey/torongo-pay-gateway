<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GatewaySetting;
use App\Models\LanguageText;
use App\Models\ManualVerification;
use App\Models\PaymentMethod;
use App\Models\SmsLog;
use App\Models\Transaction;
use App\Services\ActivityLogger;
use App\Services\PaymentVerifierService;
use App\Services\SettingsService;
use App\Services\SheetBackupService;
use App\Services\PhoneSmsQueueService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AdminController extends Controller
{
    private const APP_ARTIFACTS = [
        'android-app' => [
            'title' => 'Torongo Verify Android App',
            'version' => 'Latest APK',
            'description' => 'Android SMS reader and confirmation app for Torongo Pay devices.',
            'icon' => 'android',
            'path' => 'resources/downloads/torongo-verify-latest.apk',
            'download_name' => 'torongo-verify-latest.apk',
            'mime' => 'application/vnd.android.package-archive',
        ],
        'wordpress-plugin' => [
            'title' => 'Torongo Pay WordPress Plugin',
            'version' => 'v1.0.0',
            'description' => 'WooCommerce gateway plugin for connecting WordPress checkout with Torongo Pay.',
            'icon' => 'wordpress',
            'path' => 'resources/downloads/torongo-pay-woocommerce.zip',
            'download_name' => 'torongo-pay-woocommerce.zip',
            'mime' => 'application/zip',
        ],
    ];

    public function dashboard()
    {
        $balanceSummaries = PaymentMethod::with(['transactions' => function ($query) {
                $query->where('status', Transaction::STATUS_SUCCESS);
            }])
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
                        'name' => $method->name,
                        'account' => $account['account'],
                        'options' => $account['options'],
                        'currency' => $method->slug === 'binance' ? 'USDT' : 'BDT',
                        'base_amount' => $baseAmount,
                        'received_amount' => $receivedAmount,
                        'debit_amount' => $debitAmount,
                        'balance' => round($baseAmount + $receivedAmount - $debitAmount, 2),
                    ];
                });
            })
            ->values();

        return view('admin.dashboard', [
            'total' => Transaction::count(),
            'success' => Transaction::where('status', Transaction::STATUS_SUCCESS)->count(),
            'pending' => Transaction::where('status', Transaction::STATUS_PENDING)->count(),
            'failed' => Transaction::whereIn('status', [Transaction::STATUS_FAILED, Transaction::STATUS_EXPIRED])->count(),
            'pendingSms' => SmsLog::whereNull('matched_transaction_id')->where('sms_type', 'credit')->where('is_fraud', false)->count(),
            'todayAmount' => Transaction::where('status', Transaction::STATUS_SUCCESS)->whereDate('verified_at', today())->sum('amount'),
            'recent' => Transaction::latest()->limit(8)->get(),
            'balanceSummaries' => $balanceSummaries,
        ]);
    }

    public function transactions(Request $request)
    {
        $isPendingPage = $request->status === Transaction::STATUS_PENDING;
        $status = $isPendingPage ? Transaction::STATUS_PENDING : $request->input('status', Transaction::STATUS_SUCCESS);
        $perPage = (int) $request->input('per_page', 10);
        if (! in_array($perPage, [10, 50, 100], true)) {
            $perPage = 10;
        }

        $transactions = Transaction::with(['paymentMethod', 'smsDevice', 'latestManualVerification', 'smsLog'])
            ->when(
                $isPendingPage,
                fn ($q) => $q->where('status', Transaction::STATUS_PENDING),
                fn ($q) => $q
                    ->where('status', '!=', Transaction::STATUS_PENDING)
                    ->where('status', $status)
            )
            ->when($request->q, fn ($q) => $q->where(fn ($w) => $w
                ->where('invoice_id', 'like', "%{$request->q}%")
                ->orWhere('customer_number', 'like', "%{$request->q}%")
                ->orWhere('trx_id', 'like', "%{$request->q}%")
                ->orWhereHas('manualVerifications', fn ($manual) => $manual->where('trx_id', 'like', "%{$request->q}%"))))
            ->latest()
            ->paginate($perPage)
            ->withQueryString();

        return view('admin.transactions.index', compact('transactions', 'status', 'perPage'));
    }

    public function invoices(Request $request)
    {
        $perPage = (int) $request->input('per_page', 10);
        if (! in_array($perPage, [10, 50, 100], true)) {
            $perPage = 10;
        }

        $methods = PaymentMethod::where('is_active', true)->orderBy('sort_order')->get();
        $transactions = Transaction::with(['paymentMethod', 'smsDevice', 'latestManualVerification', 'smsLog'])
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->method, fn ($q) => $q->where('method_slug', $request->method))
            ->when($request->q, function ($q) use ($request) {
                $term = "%{$request->q}%";
                $rawTerm = trim((string) $request->q);
                $numericAmount = is_numeric($rawTerm) ? (float) $rawTerm : null;
                $q->where(function ($w) use ($term, $numericAmount) {
                    $w->where('invoice_id', 'like', $term)
                        ->orWhere('order_id', 'like', $term)
                        ->orWhere('amount', 'like', $term)
                        ->orWhere('customer_number', 'like', $term)
                        ->orWhere('trx_id', 'like', $term)
                        ->orWhere('metadata->customer_name', 'like', $term)
                        ->orWhereHas('manualVerifications', fn ($manual) => $manual->where('trx_id', 'like', $term));
                    if ($numericAmount !== null) {
                        $w->orWhere('amount', $numericAmount);
                    }
                });
            })
            ->latest()
            ->paginate($perPage)
            ->withQueryString();

        return view('admin.invoices.index', compact('transactions', 'methods', 'perPage'));
    }

    public function apps()
    {
        $artifacts = collect(self::APP_ARTIFACTS)
            ->map(function (array $artifact, string $key) {
                $path = base_path($artifact['path']);
                $exists = is_file($path);

                return [
                    ...$artifact,
                    'key' => $key,
                    'exists' => $exists,
                    'size' => $exists ? $this->formatBytes(filesize($path)) : null,
                    'updated_at' => $exists ? Carbon::createFromTimestamp(filemtime($path))->format('g:i A, j F y') : null,
                ];
            })
            ->values();

        return view('admin.apps.index', compact('artifacts'));
    }

    public function downloadApp(string $artifact)
    {
        abort_unless(array_key_exists($artifact, self::APP_ARTIFACTS), 404);

        $download = self::APP_ARTIFACTS[$artifact];
        $path = base_path($download['path']);

        abort_unless(is_file($path), 404);

        return response()->download($path, $download['download_name'], [
            'Content-Type' => $download['mime'],
        ]);
    }

    public function storeInvoice(Request $request, SettingsService $settings, SheetBackupService $sheet, ActivityLogger $log)
    {
        $data = $request->validate([
            'payment_method_id' => ['nullable', 'exists:payment_methods,id'],
            'brand_name' => ['required', 'string', 'max:120'],
            'invoice_id' => ['nullable', 'string', 'max:80', 'unique:transactions,invoice_id'],
            'order_id' => ['nullable', 'string', 'max:80'],
            'amount' => ['required', 'numeric', 'min:1'],
            'customer_name' => ['nullable', 'string', 'max:120'],
            'success_url' => ['nullable', 'url', 'max:500'],
            'failed_url' => ['nullable', 'url', 'max:500'],
            'callback_url' => ['nullable', 'url', 'max:500'],
        ]);

        $method = ! empty($data['payment_method_id']) ? PaymentMethod::find($data['payment_method_id']) : null;
        $invoiceId = $data['invoice_id'] ?: $this->generateInvoiceId();
        $secret = $settings->get('webhook_secret', env('WEBHOOK_SECRET'));
        $token = hash_hmac('sha256', $invoiceId . '|' . number_format((float) $data['amount'], 2, '.', ''), (string) $secret);

        $transaction = Transaction::create([
            'invoice_id' => $invoiceId,
            'order_id' => $data['order_id'] ?? null,
            'signed_token' => $token,
            'amount' => $data['amount'],
            'currency' => 'BDT',
            'payment_method_id' => $method?->id,
            'method_slug' => $method?->slug,
            'status' => Transaction::STATUS_PENDING,
            'created_ip' => $request->ip(),
            'expires_at' => now()->addMinutes((int) $settings->get('countdown_minutes', '15')),
            'success_url' => ($data['success_url'] ?? '') ?: $settings->get('success_redirect_url'),
            'failed_url' => ($data['failed_url'] ?? '') ?: $settings->get('failed_redirect_url'),
            'metadata' => [
                'brand_name' => $data['brand_name'],
                'customer_name' => $data['customer_name'] ?? null,
                'callback_url' => $data['callback_url'] ?? null,
                'created_from' => 'admin_invoice',
                'verification_ready' => false,
            ],
        ]);

        $sheet->push($transaction, 'created');
        $log->log('invoice.create', $transaction, $data, $request);

        return redirect()
            ->route('admin.invoices')
            ->with('ok', 'Invoice created. Payment link: ' . route('payment.invoice', ['transaction' => $transaction->invoice_id, 'token' => $token]));
    }

    public function discountDue(Request $request, Transaction $transaction, SheetBackupService $sheet, ActivityLogger $log, PaymentVerifierService $verifier)
    {
        $dueAmount = $transaction->dueAmount();
        if ($transaction->status !== Transaction::STATUS_PENDING || $dueAmount <= 0) {
            return back()->withErrors(['discount' => 'This invoice has no active due amount.']);
        }

        $metadata = $transaction->metadata ?? [];
        $metadata['discount_amount'] = round((float) ($metadata['discount_amount'] ?? 0) + $dueAmount, 2);
        $metadata['due_discounted_at'] = now()->toIso8601String();
        $metadata['due_amount'] = 0;

        $transaction->update([
            'status' => Transaction::STATUS_SUCCESS,
            'verified_at' => now(),
            'metadata' => $metadata,
            'manual_note' => sprintf('Admin discounted due amount: %.2f BDT.', $dueAmount),
        ]);

        $fresh = $transaction->fresh();
        $sheet->push($fresh, 'due_discounted');
        $verifier->notifyCallback($fresh, 'due_discounted');
        $log->log('invoice.discount_due', $transaction, ['discount' => $dueAmount], $request);

        return back()->with('ok', 'Due amount discounted and invoice settled.');
    }

    public function approve(Request $request, Transaction $transaction, SheetBackupService $sheet, ActivityLogger $log, PaymentVerifierService $verifier, PhoneSmsQueueService $sms)
    {
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $transaction->update([
            'status' => Transaction::STATUS_SUCCESS,
            'verified_at' => now(),
            'manual_note' => $data['note'] ?? 'Manual approved by admin',
        ]);

        ManualVerification::where('transaction_id', $transaction->id)->update(['status' => 'approved']);
        $fresh = $transaction->fresh();
        $sheet->push($fresh, 'manual_approved');
        $verifier->notifyCallback($fresh, 'manual_approved');
        $sms->queueSuccessfulPaymentConfirmed($fresh);
        $log->log('transaction.approve', $transaction, $data, $request);

        return back()->with('ok', 'Transaction approved.');
    }

    public function reject(Request $request, Transaction $transaction, SheetBackupService $sheet, ActivityLogger $log)
    {
        $transaction->update(['status' => Transaction::STATUS_FAILED, 'manual_note' => $request->input('note', 'Manual rejected by admin')]);
        ManualVerification::where('transaction_id', $transaction->id)->update(['status' => 'rejected']);
        $sheet->push($transaction->fresh(), 'manual_rejected');
        $log->log('transaction.reject', $transaction, ['note' => $request->input('note')], $request);

        return back()->with('ok', 'Transaction rejected.');
    }

    public function rejectAllPending(Request $request, ActivityLogger $log)
    {
        $count = Transaction::where('status', Transaction::STATUS_PENDING)->count();

        if ($count > 0) {
            DB::transaction(function () {
                $pendingIds = Transaction::where('status', Transaction::STATUS_PENDING)->pluck('id');

                Transaction::whereIn('id', $pendingIds)->update([
                    'status' => Transaction::STATUS_FAILED,
                    'manual_note' => 'Bulk rejected by admin',
                    'updated_at' => now(),
                ]);

                ManualVerification::whereIn('transaction_id', $pendingIds)->update([
                    'status' => 'rejected',
                    'updated_at' => now(),
                ]);
            });
        }

        $log->log('transaction.reject_all_pending', null, ['count' => $count], $request);

        return back()->with('ok', $count . ' pending transaction(s) rejected.');
    }

    private function generateInvoiceId(): string
    {
        do {
            $prefix = chr(random_int(65, 90)) . chr(random_int(65, 90));
            $invoiceId = $prefix . now()->format('YmdHi');
        } while (Transaction::where('invoice_id', $invoiceId)->exists());

        return $invoiceId;
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

    private function formatBytes(int|false $bytes): string
    {
        if ($bytes === false || $bytes <= 0) {
            return '0 KB';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $power = min((int) floor(log($bytes, 1024)), count($units) - 1);

        return round($bytes / (1024 ** $power), $power === 0 ? 0 : 1) . ' ' . $units[$power];
    }

    public function sms()
    {
        return view('admin.sms.index', ['logs' => SmsLog::with('smsDevice')->latest()->paginate(30)]);
    }

    public function texts()
    {
        $texts = LanguageText::orderBy('key')->get()->groupBy('key');
        return view('admin.texts.index', compact('texts'));
    }

    public function saveTexts(Request $request)
    {
        foreach ($request->input('texts', []) as $key => $langs) {
            foreach ($langs as $lang => $value) {
                LanguageText::updateOrCreate(['key' => $key, 'lang' => $lang], ['value' => $value]);
            }
        }

        return back()->with('ok', 'Language text saved.');
    }

    public function settings()
    {
        $settings = GatewaySetting::pluck('value', 'key');
        $invoiceLogoPath = trim((string) ($settings['invoice_logo_path'] ?? ''));
        $invoiceLogo = null;
        if ($invoiceLogoPath !== '' && Storage::disk('public')->exists($invoiceLogoPath)) {
            $invoiceLogo = [
                'name' => basename($invoiceLogoPath),
                'url' => Storage::disk('public')->url($invoiceLogoPath),
            ];
        }

        return view('admin.settings.index', compact('settings', 'invoiceLogo'));
    }

    public function saveSettings(Request $request, SettingsService $settings)
    {
        $request->validate([
            'invoice_logo_file' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,svg', 'max:4096'],
        ]);

        if ($request->hasFile('invoice_logo_file')) {
            $oldPath = $settings->get('invoice_logo_path');
            $path = $request->file('invoice_logo_file')->store('invoice-logos', 'public');
            $settings->set('invoice_logo_path', $path);

            if ($oldPath && Storage::disk('public')->exists($oldPath)) {
                Storage::disk('public')->delete($oldPath);
            }
        }

        $editableSettings = [
            'gateway_name',
            'invoice_from_details',
            'countdown_minutes',
            'manual_verify_delay_minutes',
            'remittance_concurrent_extra_amount',
            'webhook_secret',
            'success_redirect_url',
            'failed_redirect_url',
            'terms_title',
            'terms_body',
            'google_sheet_webhook_url',
            'google_sheet_secret',
            'payment_sms_delivery_scope',
            'payment_sms_brand',
            'payment_sms_contact_url',
            'payment_sms_template',
            'google_admin_email',
            'google_client_id',
            'google_client_secret',
        ];

        foreach ($request->only($editableSettings) as $key => $value) {
            $settings->set($key, $value);
        }

        return back()->with('ok', 'Settings saved.');
    }
}
