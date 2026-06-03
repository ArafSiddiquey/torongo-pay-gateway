@extends('layouts.payment')
@section('content')
@php
    $method = $transaction->paymentMethod;
    $slug = $method?->slug ?: 'bkash';
    $option = $transaction->method_option ?: 'send_money';
    $isBkash = $slug === 'bkash';
    $isNagad = $slug === 'nagad';
    $isRocket = $slug === 'rocket';
    $isBinance = $slug === 'binance';
    $isRemittance = $option === 'remittance';
    $hasManualVerifyFlow = ! $isRemittance && ! $isBinance;
    $isModernInstruction = ($isBkash || $isNagad) && ! $isBinance;
    $usesSplitInstruction = $isModernInstruction || ($isRocket && ! $isRemittance && ! $isBinance) || $isBinance;
    $config = $method?->config ?? [];
    $optionNumbers = $config['option_numbers'] ?? [];
    $optionQrs = $config['option_qr_paths'] ?? [];
    $firstOptionQr = collect($optionQrs)->filter()->first();

    $displayNumber = $optionNumbers[$option] ?? ($isRemittance
        ? ($method?->remittance_number ?: $method?->payment_number)
        : $method?->payment_number);
    $qrPath = $optionQrs[$option] ?? $method?->qr_path ?? $firstOptionQr;

    if ($isBinance) {
        $displayNumber = $config['binance_uid'] ?? $method?->payment_number;
        $qrPath = $method?->qr_path;
    }

    if ($isNagad) {
        $qrPath = null;
    }

    $brand = strtoupper($method?->name ?: 'BKASH');
    $dial = ['bkash' => '*247#', 'nagad' => '*167#', 'rocket' => '*322#'][$slug] ?? '*247#';
    $action = $option === 'payment' ? 'PAYMENT' : 'Send Money';
    $payableAmountValue = $isRemittance
        ? (float) ($transaction->metadata['remittance_payable_amount'] ?? $transaction->amount)
        : (float) $transaction->amount;
    $amount = number_format($payableAmountValue, 0);
    $productAmount = number_format($transaction->amount, 0);
    $accountNames = $config['account_names'] ?? [];
    $accountName = $accountNames[$option] ?? $config['checkout_product_title'] ?? 'Product Torongo Pay';
    $normalInstruction = "Dial {$dial} or open the {$brand} app, then use {$action}. Send exactly {$amount} BDT to the account shown below.";
    $failedText = 'Payment Failed or Session Expired';
    $supportUrl = $settings->get('support_contact', '#support') ?: '#support';
    $binanceAsset = strtoupper($config['binance_asset'] ?? 'USDT');
    $binanceRate = max((float) ($config['binance_exchange_rate'] ?? 130), 0.000001);
    $binanceAmountValue = floor((((float) $transaction->amount) / $binanceRate) * 100) / 100;
    $binanceAmount = number_format($binanceAmountValue, 2, '.', '');
    $totalSeconds = max((int) $settings->get('countdown_minutes', '15') * 60, 1);
    $manualButtonDelaySeconds = max((int) $settings->get('manual_verify_delay_minutes', '1') * 60, 0);
    $successLogo = match ($slug) {
        'nagad' => asset('assets/img/nagad-logo-sm.png'),
        'rocket' => asset('assets/img/rocket-logo-sm.png'),
        'binance' => asset('assets/img/binance.png'),
        default => asset('assets/img/bkash-logo-sm.png'),
    };
    $successLogoAlt = $method?->name ?: 'bKash';
@endphp

