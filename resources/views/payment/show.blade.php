@extends('layouts.payment')
@section('content')
@php
    $checkoutBaseAmount = (float) ($transaction->metadata['product_amount'] ?? $transaction->amount);
    $supportContactUrl = trim((string) $settings->get('payment_sms_contact_url', ''));
    $supportContactUrl = $supportContactUrl !== '' ? $supportContactUrl : '#';
    $supportPhoneDigits = preg_replace('/\D+/', '', trim((string) $settings->get('support_phone_number', '')));
    $supportPhoneUrl = '#';
    if ($supportPhoneDigits !== '') {
        $supportPhoneUrl = str_starts_with($supportPhoneDigits, '880')
            ? 'tel:+'.$supportPhoneDigits
            : (str_starts_with($supportPhoneDigits, '01') ? 'tel:+88'.$supportPhoneDigits : 'tel:+'.$supportPhoneDigits);
    }
@endphp
<main class="checkout">
    <section class="gateway-card">
        <div class="topbar">
            <button type="button" aria-label="Home"><svg viewBox="0 0 32 32"><path d="M6 15.5L16 7l10 8.5"/><path d="M9 14v12h14V14"/><path d="M13 26v-7h6v7"/></svg></button>
            <button type="button" onclick="window.close()" aria-label="Close"><svg viewBox="0 0 32 32"><path d="M9 9l14 14"/><path d="M23 9L9 23"/></svg></button>
        </div>
        <div class="brand-row">
            <img class="shop-logo torongo-shop-logo" src="{{ asset('assets/img/torongo-pay-mark.svg') }}" alt="Torongo Pay">
            <div class="brand-copy">
                <h1>{{ $settings->get('gateway_name', 'Brand Name') }}</h1>
                <button class="details-btn" type="button" onclick="document.getElementById('details').classList.toggle('show')">Details</button>
            </div>
        </div>
        <div id="details" class="details">
            <div class="row"><span>Invoice ID</span><b>{{ $transaction->invoice_id }}</b></div>
            <div class="row"><span>Amount</span><b>{{ number_format($transaction->amount, 0) }} BDT</b></div>
            <div class="row"><span>Merchant</span><b>{{ $settings->get('gateway_name', 'Online Shop') }}</b></div>
        </div>
        <div class="support">
            <a class="whatsapp" title="WhatsApp" href="{{ $supportContactUrl }}" target="_blank" rel="noopener"><img src="{{ asset('assets/img/support-whatsapp.jpg') }}" alt="WhatsApp"><span>WhatsApp</span></a>
            <a class="phone" title="Phone" href="{{ $supportPhoneUrl }}"><img src="{{ asset('assets/img/support-call-v2.png') }}" alt="Phone"><span>Phone</span></a>
        </div>
        @if($errors->any())<div class="errors">{{ $errors->first() }}</div>@endif
        <div class="method-tabs" role="tablist" aria-label="Payment categories">
            <button class="active" type="button" data-category-tab="mobile">Mobile Banking</button>
            <button type="button" data-category-tab="others">Other Methods</button>
        </div>
        <div class="methods">
            @foreach($methods as $method)
                @if(in_array($method->slug, ['bkash', 'nagad', 'rocket', 'binance'], true))
                    @php
                        $methodConfig = $method->config ?? [];
                        $accountNames = $methodConfig['account_names'] ?? [];
                        $sendMoneyAccountName = $accountNames['send_money'] ?? $methodConfig['checkout_product_title'] ?? 'Product Torongo Pay';
                        $paymentAccountName = $accountNames['payment'] ?? $methodConfig['checkout_product_title'] ?? 'Product Torongo Pay';
                        $remittanceAccountName = $accountNames['remittance'] ?? $methodConfig['checkout_product_title'] ?? 'Product Torongo Pay';
                        $optionFees = is_array($methodConfig['option_fees'] ?? null) ? $methodConfig['option_fees'] : [];
                        $badgeUrl = !empty($methodConfig['checkout_badge_image']) ? asset('storage/'.$methodConfig['checkout_badge_image']) : '';
                    @endphp
                    <button class="method" type="button" data-category="{{ $method->slug === 'binance' ? 'others' : 'mobile' }}" data-method-id="{{ $method->id }}" data-slug="{{ $method->slug }}" data-name="{{ $method->name }}" data-send-money="{{ $method->send_money_enabled ? 'true' : 'false' }}" data-payment="{{ $method->payment_enabled ? 'true' : 'false' }}" data-remittance="{{ $method->remittance_enabled ? 'true' : 'false' }}" data-fee-send-money="{{ $optionFees['send_money'] ?? 0 }}" data-fee-payment="{{ $optionFees['payment'] ?? 0 }}" data-account-name-send-money="{{ e($sendMoneyAccountName) }}" data-account-name-payment="{{ e($paymentAccountName) }}" data-account-name-remittance="{{ e($remittanceAccountName) }}" data-badge-url="{{ $badgeUrl }}">
                        @if($method->slug === 'bkash')
                            <img class="method-logo bkash-logo" src="{{ asset('assets/img/bkash-logo-sm.png') }}" alt="bKash">
                        @elseif($method->slug === 'nagad')
                            <img class="method-logo nagad-logo" src="{{ asset('assets/img/nagad-logo-sm.png') }}" alt="Nagad">
                        @elseif($method->slug === 'rocket')
                            <img class="method-logo rocket-logo" src="{{ asset('assets/img/rocket-logo-sm.png') }}" alt="Rocket">
                        @elseif($method->slug === 'binance')
                            <img class="method-logo binance-logo" src="{{ asset('assets/img/binance.png') }}" alt="Binance">
                        @else
                            <span class="wordmark wm-other">{{ $method->name_bn ?: $method->name }}</span>
                        @endif
                    </button>
                @endif
            @endforeach
        </div>
        <div id="emptyMethods" class="option-empty hidden">No payment method is available here.</div>
        <div class="paybar">Pay {{ number_format($transaction->amount, 0) }} BDT</div>
    </section>
