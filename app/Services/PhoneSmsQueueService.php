<?php

namespace App\Services;

use App\Models\OutgoingSms;
use App\Models\Transaction;

class PhoneSmsQueueService
{
    public const SCOPE_PROCESSING_ONLY = 'processing_only';
    public const SCOPE_ALL_SUCCESSFUL = 'all_successful';

    public function __construct(private SettingsService $settings)
    {
    }

    public function queuePaymentConfirmed(Transaction $transaction): bool
    {
        if (($transaction->metadata['manual_success_sms_suppressed'] ?? false) === true) {
            return false;
        }

        if (in_array($transaction->method_option, ['remittance', 'binance'], true)) {
            return false;
        }

        if (! in_array($transaction->method_slug, ['bkash', 'nagad', 'rocket'], true)) {
            return false;
        }

        $recipient = $this->formatRecipient($transaction->customer_number);
        if (! $recipient) {
            return false;
        }

        $existing = OutgoingSms::where('transaction_id', $transaction->id)
            ->where('purpose', 'payment_confirmed')
            ->whereIn('status', [OutgoingSms::STATUS_PENDING, OutgoingSms::STATUS_SENT])
            ->first();

        if ($existing) {
            return false;
        }

        OutgoingSms::create([
            'transaction_id' => $transaction->id,
            'recipient' => $recipient,
            'message' => $this->paymentConfirmedMessage($transaction),
            'purpose' => 'payment_confirmed',
            'status' => OutgoingSms::STATUS_PENDING,
        ]);

        return true;
    }

    public function queueSuccessfulPaymentConfirmed(Transaction $transaction): bool
    {
        if ($this->settings->get('payment_sms_delivery_scope', self::SCOPE_PROCESSING_ONLY) === self::SCOPE_ALL_SUCCESSFUL) {
            return $this->queuePaymentConfirmed($transaction);
        }

        return $this->queueProcessingPaymentConfirmed($transaction);
    }

    public function queueProcessingPaymentConfirmed(Transaction $transaction): bool
    {
        $metadata = $transaction->metadata ?? [];
        if (($metadata['manual_processing_sms_pending'] ?? false) !== true) {
            return false;
        }

        $queued = $this->queuePaymentConfirmed($transaction);
        if (! $queued) {
            return false;
        }

        $metadata['manual_processing_sms_pending'] = false;
        $metadata['manual_processing_sms_queued_at'] = now()->toIso8601String();
        $transaction->update(['metadata' => $metadata]);

        return true;
    }

    private function paymentConfirmedMessage(Transaction $transaction): string
    {
        $brand = $this->settings->get('payment_sms_brand', 'BanglaLicense');
        $contactUrl = $this->settings->get('payment_sms_contact_url', 'https://wa.me/8801882398668');
        $template = $this->settings->get(
            'payment_sms_template',
            "Your payment of {amount} BDT has been confirmed.\nThank you for choosing our service. If you have not received your order yet, please contact our support team through the link below and we will assist you as soon as possible.\n\nWhatsApp Support: {contact_url}\n\n-- {brand}"
        );

        $template = str_replace(['Ã¢â‚¬â€', '—'], '--', $template);

        return strtr($template, [
            '{brand}' => $brand,
            '{contact_url}' => $contactUrl,
            '{invoice_id}' => $transaction->invoice_id,
            '{amount}' => number_format((float) $transaction->amount, 0),
            '{trx_id}' => (string) $transaction->trx_id,
        ]);
    }

    private function formatRecipient(?string $number): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $number);
        if (! $digits) {
            return null;
        }

        if (str_starts_with($digits, '880')) {
            return '+' . $digits;
        }

        if (str_starts_with($digits, '0')) {
            return '+88' . $digits;
        }

        return '+' . $digits;
    }
}
