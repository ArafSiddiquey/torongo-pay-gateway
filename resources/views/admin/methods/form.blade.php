@extends('layouts.admin')
@section('content')
@php
    $currentSlug = old('slug', $method->slug ?: (array_key_first($gateways) ?: 'bkash'));
    $config = $method->config ?? [];
    $optionNumbers = $config['option_numbers'] ?? [];
    $accountNames = $config['account_names'] ?? [];
    $optionQrs = $config['option_qr_paths'] ?? [];
    $optionFees = is_array($config['option_fees'] ?? null) ? $config['option_fees'] : [];
    $accountBalanceBases = is_array($config['account_balance_bases'] ?? null) ? $config['account_balance_bases'] : [];
    $balanceValue = function (?string $option, ?string $account) use ($accountBalanceBases, $config) {
        $account = trim((string) $account);
        return old("account_balances.$option", $account !== '' && array_key_exists($account, $accountBalanceBases)
            ? $accountBalanceBases[$account]
            : ($config['balance_base_amount'] ?? 0));
    };
@endphp
<div class="page-head">
    <div>
        <h1>{{ $method->exists ? 'Edit Gateway' : 'Add Gateway' }}</h1>
        <p class="hint">Configure fixed payment methods, option-wise numbers and QR codes.</p>
    </div>
    <a class="btn secondary" href="{{ route('admin.methods.index') }}">View Gateways</a>
</div>

@if(empty($gateways))
    <section class="gateway-card">
        <h2>All Supported Gateways Added</h2>
        <p class="hint">bKash, Nagad, Rocket and Binance are already configured. Edit an existing gateway from the gateway list.</p>
        <a class="btn" href="{{ route('admin.methods.index') }}">View Gateways</a>
    </section>