</main>

<div id="optionModal" class="modal">
    <form id="optionForm" class="sheet" method="post" action="{{ route('payment.sender', ['transaction' => $transaction->invoice_id, 'token' => $transaction->signed_token]) }}">
        @csrf
        <button class="modal-close" onclick="optionModal.classList.remove('show')" type="button">&times;</button>
        <input type="hidden" name="payment_method_id" id="payment_method_id">
        <input type="hidden" name="method_option" id="method_option" value="payment">
        <h2>Select Payment Method</h2>
        <p class="sheet-text">This merchant offers multiple options for this gateway. Please choose one to continue.</p>
        <div id="optionButtons"></div>
    </form>
</div>

<main id="senderScreen" class="merchant sender-entry hidden">
    <form id="senderForm" class="merchant-card" method="post" action="{{ route('payment.sender', ['transaction' => $transaction->invoice_id, 'token' => $transaction->signed_token]) }}">
        @csrf
        <input type="hidden" name="payment_method_id" id="sender_payment_method_id">
        <input type="hidden" name="method_option" id="sender_method_option" value="payment">
        <div class="merchant-top">
            <img id="senderBrandLogo" class="sender-brand-logo" src="{{ asset('assets/img/bkash-logo-sm.png') }}" alt="Payment method">
            <div class="product">
                <span id="senderProductBadge" class="product-icon eps-badge" aria-hidden="true">EPS</span>
                <span>
                    <span id="senderMerchantName">Product {{ $settings->get('gateway_name', 'Online Shop') }}</span><br>
                    <small>Invoice ID: {{ $transaction->invoice_id }}</small>
                </span>
                <strong id="senderDisplayAmount">&#2547;{{ number_format($transaction->amount, 0) }}</strong>
            </div>
        </div>
        <div id="senderColorScreen" class="color-screen bkash">
            <div class="nagad-only nagad-lang"><span>বাং</span><span>Eng</span></div>
            <div class="nagad-only nagad-cart" aria-hidden="true">
                <svg viewBox="0 0 72 72"><path d="M14 18h8l8 30h28l6-21H27"/><path d="M33 23h24"/><path d="M30 31h24"/><path d="M34 39h17"/><circle cx="34" cy="56" r="4"/><circle cx="55" cy="56" r="4"/></svg>
            </div>
            <h2 id="nagadAccountName" class="nagad-only nagad-account-name">Account Name</h2>
            <div class="nagad-only nagad-summary">
                <p><b>Invoice No:</b> <span>{{ $transaction->invoice_id }}</span></p>
                <p><b>Total Amount:</b> <span>BDT {{ number_format($transaction->amount, 2) }}</span></p>
                <p><b>Charge:</b> <span>BDT 0</span></p>
            </div>
            <label id="senderScreenLabel">Enter your bKash account number</label>
            <div id="nagadInputBox" class="nagad-only nagad-input-box" aria-hidden="true" onclick="senderInput.focus()">
                <span class="nagad-digit"></span><span class="nagad-digit"></span><span class="nagad-digit"></span>
                <i></i>
                <span class="nagad-digit"></span><span class="nagad-digit"></span><span class="nagad-digit"></span><span class="nagad-digit"></span>
                <i></i>
                <span class="nagad-digit"></span><span class="nagad-digit"></span><span class="nagad-digit"></span><span class="nagad-digit"></span>
            </div>
            <input id="senderInput" name="customer_number" type="text" placeholder="e.g. 01XXXXXXXXX" maxlength="11" pattern="[0-9]{11,12}" inputmode="numeric" required oninput="updateSenderInput()" onfocus="updateSenderInput()" onblur="updateSenderInput()">
            <div id="senderTerms" class="terms">By continuing, you agree to the <button type="button" onclick="openTermsModal()">terms &amp; conditions</button></div>
            <img class="nagad-only nagad-bottom-logo" src="{{ asset('assets/img/nagad-logo-sm.png') }}" alt="Nagad">
        </div>
        <div class="actions sender-actions">
            <button id="senderCancelBtn" type="button" onclick="closeSenderScreen()">Cancel</button>
            <button id="senderConfirmBtn" class="confirm" type="submit" disabled>Confirm</button>
        </div>
    </form>