<main class="instruction">
    <section class="instruction-card" id="instructionCard">
        <div class="instruction-head {{ $slug }}">
            @if($isModernInstruction)
                <a class="instruction-back" href="{{ route('payment.show', ['transaction' => $transaction->invoice_id, 'token' => $transaction->signed_token]) }}" aria-label="Back">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 5L8 12l7 7"/><path d="M9 12h11"/></svg>
                </a>
            @endif
            <div class="check">✓</div>
            @if($isModernInstruction)
                <div class="instruction-status-head">
                    <h2 id="payTitle">Waiting for Payment</h2>
                </div>
                <p class="instruction-text hidden" id="instructionText"></p>
            @else
                <h2 id="payTitle">{{ $isBinance ? 'Binance Payment Instruction' : ($isRemittance ? 'Remittance Verification' : 'Payment Instruction') }}</h2>
            @endif

            @if(! $isBkash && $isBinance)
                <p class="instruction-text" id="instructionText">Submit your Binance Order ID after payment. We will match the order and amount automatically.</p>
            @elseif(! $isBkash && $isRemittance && ! $isNagad)
                <p class="instruction-text" id="instructionText">Use the payment number or QR code below, then submit your payment proof.</p>
            @elseif(! $isModernInstruction)
                <p class="instruction-text" id="instructionText">{{ $normalInstruction }}</p>
            @endif
        </div>

        <div class="body-pad {{ $usesSplitInstruction ? 'bkash-instruction-body' : '' }} {{ $isRocket ? 'rocket-split-instruction' : '' }} {{ $isNagad ? 'no-qr-instruction' : '' }} {{ $isBinance ? 'binance-instruction-body' : '' }}">
            @if(session('ok'))<div class="alert">{{ session('ok') }}</div>@endif
            @if($errors->any())<div class="errors">{{ $errors->first() }}</div>@endif

            @if($isNagad)
                <div class="nagad-steps" aria-label="Nagad send money instructions">
                    @if($isRemittance)
                        <div class="nagad-step remittance-number-step">
                            <span class="nagad-dot"></span>
                            <p>Send Remittance To this number: <b id="payNumber">{{ $displayNumber }}</b></p>
                            <button class="nagad-copy" onclick="copyPayNumber(this)" type="button">Copy</button>
                        </div>
                    @else
                        <div class="nagad-step">
                            <span class="nagad-dot"></span>
                            <p>Go to your <b>Nagad Mobile App</b>.</p>
                        </div>
                        <div class="nagad-step">
                            <span class="nagad-dot"></span>
                            <p>Choose: <b>Send Money</b>.</p>
                        </div>
                        <div class="nagad-step">
                            <span class="nagad-dot"></span>
                            <p>Enter the Number: <b id="payNumber">{{ $displayNumber }}</b></p>
                            <button class="nagad-copy" onclick="copyPayNumber(this)" type="button">Copy</button>
                        </div>
                        <div class="nagad-step">
                            <span class="nagad-dot"></span>
                            <p>Enter the Amount: <b>{{ $amount }} BDT</b></p>
                        </div>
                        <div class="nagad-step">
                            <span class="nagad-dot"></span>
                            <p>Now enter your <b>Nagad PIN</b> to confirm.</p>
                        </div>
                    @endif
                </div>
            @else
                <div id="payCopyRow" class="copy-row {{ $isRemittance ? 'remittance-copy-row' : '' }}">
                    @if($accountName)
                        <span class="account-label-name">({{ $accountName }})</span>
                    @endif
                    <strong id="payNumber">{{ $displayNumber }}</strong>
                    <button class="copy" onclick="copyPayNumber(this)" type="button">Copy</button>
                </div>
            @endif

            @unless($isNagad)
            <div id="qrWrap">
                @if($method?->qr_enabled && $qrPath)
                    <img class="qr" src="{{ asset('storage/'.$qrPath) }}" alt="Payment QR">
                @else
                    <div class="qr-placeholder"><span></span></div>
                @endif
            </div>
            @endunless

            @unless($isNagad)
                <div class="row"><span>Amount</span><b>{{ $isBinance ? $binanceAmount.' '.$binanceAsset : $amount.' BDT' }}</b></div>
            @endunless
            @if($usesSplitInstruction)
                <div class="circle-timer" id="circleTimer" data-total="{{ $totalSeconds }}">
                    <svg viewBox="0 0 140 140" aria-hidden="true">
                        <circle class="circle-track" cx="70" cy="70" r="60" pathLength="100"></circle>
                        <circle class="circle-progress" cx="70" cy="70" r="60" pathLength="100"></circle>
                        <circle class="circle-dot" cx="70" cy="10" r="4"></circle>
                    </svg>
                    <span id="circleTimerText">--:--</span>
                </div>
            @endif
            @unless($usesSplitInstruction || $isRemittance)
                <div class="timer" id="timer">--:--</div>
            @endunless
            <div id="statusBox" class="status {{ $usesSplitInstruction ? 'hidden' : '' }}">Waiting for payment</div>
            <div id="dueNotice" class="due-notice hidden" role="status"></div>

            @if($hasManualVerifyFlow)
                <button id="alreadyPaidBtn" class="ghost hidden" type="button" onclick="toggleManualBox()">Already paid? Click here <span class="link-arrow">›</span></button>
            @endif

            <form id="manualBox" class="proof {{ $hasManualVerifyFlow ? 'hidden' : '' }}" method="post" enctype="multipart/form-data" action="{{ route('payment.manual', ['transaction'=>$transaction->invoice_id,'token'=>$transaction->signed_token]) }}">
                @csrf
                @if($isBinance)
                    <label>Binance Order ID</label>
                    <input name="trx_id" required placeholder="Enter Binance Order ID">
                    <button>Submit Order ID</button>
                @elseif($isRemittance)
                    <label>Payment Proof</label>
                    <input type="file" name="payment_proof" accept="image/*" required>
                    <label style="margin-top:10px">WhatsApp Number</label>
                    <input name="customer_number" inputmode="tel" autocomplete="tel" placeholder="e.g. +123456789" required>
                    <button>Submit Proof</button>
                @else
                    <p class="manual-hint">If you already paid but automatic verification is delayed, submit your payment TrxID or bank name and payment account number.</p>
                    <label>Transaction ID / Bank name</label>
                    <input id="manualTrxInput" class="uppercase-input" name="trx_id" required placeholder="Transaction ID / Bank name (ex: EASTERN BANK, BRAC BANK, PUBALI BANK)" autocomplete="off" autocapitalize="characters" oninput="this.value=this.value.toUpperCase()">
                    <label style="margin-top:10px">Customer Number</label>
                    <input name="customer_number" type="text" inputmode="tel" autocomplete="tel" value="{{ $transaction->customer_number }}" placeholder="e.g. 01XXXXXXXXX" required>
                    <button>Submit</button>
                @endif
            </form>
        </div>
    </section>

    <template id="successTemplate">
        <div class="success-verified-banner"><span>✓</span> Payment verified!</div>
        <div class="success-receipt-top">
            <img class="success-brand-logo success-brand-{{ $slug }}" src="{{ $successLogo }}" alt="{{ $successLogoAlt }}">
            <div class="success-product">
                <span class="success-product-icon">
                    @if(!empty($config['checkout_badge_image']))
                        <img src="{{ asset('storage/'.$config['checkout_badge_image']) }}" alt="">
                    @else
                        EPS
                    @endif
                </span>
                <span>
                    <b>{{ $accountName }}</b>
                    <small>Invoice ID: {{ $transaction->invoice_id }}</small>
                </span>
                <strong>&#2547;{{ $productAmount }}</strong>
            </div>
        </div>
        <div class="success-panel success-panel-{{ $slug }}">
            <div class="success-check">✓</div>
            <h1>Payment Successful</h1>
            <p>Your payment of &#2547;{{ $productAmount }} has been confirmed.</p>
            <div class="success-redirect-box">
                <span>Redirecting to merchant website in</span>
                <strong id="successRedirectCountdown">5</strong>
                <em>SECONDS</em>
            </div>
            <p class="success-note">Please do not close this page.</p>
        </div>
    </template>