@else
<form id="gatewayForm" class="gateway-builder" method="post" enctype="multipart/form-data" action="{{ $method->exists ? route('admin.methods.update',$method) : route('admin.methods.store') }}">
    @csrf
    @if($method->exists)@method('put')@endif
    <input id="gatewaySlug" type="hidden" name="slug" value="{{ $currentSlug }}">

    <section class="gateway-card gateway-select-card">
        <h2>Select Payment Gateway</h2>
        <div class="gateway-tabs" role="tablist">
            @foreach($gateways as $slug => $gateway)
                <button type="button" class="gateway-tab {{ $currentSlug === $slug ? 'active' : '' }}" data-gateway="{{ $slug }}">{{ $gateway['name'] }}</button>
            @endforeach
        </div>
    </section>

    <section class="gateway-card">
        <h2><span id="gatewayTitle">{{ $gateways[$currentSlug]['name'] ?? 'Bkash' }}</span> Setup for <span class="cyan">N/A</span></h2>
        <div class="divider"></div>

        <div class="field gateway-brand-field">
            <label><span class="field-icon">↗</span> Select Brand</label>
            <input value="Default Brand" disabled>
        </div>

        <div class="gateway-grid">
            <div class="field">
                <label>Checkout Badge Image</label>
                <input type="file" name="checkout_badge_image" accept="image/*">
                @if(!empty($config['checkout_badge_image']))<small>Current badge uploaded</small>@endif
            </div>
        </div>

        <div id="accountTypeBlock">
            <label>Select Account Type:</label>
            <div class="account-tabs">
                <label data-option="send_money"><input type="checkbox" name="send_money_enabled" value="1" @checked(old('send_money_enabled', $method->send_money_enabled ?: true))> Send Money</label>
                <label data-option="payment"><input type="checkbox" name="payment_enabled" value="1" @checked(old('payment_enabled', $method->payment_enabled))> Payment</label>
                <label data-option="remittance"><input type="checkbox" name="remittance_enabled" value="1" @checked(old('remittance_enabled', $method->remittance_enabled))> Remittance</label>
            </div>
        </div>

        <div class="mobile-fields">
            <h3>Credentials</h3>
            <div class="gateway-grid option-config-fields" data-option-field="send_money">
                <div class="field">
                    <label>Account Name</label>
                    <input name="send_money_account_name" value="{{ old('send_money_account_name', $accountNames['send_money'] ?? $config['checkout_product_title'] ?? 'Product Torongo Pay') }}" placeholder="Account name for Send Money">
                </div>
                <div class="field">
                    <label><span class="field-icon">৳</span> Send Money Number</label>
                    <input name="send_money_number" value="{{ old('send_money_number', $optionNumbers['send_money'] ?? $method->payment_number) }}" placeholder="Enter Send Money number...">
                </div>
                <div class="field amount-field">
                    <label><span class="field-icon">BDT</span> Send Money Balance</label>
                    <input name="account_balances[send_money]" type="number" step="0.01" value="{{ $balanceValue('send_money', $optionNumbers['send_money'] ?? $method->payment_number) }}">
                    <span>BDT</span>
                </div>
                <div class="field amount-field fee-field">
                    <label>Send Money Fee (%)</label>
                    <input name="option_fees[send_money]" type="number" step="0.01" min="0" max="100" value="{{ old('option_fees.send_money', $optionFees['send_money'] ?? 0) }}">
                    <span>%</span>
                </div>
                <div class="field qr-field">
                    <label><span class="field-icon">▧</span> Send Money QR Code</label>
                    <input type="file" name="send_money_qr" accept="image/*">
                    @if(!empty($optionQrs['send_money']))<small>Current QR uploaded</small>@endif
                </div>
            </div>

            <div class="gateway-grid option-config-fields" data-option-field="payment">
                <div class="field">
                    <label>Account Name</label>
                    <input name="payment_account_name" value="{{ old('payment_account_name', $accountNames['payment'] ?? $config['checkout_product_title'] ?? 'Product Torongo Pay') }}" placeholder="Account name for Payment">
                </div>
                <div class="field">
                    <label><span class="field-icon">৳</span> Payment Number</label>
                    <input name="payment_option_number" value="{{ old('payment_option_number', $optionNumbers['payment'] ?? $method->payment_number) }}" placeholder="Enter Payment number...">
                </div>
                <div class="field amount-field">
                    <label><span class="field-icon">BDT</span> Payment Balance</label>
                    <input name="account_balances[payment]" type="number" step="0.01" value="{{ $balanceValue('payment', $optionNumbers['payment'] ?? $method->payment_number) }}">
                    <span>BDT</span>
                </div>
                <div class="field amount-field fee-field">
                    <label>Payment Fee (%)</label>
                    <input name="option_fees[payment]" type="number" step="0.01" min="0" max="100" value="{{ old('option_fees.payment', $optionFees['payment'] ?? 0) }}">
                    <span>%</span>
                </div>
                <div class="field qr-field">
                    <label><span class="field-icon">▧</span> Payment QR Code</label>
                    <input type="file" name="payment_qr" accept="image/*">
                    @if(!empty($optionQrs['payment']))<small>Current QR uploaded</small>@endif
                </div>
            </div>

            <div class="gateway-grid option-config-fields" data-option-field="remittance">
                <div class="field">
                    <label>Account Name</label>
                    <input name="remittance_account_name" value="{{ old('remittance_account_name', $accountNames['remittance'] ?? $config['checkout_product_title'] ?? 'Product Torongo Pay') }}" placeholder="Account name for Remittance">
                </div>
                <div class="field">
                    <label><span class="field-icon">৳</span> Remittance Number</label>
                    <input name="remittance_option_number" value="{{ old('remittance_option_number', $optionNumbers['remittance'] ?? $method->remittance_number) }}" placeholder="Enter Remittance number...">
                </div>
                <div class="field amount-field">
                    <label><span class="field-icon">BDT</span> Remittance Balance</label>
                    <input name="account_balances[remittance]" type="number" step="0.01" value="{{ $balanceValue('remittance', $optionNumbers['remittance'] ?? $method->remittance_number) }}">
                    <span>BDT</span>
                </div>
                <div class="field qr-field">
                    <label><span class="field-icon">▧</span> Remittance QR Code</label>
                    <input type="file" name="remittance_qr" accept="image/*">
                    @if(!empty($optionQrs['remittance']))<small>Current QR uploaded</small>@endif
                </div>
            </div>

            <div class="gateway-grid">
                <div class="field">
                    <label><span class="field-icon">▣</span> Logo (Optional)</label>
                    <input type="file" name="logo" accept="image/*">
                </div>
            </div>
        </div>

        <div class="gateway-grid binance-fields">
            <div class="field">
                <label><span class="field-icon">৳</span> Binance UID</label>
                <input name="binance_uid" value="{{ old('binance_uid', $config['binance_uid'] ?? '') }}" placeholder="Enter Binance UID...">
            </div>
            <div class="field amount-field">
                <label><span class="field-icon">USDT</span> Binance Balance</label>
                <input name="account_balances[binance]" type="number" step="0.01" value="{{ $balanceValue('binance', $config['binance_uid'] ?? $config['account_number'] ?? 'Binance account') }}">
                <span>USDT</span>
            </div>
            <div class="field">
                <label><span class="field-icon">৳</span> API Key</label>
                <input name="binance_api_key" value="{{ old('binance_api_key', '') }}" placeholder="{{ !empty($config['binance_api_key_saved']) ? 'Saved encrypted. Leave blank to keep current key.' : 'Enter API key...' }}">
            </div>
            <div class="field">
                <label><span class="field-icon">৳</span> Secret Key</label>
                <input name="binance_secret_key" value="{{ old('binance_secret_key', '') }}" placeholder="{{ !empty($config['binance_secret_key_saved']) ? 'Saved encrypted. Leave blank to keep current secret.' : 'Enter secret key...' }}">
            </div>
            <div class="field">
                <label><span class="field-icon">▧</span> Binance QR Code</label>
                <input type="file" name="qr" accept="image/*">
                @if($method->qr_path)<small>Current QR uploaded</small>@endif
            </div>
            <div class="field">
                <label><span class="field-icon">◎</span> Asset</label>
                <input value="USDT" disabled>
            </div>
            <div class="field">
                <label><span class="field-icon">⇄</span> Exchange Rate (1 USDT = BDT)</label>
                <input name="binance_exchange_rate" type="number" step="0.01" value="{{ old('binance_exchange_rate', $config['binance_exchange_rate'] ?? 130) }}">
            </div>
        </div>

        <h3>Limits & Fees</h3>
        <div class="gateway-grid">
            <div class="field amount-field">
                <label><span class="field-icon">⊙</span> Minimum Amount (BDT)</label>
                <input name="minimum_amount" type="number" step="0.01" value="{{ old('minimum_amount', $config['minimum_amount'] ?? 1) }}">
                <span>BDT</span>
            </div>
            <div class="field amount-field">
                <label><span class="field-icon">⊙</span> Maximum Amount (BDT)</label>
                <input name="maximum_amount" type="number" step="0.01" value="{{ old('maximum_amount', $config['maximum_amount'] ?? 100000) }}">
                <span>BDT</span>
            </div>
        </div>

        <div id="binanceNote" class="gateway-note" hidden>
            Binance personal mode: customers pay to your Binance UID/QR and submit Order ID. The system checks recent Pay history with your API key; Merchant checkout API is not used.
        </div>

        <div class="checks gateway-controls">
            <label><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $method->is_active ?? true))> Active</label>
            <label data-qr-control><input type="checkbox" name="qr_enabled" value="1" @checked(old('qr_enabled', $method->qr_enabled ?? true))> QR auto show</label>
        </div>

        <button class="btn save-gateway" type="submit">Save Gateway Credentials</button>
    </section>
