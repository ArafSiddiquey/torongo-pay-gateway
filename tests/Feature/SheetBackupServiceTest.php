<?php

namespace Tests\Feature;

use App\Models\GatewaySetting;
use App\Models\PaymentMethod;
use App\Models\SmsLog;
use App\Models\Transaction;
use App\Services\SheetBackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SheetBackupServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_sheet_backup_posts_full_transaction_and_summary_snapshot(): void
    {
        Http::fake();

        GatewaySetting::updateOrCreate(['key' => 'google_sheet_webhook_url'], ['value' => 'https://script.google.com/macros/s/demo/exec']);
        GatewaySetting::updateOrCreate(['key' => 'google_sheet_secret'], ['value' => 'sheet-secret']);

        $method = PaymentMethod::create([
            'slug' => 'bkash',
            'name' => 'bKash',
            'payment_number' => '01711111111',
            'is_active' => true,
            'send_money_enabled' => true,
            'payment_enabled' => true,
            'config' => [
                'account_balance_bases' => ['01711111111' => 1000],
                'balance_base_set_at' => Carbon::now()->subDay()->toIso8601String(),
            ],
        ]);

        $smsLog = SmsLog::create([
            'method_slug' => 'bkash',
            'official_sender' => 'bKash',
            'raw_sender' => 'bKash',
            'raw_body' => 'You have received Tk 250.00 from 01799999999. TrxID SHEET12345',
            'sms_hash' => 'sheet-backup-sms',
            'parsed_amount' => 250,
            'parsed_customer_number' => '01799999999',
            'normalized_customer_number' => '01799999999',
            'parsed_trx_id' => 'SHEET12345',
            'sms_type' => 'credit',
            'received_at' => now(),
        ]);

        $transaction = Transaction::create([
            'invoice_id' => 'INV-SHEET-BACKUP',
            'order_id' => 'ORDER-1',
            'signed_token' => str_repeat('s', 64),
            'amount' => 250,
            'currency' => 'BDT',
            'payment_method_id' => $method->id,
            'method_slug' => 'bkash',
            'method_option' => 'send_money',
            'customer_number' => '01700000000',
            'normalized_customer_number' => '01700000000',
            'trx_id' => 'SHEET12345',
            'status' => Transaction::STATUS_SUCCESS,
            'sms_log_id' => $smsLog->id,
            'verified_at' => now(),
            'metadata' => ['customer_name' => 'Test Customer'],
        ]);

        app(SheetBackupService::class)->push($transaction, 'verified');

        Http::assertSent(function ($request) {
            $payload = $request->data();

            return $request->url() === 'https://script.google.com/macros/s/demo/exec'
                && $payload['secret'] === 'sheet-secret'
                && $payload['event'] === 'verified'
                && $payload['transaction']['invoice_id'] === 'INV-SHEET-BACKUP'
                && $payload['transaction']['customer_number'] === '01799999999'
                && $payload['transaction']['input_customer_number'] === '01700000000'
                && $payload['transaction']['trx_id'] === 'SHEET12345'
                && $payload['transaction']['status_group'] === 'successful'
                && $payload['summary']['successful_transactions'] === 1
                && $payload['summary']['successful_amount_total'] == 250
                && count($payload['account_balances']) >= 1
                && $payload['account_balances'][0]['method'] === 'bkash'
                && count($payload['recent_sms_logs']) >= 1
                && $payload['recent_sms_logs'][0]['trx_id'] === 'SHEET12345';
        });
    }
}
