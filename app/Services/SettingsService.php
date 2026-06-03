<?php

namespace App\Services;

use App\Models\GatewaySetting;
use App\Models\LanguageText;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class SettingsService
{
    public function get(string $key, ?string $default = null): ?string
    {
        return Cache::remember("setting:$key", 300, function () use ($key, $default) {
            $value = GatewaySetting::where('key', $key)->value('value');
            if ($key === 'webhook_secret' && $value === 'change-this-secret') {
                return env('WEBHOOK_SECRET', $default);
            }

            if ($value !== null && $value !== '') {
                return $value;
            }

            return match ($key) {
                'gateway_name' => env('GATEWAY_NAME', $default),
                'webhook_secret' => env('WEBHOOK_SECRET', $default),
                'success_redirect_url' => env('SUCCESS_REDIRECT_URL', $default),
                'failed_redirect_url' => env('FAILED_REDIRECT_URL', $default),
                'google_client_id' => env('GOOGLE_CLIENT_ID', $default),
                'google_client_secret' => env('GOOGLE_CLIENT_SECRET', $default),
                'google_admin_email' => env('GOOGLE_ADMIN_EMAIL', $default),
                'payment_sms_brand' => env('PAYMENT_SMS_BRAND', $default),
                'payment_sms_contact_url' => env('PAYMENT_SMS_CONTACT_URL', $default),
                'payment_sms_template' => env('PAYMENT_SMS_TEMPLATE', $default),
                'payment_sms_delivery_scope' => env('PAYMENT_SMS_DELIVERY_SCOPE', $default),
                default => $default,
            };
        });
    }

    public function set(string $key, ?string $value): void
    {
        GatewaySetting::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget("setting:$key");
    }

    public function invoiceLogoUrl(): string
    {
        $path = trim((string) $this->get('invoice_logo_path', ''));
        if ($path !== '' && Storage::disk('public')->exists($path)) {
            return Storage::disk('public')->url($path);
        }

        return asset('assets/img/torongo-pay-mark.png');
    }

    public function invoiceLogoPdfSrc(): string
    {
        $path = trim((string) $this->get('invoice_logo_path', ''));
        if ($path !== '') {
            $fullPath = Storage::disk('public')->path($path);
            if (is_file($fullPath)) {
                return $this->fileDataUri($fullPath);
            }
        }

        $defaultPng = public_path('assets/img/torongo-pay-mark.png');
        if (is_file($defaultPng)) {
            return $this->fileDataUri($defaultPng);
        }

        return $this->fileDataUri(public_path('assets/img/torongo-pay-mark.svg'));
    }

    private function fileDataUri(string $path): string
    {
        $contents = file_get_contents($path);
        $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            default => 'application/octet-stream',
        };

        return 'data:'.$mime.';base64,'.base64_encode($contents ?: '');
    }

    public function text(string $key, ?string $lang = null): string
    {
        $lang = $lang ?: $this->get('default_language', 'bn');
        $value = LanguageText::where(['key' => $key, 'lang' => $lang])->value('value');

        if ($value) {
            return $value;
        }

        $fallback = LanguageText::where(['key' => $key, 'lang' => 'en'])->value('value');
        if ($fallback) {
            return $fallback;
        }

        return [
            'pay_now' => 'Pay Now',
            'mobile_banking' => 'Mobile Banking',
            'sender_number' => 'Sender Number',
            'already_paid' => 'Already paid? Click here',
            'payment_success' => 'Payment Successful',
            'payment_pending' => 'Waiting for Payment',
            'payment_failed' => 'Payment Failed or Session Expired',
        ][$key] ?? $key;
    }
}
