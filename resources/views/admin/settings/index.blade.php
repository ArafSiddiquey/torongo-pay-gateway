@extends('layouts.admin')
@section('content')
<div class="page-head">
    <div>
        <h1>Gateway Setup</h1>
        <p class="hint">Configure the public checkout, redirect behavior, security keys and SMS parsing defaults.</p>
    </div>
</div>

<div class="setup-note">
    Local test mode: keep the URLs as <b>http://sms-semi-auto-gateway.test</b>. During hosting deployment these will be changed to your real domain.
</div>

<form class="form" method="post" action="{{ route('admin.settings.save') }}" enctype="multipart/form-data">
    @csrf
    <div class="setup-sections">
        <section class="section-card">
            <h2>Business Identity</h2>
            <p class="hint">These values are shown to customers on the gateway page.</p>
            <div class="form-grid">
                <div class="field">
                    <label>Gateway / brand name</label>
                    <input name="gateway_name" value="{{ old('gateway_name', $settings['gateway_name'] ?? 'Torongo Pay') }}" placeholder="Your Shop Pay">
                </div>
                <div class="field full">
                    <label>Invoice logo</label>
                    <input name="invoice_logo_file" type="file" accept="image/png,image/jpeg,image/webp,image/svg+xml">
                    @if($invoiceLogo)
                        <div class="current-upload">
                            <span class="current-upload-thumb">
                                <img src="{{ $invoiceLogo['url'] }}" alt="Current invoice logo">
                            </span>
                            <span>
                                <b>Current image uploaded</b>
                                <small>{{ $invoiceLogo['name'] }}</small>
                            </span>
                        </div>
                    @else
                        <div class="current-upload current-upload-empty">No custom image uploaded. Torongo Pay logo will be used.</div>
                    @endif
                    <p class="hint">Leave blank to use Torongo Pay logo. Upload PNG, JPG, WebP or SVG.</p>
                </div>
                <div class="field full">
                    <label>Invoice From details</label>
                    <textarea name="invoice_from_details" rows="5">{{ old('invoice_from_details', $settings['invoice_from_details'] ?? "Torongo Pay\nBangladesh\nsupport@example.com") }}</textarea>
                </div>
            </div>
        </section>

        <section class="section-card">
            <h2>Payment Window & Redirect</h2>
            <p class="hint">Customer waits during this window. After success/failure, customer returns to merchant URL.</p>
            <div class="form-grid">
                <div class="field">
                    <label>Countdown minutes</label>
                    <input name="countdown_minutes" type="number" min="1" max="60" value="{{ old('countdown_minutes', $settings['countdown_minutes'] ?? '15') }}">
                </div>
                <div class="field">
                    <label>Already paid button delay (minutes)</label>
                    <input name="manual_verify_delay_minutes" type="number" min="0" max="60" value="{{ old('manual_verify_delay_minutes', $settings['manual_verify_delay_minutes'] ?? '1') }}">
                </div>
                <div class="field">
                    <label>Remittance concurrent extra amount</label>
                    <input name="remittance_concurrent_extra_amount" type="number" min="0" max="500" step="1" value="{{ old('remittance_concurrent_extra_amount', $settings['remittance_concurrent_extra_amount'] ?? '20') }}">
                </div>
                <div class="field">
                    <label>Webhook secret</label>
                    <input name="webhook_secret" value="{{ old('webhook_secret', $settings['webhook_secret'] ?? '') }}" placeholder="Keep this private">
                </div>
                <div class="field full">
                    <label>Success redirect URL</label>
                    <input name="success_redirect_url" value="{{ old('success_redirect_url', $settings['success_redirect_url'] ?? 'http://sms-semi-auto-gateway.test/payment/success') }}">
                </div>
                <div class="field full">
                    <label>Failed redirect URL</label>
                    <input name="failed_redirect_url" value="{{ old('failed_redirect_url', $settings['failed_redirect_url'] ?? 'http://sms-semi-auto-gateway.test/payment/failed') }}">
                </div>
            </div>
        </section>

        <section class="section-card">
            <h2>Customer Terms & Conditions</h2>
            <p class="hint">Shown when customer taps the terms link on the account number input page.</p>
            <div class="form-grid">
                <div class="field full">
                    <label>Terms title</label>
                    <input name="terms_title" value="{{ old('terms_title', $settings['terms_title'] ?? 'Terms & Conditions') }}">
                </div>
                <div class="field full">
                    <label>Terms text</label>
                    <textarea name="terms_body" rows="6">{{ old('terms_body', $settings['terms_body'] ?? "Please make sure your payment account number is correct. Payments are verified by SMS records and may take a short time to confirm. If verification is delayed, submit your transaction ID for manual review.") }}</textarea>
                </div>
            </div>
        </section>

        <section class="section-card">
            <h2>Google Sheet Backup</h2>
            <p class="hint">Optional for local testing. Later we will paste Apps Script webhook URL here.</p>
            <div class="form-grid">
                <div class="field full">
                    <label>Google Sheet webhook URL</label>
                    <input name="google_sheet_webhook_url" value="{{ old('google_sheet_webhook_url', $settings['google_sheet_webhook_url'] ?? '') }}" placeholder="https://script.google.com/...">
                </div>
                <div class="field full">
                    <label>Google Sheet secret</label>
                    <input name="google_sheet_secret" value="{{ old('google_sheet_secret', $settings['google_sheet_secret'] ?? '') }}" placeholder="Private sheet secret">
                </div>
            </div>
        </section>

        <section class="section-card">
            <h2>Customer SMS Notification</h2>
            <p class="hint">Torongo Verify sends these SMS messages from the Android phone SIM when its SMS sending toggle is on.</p>
            <div class="form-grid">
                @php($smsDeliveryScope = old('payment_sms_delivery_scope', $settings['payment_sms_delivery_scope'] ?? 'processing_only'))
                <div class="field full">
                    <label>SMS sending rule</label>
                    <div class="radio-stack">
                        <label>
                            <input type="radio" name="payment_sms_delivery_scope" value="processing_only" @checked($smsDeliveryScope !== 'all_successful')>
                            <span>
                                <b>Only processing fallback customers</b>
                                <small>Send SMS only when customer used Already paid and got processing state.</small>
                            </span>
                        </label>
                        <label>
                            <input type="radio" name="payment_sms_delivery_scope" value="all_successful" @checked($smsDeliveryScope === 'all_successful')>
                            <span>
                                <b>All successful mobile payments</b>
                                <small>Send SMS for every successful bKash, Nagad and Rocket payment.</small>
                            </span>
                        </label>
                    </div>
                </div>
                <div class="field">
                    <label>SMS Brand Name</label>
                    <input name="payment_sms_brand" value="{{ old('payment_sms_brand', $settings['payment_sms_brand'] ?? 'BanglaLicense') }}">
                </div>
                <div class="field full">
                    <label>SMS Contact URL</label>
                    <input name="payment_sms_contact_url" value="{{ old('payment_sms_contact_url', $settings['payment_sms_contact_url'] ?? 'https://wa.me/8801882398668') }}">
                </div>
                <div class="field full">
                    <label>Support phone number</label>
                    <input name="support_phone_number" value="{{ old('support_phone_number', $settings['support_phone_number'] ?? '01640041418') }}" placeholder="01640041418">
                </div>
                <div class="field full">
                    <label>Confirmation SMS template</label>
                    <textarea name="payment_sms_template" rows="6">{{ old('payment_sms_template', $settings['payment_sms_template'] ?? "Your payment of {amount} BDT has been confirmed.\nThank you for choosing our service. If you have not received your order yet, please contact our support team through the link below and we will assist you as soon as possible.\n\nWhatsApp Support: {contact_url}\n\n— {brand}") }}</textarea>
                    <p class="hint">Available variables: {brand}, {contact_url}, {invoice_id}, {amount}, {trx_id}</p>
                </div>
            </div>
        </section>

        <section class="section-card">
            <h2>Admin Security</h2>
            <p class="hint">Admin login is Google-only. The allowed Gmail must match exactly.</p>
            <div class="form-grid">
                <div class="field full">
                    <label>Allowed admin Gmail</label>
                    <input name="google_admin_email" type="email" value="{{ old('google_admin_email', $settings['google_admin_email'] ?? '') }}" placeholder="yourname@gmail.com">
                </div>
                <div class="field full">
                    <label>Google OAuth Client ID</label>
                    <input name="google_client_id" value="{{ old('google_client_id', $settings['google_client_id'] ?? '') }}" placeholder="Google Cloud OAuth client ID">
                </div>
                <div class="field full">
                    <label>Google OAuth Client Secret</label>
                    <input name="google_client_secret" value="{{ old('google_client_secret', $settings['google_client_secret'] ?? '') }}" placeholder="Google Cloud OAuth client secret">
                </div>
                <div class="field full">
                    <label>Google OAuth Redirect URL</label>
                    <input value="{{ route('admin.google.callback') }}" readonly>
                    <p class="hint">Add this exact URL in Google Cloud as an authorized redirect URI.</p>
                </div>
            </div>
        </section>
    </div>

    <button class="btn" style="margin-top:18px">Save setup</button>
</form>
@endsection
