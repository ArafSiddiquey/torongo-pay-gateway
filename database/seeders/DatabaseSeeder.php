<?php

namespace Database\Seeders;

use App\Models\GatewaySetting;
use App\Models\LanguageText;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            'gateway_name' => 'Torongo Pay',
            'default_language' => 'bn',
            'countdown_minutes' => '15',
            'manual_verify_delay_minutes' => '1',
            'remittance_concurrent_extra_amount' => '20',
            'webhook_secret' => env('WEBHOOK_SECRET', ''),
            'success_redirect_url' => 'https://your-domain.com/payment/success',
            'failed_redirect_url' => 'https://your-domain.com/payment/failed',
            'terms_title' => 'Terms & Conditions',
            'terms_body' => 'Please make sure your payment account number is correct. Payments are verified by SMS records and may take a short time to confirm. If verification is delayed, submit your transaction ID for manual review.',
            'google_sheet_webhook_url' => '',
            'google_sheet_secret' => '',
            'payment_sms_brand' => 'BanglaLicense',
            'payment_sms_contact_url' => 'https://wa.me/8801882398668',
            'payment_sms_template' => "Your payment of {amount} BDT has been confirmed.\nThank you for choosing our service. If you have not received your order yet, please contact our support team through the link below and we will assist you as soon as possible.\n\nWhatsApp Support: {contact_url}\n\n- {brand}",
            'google_admin_email' => env('GOOGLE_ADMIN_EMAIL', ''),
            'google_client_id' => env('GOOGLE_CLIENT_ID', ''),
            'google_client_secret' => env('GOOGLE_CLIENT_SECRET', ''),
            'official_sender_map' => json_encode([
                'bkash' => ['bkash'],
                'nagad' => ['nagad'],
                'rocket' => ['16216'],
            ], JSON_UNESCAPED_UNICODE),
        ] as $key => $value) {
            GatewaySetting::firstOrCreate(['key' => $key], ['value' => $value]);
        }

        foreach ([
            'pay_now' => ['en' => 'Pay now', 'bn' => 'পেমেন্ট করুন'],
            'mobile_banking' => ['en' => 'Mobile Banking', 'bn' => 'মোবাইল ব্যাংকিং'],
            'sender_number' => ['en' => 'Sender number', 'bn' => 'যে নাম্বার থেকে টাকা পাঠাবেন'],
            'already_paid' => ['en' => 'Already paid? Click here', 'bn' => 'পেমেন্ট করেছেন? এখানে ক্লিক করুন'],
            'payment_success' => ['en' => 'Payment successful', 'bn' => 'পেমেন্ট সফল হয়েছে'],
            'payment_pending' => ['en' => 'Waiting For Payment', 'bn' => 'Waiting For Payment'],
            'payment_failed' => ['en' => 'Payment failed or expired', 'bn' => 'পেমেন্ট ব্যর্থ বা মেয়াদ শেষ'],
        ] as $key => $langs) {
            foreach ($langs as $lang => $value) {
                LanguageText::firstOrCreate(['key' => $key, 'lang' => $lang], ['value' => $value]);
            }
        }
    }
}
