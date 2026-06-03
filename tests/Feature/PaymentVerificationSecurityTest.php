<?php

namespace Tests\Feature;

use App\Models\SmsLog;
use App\Models\SmsDevice;
use App\Models\GatewaySetting;
use App\Models\ManualVerification;
use App\Models\OutgoingSms;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Http\Controllers\Payment\GatewayController;
use App\Services\PaymentVerifierService;
use App\Services\SettingsService;
use App\Services\SmsParserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentVerificationSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_parser_rejects_gateway_name_inside_unofficial_sender_body(): void
    {
        $parsed = app(SmsParserService::class)->parse(
            'Personal',
            'You have received Tk 250.00 from 01711111111. TrxID ABC1234567. bKash'
        );

        $this->assertTrue($parsed['is_fraud']);
        $this->assertNull($parsed['method_slug']);
    }

    public function test_parser_marks_outgoing_payment_sms_as_debit(): void
    {
        $parsed = app(SmsParserService::class)->parse(
            'bKash',
            'Payment Tk 10.00 to As Sunnah Foundation-1-RM46979 is successful. Balance Tk 3,996.07. TrxID DF15SVEXMR at 01/06/2026 13:06'
        );

        $this->assertFalse($parsed['is_fraud']);
        $this->assertSame('debit', $parsed['sms_type']);
        $this->assertSame(10.00, $parsed['parsed_amount']);
        $this->assertSame('DF15SVEXMR', $parsed['parsed_trx_id']);
    }

    public function test_auto_verification_requires_matching_customer_number(): void
    {
        $transaction = Transaction::create([
            'invoice_id' => 'INV-STRICT-1',
            'signed_token' => str_repeat('a', 64),
            'amount' => 250,
            'currency' => 'BDT',
            'method_slug' => 'bkash',
            'method_option' => 'send_money',
            'customer_number' => '01711111111',
            'normalized_customer_number' => '01711111111',
            'status' => Transaction::STATUS_PENDING,
            'expires_at' => now()->addMinutes(15),
            'metadata' => ['verification_ready' => true, 'manual_processing_sms_pending' => true],
        ]);

        $sms = SmsLog::create([
            'method_slug' => 'bkash',
            'official_sender' => 'bKash',
            'raw_sender' => 'bKash',
            'raw_body' => 'You have received Tk 250.00 from 01722222222. TrxID ABC1234567.',
            'sms_hash' => hash('sha256', 'mismatch'),
            'parsed_amount' => 250,
            'parsed_customer_number' => '01722222222',
            'normalized_customer_number' => '01722222222',
            'parsed_trx_id' => 'ABC1234567',
            'received_at' => now(),
            'is_fraud' => false,
        ]);

        $this->assertNull(app(PaymentVerifierService::class)->verifySms($sms));
        $this->assertSame(Transaction::STATUS_PENDING, $transaction->fresh()->status);
        $this->assertNull($transaction->fresh()->trx_id);
    }

    public function test_gateway_option_fee_updates_payable_amount(): void
    {
        $method = PaymentMethod::create([
            'slug' => 'bkash',
            'name' => 'bKash',
            'payment_number' => '01711111111',
            'send_money_enabled' => true,
            'payment_enabled' => true,
            'is_active' => true,
            'config' => [
                'option_fees' => ['send_money' => 2, 'payment' => 0],
            ],
        ]);

        $transaction = Transaction::create([
            'invoice_id' => 'INV-FEE-1',
            'signed_token' => str_repeat('f', 64),
            'amount' => 100,
            'currency' => 'BDT',
            'status' => Transaction::STATUS_PENDING,
            'expires_at' => now()->addMinutes(15),
            'metadata' => ['verification_ready' => false],
        ]);

        $response = $this->post(route('payment.sender', ['transaction' => $transaction->invoice_id, 'token' => $transaction->signed_token]), [
            'payment_method_id' => $method->id,
            'method_option' => 'send_money',
            'customer_number' => '01722222222',
        ]);

        $response->assertRedirect();
        $transaction->refresh();

        $this->assertSame(102.0, (float) $transaction->amount);
        $this->assertSame(100.0, (float) $transaction->metadata['product_amount']);
        $this->assertSame(2.0, (float) $transaction->metadata['payment_fee_amount']);
    }

    public function test_sms_method_option_hint_prevents_wrong_bkash_option_match(): void
    {
        $sendMoney = Transaction::create([
            'invoice_id' => 'INV-SEND-MONEY-ONLY',
            'signed_token' => str_repeat('s', 64),
            'amount' => 50,
            'currency' => 'BDT',
            'method_slug' => 'bkash',
            'method_option' => 'send_money',
            'customer_number' => '01711111111',
            'normalized_customer_number' => '01711111111',
            'status' => Transaction::STATUS_PENDING,
            'expires_at' => now()->addMinutes(15),
            'metadata' => ['verification_ready' => true],
        ]);

        $payment = Transaction::create([
            'invoice_id' => 'INV-PAYMENT-ONLY',
            'signed_token' => str_repeat('p', 64),
            'amount' => 50,
            'currency' => 'BDT',
            'method_slug' => 'bkash',
            'method_option' => 'payment',
            'customer_number' => '01711111111',
            'normalized_customer_number' => '01711111111',
            'status' => Transaction::STATUS_PENDING,
            'expires_at' => now()->addMinutes(15),
            'metadata' => ['verification_ready' => true],
        ]);

        $sms = SmsLog::create([
            'method_slug' => 'bkash',
            'method_option' => 'payment',
            'official_sender' => 'bKash',
            'raw_sender' => 'bKash',
            'raw_body' => 'You have received Tk 50.00 from 01711111111. TrxID OPTION123.',
            'sms_hash' => hash('sha256', 'option-hint'),
            'parsed_amount' => 50,
            'parsed_customer_number' => '01711111111',
            'normalized_customer_number' => '01711111111',
            'parsed_trx_id' => 'OPTION123',
            'received_at' => now(),
            'sms_type' => 'credit',
            'is_fraud' => false,
        ]);

        $matched = app(PaymentVerifierService::class)->verifySms($sms);

        $this->assertSame($payment->id, $matched?->id);
        $this->assertSame(Transaction::STATUS_PENDING, $sendMoney->fresh()->status);
        $this->assertSame(Transaction::STATUS_SUCCESS, $payment->fresh()->status);
    }

    public function test_parser_accepts_bkash_ibanking_deposit_as_credit(): void
    {
        $parsed = app(SmsParserService::class)->parse(
            'bKash',
            'You have received deposit from iBanking of Tk 350.00 from BRAC Bank Internet Banking. Fee Tk 0.00. Balance Tk 3,695.07. TrxID DF25UFI4PX at 02/06/2026 20:15'
        );

        $this->assertFalse($parsed['is_fraud']);
        $this->assertSame('bkash', $parsed['method_slug']);
        $this->assertSame('credit', $parsed['sms_type']);
        $this->assertSame(350.00, $parsed['parsed_amount']);
        $this->assertSame('DF25UFI4PX', $parsed['parsed_trx_id']);
    }

    public function test_manual_fallback_matches_ibanking_deposit_by_bank_name_amount_and_time(): void
    {
        $transaction = Transaction::create([
            'invoice_id' => 'INV-BANK-NAME-1',
            'signed_token' => str_repeat('b', 64),
            'amount' => 350,
            'currency' => 'BDT',
            'method_slug' => 'bkash',
            'method_option' => 'payment',
            'customer_number' => '01640041418',
            'normalized_customer_number' => '01640041418',
            'status' => Transaction::STATUS_PENDING,
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
            'expires_at' => now()->addMinutes(10),
            'metadata' => ['verification_ready' => true, 'manual_hold' => true],
        ]);

        $sms = SmsLog::create([
            'method_slug' => 'bkash',
            'method_option' => 'payment',
            'official_sender' => 'bKash',
            'raw_sender' => 'bKash',
            'raw_body' => 'You have received deposit from iBanking of Tk 350.00 from BRAC Bank Internet Banking. Fee Tk 0.00. Balance Tk 3,695.07. TrxID DF25UFI4PX at 02/06/2026 20:15',
            'sms_hash' => hash('sha256', 'bank-name-fallback'),
            'parsed_amount' => 350,
            'parsed_trx_id' => 'DF25UFI4PX',
            'received_at' => now(),
            'sms_type' => 'credit',
            'is_fraud' => false,
        ]);

        $matched = app(PaymentVerifierService::class)->verifyManualAttempt($transaction, 'brac bank', '01640041418');

        $this->assertSame($transaction->id, $matched?->id);
        $this->assertSame(Transaction::STATUS_SUCCESS, $transaction->fresh()->status);
        $this->assertSame('DF25UFI4PX', $transaction->fresh()->trx_id);
        $this->assertSame($transaction->id, $sms->fresh()->matched_transaction_id);
        $this->assertSame('BRAC Bank', $transaction->fresh()->metadata['payment_receipts'][0]['bank_name']);
    }

    public function test_debit_sms_never_verifies_payment(): void
    {
        $transaction = Transaction::create([
            'invoice_id' => 'INV-DEBIT-IGNORE',
            'signed_token' => str_repeat('d', 64),
            'amount' => 10,
            'currency' => 'BDT',
            'method_slug' => 'bkash',
            'method_option' => 'payment',
            'customer_number' => '01711111111',
            'normalized_customer_number' => '01711111111',
            'status' => Transaction::STATUS_PENDING,
            'expires_at' => now()->addMinutes(15),
            'metadata' => ['verification_ready' => true],
        ]);

        $smsLog = SmsLog::create([
            'method_slug' => 'bkash',
            'official_sender' => 'bKash',
            'raw_sender' => 'bKash',
            'raw_body' => 'Payment Tk 10.00 to As Sunnah Foundation is successful. Balance Tk 3,996.07. TrxID DF15SVEXMR',
            'sms_hash' => 'debit-ignore',
            'parsed_amount' => 10,
            'parsed_trx_id' => 'DF15SVEXMR',
            'received_at' => now(),
            'sms_type' => 'debit',
            'is_fraud' => false,
        ]);

        $verified = app(PaymentVerifierService::class)->verifySms($smsLog);

        $this->assertNull($verified);
        $this->assertSame(Transaction::STATUS_PENDING, $transaction->fresh()->status);
        $this->assertNull($smsLog->fresh()->matched_transaction_id);
    }

    public function test_auto_verification_saves_backend_trx_id_when_number_matches(): void
    {
        $transaction = Transaction::create([
            'invoice_id' => 'INV-STRICT-2',
            'signed_token' => str_repeat('b', 64),
            'amount' => 250,
            'currency' => 'BDT',
            'method_slug' => 'bkash',
            'method_option' => 'send_money',
            'customer_number' => '01711111111',
            'normalized_customer_number' => '01711111111',
            'status' => Transaction::STATUS_PENDING,
            'expires_at' => now()->addMinutes(15),
            'metadata' => ['verification_ready' => true],
        ]);

        $sms = SmsLog::create([
            'method_slug' => 'bkash',
            'official_sender' => 'bKash',
            'raw_sender' => 'bKash',
            'raw_body' => 'You have received Tk 250.00 from 01711111111. TrxID ABC7654321.',
            'sms_hash' => hash('sha256', 'match'),
            'parsed_amount' => 250,
            'parsed_customer_number' => '01711111111',
            'normalized_customer_number' => '01711111111',
            'parsed_trx_id' => 'ABC7654321',
            'received_at' => now(),
            'is_fraud' => false,
        ]);

        $matched = app(PaymentVerifierService::class)->verifySms($sms);

        $this->assertNotNull($matched);
        $this->assertSame(Transaction::STATUS_SUCCESS, $transaction->fresh()->status);
        $this->assertSame('ABC7654321', $transaction->fresh()->trx_id);
        $this->assertSame(0, OutgoingSms::count());
    }

    public function test_auto_verification_accepts_sms_time_with_same_minute_as_invoice_creation(): void
    {
        $createdAt = now()->setTime(17, 17, 28);

        $transaction = Transaction::create([
            'invoice_id' => 'INV-SAME-MINUTE',
            'signed_token' => str_repeat('m', 64),
            'amount' => 5,
            'currency' => 'BDT',
            'method_slug' => 'bkash',
            'method_option' => 'payment',
            'customer_number' => '01640041418',
            'normalized_customer_number' => '01640041418',
            'status' => Transaction::STATUS_PENDING,
            'created_at' => $createdAt,
            'expires_at' => $createdAt->copy()->addMinutes(15),
            'metadata' => ['verification_ready' => true],
        ]);
        $transaction->forceFill(['created_at' => $createdAt])->save();

        $sms = SmsLog::create([
            'method_slug' => 'bkash',
            'official_sender' => 'bKash',
            'raw_sender' => 'bKash',
            'raw_body' => 'You have received payment Tk 5.00 from 01640041418. Fee Tk 0.00. TrxID SAME5171717 at 31/05/2026 17:17',
            'sms_hash' => hash('sha256', 'same-minute-sms'),
            'parsed_amount' => 5,
            'parsed_customer_number' => '01640041418',
            'normalized_customer_number' => '01640041418',
            'parsed_trx_id' => 'SAME5171717',
            'received_at' => $createdAt->copy()->startOfMinute(),
            'is_fraud' => false,
        ]);

        $matched = app(PaymentVerifierService::class)->verifySms($sms);

        $this->assertNotNull($matched);
        $this->assertSame(Transaction::STATUS_SUCCESS, $transaction->fresh()->status);
        $this->assertSame('SAME5171717', $transaction->fresh()->trx_id);
    }

    public function test_partial_payment_keeps_invoice_pending_until_due_sms_arrives(): void
    {
        $transaction = Transaction::create([
            'invoice_id' => 'INV-PARTIAL-1',
            'signed_token' => str_repeat('p', 64),
            'amount' => 10,
            'currency' => 'BDT',
            'method_slug' => 'bkash',
            'method_option' => 'payment',
            'customer_number' => '01640041418',
            'normalized_customer_number' => '01640041418',
            'status' => Transaction::STATUS_PENDING,
            'expires_at' => now()->addMinutes(15),
            'metadata' => ['verification_ready' => true],
        ]);

        $first = SmsLog::create([
            'method_slug' => 'bkash',
            'official_sender' => 'bKash',
            'raw_sender' => 'bKash',
            'raw_body' => 'You have received payment Tk 5.00 from 01640041418. TrxID PARTIAL0001.',
            'sms_hash' => hash('sha256', 'partial-1'),
            'parsed_amount' => 5,
            'parsed_customer_number' => '01640041418',
            'normalized_customer_number' => '01640041418',
            'parsed_trx_id' => 'PARTIAL0001',
            'received_at' => now(),
            'is_fraud' => false,
        ]);

        $this->assertNull(app(PaymentVerifierService::class)->verifySms($first));
        $this->assertSame(Transaction::STATUS_PENDING, $transaction->fresh()->status);
        $this->assertSame(5.0, $transaction->fresh()->paidAmount());
        $this->assertSame(5.0, $transaction->fresh()->dueAmount());

        $second = SmsLog::create([
            'method_slug' => 'bkash',
            'official_sender' => 'bKash',
            'raw_sender' => 'bKash',
            'raw_body' => 'You have received payment Tk 5.00 from 01640041418. TrxID PARTIAL0002.',
            'sms_hash' => hash('sha256', 'partial-2'),
            'parsed_amount' => 5,
            'parsed_customer_number' => '01640041418',
            'normalized_customer_number' => '01640041418',
            'parsed_trx_id' => 'PARTIAL0002',
            'received_at' => now(),
            'is_fraud' => false,
        ]);

        $matched = app(PaymentVerifierService::class)->verifySms($second);

        $this->assertNotNull($matched);
        $this->assertSame(Transaction::STATUS_SUCCESS, $transaction->fresh()->status);
        $this->assertSame('PARTIAL0002', $transaction->fresh()->trx_id);
        $this->assertSame(10.0, $transaction->fresh()->paidAmount());
        $this->assertSame(0.0, $transaction->fresh()->dueAmount());
    }

    public function test_rocket_parser_reads_masked_account_suffix_and_timestamp(): void
    {
        $parsed = app(SmsParserService::class)->parse(
            '16216',
            'Tk280.00 received from A/C:***033 Fee:Tk0, Your A/C Balance: Tk285.00 TxnId:6578516337 Date:29-MAY-26 10:26:24 pm.'
        );

        $this->assertFalse($parsed['is_fraud']);
        $this->assertSame('rocket', $parsed['method_slug']);
        $this->assertSame(280.00, $parsed['parsed_amount']);
        $this->assertSame('033', $parsed['normalized_customer_number']);
        $this->assertSame('6578516337', $parsed['parsed_trx_id']);
        $this->assertSame('2026-05-29 22:26:24', $parsed['received_at']->format('Y-m-d H:i:s'));
    }

    public function test_rocket_auto_verification_matches_last_three_digits_only(): void
    {
        $transaction = Transaction::create([
            'invoice_id' => 'INV-ROCKET-LAST3',
            'signed_token' => str_repeat('r', 64),
            'amount' => 280,
            'currency' => 'BDT',
            'method_slug' => 'rocket',
            'method_option' => 'send_money',
            'customer_number' => '01812345033',
            'normalized_customer_number' => '01812345033',
            'status' => Transaction::STATUS_PENDING,
            'expires_at' => now()->addMinutes(15),
            'metadata' => ['verification_ready' => true],
        ]);

        $sms = SmsLog::create([
            'method_slug' => 'rocket',
            'official_sender' => '16216',
            'raw_sender' => '16216',
            'raw_body' => 'Tk280.00 received from A/C:***033 Fee:Tk0, Your A/C Balance: Tk285.00 TxnId:6578516337 Date:29-MAY-26 10:26:24 pm.',
            'sms_hash' => hash('sha256', 'rocket-last3-match'),
            'parsed_amount' => 280,
            'parsed_customer_number' => '033',
            'normalized_customer_number' => '033',
            'parsed_trx_id' => '6578516337',
            'received_at' => now(),
            'is_fraud' => false,
        ]);

        $matched = app(PaymentVerifierService::class)->verifySms($sms);

        $this->assertNotNull($matched);
        $this->assertSame(Transaction::STATUS_SUCCESS, $transaction->fresh()->status);
        $this->assertSame('6578516337', $transaction->fresh()->trx_id);
    }

    public function test_rocket_auto_verification_rejects_wrong_last_three_digits(): void
    {
        $transaction = Transaction::create([
            'invoice_id' => 'INV-ROCKET-LAST3-NO',
            'signed_token' => str_repeat('s', 64),
            'amount' => 280,
            'currency' => 'BDT',
            'method_slug' => 'rocket',
            'method_option' => 'send_money',
            'customer_number' => '01812345034',
            'normalized_customer_number' => '01812345034',
            'status' => Transaction::STATUS_PENDING,
            'expires_at' => now()->addMinutes(15),
            'metadata' => ['verification_ready' => true],
        ]);

        $sms = SmsLog::create([
            'method_slug' => 'rocket',
            'official_sender' => '16216',
            'raw_sender' => '16216',
            'raw_body' => 'Tk280.00 received from A/C:***033 Fee:Tk0, Your A/C Balance: Tk285.00 TxnId:6578516338 Date:29-MAY-26 10:26:24 pm.',
            'sms_hash' => hash('sha256', 'rocket-last3-mismatch'),
            'parsed_amount' => 280,
            'parsed_customer_number' => '033',
            'normalized_customer_number' => '033',
            'parsed_trx_id' => '6578516338',
            'received_at' => now(),
            'is_fraud' => false,
        ]);

        $this->assertNull(app(PaymentVerifierService::class)->verifySms($sms));
        $this->assertSame(Transaction::STATUS_PENDING, $transaction->fresh()->status);
        $this->assertNull($transaction->fresh()->trx_id);
    }

    public function test_remittance_verification_uses_payable_amount_without_customer_trx_input(): void
    {
        $transaction = Transaction::create([
            'invoice_id' => 'INV-REMIT-1',
            'signed_token' => str_repeat('c', 64),
            'amount' => 350,
            'currency' => 'BDT',
            'method_slug' => 'bkash',
            'method_option' => 'remittance',
            'customer_number' => '+8801711111111',
            'status' => Transaction::STATUS_PENDING,
            'payment_proof_path' => 'payment-proofs/demo.jpg',
            'metadata' => ['remittance_payable_amount' => 370, 'verification_ready' => true],
        ]);

        $sms = SmsLog::create([
            'method_slug' => 'bkash',
            'official_sender' => 'bKash',
            'raw_sender' => 'bKash',
            'raw_body' => 'You have received Tk 370.00. TrxID RMT1234567.',
            'sms_hash' => hash('sha256', 'remittance-match'),
            'parsed_amount' => 370,
            'parsed_trx_id' => 'RMT1234567',
            'received_at' => now(),
            'is_fraud' => false,
        ]);

        $matched = app(PaymentVerifierService::class)->verifyPendingRemittance($transaction);

        $this->assertNotNull($matched);
        $this->assertSame(Transaction::STATUS_SUCCESS, $transaction->fresh()->status);
        $this->assertSame('RMT1234567', $transaction->fresh()->trx_id);
        $this->assertSame($transaction->id, $sms->fresh()->matched_transaction_id);
    }

    public function test_remittance_verification_does_not_match_original_amount_when_offset_is_assigned(): void
    {
        $transaction = Transaction::create([
            'invoice_id' => 'INV-REMIT-2',
            'signed_token' => str_repeat('d', 64),
            'amount' => 350,
            'currency' => 'BDT',
            'method_slug' => 'bkash',
            'method_option' => 'remittance',
            'customer_number' => '+8801711111111',
            'status' => Transaction::STATUS_PENDING,
            'payment_proof_path' => 'payment-proofs/demo.jpg',
            'metadata' => ['remittance_payable_amount' => 370, 'verification_ready' => true],
        ]);

        SmsLog::create([
            'method_slug' => 'bkash',
            'official_sender' => 'bKash',
            'raw_sender' => 'bKash',
            'raw_body' => 'You have received Tk 350.00. TrxID RMT7654321.',
            'sms_hash' => hash('sha256', 'remittance-mismatch'),
            'parsed_amount' => 350,
            'parsed_trx_id' => 'RMT7654321',
            'received_at' => now(),
            'is_fraud' => false,
        ]);

        $this->assertNull(app(PaymentVerifierService::class)->verifyPendingRemittance($transaction));
        $this->assertSame(Transaction::STATUS_PENDING, $transaction->fresh()->status);
        $this->assertNull($transaction->fresh()->trx_id);
    }

    public function test_remittance_payable_amount_adds_single_fixed_extra_for_concurrent_same_amount(): void
    {
        $method = PaymentMethod::create([
            'slug' => 'bkash',
            'name' => 'bKash',
            'payment_number' => '01700000000',
            'remittance_number' => '01700000000',
            'is_active' => true,
            'remittance_enabled' => true,
        ]);

        Transaction::create([
            'invoice_id' => 'INV-REMIT-ACTIVE',
            'signed_token' => str_repeat('e', 64),
            'amount' => 350,
            'currency' => 'BDT',
            'method_slug' => 'bkash',
            'method_option' => 'remittance',
            'status' => Transaction::STATUS_PENDING,
            'expires_at' => now()->addMinutes(15),
        ]);

        $second = Transaction::create([
            'invoice_id' => 'INV-REMIT-SECOND',
            'signed_token' => str_repeat('f', 64),
            'amount' => 350,
            'currency' => 'BDT',
            'status' => Transaction::STATUS_PENDING,
        ]);

        $methodCall = new \ReflectionMethod(GatewayController::class, 'resolveRemittancePayableAmount');
        $methodCall->setAccessible(true);

        $this->assertSame(
            370.0,
            $methodCall->invoke(app(GatewayController::class), $second, $method, app(SettingsService::class))
        );
    }

    public function test_preselected_invoice_is_not_auto_matched_before_customer_submission(): void
    {
        $transaction = Transaction::create([
            'invoice_id' => 'INV-PRESELECT-1',
            'signed_token' => str_repeat('g', 64),
            'amount' => 250,
            'currency' => 'BDT',
            'method_slug' => 'bkash',
            'method_option' => 'send_money',
            'customer_number' => '01711111111',
            'normalized_customer_number' => '01711111111',
            'status' => Transaction::STATUS_PENDING,
            'expires_at' => now()->addMinutes(15),
            'metadata' => ['verification_ready' => false],
        ]);

        $sms = SmsLog::create([
            'method_slug' => 'bkash',
            'official_sender' => 'bKash',
            'raw_sender' => 'bKash',
            'raw_body' => 'You have received Tk 250.00 from 01711111111. TrxID PRE1234567.',
            'sms_hash' => hash('sha256', 'preselect-not-ready'),
            'parsed_amount' => 250,
            'parsed_customer_number' => '01711111111',
            'normalized_customer_number' => '01711111111',
            'parsed_trx_id' => 'PRE1234567',
            'received_at' => now(),
            'is_fraud' => false,
        ]);

        $this->assertNull(app(PaymentVerifierService::class)->verifySms($sms));
        $this->assertSame(Transaction::STATUS_PENDING, $transaction->fresh()->status);
        $this->assertNull($transaction->fresh()->trx_id);
    }

    public function test_manual_fallback_matches_trx_amount_and_method_when_customer_number_was_wrong(): void
    {
        $this->setSmsSettings();

        $transaction = Transaction::create([
            'invoice_id' => 'INV-MANUAL-WRONG-NUMBER',
            'signed_token' => str_repeat('h', 64),
            'amount' => 250,
            'currency' => 'BDT',
            'method_slug' => 'bkash',
            'method_option' => 'send_money',
            'customer_number' => '01700000000',
            'normalized_customer_number' => '01700000000',
            'status' => Transaction::STATUS_PENDING,
            'created_at' => now()->subMinute(),
            'expires_at' => now()->addMinutes(10),
            'metadata' => ['verification_ready' => true, 'manual_processing_sms_pending' => true],
        ]);

        ManualVerification::create([
            'transaction_id' => $transaction->id,
            'trx_id' => 'MAN1234567',
            'customer_number' => '01799999999',
            'status' => 'submitted',
        ]);

        $sms = SmsLog::create([
            'method_slug' => 'bkash',
            'official_sender' => 'bKash',
            'raw_sender' => 'bKash',
            'raw_body' => 'You have received Tk 250.00 from 01799999999. TrxID MAN1234567.',
            'sms_hash' => hash('sha256', 'manual-wrong-number'),
            'parsed_amount' => 250,
            'parsed_customer_number' => '01799999999',
            'normalized_customer_number' => '01799999999',
            'parsed_trx_id' => 'MAN1234567',
            'received_at' => now(),
            'is_fraud' => false,
        ]);

        $matched = app(PaymentVerifierService::class)->verifySms($sms);

        $this->assertNotNull($matched);
        $this->assertSame(Transaction::STATUS_SUCCESS, $transaction->fresh()->status);
        $this->assertSame('MAN1234567', $transaction->fresh()->trx_id);
        $this->assertSame('01799999999', $transaction->fresh()->customer_number);
        $this->assertSame('approved', ManualVerification::first()->fresh()->status);
        $this->assertSame(1, OutgoingSms::where('transaction_id', $transaction->id)->count());
        $this->assertSame('+8801799999999', OutgoingSms::first()->recipient);
        $this->assertStringContainsString('Your payment of 250 BDT has been confirmed.', OutgoingSms::first()->message);
    }

    public function test_manual_fallback_submit_redirects_to_verifying_screen_even_when_trx_matches_immediately(): void
    {
        $this->setSmsSettings();

        $transaction = Transaction::create([
            'invoice_id' => 'INV-MANUAL-VERIFY-SCREEN',
            'signed_token' => str_repeat('v', 64),
            'amount' => 250,
            'currency' => 'BDT',
            'method_slug' => 'bkash',
            'method_option' => 'send_money',
            'customer_number' => '01700000000',
            'normalized_customer_number' => '01700000000',
            'status' => Transaction::STATUS_PENDING,
            'created_at' => now()->subMinute(),
            'expires_at' => now()->addMinutes(10),
            'metadata' => ['verification_ready' => true, 'manual_hold' => true],
        ]);

        SmsLog::create([
            'method_slug' => 'bkash',
            'official_sender' => 'bKash',
            'raw_sender' => 'bKash',
            'raw_body' => 'You have received Tk 250.00 from 01799999999. TrxID SCREEN1234.',
            'sms_hash' => hash('sha256', 'manual-verifying-screen'),
            'parsed_amount' => 250,
            'parsed_customer_number' => '01799999999',
            'normalized_customer_number' => '01799999999',
            'parsed_trx_id' => 'SCREEN1234',
            'received_at' => now(),
            'is_fraud' => false,
        ]);

        $this->post(route('payment.manual', ['transaction' => $transaction->invoice_id, 'token' => $transaction->signed_token]), [
            'trx_id' => 'SCREEN1234',
            'customer_number' => '01700000000',
        ])->assertRedirect(route('payment.processing', [
            'transaction' => $transaction->invoice_id,
            'token' => $transaction->signed_token,
            'verify' => 1,
        ]));

        $this->assertSame(Transaction::STATUS_SUCCESS, $transaction->fresh()->status);
        $this->assertSame('SCREEN1234', $transaction->fresh()->trx_id);
    }

    public function test_manual_fallback_does_not_send_sms_when_submitted_number_does_not_match_sms_sender(): void
    {
        $this->setSmsSettings();
        GatewaySetting::updateOrCreate(['key' => 'payment_sms_delivery_scope'], ['value' => 'all_successful']);

        $transaction = Transaction::create([
            'invoice_id' => 'INV-MANUAL-NO-SMS-WRONG-NUMBER',
            'signed_token' => str_repeat('w', 64),
            'amount' => 250,
            'currency' => 'BDT',
            'method_slug' => 'bkash',
            'method_option' => 'send_money',
            'customer_number' => '01700000000',
            'normalized_customer_number' => '01700000000',
            'status' => Transaction::STATUS_PENDING,
            'created_at' => now()->subMinute(),
            'expires_at' => now()->addMinutes(10),
            'metadata' => ['verification_ready' => true, 'manual_processing_sms_pending' => true],
        ]);

        SmsLog::create([
            'method_slug' => 'bkash',
            'official_sender' => 'bKash',
            'raw_sender' => 'bKash',
            'raw_body' => 'You have received Tk 250.00 from 01799999999. TrxID NOSMS12345.',
            'sms_hash' => hash('sha256', 'manual-no-sms-wrong-number'),
            'parsed_amount' => 250,
            'parsed_customer_number' => '01799999999',
            'normalized_customer_number' => '01799999999',
            'parsed_trx_id' => 'NOSMS12345',
            'received_at' => now(),
            'is_fraud' => false,
        ]);

        $this->post(route('payment.manual', ['transaction' => $transaction->invoice_id, 'token' => $transaction->signed_token]), [
            'trx_id' => 'NOSMS12345',
            'customer_number' => '01700000000',
        ])->assertRedirect(route('payment.processing', [
            'transaction' => $transaction->invoice_id,
            'token' => $transaction->signed_token,
            'verify' => 1,
        ]));

        $fresh = $transaction->fresh();
        $this->assertSame(Transaction::STATUS_SUCCESS, $fresh->status);
        $this->assertSame('NOSMS12345', $fresh->trx_id);
        $this->assertTrue($fresh->metadata['manual_success_sms_suppressed']);
        $this->assertFalse($fresh->metadata['manual_processing_sms_pending']);
        $this->assertSame(0, OutgoingSms::where('transaction_id', $transaction->id)->count());
    }

    public function test_delayed_manual_fallback_does_not_send_sms_when_submitted_number_does_not_match_sms_sender(): void
    {
        $this->setSmsSettings();
        GatewaySetting::updateOrCreate(['key' => 'payment_sms_delivery_scope'], ['value' => 'all_successful']);

        $transaction = Transaction::create([
            'invoice_id' => 'INV-DELAYED-MANUAL-NO-SMS',
            'signed_token' => str_repeat('x', 64),
            'amount' => 250,
            'currency' => 'BDT',
            'method_slug' => 'bkash',
            'method_option' => 'send_money',
            'customer_number' => '01700000000',
            'normalized_customer_number' => '01700000000',
            'status' => Transaction::STATUS_PENDING,
            'created_at' => now()->subMinute(),
            'expires_at' => now()->addMinutes(10),
            'metadata' => ['verification_ready' => true, 'manual_processing_sms_pending' => true],
        ]);

        ManualVerification::create([
            'transaction_id' => $transaction->id,
            'trx_id' => 'DELAYNOSMS1',
            'customer_number' => '01700000000',
            'status' => 'submitted',
        ]);

        $sms = SmsLog::create([
            'method_slug' => 'bkash',
            'official_sender' => 'bKash',
            'raw_sender' => 'bKash',
            'raw_body' => 'You have received Tk 250.00 from 01799999999. TrxID DELAYNOSMS1.',
            'sms_hash' => hash('sha256', 'delayed-manual-no-sms-wrong-number'),
            'parsed_amount' => 250,
            'parsed_customer_number' => '01799999999',
            'normalized_customer_number' => '01799999999',
            'parsed_trx_id' => 'DELAYNOSMS1',
            'received_at' => now(),
            'is_fraud' => false,
        ]);

        $matched = app(PaymentVerifierService::class)->verifySms($sms);

        $fresh = $transaction->fresh();
        $this->assertNotNull($matched);
        $this->assertSame(Transaction::STATUS_SUCCESS, $fresh->status);
        $this->assertSame('DELAYNOSMS1', $fresh->trx_id);
        $this->assertTrue($fresh->metadata['manual_success_sms_suppressed']);
        $this->assertFalse($fresh->metadata['manual_processing_sms_pending']);
        $this->assertSame(0, OutgoingSms::where('transaction_id', $transaction->id)->count());
    }

    private function setSmsSettings(): void
    {
        foreach ([
            'payment_sms_brand' => 'BanglaLicense',
            'payment_sms_contact_url' => 'https://wa.me/8801882398668',
        ] as $key => $value) {
            GatewaySetting::updateOrCreate(['key' => $key], ['value' => $value]);
        }
    }

    public function test_manual_hold_prevents_countdown_expiry(): void
    {
        $held = Transaction::create([
            'invoice_id' => 'INV-HOLD-1',
            'signed_token' => str_repeat('i', 64),
            'amount' => 250,
            'currency' => 'BDT',
            'method_slug' => 'bkash',
            'method_option' => 'send_money',
            'customer_number' => '01711111111',
            'normalized_customer_number' => '01711111111',
            'status' => Transaction::STATUS_PENDING,
            'expires_at' => now()->subMinute(),
            'metadata' => ['verification_ready' => true, 'manual_hold' => true],
        ]);

        $expired = Transaction::create([
            'invoice_id' => 'INV-NO-HOLD-1',
            'signed_token' => str_repeat('j', 64),
            'amount' => 250,
            'currency' => 'BDT',
            'method_slug' => 'bkash',
            'method_option' => 'send_money',
            'customer_number' => '01722222222',
            'normalized_customer_number' => '01722222222',
            'status' => Transaction::STATUS_PENDING,
            'expires_at' => now()->subMinute(),
            'metadata' => ['verification_ready' => true],
        ]);

        $this->assertSame(1, app(PaymentVerifierService::class)->expireOldPending());
        $this->assertSame(Transaction::STATUS_PENDING, $held->fresh()->status);
        $this->assertSame(Transaction::STATUS_EXPIRED, $expired->fresh()->status);
    }

    public function test_held_payment_can_auto_verify_after_countdown(): void
    {
        $this->setSmsSettings();

        $transaction = Transaction::create([
            'invoice_id' => 'INV-HOLD-VERIFY',
            'signed_token' => str_repeat('k', 64),
            'amount' => 250,
            'currency' => 'BDT',
            'method_slug' => 'bkash',
            'method_option' => 'send_money',
            'customer_number' => '01711111111',
            'normalized_customer_number' => '01711111111',
            'status' => Transaction::STATUS_PENDING,
            'created_at' => now()->subMinutes(20),
            'expires_at' => now()->subMinutes(5),
            'metadata' => ['verification_ready' => true, 'manual_hold' => true, 'manual_processing_sms_pending' => true],
        ]);

        $sms = SmsLog::create([
            'method_slug' => 'bkash',
            'official_sender' => 'bKash',
            'raw_sender' => 'bKash',
            'raw_body' => 'You have received Tk 250.00 from 01711111111. TrxID HOLD1234567.',
            'sms_hash' => hash('sha256', 'manual-hold-after-expiry'),
            'parsed_amount' => 250,
            'parsed_customer_number' => '01711111111',
            'normalized_customer_number' => '01711111111',
            'parsed_trx_id' => 'HOLD1234567',
            'received_at' => now(),
            'is_fraud' => false,
        ]);

        $matched = app(PaymentVerifierService::class)->verifySms($sms);

        $this->assertNotNull($matched);
        $this->assertSame(Transaction::STATUS_SUCCESS, $transaction->fresh()->status);
        $this->assertSame('HOLD1234567', $transaction->fresh()->trx_id);
        $this->assertSame(1, OutgoingSms::where('transaction_id', $transaction->id)->count());
        $this->assertFalse($transaction->fresh()->metadata['manual_processing_sms_pending']);
    }

    public function test_sms_sync_ignores_messages_older_than_device_registration(): void
    {
        $plainKey = 'smsr_test_key';
        SmsDevice::create([
            'name' => 'Test Phone',
            'api_key_hash' => hash('sha256', $plainKey),
            'allowed_methods' => ['bkash'],
            'is_active' => true,
            'created_at' => now(),
        ]);

        $response = $this->withHeader('X-Device-Key', $plainKey)->postJson('/api/v1/sms/sync', [
            'messages' => [[
                'sender' => 'bKash',
                'body' => 'You have received Tk 325.00 from 01742208442. Fee Tk 0.00. Balance Tk 1,622.07. TrxID DES0ONKX1E at 28/05/2026 00:21',
                'received_at' => now()->subDay()->toIso8601String(),
            ]],
        ]);

        $response->assertOk()->assertJson(['accepted' => 0, 'verified' => 0]);
        $this->assertSame(0, SmsLog::count());
    }

    public function test_sms_sync_does_not_insert_same_method_trx_twice(): void
    {
        $plainKey = 'smsr_test_key';
        SmsDevice::create([
            'name' => 'Test Phone',
            'api_key_hash' => hash('sha256', $plainKey),
            'allowed_methods' => ['bkash'],
            'is_active' => true,
            'created_at' => now()->subDays(2),
        ]);

        $message = [
            'sender' => 'bKash',
            'body' => 'You have received Tk 10.00 from 01731196069. Fee Tk 0.00. Balance Tk 1,512.07. TrxID DET4PYHWC4',
            'received_at' => now()->toIso8601String(),
        ];

        $this->withHeader('X-Device-Key', $plainKey)->postJson('/api/v1/sms/sync', ['messages' => [$message]])->assertOk();
        $this->withHeader('X-Device-Key', $plainKey)->postJson('/api/v1/sms/sync', ['messages' => [$message]])->assertOk();

        $this->assertSame(1, SmsLog::where('parsed_trx_id', 'DET4PYHWC4')->count());
    }

    public function test_disallowed_device_sms_does_not_block_allowed_device_later(): void
    {
        $wrongKey = 'smsr_wrong_key';
        $rightKey = 'smsr_right_key';

        SmsDevice::create([
            'name' => 'Rocket Phone',
            'api_key_hash' => hash('sha256', $wrongKey),
            'allowed_methods' => ['rocket'],
            'is_active' => true,
            'created_at' => now()->subDays(2),
        ]);

        SmsDevice::create([
            'name' => 'bKash Phone',
            'api_key_hash' => hash('sha256', $rightKey),
            'allowed_methods' => ['bkash'],
            'is_active' => true,
            'created_at' => now()->subDays(2),
        ]);

        $message = [
            'sender' => 'bKash',
            'body' => 'You have received Tk 10.00 from 01731196069. Fee Tk 0.00. Balance Tk 1,512.07. TrxID MULTIDEV1',
            'received_at' => now()->toIso8601String(),
        ];

        $this->withHeader('X-Device-Key', $wrongKey)
            ->postJson('/api/v1/sms/sync', ['messages' => [$message]])
            ->assertOk()
            ->assertJson(['accepted' => 0, 'ignored' => 1]);

        $this->assertFalse(SmsLog::where('parsed_trx_id', 'MULTIDEV1')->exists());

        $this->withHeader('X-Device-Key', $rightKey)
            ->postJson('/api/v1/sms/sync', ['messages' => [$message]])
            ->assertOk()
            ->assertJson(['accepted' => 1]);

        $this->assertSame(1, SmsLog::where('parsed_trx_id', 'MULTIDEV1')->count());
        $this->assertSame('bKash Phone', SmsLog::where('parsed_trx_id', 'MULTIDEV1')->first()->smsDevice->name);
    }

    public function test_sms_sync_uses_device_last_sms_checkpoint(): void
    {
        $plainKey = 'smsr_test_key';
        SmsDevice::create([
            'name' => 'Test Phone',
            'api_key_hash' => hash('sha256', $plainKey),
            'allowed_methods' => ['bkash'],
            'is_active' => true,
            'created_at' => now()->subDays(2),
            'last_sms_received_at' => now()->subMinute(),
        ]);

        $oldMessage = [
            'sender' => 'bKash',
            'body' => 'You have received Tk 10.00 from 01731196069. Fee Tk 0.00. Balance Tk 1,512.07. TrxID OLD1234567',
            'received_at' => now()->subMinutes(2)->toIso8601String(),
        ];
        $newMessage = [
            'sender' => 'bKash',
            'body' => 'You have received Tk 10.00 from 01731196069. Fee Tk 0.00. Balance Tk 1,512.07. TrxID NEW1234567',
            'received_at' => now()->toIso8601String(),
        ];

        $this->withHeader('X-Device-Key', $plainKey)
            ->postJson('/api/v1/sms/sync', ['messages' => [$newMessage, $oldMessage]])
            ->assertOk()
            ->assertJson(['accepted' => 1]);

        $this->assertFalse(SmsLog::where('parsed_trx_id', 'OLD1234567')->exists());
        $this->assertTrue(SmsLog::where('parsed_trx_id', 'NEW1234567')->exists());
    }

    public function test_sms_sync_processes_all_new_messages_after_checkpoint_even_if_unordered(): void
    {
        $plainKey = 'smsr_test_key';
        $device = SmsDevice::create([
            'name' => 'Test Phone',
            'api_key_hash' => hash('sha256', $plainKey),
            'allowed_methods' => ['bkash'],
            'is_active' => true,
            'last_sms_received_at' => \Carbon\Carbon::create(2026, 5, 29, 21, 10, 0, 'Asia/Dhaka'),
        ]);
        $device->forceFill(['created_at' => \Carbon\Carbon::create(2026, 5, 29, 12, 0, 0, 'Asia/Dhaka')])->save();

        $messages = [
            [
                'sender' => 'bKash',
                'body' => 'You have received Tk 10.00 from 01731196069. TrxID NEW2135 at 29/05/2026 21:35',
                'received_at' => \Carbon\Carbon::create(2026, 5, 29, 21, 35, 0, 'Asia/Dhaka')->toIso8601String(),
            ],
            [
                'sender' => 'bKash',
                'body' => 'You have received Tk 10.00 from 01731196069. TrxID NEW2113 at 29/05/2026 21:13',
                'received_at' => \Carbon\Carbon::create(2026, 5, 29, 21, 13, 0, 'Asia/Dhaka')->toIso8601String(),
            ],
            [
                'sender' => 'bKash',
                'body' => 'You have received Tk 10.00 from 01731196069. TrxID NEW2124 at 29/05/2026 21:24',
                'received_at' => \Carbon\Carbon::create(2026, 5, 29, 21, 24, 0, 'Asia/Dhaka')->toIso8601String(),
            ],
        ];

        $this->withHeader('X-Device-Key', $plainKey)
            ->postJson('/api/v1/sms/sync', ['messages' => $messages])
            ->assertOk()
            ->assertJson(['accepted' => 3]);

        $this->assertSame(3, SmsLog::whereIn('parsed_trx_id', ['NEW2113', 'NEW2124', 'NEW2135'])->count());
        $this->assertSame('21:35:00', SmsDevice::first()->fresh()->last_sms_received_at->format('H:i:s'));
    }
}