</main>

<script>
const expiresAt = new Date(@json($transaction->expires_at?->toIso8601String())).getTime();
const isRemittance = @json($isRemittance);
const isBinance = @json($isBinance);
const failedText = @json($failedText);
const supportUrl = @json($supportUrl);
const amountText = @json($amount);
const defaultSuccessRedirectUrl = @json($transaction->success_url ?: ($settings->get('success_redirect_url') ?: url('/')));
const manualHoldUrl = @json(route('payment.hold', ['transaction'=>$transaction->invoice_id,'token'=>$transaction->signed_token]));
const csrfToken = @json(csrf_token());
let successRendered = false;
let expiredRendered = false;
let successDelayStarted = false;
let timerInterval = null;
let manualHold = @json(($transaction->metadata['manual_hold'] ?? false) === true);
const manualButtonDelayMs = @json($manualButtonDelaySeconds * 1000);

function copyTextFallback(text) {
    const input = document.createElement('textarea');
    input.value = text;
    input.setAttribute('readonly', '');
    input.style.position = 'fixed';
    input.style.opacity = '0';
    document.body.appendChild(input);
    input.select();
    document.execCommand('copy');
    input.remove();
}

function showCopyToast() {
    const stack = document.getElementById('copyToastStack');
    if (!stack) return;
    const toast = document.createElement('div');
    toast.className = 'copy-toast';
    toast.setAttribute('role', 'status');
    toast.innerHTML = '<span aria-hidden="true">✓</span><strong>Copied!</strong>';
    stack.prepend(toast);
    requestAnimationFrame(() => toast.classList.add('show'));
    setTimeout(() => {
        toast.classList.add('hide');
        toast.addEventListener('transitionend', () => toast.remove(), { once: true });
    }, 1600);
}

