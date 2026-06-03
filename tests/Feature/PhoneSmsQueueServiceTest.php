<?php

namespace Tests\Feature;

use App\Models\GatewaySetting;
use App\Models\OutgoingSms;
use App\Models\SmsDevice;
use App\Models\Transaction;
use App\Services\PhoneSmsQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhoneSmsQueueServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_phone_sms_queue_adds_confirmation_for_mobile_payment(): void
    {
        $this->setSmsSettings();

        $transaction = Transaction::create([
            'invoice_id' => 'INV-PHONE-SMS-1',
            'signed_token' => str_repeat('t', 64),
            'amount' => 250,
            'currency' => 'BDT',
            'method_slug' => 'bkash',
            'method_option' => 'send_money',
            'customer_number' => '01711111111',
            'normalized_customer_number' => '01711111111',
            'trx_id' => 'TXT1234567',
            'status' => Transaction::STATUS_SUCCESS,
        ]);

        $this->assertTrue(app(PhoneSmsQueueService::class)->queuePaymentConfirmed($transaction));

        $sms = OutgoingSms::first();
        $this->assertSame('+8801711111111', $sms->recipient);
        $this->assertSame(OutgoingSms::STATUS_PENDING, $sms->status);
        $this->assertStringContainsString('Your payment of 250 BDT has been confirmed.', $sms->message);
        $this->assertStringContainsString('WhatsApp Support: https://wa.me/8801882398668', $sms->message);
    }

    public function test_phone_sms_queue_does_not_queue_for_remittance_or_binance(): void
    {
        $this->setSmsSettings();

        foreach (['remittance', 'binance'] as $option) {
            $transaction = Transaction::create([
                'invoice_id' => 'INV-PHONE-SMS-' . strtoupper($option),
                'signed_token' => str_repeat($option[0], 64),
                'amount' => 250,
                'currency' => 'BDT',
                'method_slug' => $option === 'binance' ? 'binance' : 'bkash',
                'method_option' => $option,
                'customer_number' => '01711111111',
                'status' => Transaction::STATUS_SUCCESS,
            ]);

            $this->assertFalse(app(PhoneSmsQueueService::class)->queuePaymentConfirmed($transaction));
        }

        $this->assertSame(0, OutgoingSms::count());
    }

    public function test_android_device_can_fetch_and_report_outgoing_sms(): void
    {
        $deviceKey = SmsDevice::generatePlainKey();
        $device = SmsDevice::create([
            'name' => 'Main Phone',
            'api_key_hash' => hash('sha256', $deviceKey),
            'allowed_methods' => ['bkash', 'nagad', 'rocket'],
            'is_active' => true,
        ]);

        $sms = OutgoingSms::create([
            'recipient' => '+8801711111111',
            'message' => 'Payment confirmed',
            'status' => OutgoingSms::STATUS_PENDING,
        ]);

        $fetch = $this->withHeader('X-Device-Key', $deviceKey)
            ->postJson('/api/v1/sms/outgoing/fetch');

        $fetch->assertOk()
            ->assertJsonPath('messages.0.id', $sms->id)
            ->assertJsonPath('messages.0.recipient', '+8801711111111');

        $this->assertSame(1, $sms->fresh()->attempts);
        $this->assertSame($device->id, $sms->fresh()->sms_device_id);
        $this->assertSame(OutgoingSms::STATUS_PROCESSING, $sms->fresh()->status);

        $this->withHeader('X-Device-Key', $deviceKey)
            ->postJson('/api/v1/sms/outgoing/report', [
                'messages' => [
                    ['id' => $sms->id, 'status' => 'sent'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('sent', 1);

        $this->assertSame(OutgoingSms::STATUS_SENT, $sms->fresh()->status);
        $this->assertNotNull($sms->fresh()->sent_at);
    }

    public function test_outgoing_sms_fetch_reserves_message_for_one_device(): void
    {
        $firstKey = SmsDevice::generatePlainKey();
        $secondKey = SmsDevice::generatePlainKey();
        $firstDevice = SmsDevice::create([
            'name' => 'First Phone',
            'api_key_hash' => hash('sha256', $firstKey),
            'allowed_methods' => ['bkash'],
            'is_active' => true,
        ]);
        SmsDevice::create([
            'name' => 'Second Phone',
            'api_key_hash' => hash('sha256', $secondKey),
            'allowed_methods' => ['bkash'],
            'is_active' => true,
        ]);

        $sms = OutgoingSms::create([
            'recipient' => '+8801711111111',
            'message' => 'Payment confirmed',
            'status' => OutgoingSms::STATUS_PENDING,
        ]);

        $this->withHeader('X-Device-Key', $firstKey)
            ->postJson('/api/v1/sms/outgoing/fetch')
            ->assertOk()
            ->assertJsonCount(1, 'messages');

        $this->assertSame(OutgoingSms::STATUS_PROCESSING, $sms->fresh()->status);
        $this->assertSame($firstDevice->id, $sms->fresh()->sms_device_id);

        $this->withHeader('X-Device-Key', $secondKey)
            ->postJson('/api/v1/sms/outgoing/fetch')
            ->assertOk()
            ->assertJsonCount(0, 'messages');

        $this->withHeader('X-Device-Key', $secondKey)
            ->postJson('/api/v1/sms/outgoing/report', [
                'messages' => [
                    ['id' => $sms->id, 'status' => 'sent'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('sent', 0);

        $this->assertSame(OutgoingSms::STATUS_PROCESSING, $sms->fresh()->status);
    }

    public function test_admin_manual_approve_queues_phone_sms_for_processing_payment(): void
    {
        $this->setSmsSettings();

        $transaction = Transaction::create([
            'invoice_id' => 'INV-PHONE-SMS-ADMIN',
            'signed_token' => str_repeat('a', 64),
            'amount' => 250,
            'currency' => 'BDT',
            'method_slug' => 'bkash',
            'method_option' => 'send_money',
            'customer_number' => '01711111111',
            'status' => Transaction::STATUS_PENDING,
            'metadata' => ['manual_processing_sms_pending' => true],
        ]);

        $this->withSession(['admin_id' => 'ksa06024@gmail.com'])
            ->post(route('admin.transactions.approve', $transaction))
            ->assertRedirect();

        $this->assertSame(1, OutgoingSms::where('transaction_id', $transaction->id)->count());
        $this->assertFalse($transaction->fresh()->metadata['manual_processing_sms_pending']);
    }

    public function test_successful_sms_policy_defaults_to_processing_customers_only(): void
    {
        $this->setSmsSettings();

        $transaction = $this->mobileSuccessTransaction('INV-PHONE-SMS-POLICY-1');

        $this->assertFalse(app(PhoneSmsQueueService::class)->queueSuccessfulPaymentConfirmed($transaction));
        $this->assertSame(0, OutgoingSms::count());

        $transaction->update(['metadata' => ['manual_processing_sms_pending' => true]]);

        $this->assertTrue(app(PhoneSmsQueueService::class)->queueSuccessfulPaymentConfirmed($transaction->fresh()));
        $this->assertSame(1, OutgoingSms::count());
    }

    public function test_successful_sms_policy_can_send_to_all_successful_mobile_payments(): void
    {
        $this->setSmsSettings([
            'payment_sms_delivery_scope' => PhoneSmsQueueService::SCOPE_ALL_SUCCESSFUL,
        ]);

        $transaction = $this->mobileSuccessTransaction('INV-PHONE-SMS-POLICY-2');

        $this->assertTrue(app(PhoneSmsQueueService::class)->queueSuccessfulPaymentConfirmed($transaction));
        $this->assertSame(1, OutgoingSms::count());
    }

    private function mobileSuccessTransaction(string $invoiceId): Transaction
    {
        return Transaction::create([
            'invoice_id' => $invoiceId,
            'signed_token' => str_repeat('m', 64),
            'amount' => 250,
            'currency' => 'BDT',
            'method_slug' => 'bkash',
            'method_option' => 'send_money',
            'customer_number' => '01711111111',
            'normalized_customer_number' => '01711111111',
            'trx_id' => 'TXT1234567',
            'status' => Transaction::STATUS_SUCCESS,
        ]);
    }

    private function setSmsSettings(array $overrides = []): void
    {
        foreach (array_merge([
            'payment_sms_brand' => 'BanglaLicense',
            'payment_sms_contact_url' => 'https://wa.me/8801882398668',
        ], $overrides) as $key => $value) {
            GatewaySetting::updateOrCreate(['key' => $key], ['value' => $value]);
        }
    }
}
