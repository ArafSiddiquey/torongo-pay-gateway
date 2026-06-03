@extends('layouts.payment')
@section('content')
@php
    $method = $transaction->paymentMethod;
    $config = $method?->config ?? [];
    $isRemittance = $transaction->method_option === 'remittance';
    $isBinance = $transaction->method_option === 'binance';
    $isMobileVerifying = request()->boolean('verify') && ! $isRemittance && ! $isBinance;
    $hasRemittanceContact = $isRemittance && filled($transaction->customer_number);
    $amount = number_format($transaction->amount, 0);
    $accountName = ($config['account_names'][$transaction->method_option] ?? null) ?: ($config['checkout_product_title'] ?? 'Product Torongo Pay');
    $slug = $method?->slug ?: 'bkash';
    $successLogo = match ($slug) {
        'nagad' => asset('assets/img/nagad-logo-sm.png'),
        'rocket' => asset('assets/img/rocket-logo-sm.png'),
        'binance' => asset('assets/img/binance.png'),
        default => asset('assets/img/bkash-logo-sm.png'),
    };
    $successLogoAlt = $method?->name ?: 'bKash';
@endphp
<main class="processing-page">
    <section class="processing-card {{ ($isRemittance || $isBinance || $isMobileVerifying) ? 'verifying-card' : '' }}" id="processingCard">
        @if($isBinance)
            <div class="processing-loader binance-loader" aria-hidden="true"></div>
            <h1 id="processingTitle">Checking your payment</h1>
            <p id="verifyHelp">We are verifying your Binance Order ID. Please do not close this page.</p>
            <div class="processing-ok hidden" id="processingMessage"></div>
        @elseif($isRemittance)
            <div class="processing-loader" aria-hidden="true"></div>
            <h1>Verifying your payment</h1>
            <p id="verifyHelp">Please keep this page open while we check your payment proof.</p>
            @if(session('ok'))
                <div class="processing-ok">{{ session('ok') }}</div>
            @endif
        @elseif($isMobileVerifying)
            <div class="processing-loader" aria-hidden="true"></div>
            <p class="verify-only" id="verifyHelp">Verifying Your Payment . Please Wait</p>
        @else
            <div class="processing-emoji" aria-hidden="true">&#128233;</div>
            <h1>Your payment is on processing</h1>
            <p>We have received your request. You can close this page and wait for the confirmation SMS on your phone number.</p>
        @endif
    </section>
</main>
@if($isBinance)
<script>
const verifyStatusUrl = @json(route('payment.status', ['transaction' => $transaction->invoice_id, 'token' => $transaction->signed_token]));
const defaultSuccessRedirectUrl = @json($transaction->success_url ?: ($settings->get('success_redirect_url') ?: url('/')));
const defaultFailedRedirectUrl = @json($transaction->failed_url ?: null);
let binanceFinished = false;

function showBinanceMessage(message, failed = false) {
    const box = document.getElementById('processingMessage');
    if (!box) return;
    box.textContent = message;
    box.className = failed ? 'processing-ok processing-failed' : 'processing-ok';
}

function renderBinanceSuccess(redirectUrl) {
    if (binanceFinished) return;
    binanceFinished = true;

    setTimeout(() => {
    const card = document.getElementById('processingCard');
    card.className = 'success-receipt-card success-receipt-{{ $slug }}';
    card.innerHTML = `
        <div class="success-verified-banner"><span>âœ“</span> Payment verified!</div>
        <div class="success-receipt-top">
            <img class="success-brand-logo success-brand-{{ $slug }}" src="{{ $successLogo }}" alt="{{ $successLogoAlt }}">
            <div class="success-product">
                <span class="success-product-icon">EPS</span>
                <span>
                    <b>{{ $accountName }}</b>
                    <small>Invoice ID: {{ $transaction->invoice_id }}</small>
                </span>
                <strong>à§³{{ $amount }}</strong>
            </div>
        </div>
        <div class="success-panel success-panel-{{ $slug }}">
            <div class="success-check">âœ“</div>
            <h1>Payment Successful</h1>
            <p>Your payment of à§³{{ $amount }} has been confirmed.</p>
            <div class="success-redirect-box">
                <span>Redirecting to merchant website in</span>
                <strong id="successRedirectCountdown">5</strong>
                <em>SECONDS</em>
            </div>
            <p class="success-note">Please do not close this page.</p>
        </div>
    `;

    let left = 5;
    const countdown = document.getElementById('successRedirectCountdown');
    const timer = setInterval(() => {
        left -= 1;
        if (countdown) countdown.textContent = String(Math.max(left, 0));
        if (left <= 0) {
            clearInterval(timer);
            window.location.href = redirectUrl || defaultSuccessRedirectUrl;
        }
    }, 1000);
    }, 1000);
}