async function copyPayNumber(button) {
    const text = document.getElementById('payNumber').textContent.trim();
    try {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);
        } else {
            copyTextFallback(text);
        }
        showCopyToast();
    } catch (error) {
        copyTextFallback(text);
        showCopyToast();
    }
}

setTimeout(() => {
    const alreadyPaidBtn = document.getElementById('alreadyPaidBtn');
    if (alreadyPaidBtn && !successRendered && !expiredRendered) {
        alreadyPaidBtn.classList.remove('hidden');
        if (window.matchMedia('(max-width: 760px)').matches) {
            requestAnimationFrame(() => {
                alreadyPaidBtn.scrollIntoView({ behavior: 'smooth', block: 'center' });
            });
        }
    }
}, manualButtonDelayMs);

function toggleManualBox() {
    const manualBox = document.getElementById('manualBox');
    if (!manualBox) return;
    const willOpen = manualBox.classList.contains('hidden');
    manualBox.classList.toggle('hidden');
    if (willOpen) {
        markManualHold();
        requestAnimationFrame(() => {
            manualBox.scrollIntoView({ behavior: 'smooth', block: 'start' });
            const firstInput = manualBox.querySelector('input');
            if (firstInput && window.matchMedia('(min-width: 761px)').matches) firstInput.focus({ preventScroll: true });
        });
    }
}

function markManualHold() {
    if (manualHold || isRemittance || isBinance) return;
    manualHold = true;
    fetch(manualHoldUrl, {
        method: 'POST',
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken,
        },
        body: JSON.stringify({ hold: true }),
    }).catch(() => {});
}

function renderTimer() {
    if (successRendered || expiredRendered) return;
    const left = Math.max(0, Math.floor((expiresAt - Date.now()) / 1000));
    const time = String(Math.floor(left / 60)).padStart(2, '0') + ':' + String(left % 60).padStart(2, '0');
    const circleTimerText = document.getElementById('circleTimerText');
    const circleTimer = document.getElementById('circleTimer');
    const circleProgress = circleTimer?.querySelector('.circle-progress');
    const circleDot = circleTimer?.querySelector('.circle-dot');
    if (circleTimerText) circleTimerText.textContent = time;
    if (circleTimer && circleProgress) {
        const total = Math.max(Number(circleTimer.dataset.total || 1), 1);
        const percent = Math.max(0, Math.min(100, (left / total) * 100));
        circleProgress.style.strokeDashoffset = String(100 - percent);
        if (circleDot) {
            const angle = ((100 - percent) / 100) * 360 - 90;
            const radians = angle * Math.PI / 180;
            const x = 70 + 60 * Math.cos(radians);
            const y = 70 + 60 * Math.sin(radians);
            circleDot.setAttribute('cx', x.toFixed(2));
            circleDot.setAttribute('cy', y.toFixed(2));
        }
    }
    document.querySelectorAll('#timer').forEach(timerEl => {
        const timerText = timerEl.querySelector('.timer-text');
        if (timerText) timerText.textContent = time;
        else timerEl.textContent = time;
    });
    if (left <= 0 && !isRemittance && !manualHold) renderExpired();
}