</form>

<script>
const gatewayMeta = @json($gateways);
const gatewaySlug = document.getElementById('gatewaySlug');
const title = document.getElementById('gatewayTitle');
const mobileFields = document.querySelector('.mobile-fields');
const binanceFields = document.querySelector('.binance-fields');
const accountBlock = document.getElementById('accountTypeBlock');
const binanceNote = document.getElementById('binanceNote');
const optionLabels = [...document.querySelectorAll('.account-tabs label')];
const optionFields = [...document.querySelectorAll('.option-config-fields')];
const qrFields = [...document.querySelectorAll('.qr-field')];
const qrControl = document.querySelector('[data-qr-control]');

function setQrVisibility(supportsQr) {
    qrFields.forEach(field => {
        field.hidden = !supportsQr;
        field.querySelectorAll('input').forEach(input => input.disabled = !supportsQr);
    });
    if (qrControl) {
        qrControl.hidden = !supportsQr;
        const input = qrControl.querySelector('input');
        if (input) {
            input.disabled = !supportsQr;
            if (!supportsQr) input.checked = false;
        }
    }
}

function setGateway(slug) {
    gatewaySlug.value = slug;
    title.textContent = gatewayMeta[slug].name;
    document.querySelectorAll('.gateway-tab').forEach(btn => btn.classList.toggle('active', btn.dataset.gateway === slug));

    const isBinance = slug === 'binance';
    mobileFields.hidden = isBinance;
    binanceFields.hidden = !isBinance;
    accountBlock.hidden = isBinance;
    binanceNote.hidden = !isBinance;
    const supportsQr = slug !== 'nagad';

    optionLabels.forEach(label => {
        const option = label.dataset.option;
        const allowed = gatewayMeta[slug].options.includes(option);
        label.hidden = !allowed;
        label.style.display = allowed ? '' : 'none';
        const input = label.querySelector('input');
        input.disabled = !allowed;
        if (allowed && !input.checked) input.checked = true;
        if (!allowed) input.checked = false;
    });

    optionFields.forEach(field => {
        const allowed = gatewayMeta[slug].options.includes(field.dataset.optionField);
        field.hidden = isBinance || !allowed;
        field.querySelectorAll('input').forEach(input => input.disabled = isBinance || !allowed);
    });

    setQrVisibility(supportsQr);
}

document.querySelectorAll('.gateway-tab').forEach(btn => btn.addEventListener('click', () => setGateway(btn.dataset.gateway)));
setGateway(gatewaySlug.value);
</script>
@endif
@endsection