async function pollBinanceStatus() {
    if (binanceFinished) return;

    try {
        const response = await fetch(verifyStatusUrl, { headers: { Accept: 'application/json' } });
        const data = await response.json();

        if (data.status === 'success') {
            renderBinanceSuccess(data.redirect_url);
            return;
        }

        if (data.binance_result === 'underpaid') {
            binanceFinished = true;
            showBinanceMessage('The received amount is lower than required. Please contact the merchant.', true);
            return;
        }

        if (data.binance_result === 'duplicate') {
            binanceFinished = true;
            showBinanceMessage('This Binance Order ID has already been used. Please contact the merchant.', true);
            if (defaultFailedRedirectUrl) setTimeout(() => window.location.href = defaultFailedRedirectUrl, 2500);
            return;
        }

        if (data.status !== 'pending') {
            binanceFinished = true;
            showBinanceMessage('Payment verification failed. Please contact the merchant.', true);
            if (data.redirect_url) setTimeout(() => window.location.href = data.redirect_url, 2500);
            return;
        }
    } catch (error) {}

    setTimeout(pollBinanceStatus, 3000);
}

pollBinanceStatus();
</script>
@elseif($isRemittance)
<script>
const verifyStartedAt = Date.now();
const verifyFallbackAfter = 5 * 60 * 1000;
const hasRemittanceContact = @json($hasRemittanceContact);
const verifyStatusUrl = @json(route('payment.status', ['transaction' => $transaction->invoice_id, 'token' => $transaction->signed_token]));
const verifyInstructionsUrl = @json(route('payment.instructions', ['transaction' => $transaction->invoice_id, 'token' => $transaction->signed_token]));
const defaultSuccessRedirectUrl = @json($transaction->success_url ?: ($settings->get('success_redirect_url') ?: url('/')));
let successRendered = false;

function renderRemittanceSuccess() {
    if (successRendered) return;
    successRendered = true;

    setTimeout(() => {
    const card = document.getElementById('processingCard');
    card.className = 'success-receipt-card success-receipt-{{ $slug }}';
    card.innerHTML = `
        <div class="success-verified-banner"><span>âœ“</span> Payment verified!</div>
        <div class="success-receipt-top">
            <img class="success-brand-logo success-brand-{{ $slug }}" src="{{ $successLogo }}" alt="{{ $successLogoAlt }}">
            <div class="success-product">
                <span class="success-product-icon">EPS</span>
                <span>
                    <b>{{ $accountName }}</b>
                    <small>Invoice ID: {{ $transaction->invoice_id }}</small>
                </span>
                <strong>à§³{{ $amount }}</strong>
            </div>
        </div>
        <div class="success-panel success-panel-{{ $slug }}">
            <div class="success-check">âœ“</div>
            <h1>Payment Successful</h1>
            <p>Your payment of à§³{{ $amount }} has been confirmed.</p>
            <div class="success-redirect-box">
                <span>Redirecting to merchant website in</span>
                <strong id="successRedirectCountdown">5</strong>
                <em>SECONDS</em>
            </div>
            <p class="success-note">Please do not close this page.</p>
        </div>
    `;

    let left = 5;
    const countdown = document.getElementById('successRedirectCountdown');
    const timer = setInterval(() => {
        left -= 1;
        if (countdown) countdown.textContent = String(Math.max(left, 0));
        if (left <= 0) {
            clearInterval(timer);
            window.location.href = defaultSuccessRedirectUrl;
        }
    }, 1000);
    }, 1000);
}

