<?php

namespace Tests\Feature;

use App\Models\GatewaySetting;
use App\Models\PaymentMethod;
use App\Models\SmsLog;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminInvoiceMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_reject_all_pending_transactions(): void
    {
        Transaction::create([
            'invoice_id' => 'INV-PENDING-1',
            'signed_token' => str_repeat('p', 64),
            'amount' => 10,
            'currency' => 'BDT',
            'status' => Transaction::STATUS_PENDING,
        ]);

        Transaction::create([
            'invoice_id' => 'INV-PENDING-2',
            'signed_token' => str_repeat('q', 64),
            'amount' => 20,
            'currency' => 'BDT',
            'status' => Transaction::STATUS_PENDING,
        ]);

        Transaction::create([
            'invoice_id' => 'INV-SUCCESS-1',
            'signed_token' => str_repeat('s', 64),
            'amount' => 30,
            'currency' => 'BDT',
            'status' => Transaction::STATUS_SUCCESS,
        ]);

        $this->withSession(['admin_id' => 'ksa06024@gmail.com'])
            ->post(route('admin.transactions.reject_all_pending'))
            ->assertRedirect();

        $this->assertSame(0, Transaction::whereStatus(Transaction::STATUS_PENDING)->count());
        $this->assertSame(2, Transaction::whereStatus(Transaction::STATUS_FAILED)->count());
        $this->assertSame(1, Transaction::whereStatus(Transaction::STATUS_SUCCESS)->count());
    }

    public function test_transactions_page_excludes_pending_and_filter_option(): void
    {
        Transaction::create([
            'invoice_id' => 'INV-PENDING-HIDDEN',
            'signed_token' => str_repeat('p', 64),
            'amount' => 10,
            'currency' => 'BDT',
            'status' => Transaction::STATUS_PENDING,
        ]);

        Transaction::create([
            'invoice_id' => 'INV-SUCCESS-VISIBLE',
            'signed_token' => str_repeat('s', 64),
            'amount' => 20,
            'currency' => 'BDT',
            'status' => Transaction::STATUS_SUCCESS,
        ]);

        Transaction::create([
            'invoice_id' => 'INV-FAILED-HIDDEN-BY-DEFAULT',
            'signed_token' => str_repeat('f', 64),
            'amount' => 30,
            'currency' => 'BDT',
            'status' => Transaction::STATUS_FAILED,
        ]);

        $this->withSession(['admin_id' => 'ksa06024@gmail.com'])
            ->get(route('admin.transactions'))
            ->assertOk()
            ->assertSee('INV-SUCCESS-VISIBLE')
            ->assertDontSee('INV-PENDING-HIDDEN')
            ->assertDontSee('INV-FAILED-HIDDEN-BY-DEFAULT')
            ->assertSee('<option value="success" selected>Success</option>', false)
            ->assertSee('name="per_page"', false)
            ->assertSee('<option value="10" selected>10</option>', false)
            ->assertSee('transactions')
            ->assertDontSee('<option value="pending"', false)
            ->assertDontSee('<th>Action</th>', false)
            ->assertDontSee('Approve')
            ->assertDontSee('<svg', false);
    }

    public function test_transactions_page_can_filter_failed_or_expired_transactions(): void
    {
        Transaction::create([
            'invoice_id' => 'INV-SUCCESS-HIDDEN-BY-FILTER',
            'signed_token' => str_repeat('s', 64),
            'amount' => 20,
            'currency' => 'BDT',
            'status' => Transaction::STATUS_SUCCESS,
        ]);

        Transaction::create([
            'invoice_id' => 'INV-FAILED-VISIBLE',
            'signed_token' => str_repeat('f', 64),
            'amount' => 30,
            'currency' => 'BDT',
            'status' => Transaction::STATUS_FAILED,
        ]);

        $this->withSession(['admin_id' => 'ksa06024@gmail.com'])
            ->get(route('admin.transactions', ['status' => Transaction::STATUS_FAILED]))
            ->assertOk()
            ->assertSee('INV-FAILED-VISIBLE')
            ->assertDontSee('INV-SUCCESS-HIDDEN-BY-FILTER')
            ->assertSee('<option value="failed" selected>Failed</option>', false)
            ->assertDontSee('<th>Action</th>', false);
    }

    public function test_admin_lists_show_official_sender_number_when_customer_input_mismatched(): void
    {
        $smsLog = SmsLog::create([
            'method_slug' => 'bkash',
            'official_sender' => 'bKash',
            'raw_sender' => 'bKash',
            'raw_body' => 'You have received Tk 10.00 from 01799999999. TrxID TXNMISMATCH1',
            'sms_hash' => 'admin-mismatch-sms',
            'parsed_amount' => 10,
            'parsed_customer_number' => '01799999999',
            'normalized_customer_number' => '01799999999',
            'parsed_trx_id' => 'TXNMISMATCH1',
            'received_at' => now(),
        ]);

        Transaction::create([
            'invoice_id' => 'INV-MISMATCH-SENDER',
            'signed_token' => str_repeat('m', 64),
            'amount' => 10,
            'currency' => 'BDT',
            'method_slug' => 'bkash',
            'method_option' => 'payment',
            'customer_number' => '01700000000',
            'normalized_customer_number' => '01700000000',
            'trx_id' => 'TXNMISMATCH1',
            'status' => Transaction::STATUS_SUCCESS,
            'sms_log_id' => $smsLog->id,
            'metadata' => [
                'manual_success_sms_suppressed' => true,
                'manual_success_sms_suppressed_reason' => 'customer_number_mismatch',
            ],
        ]);

        $this->withSession(['admin_id' => 'ksa06024@gmail.com'])
            ->get(route('admin.invoices'))
            ->assertOk()
            ->assertSee('01799999999')
            ->assertSee('Input: 01700000000');

        $this->withSession(['admin_id' => 'ksa06024@gmail.com'])
            ->get(route('admin.transactions'))
            ->assertOk()
            ->assertSee('01799999999')
            ->assertSee('Input: 01700000000');
    }

    public function test_customer_invoice_shows_official_sender_number_when_customer_input_was_wrong(): void
    {
        $smsLog = SmsLog::create([
            'method_slug' => 'bkash',
            'official_sender' => 'bKash',
            'raw_sender' => 'bKash',
            'raw_body' => 'You have received Tk 10.00 from 01799999999. TrxID TXNINVOICE1',
            'sms_hash' => 'invoice-mismatch-sms',
            'parsed_amount' => 10,
            'parsed_customer_number' => '01799999999',
            'normalized_customer_number' => '01799999999',
            'parsed_trx_id' => 'TXNINVOICE1',
            'received_at' => now(),
        ]);

        $transaction = Transaction::create([
            'invoice_id' => 'INV-MISMATCH-INVOICE',
            'signed_token' => str_repeat('i', 64),
            'amount' => 10,
            'currency' => 'BDT',
            'method_slug' => 'bkash',
            'method_option' => 'payment',
            'customer_number' => '01700000000',
            'normalized_customer_number' => '01700000000',
            'trx_id' => 'TXNINVOICE1',
            'status' => Transaction::STATUS_SUCCESS,
            'sms_log_id' => $smsLog->id,
        ]);

        $this->get(route('payment.invoice', [
                'transaction' => $transaction->invoice_id,
                'token' => $transaction->signed_token,
            ]))
            ->assertOk()
            ->assertSee('01799999999')
            ->assertDontSee('01700000000');

        $pdfHtml = view('payment.invoice_pdf', [
            'transaction' => $transaction->fresh(),
            'settings' => app(\App\Services\SettingsService::class),
        ])->render();

        $this->assertStringContainsString('01799999999', $pdfHtml);
        $this->assertStringNotContainsString('01700000000', $pdfHtml);
    }

    public function test_transactions_page_accepts_per_page_selection(): void
    {
        foreach (range(1, 12) as $index) {
            Transaction::create([
                'invoice_id' => 'INV-TRANSACTION-PAGED-' . $index,
                'signed_token' => str_pad((string) $index, 64, 't'),
                'amount' => 20,
                'currency' => 'BDT',
                'status' => Transaction::STATUS_SUCCESS,
            ]);
        }

        $this->withSession(['admin_id' => 'ksa06024@gmail.com'])
            ->get(route('admin.transactions', ['per_page' => 50]))
            ->assertOk()
            ->assertSee('<option value="50" selected>50</option>', false)
            ->assertSee('Showing 1 to 12 of 12 transactions')
            ->assertSee('Page 1 of 1')
            ->assertDontSee('<svg', false);
    }

    public function test_pending_requests_page_only_shows_pending_transactions(): void
    {
        Transaction::create([
            'invoice_id' => 'INV-PENDING-VISIBLE',
            'signed_token' => str_repeat('p', 64),
            'amount' => 10,
            'currency' => 'BDT',
            'status' => Transaction::STATUS_PENDING,
        ]);

        Transaction::create([
            'invoice_id' => 'INV-SUCCESS-HIDDEN',
            'signed_token' => str_repeat('s', 64),
            'amount' => 20,
            'currency' => 'BDT',
            'status' => Transaction::STATUS_SUCCESS,
        ]);

        $this->withSession(['admin_id' => 'ksa06024@gmail.com'])
            ->get(route('admin.transactions', ['status' => Transaction::STATUS_PENDING]))
            ->assertOk()
            ->assertSee('Pending Requests')
            ->assertSee('INV-PENDING-VISIBLE')
            ->assertDontSee('INV-SUCCESS-HIDDEN')
            ->assertSee('<input type="hidden" name="status" value="pending">', false)
            ->assertDontSee('<option value="pending"', false)
            ->assertSee('<th>Action</th>', false)
            ->assertSee('Approve')
            ->assertSee('Reject');
    }

    public function test_dashboard_create_invoice_link_opens_invoice_modal(): void
    {
        $this->withSession(['admin_id' => 'ksa06024@gmail.com'])
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee(route('admin.invoices', ['create' => 1]), false);

        $this->withSession(['admin_id' => 'ksa06024@gmail.com'])
            ->get(route('admin.invoices', ['create' => 1]))
            ->assertOk()
            ->assertSee('invoiceModal')
            ->assertSee('showModal()', false);
    }

    public function test_processing_page_is_centered_on_mobile_visual_viewport(): void
    {
        $css = file_get_contents(public_path('assets/css/gateway.css'));

        $this->assertStringContainsString('.processing-page{min-height:100vh;min-height:100svh;min-height:100dvh;display:flex;align-items:center;justify-content:center', $css);
        $this->assertStringContainsString('@media(max-width:560px){.processing-page{min-height:100svh;min-height:100dvh;padding:12px}', $css);
    }

    public function test_custom_invoice_allows_empty_redirect_and_webhook_urls(): void
    {
        GatewaySetting::updateOrCreate(['key' => 'success_redirect_url'], ['value' => 'https://example.com/success']);
        GatewaySetting::updateOrCreate(['key' => 'failed_redirect_url'], ['value' => 'https://example.com/cancel']);

        $this->withSession(['admin_id' => 'ksa06024@gmail.com'])
            ->post(route('admin.invoices.store'), [
                'brand_name' => 'Torongo Pay',
                'invoice_id' => 'INV-OPTIONAL-URLS',
                'amount' => '10',
                'success_url' => '',
                'failed_url' => '',
                'callback_url' => '',
            ])
            ->assertRedirect(route('admin.invoices'));

        $transaction = Transaction::query()->where('invoice_id', 'INV-OPTIONAL-URLS')->first();

        $this->assertNotNull($transaction);
        $this->assertSame('https://example.com/success', $transaction->success_url);
        $this->assertSame('https://example.com/cancel', $transaction->failed_url);
        $this->assertNull($transaction->metadata['callback_url'] ?? null);
    }

    public function test_dashboard_shows_method_balance_from_saved_base_plus_future_successful_paid_amounts(): void
    {
        $method = PaymentMethod::create([
            'slug' => 'bkash',
            'name' => 'bKash',
            'name_bn' => 'bKash',
            'is_active' => true,
            'sort_order' => 1,
            'send_money_enabled' => true,
            'payment_enabled' => true,
            'remittance_enabled' => true,
            'config' => [
                'option_numbers' => [
                    'send_money' => '01711111111',
                    'payment' => '01822222222',
                    'remittance' => '01711111111',
                ],
                'balance_base_amount' => 1000,
                'balance_base_set_at' => now()->subMinute()->toIso8601String(),
            ],
        ]);

        Transaction::create([
            'invoice_id' => 'INV-BALANCE-OLD',
            'signed_token' => str_repeat('o', 64),
            'amount' => 500,
            'currency' => 'BDT',
            'payment_method_id' => $method->id,
            'method_slug' => 'bkash',
            'method_option' => 'send_money',
            'status' => Transaction::STATUS_SUCCESS,
            'verified_at' => now()->subMinutes(2),
            'metadata' => ['paid_amount' => 500],
        ]);

        Transaction::create([
            'invoice_id' => 'INV-BALANCE-NEW',
            'signed_token' => str_repeat('n', 64),
            'amount' => 250,
            'currency' => 'BDT',
            'payment_method_id' => $method->id,
            'method_slug' => 'bkash',
            'method_option' => 'send_money',
            'status' => Transaction::STATUS_SUCCESS,
            'verified_at' => now(),
            'metadata' => ['paid_amount' => 250],
        ]);

        Transaction::create([
            'invoice_id' => 'INV-BALANCE-PAYMENT',
            'signed_token' => str_repeat('q', 64),
            'amount' => 100,
            'currency' => 'BDT',
            'payment_method_id' => $method->id,
            'method_slug' => 'bkash',
            'method_option' => 'payment',
            'status' => Transaction::STATUS_SUCCESS,
            'verified_at' => now(),
            'metadata' => ['paid_amount' => 100],
        ]);

        Transaction::create([
            'invoice_id' => 'INV-BALANCE-PENDING',
            'signed_token' => str_repeat('p', 64),
            'amount' => 300,
            'currency' => 'BDT',
            'payment_method_id' => $method->id,
            'method_slug' => 'bkash',
            'status' => Transaction::STATUS_PENDING,
        ]);

        $response = $this->withSession(['admin_id' => 'ksa06024@gmail.com'])
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Account Balances')
            ->assertSee('bKash')
            ->assertSee('1,250 BDT')
            ->assertSee('Base 1,000 BDT + received 250 BDT')
            ->assertSee('1,100 BDT')
            ->assertSee('Base 1,000 BDT + received 100 BDT')
            ->assertSee('Total invoices')
            ->assertSee('Failed or expired')
            ->assertDontSee('Total payments')
            ->assertDontSee('All invoices created');

        $this->assertSame(1, substr_count($response->getContent(), '01711111111'));
        $this->assertSame(1, substr_count($response->getContent(), '01822222222'));
    }

    public function test_dashboard_subtracts_debit_sms_from_single_account_balance(): void
    {
        PaymentMethod::create([
            'slug' => 'nagad',
            'name' => 'Nagad',
            'name_bn' => 'Nagad',
            'payment_number' => '01640041418',
            'is_active' => true,
            'sort_order' => 1,
            'send_money_enabled' => true,
            'config' => [
                'option_numbers' => [
                    'send_money' => '01640041418',
                ],
                'balance_base_amount' => 1000,
                'account_balance_bases' => [
                    '01640041418' => 1000,
                ],
                'balance_base_set_at' => now()->subMinute()->toIso8601String(),
            ],
        ]);

        SmsLog::create([
            'method_slug' => 'nagad',
            'official_sender' => 'Nagad',
            'raw_sender' => 'Nagad',
            'raw_body' => 'Payment Tk 10.00 to Merchant is successful. Balance Tk 990.00. TrxID DEBIT12345',
            'sms_hash' => 'dashboard-debit-balance',
            'parsed_amount' => 10,
            'parsed_trx_id' => 'DEBIT12345',
            'received_at' => now(),
            'sms_type' => 'debit',
            'is_fraud' => false,
        ]);

        $this->withSession(['admin_id' => 'ksa06024@gmail.com'])
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('990 BDT')
            ->assertSee('- spent 10 BDT');
    }

    public function test_dashboard_shows_binance_balance_in_usdt(): void
    {
        PaymentMethod::create([
            'slug' => 'binance',
            'name' => 'Binance',
            'name_bn' => 'Binance',
            'payment_number' => '899891797',
            'is_active' => true,
            'sort_order' => 1,
            'config' => [
                'binance_uid' => '899891797',
                'account_balance_bases' => [
                    '899891797' => 100,
                ],
                'balance_base_set_at' => now()->toIso8601String(),
            ],
        ]);

        $this->withSession(['admin_id' => 'ksa06024@gmail.com'])
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('100 USDT')
            ->assertSee('Base 100 USDT + received 0 USDT')
            ->assertDontSee('100 BDT');
    }

    public function test_dashboard_balance_keeps_real_decimal_values_only(): void
    {
        $method = PaymentMethod::create([
            'slug' => 'bkash',
            'name' => 'bKash',
            'name_bn' => 'bKash',
            'payment_number' => '01640041418',
            'is_active' => true,
            'sort_order' => 1,
            'send_money_enabled' => true,
            'config' => [
                'option_numbers' => [
                    'send_money' => '01640041418',
                ],
                'account_balance_bases' => [
                    '01640041418' => 10.10,
                ],
                'balance_base_set_at' => now()->subMinute()->toIso8601String(),
            ],
        ]);

        Transaction::create([
            'invoice_id' => 'INV-BALANCE-DECIMAL',
            'signed_token' => str_repeat('d', 64),
            'amount' => 0.09,
            'currency' => 'BDT',
            'payment_method_id' => $method->id,
            'method_slug' => 'bkash',
            'method_option' => 'send_money',
            'status' => Transaction::STATUS_SUCCESS,
            'verified_at' => now(),
            'metadata' => ['paid_amount' => 0.09],
        ]);

        $this->withSession(['admin_id' => 'ksa06024@gmail.com'])
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('10.19 BDT')
            ->assertSee('Base 10.1 BDT + received 0.09 BDT')
            ->assertDontSee('10.19 BDT.00');
    }

    public function test_method_save_keeps_account_balance_per_unique_number(): void
    {
        $this->withSession(['admin_id' => 'ksa06024@gmail.com'])
            ->post(route('admin.methods.store'), [
                'slug' => 'bkash',
                'send_money_enabled' => '1',
                'payment_enabled' => '1',
                'remittance_enabled' => '1',
                'send_money_number' => '01711111111',
                'payment_option_number' => '01822222222',
                'remittance_option_number' => '01711111111',
                'available_balance' => 0,
                'account_balances' => [
                    'send_money' => 100000,
                    'payment' => 250000,
                    'remittance' => 100000,
                ],
                'minimum_amount' => 1,
                'maximum_amount' => 1000000,
            ])
            ->assertRedirect(route('admin.methods.index'));

        $config = PaymentMethod::where('slug', 'bkash')->firstOrFail()->config;

        $this->assertEquals([
            '01711111111' => 100000.0,
            '01822222222' => 250000.0,
        ], $config['account_balance_bases']);
    }

    public function test_add_gateway_page_only_shows_missing_gateways(): void
    {
        PaymentMethod::create([
            'slug' => 'bkash',
            'name' => 'bKash',
            'name_bn' => 'bKash',
            'payment_number' => '01711111111',
            'is_active' => true,
        ]);

        $this->withSession(['admin_id' => 'ksa06024@gmail.com'])
            ->get(route('admin.methods.create'))
            ->assertOk()
            ->assertDontSee('data-gateway="bkash"', false)
            ->assertSee('data-gateway="nagad"', false)
            ->assertSee('data-gateway="rocket"', false)
            ->assertSee('data-gateway="binance"', false);
    }

    public function test_add_gateway_page_has_empty_state_when_all_supported_gateways_exist(): void
    {
        foreach (['bkash' => 'bKash', 'nagad' => 'Nagad', 'rocket' => 'Rocket', 'binance' => 'Binance'] as $slug => $name) {
            PaymentMethod::create([
                'slug' => $slug,
                'name' => $name,
                'name_bn' => $name,
                'payment_number' => '01711111111',
                'is_active' => true,
            ]);
        }

        $this->withSession(['admin_id' => 'ksa06024@gmail.com'])
            ->get(route('admin.methods.create'))
            ->assertOk()
            ->assertSee('All Supported Gateways Added')
            ->assertDontSee('Save Gateway Credentials');
    }

    public function test_store_gateway_rejects_already_added_slug(): void
    {
        PaymentMethod::create([
            'slug' => 'bkash',
            'name' => 'bKash',
            'name_bn' => 'bKash',
            'payment_number' => '01711111111',
            'is_active' => true,
        ]);

        $this->withSession(['admin_id' => 'ksa06024@gmail.com'])
            ->from(route('admin.methods.create'))
            ->post(route('admin.methods.store'), [
                'slug' => 'bkash',
                'send_money_enabled' => '1',
                'send_money_number' => '01822222222',
                'minimum_amount' => 1,
                'maximum_amount' => 1000000,
            ])
            ->assertRedirect(route('admin.methods.create'))
            ->assertSessionHasErrors('slug');

        $this->assertSame('01711111111', PaymentMethod::where('slug', 'bkash')->value('payment_number'));
        $this->assertSame(1, PaymentMethod::where('slug', 'bkash')->count());
    }

    public function test_binance_asset_is_fixed_to_usdt(): void
    {
        $this->withSession(['admin_id' => 'ksa06024@gmail.com'])
            ->post(route('admin.methods.store'), [
                'slug' => 'binance',
                'binance_uid' => '899891797',
                'binance_asset' => 'BTC',
                'binance_exchange_rate' => 123,
                'account_balances' => [
                    'binance' => 25,
                ],
                'minimum_amount' => 1,
                'maximum_amount' => 1000000,
            ])
            ->assertRedirect(route('admin.methods.index'));

        $config = PaymentMethod::where('slug', 'binance')->firstOrFail()->config;

        $this->assertSame('USDT', $config['binance_asset']);
        $this->assertSame(123, (int) $config['binance_exchange_rate']);
        $this->assertEquals(25.0, $config['account_balance_bases']['899891797']);
    }

    public function test_invoice_page_has_per_page_selector_and_custom_pager(): void
    {
        foreach (range(1, 12) as $index) {
            Transaction::create([
                'invoice_id' => 'INV-PAGED-' . $index,
                'signed_token' => str_pad((string) $index, 64, 'x'),
                'amount' => 10,
                'currency' => 'BDT',
                'status' => Transaction::STATUS_SUCCESS,
            ]);
        }

        $this->withSession(['admin_id' => 'ksa06024@gmail.com'])
            ->get(route('admin.invoices', ['per_page' => 10]))
            ->assertOk()
            ->assertSee('name="per_page"', false)
            ->assertSee('<option value="10" selected>10</option>', false)
            ->assertSee('Showing 1 to 10 of 12 invoices')
            ->assertSee('Page 1 of 2')
            ->assertDontSee('<svg', false);
    }

    public function test_invoice_page_accepts_larger_per_page_selection(): void
    {
        foreach (range(1, 12) as $index) {
            Transaction::create([
                'invoice_id' => 'INV-PER-PAGE-' . $index,
                'signed_token' => str_pad((string) $index, 64, 'y'),
                'amount' => 10,
                'currency' => 'BDT',
                'status' => Transaction::STATUS_SUCCESS,
            ]);
        }

        $this->withSession(['admin_id' => 'ksa06024@gmail.com'])
            ->get(route('admin.invoices', ['per_page' => 50]))
            ->assertOk()
            ->assertSee('<option value="50" selected>50</option>', false)
            ->assertSee('Showing 1 to 12 of 12 invoices')
            ->assertSee('Page 1 of 1');
    }

    public function test_settled_invoice_download_route_returns_pdf(): void
    {
        $transaction = Transaction::create([
            'invoice_id' => 'INV-PDF-1',
            'signed_token' => str_repeat('d', 64),
            'amount' => 10,
            'currency' => 'BDT',
            'status' => Transaction::STATUS_SUCCESS,
            'metadata' => ['paid_amount' => 10],
        ]);

        $response = $this->get(route('payment.invoice.download', [
            'transaction' => $transaction->invoice_id,
            'token' => $transaction->signed_token,
        ]));

        $response->assertOk();
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_admin_can_upload_invoice_logo_for_invoice_rendering(): void
    {
        Storage::fake('public');

        $this->withSession(['admin_id' => 'ksa06024@gmail.com'])
            ->post(route('admin.settings.save'), [
                'gateway_name' => 'Torongo Pay',
                'invoice_logo_file' => UploadedFile::fake()->image('invoice-logo.png', 120, 120),
            ])
            ->assertRedirect();

        $path = GatewaySetting::where('key', 'invoice_logo_path')->value('value');

        $this->assertNotEmpty($path);
        Storage::disk('public')->assertExists($path);

        $this->withSession(['admin_id' => 'ksa06024@gmail.com'])
            ->get(route('admin.settings'))
            ->assertOk()
            ->assertSee('Current image uploaded')
            ->assertSee('invoice-logo')
            ->assertSee('Only processing fallback customers')
            ->assertSee('All successful mobile payments');
    }

    public function test_expired_invoice_link_does_not_show_payment_methods(): void
    {
        $transaction = Transaction::create([
            'invoice_id' => 'INV-EXPIRED-LINK',
            'signed_token' => str_repeat('e', 64),
            'amount' => 10,
            'currency' => 'BDT',
            'status' => Transaction::STATUS_EXPIRED,
        ]);

        $response = $this->get(route('payment.invoice', [
            'transaction' => $transaction->invoice_id,
            'token' => $transaction->signed_token,
        ]));

        $response->assertStatus(410);
        $this->assertSame('', $response->getContent());
    }
}
