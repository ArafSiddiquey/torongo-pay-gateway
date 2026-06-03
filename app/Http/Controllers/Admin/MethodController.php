<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class MethodController extends Controller
{
    public function index()
    {
        return view('admin.methods.index', ['methods' => PaymentMethod::orderBy('sort_order')->get()]);
    }

    public function create()
    {
        return view('admin.methods.form', [
            'method' => new PaymentMethod(),
            'gateways' => $this->availableGateways(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->data($request);

        if (PaymentMethod::where('slug', $data['slug'])->exists()) {
            return back()
                ->withErrors(['slug' => 'This gateway is already added. Edit the existing gateway instead.'])
                ->withInput();
        }

        PaymentMethod::create($data);

        return redirect()->route('admin.methods.index')->with('ok', 'Gateway credentials saved.');
    }

    public function edit(PaymentMethod $method)
    {
        $method->config = $this->displayConfig($method->config ?? []);

        return view('admin.methods.form', ['method' => $method, 'gateways' => $this->gatewayForMethod($method)]);
    }

    public function update(Request $request, PaymentMethod $method)
    {
        $method->update($this->data($request, $method));

        return redirect()->route('admin.methods.index')->with('ok', 'Payment method updated.');
    }

    public function destroy(PaymentMethod $method)
    {
        $method->delete();

        return back()->with('ok', 'Payment method deleted.');
    }

    private function data(Request $request, ?PaymentMethod $method = null): array
    {
        $data = $request->validate([
            'slug' => ['required', 'in:bkash,nagad,rocket,binance'],
            'payment_number' => ['nullable', 'string', 'max:80'],
            'remittance_number' => ['nullable', 'string', 'max:80'],
            'send_money_number' => ['nullable', 'string', 'max:80'],
            'payment_option_number' => ['nullable', 'string', 'max:80'],
            'remittance_option_number' => ['nullable', 'string', 'max:80'],
            'send_money_account_name' => ['nullable', 'string', 'max:120'],
            'payment_account_name' => ['nullable', 'string', 'max:120'],
            'remittance_account_name' => ['nullable', 'string', 'max:120'],
            'logo' => ['nullable', 'image', 'max:2048'],
            'qr' => ['nullable', 'image', 'max:4096'],
            'send_money_qr' => ['nullable', 'image', 'max:4096'],
            'payment_qr' => ['nullable', 'image', 'max:4096'],
            'remittance_qr' => ['nullable', 'image', 'max:4096'],
            'checkout_badge_image' => ['nullable', 'image', 'max:2048'],
            'sort_order' => ['nullable', 'integer'],
            'available_balance' => ['nullable', 'numeric', 'min:0'],
            'account_balances' => ['nullable', 'array'],
            'account_balances.*' => ['nullable', 'numeric', 'min:0'],
            'option_fees' => ['nullable', 'array'],
            'option_fees.*' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'minimum_amount' => ['nullable', 'numeric', 'min:0'],
            'maximum_amount' => ['nullable', 'numeric', 'min:1'],
            'binance_uid' => ['nullable', 'string', 'max:120'],
            'binance_api_key' => ['nullable', 'string', 'max:255'],
            'binance_secret_key' => ['nullable', 'string', 'max:255'],
            'binance_asset' => ['nullable', 'string', 'max:20'],
            'binance_exchange_rate' => ['nullable', 'numeric', 'min:0'],
            'instructions_en' => ['nullable', 'string'],
            'instructions_bn' => ['nullable', 'string'],
        ]);

        if ($method) {
            $data['slug'] = $method->slug;
        }

        $gateway = $this->gateways()[$data['slug']];
        $config = $method?->config ?? [];
        $optionNumbers = $config['option_numbers'] ?? [];
        $accountNames = $config['account_names'] ?? [];
        $optionQrPaths = $config['option_qr_paths'] ?? [];
        $existingOptionFees = is_array($config['option_fees'] ?? null) ? $config['option_fees'] : [];

        $data['name'] = $gateway['name'];
        $data['name_bn'] = $gateway['name_bn'];
        $data['sort_order'] = $data['sort_order'] ?? $gateway['sort_order'];
        $data['is_active'] = $request->boolean('is_active', true);
        $data['qr_enabled'] = $data['slug'] !== 'nagad' && $request->boolean('qr_enabled', true);
        $data['cash_out_enabled'] = false;
        $data['send_money_enabled'] = in_array('send_money', $gateway['options'], true) && $request->boolean('send_money_enabled', true);
        $data['payment_enabled'] = in_array('payment', $gateway['options'], true) && $request->boolean('payment_enabled', in_array('payment', $gateway['options'], true));
        $data['remittance_enabled'] = in_array('remittance', $gateway['options'], true) && $request->boolean('remittance_enabled', in_array('remittance', $gateway['options'], true));

        $sendMoneyNumber = $data['send_money_number'] ?? ($data['payment_number'] ?? null);
        $paymentOptionNumber = $data['payment_option_number'] ?? ($data['payment_number'] ?? null);
        $remittanceOptionNumber = $data['remittance_option_number'] ?? ($data['remittance_number'] ?? null);

        if (in_array('send_money', $gateway['options'], true)) {
            $optionNumbers['send_money'] = $sendMoneyNumber;
            $accountNames['send_money'] = $data['send_money_account_name'] ?? ($accountNames['send_money'] ?? 'Product Torongo Pay');
        }
        if (in_array('payment', $gateway['options'], true)) {
            $optionNumbers['payment'] = $paymentOptionNumber;
            $accountNames['payment'] = $data['payment_account_name'] ?? ($accountNames['payment'] ?? 'Product Torongo Pay');
        }
        if (in_array('remittance', $gateway['options'], true)) {
            $optionNumbers['remittance'] = $remittanceOptionNumber;
            $accountNames['remittance'] = $data['remittance_account_name'] ?? ($accountNames['remittance'] ?? 'Product Torongo Pay');
        }

        foreach ([
            'send_money_qr' => 'send_money',
            'payment_qr' => 'payment',
            'remittance_qr' => 'remittance',
        ] as $field => $option) {
            if ($data['slug'] !== 'nagad' && $request->hasFile($field) && in_array($option, $gateway['options'], true)) {
                $optionQrPaths[$option] = $request->file($field)->store('qr', 'public');
            }
        }

        if ($data['slug'] === 'nagad') {
            $optionQrPaths = [];
        }

        if ($request->hasFile('checkout_badge_image')) {
            $config['checkout_badge_image'] = $request->file('checkout_badge_image')->store('checkout-badges', 'public');
        }

        $data['payment_number'] = $sendMoneyNumber ?: $paymentOptionNumber ?: $remittanceOptionNumber;
        $data['remittance_number'] = in_array('remittance', $gateway['options'], true) ? $remittanceOptionNumber : null;

        $binanceApiKey = $this->encryptedSecret($data['binance_api_key'] ?? null, $config['binance_api_key'] ?? null);
        $binanceSecretKey = $this->encryptedSecret($data['binance_secret_key'] ?? null, $config['binance_secret_key'] ?? null);
        $existingBalance = round((float) ($config['balance_base_amount'] ?? 0), 2);
        $incomingBalance = $request->has('available_balance')
            ? round((float) ($data['available_balance'] ?? 0), 2)
            : $existingBalance;
        $incomingAccountBalances = is_array($data['account_balances'] ?? null) ? $data['account_balances'] : [];
        $existingAccountBalances = is_array($config['account_balance_bases'] ?? null) ? $config['account_balance_bases'] : [];
        $incomingOptionFees = is_array($data['option_fees'] ?? null) ? $data['option_fees'] : [];
        $optionFees = $this->optionFees($gateway['options'], $incomingOptionFees, $existingOptionFees);
        $balanceConfig = array_merge($config, [
            'binance_uid' => $data['binance_uid'] ?? ($config['binance_uid'] ?? null),
        ]);
        $accountBalanceBases = $this->accountBalanceBases($data['slug'], $optionNumbers, $balanceConfig, $incomingAccountBalances, $existingAccountBalances, $incomingBalance);
        $balanceSetAt = $config['balance_base_set_at'] ?? null;

        if (! $method || $incomingBalance !== $existingBalance || $accountBalanceBases !== $existingAccountBalances || blank($balanceSetAt)) {
            $balanceSetAt = now()->toIso8601String();
        }

        $data['config'] = array_merge($config, [
            'balance_base_amount' => $incomingBalance,
            'account_balance_bases' => $accountBalanceBases,
            'balance_base_set_at' => $balanceSetAt,
            'minimum_amount' => $data['minimum_amount'] ?? 1,
            'maximum_amount' => $data['maximum_amount'] ?? 100000,
            'option_fees' => $optionFees,
            'account_names' => $accountNames,
            'binance_uid' => $data['binance_uid'] ?? null,
            'binance_api_key' => $data['slug'] === 'binance' ? $binanceApiKey : null,
            'binance_secret_key' => $data['slug'] === 'binance' ? $binanceSecretKey : null,
            'binance_api_key_encrypted' => $data['slug'] === 'binance' && $binanceApiKey ? true : null,
            'binance_secret_key_encrypted' => $data['slug'] === 'binance' && $binanceSecretKey ? true : null,
            'binance_asset' => 'USDT',
            'binance_exchange_rate' => $data['binance_exchange_rate'] ?? 130,
            'binance_mode' => $data['slug'] === 'binance' ? 'personal_manual_with_optional_history_check' : null,
            'option_numbers' => $optionNumbers,
            'option_qr_paths' => $optionQrPaths,
        ]);

        unset(
            $data['minimum_amount'], $data['maximum_amount'],
            $data['available_balance'], $data['account_balances'], $data['option_fees'],
            $data['binance_uid'], $data['binance_api_key'], $data['binance_secret_key'], $data['binance_asset'], $data['binance_exchange_rate'],
            $data['send_money_number'], $data['payment_option_number'], $data['remittance_option_number'],
            $data['send_money_account_name'], $data['payment_account_name'], $data['remittance_account_name'],
            $data['send_money_qr'], $data['payment_qr'], $data['remittance_qr'], $data['checkout_badge_image']
        );

        if ($request->hasFile('logo')) {
            $data['logo_path'] = $request->file('logo')->store('methods', 'public');
        }

        if ($request->hasFile('qr')) {
            $data['qr_path'] = $request->file('qr')->store('qr', 'public');
        }

        unset($data['logo'], $data['qr']);

        return $data;
    }

    private function availableGateways(): array
    {
        $added = PaymentMethod::pluck('slug')->all();

        return array_diff_key($this->gateways(), array_flip($added));
    }

    private function gatewayForMethod(PaymentMethod $method): array
    {
        return array_intersect_key($this->gateways(), [$method->slug => true]);
    }

    private function displayConfig(array $config): array
    {
        if (! empty($config['binance_api_key'])) {
            $config['binance_api_key_saved'] = true;
            $config['binance_api_key'] = '';
        }

        if (! empty($config['binance_secret_key'])) {
            $config['binance_secret_key_saved'] = true;
            $config['binance_secret_key'] = '';
        }

        return $config;
    }

    private function encryptedSecret(?string $incoming, ?string $existing): ?string
    {
        if (filled($incoming)) {
            return Crypt::encryptString($incoming);
        }

        if (! filled($existing)) {
            return null;
        }

        try {
            Crypt::decryptString($existing);
            return $existing;
        } catch (\Throwable) {
            return Crypt::encryptString($existing);
        }
    }

    private function accountBalanceBases(string $slug, array $optionNumbers, array $config, array $incoming, array $existing, float $fallback): array
    {
        $accounts = [];
        $optionAccounts = match ($slug) {
            'bkash' => [
                'send_money' => $optionNumbers['send_money'] ?? null,
                'payment' => $optionNumbers['payment'] ?? null,
                'remittance' => $optionNumbers['remittance'] ?? null,
            ],
            'nagad' => [
                'send_money' => $optionNumbers['send_money'] ?? null,
                'remittance' => $optionNumbers['remittance'] ?? null,
            ],
            'rocket' => [
                'send_money' => $optionNumbers['send_money'] ?? null,
            ],
            'binance' => [
                'binance' => $config['binance_uid'] ?? $config['account_number'] ?? 'Binance account',
            ],
            default => [],
        };

        foreach ($optionAccounts as $option => $account) {
            $account = trim((string) $account);
            if ($account === '' || array_key_exists($account, $accounts)) {
                continue;
            }

            $accounts[$account] = round((float) ($incoming[$option] ?? $existing[$account] ?? $fallback), 2);
        }

        return $accounts;
    }

    private function optionFees(array $options, array $incoming, array $existing): array
    {
        $fees = [];
        foreach ($options as $option) {
            if ($option === 'remittance') {
                $fees[$option] = 0;
                continue;
            }

            $fees[$option] = round((float) ($incoming[$option] ?? $existing[$option] ?? 0), 2);
        }

        return $fees;
    }

    private function gateways(): array
    {
        return [
            'bkash' => ['name' => 'bKash', 'name_bn' => 'বিকাশ', 'sort_order' => 1, 'options' => ['send_money', 'payment', 'remittance']],
            'nagad' => ['name' => 'Nagad', 'name_bn' => 'নগদ', 'sort_order' => 2, 'options' => ['send_money', 'remittance']],
            'rocket' => ['name' => 'Rocket', 'name_bn' => 'রকেট', 'sort_order' => 3, 'options' => ['send_money']],
            'binance' => ['name' => 'Binance', 'name_bn' => 'Binance', 'sort_order' => 4, 'options' => []],
        ];
    }
}