function showWhatsappFallback() {
    const help = document.getElementById('verifyHelp');
    if (help) {
        help.textContent = 'Payment Is On Processing. You Will get an update On Your Whatsapp number.';
    }
}

async function pollRemittanceStatus() {
    if (Date.now() - verifyStartedAt >= verifyFallbackAfter) {
        showWhatsappFallback();
        return;
    }

    try {
        const response = await fetch(verifyStatusUrl, { headers: { Accept: 'application/json' } });
        const data = await response.json();
        if (data.status === 'success') {
            window.location.href = verifyInstructionsUrl;
            return;
        }
    } catch (error) {}

    setTimeout(pollRemittanceStatus, 4000);
}

pollRemittanceStatus();
</script>
@elseif($isMobileVerifying)
<script>
const verifyStatusUrl = @json(route('payment.status', ['transaction' => $transaction->invoice_id, 'token' => $transaction->signed_token]));
const defaultSuccessRedirectUrl = @json($transaction->success_url ?: ($settings->get('success_redirect_url') ?: url('/')));
const fallbackAfterMs = 1000;
const startedAt = Date.now();
let finished = false;
let processingShown = false;

function renderMobileSuccess(redirectUrl) {
    if (finished) return;
    finished = true;

    setTimeout(() => {
    const card = document.getElementById('processingCard');
    card.className = 'success-receipt-card success-receipt-{{ $slug }}';
    card.innerHTML = `
        <div class="success-verified-banner"><span>&#10003;</span> Payment verified!</div>
        <div class="success-receipt-top">
            <img class="success-brand-logo success-brand-{{ $slug }}" src="{{ $successLogo }}" alt="{{ $successLogoAlt }}">
            <div class="success-product">
                <span class="success-product-icon">EPS</span>
                <span>
                    <b>{{ $accountName }}</b>
                    <small>Invoice ID: {{ $transaction->invoice_id }}</small>
                </span>
                <strong>&#2547;{{ $amount }}</strong>
            </div>
        </div>
        <div class="success-panel success-panel-{{ $slug }}">
            <div class="success-check">&#10003;</div>
            <h1>Payment Successful</h1>
            <p>Your payment of &#2547;{{ $amount }} has been confirmed.</p>
            <div class="success-redirect-box">
                <span>Redirecting to merchant website in</span>
                <strong id="successRedirectCountdown">5</strong>
                <em>SECONDS</em>
            </div>
            <p class="success-note">Please do not close this page.</p>
        </div>
    `;

    let left = 5;
    const countdown = document.getElementById('successRedirectCountdown');
    const timer = setInterval(() => {
        left -= 1;
        if (countdown) countdown.textContent = String(Math.max(left, 0));
        if (left <= 0) {
            clearInterval(timer);
            window.location.href = redirectUrl || defaultSuccessRedirectUrl;
        }
    }, 1000);
    }, 1000);
}

function renderProcessingMessage() {
    if (finished || processingShown) return;
    processingShown = true;

    const card = document.getElementById('processingCard');
    card.className = 'processing-card';
    card.innerHTML = `
        <div class="processing-emoji" aria-hidden="true">&#128233;</div>
        <h1>Your payment is on processing</h1>
        <p>We have received your request. You can close this page and wait for the confirmation SMS on your phone number.</p>
    `;
}

async function pollMobileStatus() {
    if (finished) return;

    try {
        const response = await fetch(verifyStatusUrl, { headers: { Accept: 'application/json' } });
        const data = await response.json();

        if (data.status === 'success') {
            renderMobileSuccess(data.redirect_url);
            return;
        }
    } catch (error) {}

    if (Date.now() - startedAt >= fallbackAfterMs) {
        renderProcessingMessage();
    }

    setTimeout(pollMobileStatus, processingShown ? 4000 : 2000);
}

pollMobileStatus();
</script>
@endif
@endsection
