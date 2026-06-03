<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use App\Models\ManualVerification;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Services\NumberNormalizer;
use App\Services\BinancePersonalVerifierService;
use App\Services\PaymentVerifierService;
use App\Services\SettingsService;
use App\Services\SheetBackupService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class GatewayController extends Controller
{
    public function show(Request $request, Transaction $transaction, SettingsService $settings)
    {
        $this->guardToken($request, $transaction);
        $this->abortExpired($transaction);

        if ($transaction->isInvoiceSettled()) {
            return view('payment.invoice', compact('transaction', 'settings'));
        }

        $methods = PaymentMethod::where('is_active', true)->orderBy('sort_order')->get();

        return view('payment.show', compact('transaction', 'methods', 'settings'));
    }

    public function downloadPdf(Request $request, Transaction $transaction, SettingsService $settings)
    {
        $this->guardToken($request, $transaction);
        abort_unless($transaction->isInvoiceSettled(), 404);

        $pdf = Pdf::setOptions([
                'isRemoteEnabled' => true,
                'defaultFont' => 'calibri',
            ])
            ->loadView('payment.invoice_pdf', compact('transaction', 'settings'))
            ->setPaper('a4', 'portrait');

        return $pdf->download($transaction->invoice_id . '.pdf');
    }

    public function saveSender(Request $request, Transaction $transaction, SettingsService $settings)
    {
        $this->guardToken($request, $transaction);
        $this->abortExpired($transaction);

        $data = $request->validate([
            'payment_method_id' => ['required', 'exists:payment_methods,id'],
            'method_option' => ['nullable', 'in:send_money,payment,remittance,cash_out,binance'],
            'customer_number' => ['nullable', 'required_unless:method_option,remittance,binance', 'regex:/^[0-9]{11,12}$/'],
        ]);

        $method = PaymentMethod::findOrFail($data['payment_method_id']);
        $option = $method->slug === 'binance' ? 'binance' : ($data['method_option'] ?: 'payment');

        if ($method->slug === 'nagad' && $option === 'payment') {
            abort(422, 'Nagad Payment option is not available.');
        }

        if ($method->slug === 'rocket' && $option !== 'send_money') {
            abort(422, 'Rocket only supports Send Money.');
        }

        if ($option !== 'remittance' && $option !== 'binance') {
            $length = strlen((string) $data['customer_number']);
            if (($method->slug === 'rocket' && $length !== 12) || ($method->slug !== 'rocket' && $length !== 11)) {
                abort(422, 'Invalid customer number length.');
            }
        }

        if (! $method->is_active
            || ($option === 'send_money' && ! $method->send_money_enabled)
            || ($option === 'payment' && ! $method->payment_enabled)
            || ($option === 'cash_out' && ! $method->cash_out_enabled)
            || ($option === 'remittance' && ! $method->remittance_enabled)) {
            abort(422, 'Payment method option is not available.');
        }

        $metadata = $transaction->metadata ?? [];
        $productAmount = round((float) ($metadata['product_amount'] ?? $transaction->amount), 2);
        $feePercent = $option === 'remittance' ? 0.0 : $this->optionFeePercent($method, $option);
        $feeAmount = round($productAmount * ($feePercent / 100), 2);
        $payableAmount = round($productAmount + $feeAmount, 2);

        if ($option === 'remittance') {
            $metadata['remittance_payable_amount'] = $this->resolveRemittancePayableAmount($transaction, $method, $settings, $productAmount);
        }
        $metadata['product_amount'] = $productAmount;
        $metadata['payment_fee_percent'] = $feePercent;
        $metadata['payment_fee_amount'] = $feeAmount;
        $metadata['payable_amount'] = $payableAmount;
        $metadata['verification_ready'] = ! in_array($option, ['remittance', 'binance'], true);

        $transaction->update([
            'amount' => $payableAmount,
            'payment_method_id' => $method->id,
            'method_slug' => $method->slug,
            'method_option' => $option,
            'customer_number' => in_array($option, ['remittance', 'binance'], true) ? null : $data['customer_number'],
            'normalized_customer_number' => in_array($option, ['remittance', 'binance'], true) ? null : NumberNormalizer::mobile($data['customer_number']),
            'expires_at' => now()->addMinutes((int) $settings->get('countdown_minutes', '15')),
            'metadata' => $metadata,
        ]);

        $redirectUrl = route('payment.instructions', ['transaction' => $transaction->invoice_id, 'token' => $transaction->signed_token]);

        if ($request->expectsJson()) {
            return response()->json(['redirect_url' => $redirectUrl]);
        }

        return redirect($redirectUrl);
    }

    public function instructions(Request $request, Transaction $transaction, SettingsService $settings)
    {
        $this->guardToken($request, $transaction);
        $this->abortExpired($transaction);

        if ($transaction->isInvoiceSettled()) {
            return view('payment.invoice', compact('transaction', 'settings'));
        }

        $transaction->load('paymentMethod');

        return view('payment.instructions', compact('transaction', 'settings'));
    }

    public function processing(Request $request, Transaction $transaction, SettingsService $settings)
    {
        $this->guardToken($request, $transaction);
        $this->abortExpired($transaction);

        return view('payment.processing', compact('transaction', 'settings'));
    }

    public function status(Request $request, Transaction $transaction, PaymentVerifierService $verifier, BinancePersonalVerifierService $binance, SettingsService $settings, SheetBackupService $sheet)
    {
        $this->guardToken($request, $transaction);
        $transaction->load('paymentMethod');
        $binanceResult = $binance->check($transaction);
        if ($binanceResult === 'success') {
            $transaction->refresh();
            $sheet->push($transaction, 'verified');
            $verifier->notifyCallback($transaction, 'verified');
        }
        if ($transaction->method_option === 'remittance' && $transaction->status === Transaction::STATUS_PENDING) {
            $verifier->verifyPendingRemittance($transaction);
        }
        $verifier->expireOldPending();
        $transaction->refresh();

        return response()->json([
            'status' => $transaction->status,
            'trx_id' => $transaction->trx_id,
            'binance_result' => $binanceResult,
            'paid_amount' => $transaction->paidAmount(),
            'due_amount' => $transaction->dueAmount(),
            'required_amount' => (float) $transaction->amount,
            'is_partially_paid' => $transaction->status === Transaction::STATUS_PENDING && $transaction->paidAmount() > 0 && $transaction->dueAmount() > 0,
            'support_url' => $binanceResult === 'underpaid' ? $settings->get('support_contact', '#support') : null,
            'redirect_url' => $transaction->status === Transaction::STATUS_SUCCESS ? $transaction->success_url : ($transaction->status !== Transaction::STATUS_PENDING ? $transaction->failed_url : null),
        ]);
    }

    public function holdManual(Request $request, Transaction $transaction)
    {
        $this->guardToken($request, $transaction);
        $this->abortExpired($transaction);

        if ($transaction->status !== Transaction::STATUS_PENDING
            || in_array($transaction->method_option, ['remittance', 'binance'], true)) {
            return response()->json(['ok' => false], 422);
        }

        $metadata = $transaction->metadata ?? [];
        $metadata['manual_hold'] = true;
        $metadata['manual_hold_at'] = now()->toIso8601String();

        $transaction->update(['metadata' => $metadata]);

        return response()->json(['ok' => true]);
    }

    public function manualVerify(Request $request, Transaction $transaction, SheetBackupService $sheet, PaymentVerifierService $verifier)
    {
        $this->guardToken($request, $transaction);
        $this->abortExpired($transaction);

        $isRemittance = $transaction->method_option === 'remittance';
        $isBinance = $transaction->method_option === 'binance';
        $isMobileFallback = ! $isRemittance && ! $isBinance;
        $isManualHold = ($transaction->metadata['manual_hold'] ?? false) === true;

        if (! $isRemittance && ! $isManualHold && $transaction->expires_at?->isPast()) {
            $transaction->update(['status' => Transaction::STATUS_EXPIRED]);
            return redirect()->route('payment.instructions', [
                'transaction' => $transaction->invoice_id,
                'token' => $transaction->signed_token,
            ]);
        }

        if ($transaction->status !== Transaction::STATUS_PENDING) {
            return redirect()->route('payment.instructions', [
                'transaction' => $transaction->invoice_id,
                'token' => $transaction->signed_token,
            ]);
        }

        $data = $request->validate([
            'trx_id' => [($isBinance || $isMobileFallback) ? 'required' : 'nullable', 'string', 'max:80'],
            'customer_number' => [($isRemittance || $isMobileFallback) ? 'required' : 'nullable', 'string', 'max:20', 'regex:/^[0-9+ ]{8,20}$/'],
            'payment_proof' => [$isRemittance ? 'required' : 'nullable', 'image', 'max:4096'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $proofPath = $request->hasFile('payment_proof') ? $request->file('payment_proof')->store('payment-proofs', 'public') : null;

        ManualVerification::create([
            'transaction_id' => $transaction->id,
            'trx_id' => isset($data['trx_id']) ? strtoupper($data['trx_id']) : null,
            'customer_number' => $data['customer_number'] ?? null,
            'note' => $data['note'] ?? null,
            'ip' => $request->ip(),
        ]);

        $metadata = $transaction->metadata ?? [];
        if ($isRemittance) {
            $metadata['verification_ready'] = true;
        }

        $transaction->update([
            'payment_proof_path' => $proofPath ?: $transaction->payment_proof_path,
            'customer_number' => ! empty($data['customer_number']) ? $data['customer_number'] : $transaction->customer_number,
            'normalized_customer_number' => ! empty($data['customer_number']) ? NumberNormalizer::mobile($data['customer_number']) : $transaction->normalized_customer_number,
            'metadata' => $metadata,
            'manual_note' => $isRemittance
                ? 'Remittance proof submitted. WhatsApp number: ' . ($data['customer_number'] ?? '')
                : ($isBinance ? 'Binance order ID submitted: ' . strtoupper($data['trx_id']) : 'Customer requested payment recheck. Submitted TrxID: ' . strtoupper($data['trx_id'])),
        ]);

        if (! $isRemittance && ! $isBinance && ! empty($data['trx_id']) && $matched = $verifier->verifyManualAttempt($transaction->fresh(), strtoupper($data['trx_id']), $data['customer_number'] ?? null)) {
            return redirect()->route('payment.processing', [
                'transaction' => $matched->invoice_id,
                'token' => $matched->signed_token,
                'verify' => 1,
            ]);
        }

        if ($isMobileFallback) {
            $freshMetadata = $transaction->fresh()->metadata ?? [];
            $freshMetadata['manual_processing_sms_pending'] = true;
            $freshMetadata['manual_processing_sms_requested_at'] = now()->toIso8601String();
            $transaction->update(['metadata' => $freshMetadata]);
        }

        $sheet->push($transaction->fresh(), 'manual_attempt');

        return redirect()->route('payment.processing', [
            'transaction' => $transaction->invoice_id,
            'token' => $transaction->signed_token,
            'verify' => $isMobileFallback ? 1 : null,
        ]);
    }

    public function remittanceContact(Request $request, Transaction $transaction)
    {
        $this->guardToken($request, $transaction);
        $this->abortExpired($transaction);

        abort_unless($transaction->method_option === 'remittance', 404);

        $data = $request->validate([
            'whatsapp_number' => ['required', 'string', 'max:20', 'regex:/^[0-9+ ]{8,20}$/'],
        ]);

        $number = trim($data['whatsapp_number']);

        ManualVerification::create([
            'transaction_id' => $transaction->id,
            'customer_number' => $number,
            'note' => 'Customer WhatsApp number submitted after 5 minutes: ' . $number,
            'ip' => $request->ip(),
        ]);

        $transaction->update([
            'customer_number' => $number,
            'manual_note' => 'Remittance proof waiting. WhatsApp number: ' . $number,
        ]);

        return back()->with('ok', 'Your WhatsApp number has been submitted for manual verification.');
    }

    private function guardToken(Request $request, Transaction $transaction): void
    {
        $token = (string) ($request->query('token') ?: $request->input('token'));
        if (! hash_equals((string) $transaction->signed_token, $token)) {
            abort(403, 'Invalid invoice token.');
        }
    }

    private function abortExpired(Transaction $transaction): void
    {
        if ($transaction->status === Transaction::STATUS_EXPIRED) {
            abort(response('', 410));
        }
    }

    private function resolveRemittancePayableAmount(Transaction $transaction, PaymentMethod $method, SettingsService $settings, ?float $base = null): float
    {
        $baseAmount = (float) ($base ?? $transaction->amount);
        $extraAmount = max((float) $settings->get('remittance_concurrent_extra_amount', '20'), 0);

        if ($extraAmount <= 0) {
            return round($baseAmount, 2);
        }

        $hasActiveSameAmountRemittance = Transaction::query()
            ->whereKeyNot($transaction->id)
            ->where('status', Transaction::STATUS_PENDING)
            ->where('method_slug', $method->slug)
            ->where('method_option', 'remittance')
            ->where('amount', $transaction->amount)
            ->where('expires_at', '>=', now())
            ->exists();

        return round($baseAmount + ($hasActiveSameAmountRemittance ? $extraAmount : 0), 2);
    }

    private function optionFeePercent(PaymentMethod $method, string $option): float
    {
        $fees = $method->config['option_fees'] ?? [];
        if (! is_array($fees)) {
            return 0.0;
        }

        return max(round((float) ($fees[$option] ?? 0), 2), 0.0);
    }
}
