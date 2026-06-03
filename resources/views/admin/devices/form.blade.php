@extends('layouts.admin')
@section('content')
<div class="page-head">
    <div>
        <h1>{{ $device->exists ? 'Edit' : 'Add' }} SMS Device</h1>
        <p class="hint">Create API keys for Android SMS reader apps.</p>
    </div>
    <a class="btn secondary" href="{{ route('admin.devices.index') }}">Back</a>
</div>

@if($plainKey && $setupPayload)
    <section class="device-setup-card">
        <div class="device-setup-copy">
            <h2>Copy this setup now</h2>
            <p class="hint">The API key will not be shown again. Use the QR code with Torongo Verify to auto-fill the app.</p>

            <div class="setup-copy-row">
                <span>Device URL</span>
                <code id="setupServer">{{ $deviceUrl }}</code>
                <button class="btn secondary" type="button" onclick="copySetupText('setupServer', this)">Copy</button>
            </div>
            <div class="setup-copy-row">
                <span>Device API key</span>
                <code id="setupApiKey">{{ $plainKey }}</code>
                <button class="btn secondary" type="button" onclick="copySetupText('setupApiKey', this)">Copy</button>
            </div>
            <div class="setup-copy-row">
                <span>Device name</span>
                <code id="setupDeviceName">{{ $device->name }}</code>
                <button class="btn secondary" type="button" onclick="copySetupText('setupDeviceName', this)">Copy</button>
            </div>
        </div>
        <div class="device-setup-qr">
            <img src="https://api.qrserver.com/v1/create-qr-code/?size=220x220&margin=10&data={{ urlencode(json_encode($setupPayload)) }}" alt="Torongo Verify setup QR">
            <p>Open Torongo Verify and tap <b>Scan setup QR</b>.</p>
        </div>
    </section>
@endif

@if($plainKey && $setupPayload)
    <div class="form device-setup-finish">
        <p class="hint">After scanning this QR and saving setup in Torongo Verify, click Save to return to SMS Devices.</p>
        <a class="btn" href="{{ route('admin.devices.index') }}">Save</a>
    </div>
@else
<form class="form" method="post" action="{{ $device->exists ? route('admin.devices.update',$device) : route('admin.devices.store') }}">
    @csrf
    @if($device->exists)@method('put')@endif

    <div class="field"><label>Device name</label><input name="name" value="{{ old('name',$device->name) }}" required placeholder="Shop Android Phone"></div>
    <div class="field">
        <label>Allowed methods</label>
        <div class="checks">
            @foreach($methods as $method)
                <label><input type="checkbox" name="allowed_methods[]" value="{{ $method->slug }}" @checked(in_array($method->slug,$device->allowed_methods ?? []))>{{ $method->name }}</label>
            @endforeach
        </div>
    </div>
    <div class="checks">
        <label><input type="checkbox" name="is_active" value="1" @checked(old('is_active',$device->is_active ?? true))>Active</label>
        @if($device->exists)
            <label><input type="checkbox" name="rotate_key" value="1">Rotate API key</label>
        @endif
    </div>
    <button class="btn" style="margin-top:18px">{{ $device->exists ? 'Save device' : 'Next' }}</button>
</form>
@endif

<script>
async function copySetupText(id, button) {
    const text = document.getElementById(id)?.textContent?.trim() || '';
    if (!text) return;
    try {
        await navigator.clipboard.writeText(text);
    } catch (error) {
        const input = document.createElement('textarea');
        input.value = text;
        input.style.position = 'fixed';
        input.style.opacity = '0';
        document.body.appendChild(input);
        input.select();
        document.execCommand('copy');
        input.remove();
    }
    const old = button.textContent;
    button.textContent = 'Copied';
    setTimeout(() => button.textContent = old, 1200);
}
</script>
@endsection
