<?php

namespace Database\Seeders;

use App\Models\GatewaySetting;
use App\Models\LanguageText;
use App\Models\PaymentMethod;
use App\Models\SmsDevice;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@example.com'],
            ['name' => 'Gateway Admin', 'password' => Hash::make('admin12345')]
        );

        $methods = [
            ['slug' => 'bkash', 'name' => 'bKash', 'name_bn' => 'বিকাশ', 'payment_number' => '01XXXXXXXXX', 'remittance_number' => '01XXXXXXXXX', 'send_money_enabled' => true, 'payment_enabled' => true, 'remittance_enabled' => true, 'sort_order' => 1],
            ['slug' => 'nagad', 'name' => 'Nagad', 'name_bn' => 'নগদ', 'payment_number' => '01XXXXXXXXX', 'remittance_number' => '01XXXXXXXXX', 'send_money_enabled' => true, 'payment_enabled' => false, 'remittance_enabled' => true, 'sort_order' => 2],
            ['slug' => 'rocket', 'name' => 'Rocket', 'name_bn' => 'রকেট', 'payment_number' => '01XXXXXXXXXXX', 'remittance_number' => null, 'send_money_enabled' => true, 'payment_enabled' => false, 'remittance_enabled' => false, 'sort_order' => 3],
            ['slug' => 'binance', 'name' => 'Binance', 'name_bn' => 'Binance', 'payment_number' => '', 'remittance_number' => '', 'send_money_enabled' => false, 'payment_enabled' => false, 'remittance_enabled' => false, 'sort_order' => 4],
        ];

        foreach ($methods as $method) {
            PaymentMethod::updateOrCreate(['slug' => $method['slug']], $method + [
                'qr_enabled' => true,
                'is_active' => true,
                'cash_out_enabled' => false,
                'instructions_en' => 'Complete the transfer, then wait for automatic verification.',
                'instructions_bn' => 'পেমেন্ট সম্পন্ন করুন, এরপর অটো ভেরিফিকেশনের জন্য অপেক্ষা করুন।',
                'config' => [
                    'minimum_amount' => 1,
                    'maximum_amount' => 100000,
                    'binance_uid' => null,
                    'binance_api_key' => null,
                    'binance_secret_key' => null,
                    'binance_asset' => 'USDT',
                    'binance_exchange_rate' => 130,
                    'binance_mode' => $method['slug'] === 'binance' ? 'personal_manual_with_optional_history_check' : null,
                    'account_names' => [
                        'send_money' => 'Product Torongo Pay',
                        'payment' => 'Product Torongo Pay',
                        'remittance' => 'Product Torongo Pay',
                    ],
                    'option_numbers' => [
                        'send_money' => $method['payment_number'] ?: null,
                        'payment' => $method['slug'] === 'bkash' ? $method['payment_number'] : null,
                        'remittance' => $method['remittance_number'] ?: null,
                    ],
                    'option_qr_paths' => [],
                ],
            ]);
        }

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
            'payment_sms_template' => "Your payment of {amount} BDT has been confirmed.\nThank you for choosing our service. If you have not received your order yet, please contact our support team through the link below and we will assist you as soon as possible.\n\nWhatsApp Support: {contact_url}\n\n— {brand}",
            'google_admin_email' => env('GOOGLE_ADMIN_EMAIL', ''),
            'google_client_id' => env('GOOGLE_CLIENT_ID', ''),
            'google_client_secret' => env('GOOGLE_CLIENT_SECRET', ''),
            'official_sender_map' => json_encode([
                'bkash' => ['bkash'],
                'nagad' => ['nagad'],
                'rocket' => ['16216'],
            ], JSON_UNESCAPED_UNICODE),
        ] as $key => $value) {
            GatewaySetting::updateOrCreate(['key' => $key], ['value' => $value]);
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
                LanguageText::updateOrCreate(['key' => $key, 'lang' => $lang], ['value' => $value]);
            }
        }

        if (! SmsDevice::exists()) {
            SmsDevice::create([
                'name' => 'Demo Android Device',
                'api_key_hash' => hash('sha256', 'smsr_demo_change_me'),
                'allowed_methods' => ['bkash', 'nagad', 'rocket'],
                'is_active' => true,
            ]);
        }
    }
}