</main>

<div id="termsModal" class="terms-modal hidden" role="dialog" aria-modal="true" aria-labelledby="termsTitle">
    <div class="terms-sheet">
        <button class="terms-close" type="button" onclick="closeTermsModal()" aria-label="Close">&times;</button>
        <h2 id="termsTitle">{{ $settings->get('terms_title', 'Terms & Conditions') }}</h2>
        <div class="terms-content">{!! nl2br(e($settings->get('terms_body', "Please make sure your payment account number is correct. Payments are verified by SMS records and may take a short time to confirm. If verification is delayed, submit your transaction ID for manual review."))) !!}</div>
    </div>
</div>

<script>
let selectedSlug = null;
let selectedMethod = null;
let nagadLanguage = 'en';
const methodAssets = {
    bkash: '{{ asset('assets/img/bkash-logo-sm.png') }}',
    nagad: '{{ asset('assets/img/nagad-logo-sm.png') }}',
    rocket: '{{ asset('assets/img/rocket-logo-sm.png') }}',
};
const nagadText = {
    en: {
        invoice: 'Invoice No:',
        amount: 'Total Amount:',
        charge: 'Charge:',
        label: 'Your Nagad Account Number',
        terms: 'By clicking/tapping "Proceed" you are agreeing to our <button type="button" onclick="openTermsModal()">Terms and Conditions</button>',
        proceed: 'Proceed',
        close: 'Close',
    },
    bn: {
        invoice: '&#2458;&#2494;&#2482;&#2494;&#2472; &#2472;&#2434;:',
        amount: '&#2488;&#2480;&#2509;&#2476;&#2478;&#2507;&#2463; &#2474;&#2480;&#2495;&#2478;&#2494;&#2467;:',
        charge: '&#2458;&#2494;&#2480;&#2509;&#2460;:',
        label: '&#2438;&#2474;&#2472;&#2494;&#2480; &#2472;&#2455;&#2470; &#2437;&#2509;&#2479;&#2494;&#2453;&#2494;&#2441;&#2472;&#2509;&#2463; &#2472;&#2478;&#2509;&#2476;&#2480;',
        terms: '"&#2447;&#2455;&#2495;&#2527;&#2503; &#2479;&#2494;&#2472;" &#2453;&#2509;&#2482;&#2495;&#2453;/ &#2463;&#2509;&#2479;&#2494;&#2474; &#2453;&#2480;&#2494;&#2480; &#2478;&#2494;&#2471;&#2509;&#2479;&#2478;&#2503; &#2438;&#2474;&#2472;&#2495; &#2438;&#2478;&#2494;&#2470;&#2503;&#2480; <button type="button" onclick="openTermsModal()">&#2486;&#2480;&#2509;&#2468;&#2494;&#2476;&#2482;&#2496;&#2468;&#2503;</button> &#2488;&#2478;&#2509;&#2478;&#2468;&#2495; &#2470;&#2495;&#2458;&#2509;&#2459;&#2503;&#2472;&#2404;',
        proceed: '&#2447;&#2455;&#2495;&#2527;&#2503; &#2479;&#2494;&#2472;',
        close: '&#2476;&#2494;&#2468;&#2495;&#2482;',
    }
};
function setNagadLanguage(lang) {
    nagadLanguage = lang === 'bn' ? 'bn' : 'en';
    const toggles = document.querySelectorAll('.nagad-lang span');
    toggles.forEach((item, index) => item.classList.toggle('active', (nagadLanguage === 'bn' && index === 0) || (nagadLanguage === 'en' && index === 1)));
    if (selectedSlug !== 'nagad') return;
    const text = nagadText[nagadLanguage];
    const summaryLabels = document.querySelectorAll('.nagad-summary b');
    summaryLabels[0].innerHTML = text.invoice;
    summaryLabels[1].innerHTML = text.amount;
    summaryLabels[2].innerHTML = text.charge;
    senderScreenLabel.innerHTML = text.label;
    senderTerms.innerHTML = text.terms;
    senderCancelBtn.innerHTML = text.close;
    senderConfirmBtn.innerHTML = text.proceed;
}
document.querySelectorAll('.nagad-lang span').forEach((item, index) => {
    item.addEventListener('click', () => setNagadLanguage(index === 0 ? 'bn' : 'en'));
});
function selectMethod(id, slug, name, sendMoney, payment, remittance, el) {
    selectedSlug = slug;
    selectedMethod = {
        id, slug, name, sendMoney, payment, remittance,
        accountNames: {
            send_money: el?.dataset.accountNameSendMoney || 'Product Torongo Pay',
            payment: el?.dataset.accountNamePayment || 'Product Torongo Pay',
            remittance: el?.dataset.accountNameRemittance || 'Product Torongo Pay',
        },
        fees: {
            send_money: Number(el?.dataset.feeSendMoney || 0),
            payment: Number(el?.dataset.feePayment || 0),
            remittance: 0,
        },
        badgeUrl: el?.dataset.badgeUrl || ''
    };
    document.querySelectorAll('.method').forEach(btn => btn.classList.remove('selected'));
    if (el) el.classList.add('selected');
}
function chooseMethod(el) {
    selectMethod(
        Number(el.dataset.methodId),
        el.dataset.slug,
        el.dataset.name,
        el.dataset.sendMoney === 'true',
        el.dataset.payment === 'true',
        el.dataset.remittance === 'true',
        el
    );
    startPaymentFlow();
}
function startPaymentFlow() {
    if (!selectedMethod) return;
    const { id, slug, name, sendMoney, payment, remittance } = selectedMethod;
    payment_method_id.value = id;
    if (slug === 'binance') {
        method_option.value = 'binance';
        optionForm.submit();
        return;
    }
    optionButtons.innerHTML = '';
    const logo = methodAssets[slug] || methodAssets.bkash;
    const safeName = escapeHtml(name);
    if ((slug === 'bkash' || slug === 'nagad' || slug === 'rocket') && sendMoney) optionButtons.innerHTML += `<button class="option" type="button" onclick="openSenderScreen('send_money')"><img class="option-logo" src="${logo}" alt=""><span><span class="option-title">Send Money</span><span class="option-sub">${safeName}</span></span></button>`;
    if (slug === 'bkash' && payment) optionButtons.innerHTML += `<button class="option" type="button" onclick="openSenderScreen('payment')"><img class="option-logo" src="${logo}" alt=""><span><span class="option-title">Payment <i class="live-dot"></i></span><span class="option-sub">${safeName}</span></span></button>`;
    if ((slug === 'bkash' || slug === 'nagad') && remittance) optionButtons.innerHTML += `<button class="option" type="button" onclick="submitRemittance()"><img class="option-logo" src="${logo}" alt=""><span><span class="option-title">Remittance</span><span class="option-sub">${safeName}</span></span></button>`;
    if (!optionButtons.innerHTML.trim()) {
        optionButtons.innerHTML = '<div class="option-empty">No active option is available for this gateway.</div>';
    }
    optionModal.classList.add('show');
}
function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, char => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;',
    }[char]));
}
function openSenderScreen(option) {
    const { id, slug, name } = selectedMethod;
    selectedSlug = slug;
    optionModal.classList.remove('show');
    document.querySelector('.checkout').classList.add('hidden');
    sender_payment_method_id.value = id;
    sender_method_option.value = option;
    senderBrandLogo.src = methodAssets[slug] || methodAssets.bkash;
    senderBrandLogo.alt = name;
    senderColorScreen.className = `color-screen ${slug}`;
    senderScreen.classList.remove('nagad-sender');
    if (slug === 'nagad') senderScreen.classList.add('nagad-sender');
    senderScreenLabel.textContent = slug === 'nagad' ? 'Your Nagad Account Number' : `Enter your ${name} account number`;
    senderMerchantName.textContent = selectedMethod.accountNames?.[option] || 'Product Torongo Pay';
    nagadAccountName.textContent = selectedMethod.accountNames?.[option] || selectedMethod.name || 'Easy Payment System';
    senderProductBadge.classList.toggle('has-image', Boolean(selectedMethod.badgeUrl));
    senderProductBadge.innerHTML = selectedMethod.badgeUrl ? `<img src="${selectedMethod.badgeUrl}" alt="">` : 'EPS';
    senderDisplayAmount.innerHTML = '&#2547;' + payableAmount(option).toLocaleString('en-US', { maximumFractionDigits: 2 });
    const nagadAmount = document.querySelector('.nagad-summary p:nth-child(2) span');
    const nagadCharge = document.querySelector('.nagad-summary p:nth-child(3) span');
    if (nagadAmount) nagadAmount.textContent = 'BDT ' + payableAmount(option).toFixed(2);
    if (nagadCharge) nagadCharge.textContent = 'BDT ' + feeAmount(option).toFixed(2);
    senderInput.value = '';
    senderInput.maxLength = slug === 'rocket' ? 12 : 11;
    senderInput.placeholder = slug === 'rocket' ? 'e.g. 01XXXXXXXXXX' : 'e.g. 01XXXXXXXXX';
    senderInput.required = true;
    senderConfirmBtn.disabled = true;
    if (slug === 'nagad') {
        setNagadLanguage(nagadLanguage);
    } else {
        senderCancelBtn.textContent = 'Cancel';
        senderConfirmBtn.textContent = 'Confirm';
        senderTerms.innerHTML = 'By continuing, you agree to the <button type="button" onclick="openTermsModal()">terms &amp; conditions</button>';
    }
    updateSenderInput();
    senderScreen.classList.remove('hidden');
    setTimeout(() => senderInput.focus(), 80);
}
function feeAmount(option) {
    const base = Number(@json($checkoutBaseAmount));
    const percent = Number(selectedMethod?.fees?.[option] || 0);
    return Math.round(base * (percent / 100) * 100) / 100;
}
function payableAmount(option) {
    const base = Number(@json($checkoutBaseAmount));
    return Math.round((base + feeAmount(option)) * 100) / 100;
}
function closeSenderScreen() {
    senderScreen.classList.add('hidden');
    document.querySelector('.checkout').classList.remove('hidden');
    optionModal.classList.add('show');
}
function openTermsModal() {
    termsModal.classList.remove('hidden');
}
function closeTermsModal() {
    termsModal.classList.add('hidden');
}
function updateSenderInput() {
    const max = selectedSlug === 'rocket' ? 12 : 11;
    const requiredLength = selectedSlug === 'rocket' ? 12 : 11;
    senderInput.value = senderInput.value.replace(/[^0-9]/g, '').slice(0, max);
    senderConfirmBtn.disabled = senderInput.value.length !== requiredLength;
    if (selectedSlug === 'nagad') {
        const digits = nagadInputBox.querySelectorAll('.nagad-digit');
        const value = senderInput.value;
        digits.forEach((cell, index) => {
            cell.textContent = value[index] || '';
            cell.classList.toggle('active', index === value.length && value.length < digits.length && document.activeElement === senderInput);
        });
    }
}
senderForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (senderConfirmBtn.disabled) return;

    senderConfirmBtn.disabled = true;
    try {
        const response = await fetch(senderForm.action, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: new FormData(senderForm),
        });
        const data = await response.json();
        if (!response.ok || !data.redirect_url) {
            throw new Error(data.message || 'Payment could not continue.');
        }
        window.location.replace(data.redirect_url);
    } catch (error) {
        senderConfirmBtn.disabled = false;
        alert(error.message || 'Unable to continue this payment.');
    }
});
function submitRemittance() {
    method_option.value = 'remittance';
    optionForm.submit();
}
optionModal.addEventListener('click', e => { if (e.target === optionModal) optionModal.classList.remove('show') });
termsModal.addEventListener('click', e => { if (e.target === termsModal) closeTermsModal() });
document.querySelectorAll('.method').forEach(btn => btn.addEventListener('click', () => chooseMethod(btn)));
function setMethodCategory(category) {
    let visible = 0;
    const emptyBox = document.getElementById('emptyMethods');
    document.querySelectorAll('[data-category-tab]').forEach(btn => btn.classList.toggle('active', btn.dataset.categoryTab === category));
    document.querySelectorAll('.method').forEach(btn => {
        const show = btn.dataset.category === category;
        btn.classList.toggle('hidden', !show);
        if (show) visible += 1;
    });
    if (emptyBox) emptyBox.classList.toggle('hidden', visible > 0);
}
document.querySelectorAll('[data-category-tab]').forEach(btn => btn.addEventListener('click', () => setMethodCategory(btn.dataset.categoryTab)));
setMethodCategory('mobile');
</script>
@endsection