function renderExpired() {
    if (successRendered || expiredRendered) return;
    expiredRendered = true;
    if (timerInterval) clearInterval(timerInterval);
    const title = document.getElementById('payTitle');
    if (title) title.textContent = 'Payment Failed';
    statusBox.className = 'status failed';
    statusBox.textContent = failedText;
    const manual = document.getElementById('manualBox');
    if (manual) manual.classList.add('hidden');
    const alreadyPaidBtn = document.getElementById('alreadyPaidBtn');
    if (alreadyPaidBtn) alreadyPaidBtn.classList.add('hidden');
}

function renderSuccess(j) {
    if (successRendered) return;
    successRendered = true;
    if (timerInterval) clearInterval(timerInterval);
    instructionCard.className = 'success-receipt-card success-receipt-{{ $slug }}';
    instructionCard.innerHTML = document.getElementById('successTemplate').innerHTML;

    const redirectUrl = j.redirect_url || defaultSuccessRedirectUrl;
    let left = 5;
    const countdown = document.getElementById('successRedirectCountdown');
    if (countdown) countdown.textContent = String(left);
    const redirectTimer = setInterval(() => {
        left -= 1;
        if (countdown) countdown.textContent = String(Math.max(left, 0));
        if (left <= 0) {
            clearInterval(redirectTimer);
            location.href = redirectUrl;
        }
    }, 1000);
}

function renderSuccessAfterVerifying(j) {
    if (successRendered || successDelayStarted || expiredRendered) return;
    successDelayStarted = true;
    if (timerInterval) clearInterval(timerInterval);

    instructionCard.className = 'processing-card verifying-card';
    instructionCard.innerHTML = `
        <div class="processing-loader" aria-hidden="true"></div>
        <p class="verify-only">Verifying Your Payment . Please Wait</p>
    `;

    setTimeout(() => renderSuccess(j), 1000);
}

function showDueNotice(j) {
    const notice = document.getElementById('dueNotice');
    if (!notice || !j.is_partially_paid) return;
    const paid = Number(j.paid_amount || 0).toFixed(2);
    const required = Number(j.required_amount || 0).toFixed(2);
    const due = Number(j.due_amount || 0).toFixed(2);
    notice.textContent = `You Paid ${paid} BDT only. But the amount is ${required} BDT, Please pay ${due} BDT more now.`;
    notice.classList.remove('hidden');
    if (window.matchMedia('(max-width: 760px)').matches) {
        requestAnimationFrame(() => notice.scrollIntoView({ behavior: 'smooth', block: 'center' }));
    }
}

document.querySelectorAll('.uppercase-input').forEach(input => {
    input.addEventListener('input', () => {
        input.value = input.value.toUpperCase();
    });
});

function autoScrollBinanceMobile() {
    if (!isBinance || successRendered || expiredRendered) return;
    if (!window.matchMedia('(max-width: 760px)').matches) return;

    const manual = document.getElementById('manualBox');
    if (!manual) return;
    setTimeout(() => {
        if (!successRendered && !expiredRendered) {
            manual.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }, 1300);
}

async function poll() {
    if (successRendered || successDelayStarted || expiredRendered) return;
    const r = await fetch(@json(route('payment.status', ['transaction'=>$transaction->invoice_id,'token'=>$transaction->signed_token])));
    const j = await r.json();

    if (j.status === 'success') {
        renderSuccessAfterVerifying(j);
        return;
    }

    if (j.is_partially_paid) {
        showDueNotice(j);
    }

    if (j.binance_result === 'underpaid') {
        statusBox.className = 'status failed';
        statusBox.textContent = 'The received amount is lower than required. Please contact the merchant.';
        setTimeout(poll, 6000);
        return;
    }

    if (j.status !== 'pending') {
        statusBox.className = 'status failed';
        statusBox.textContent = failedText;
        if (j.redirect_url) setTimeout(() => location.href = j.redirect_url, 1200);
        return;
    }

    setTimeout(poll, 4000);
}

renderTimer();
timerInterval = setInterval(renderTimer, 1000);
autoScrollBinanceMobile();
poll();

</script>
@endsection
