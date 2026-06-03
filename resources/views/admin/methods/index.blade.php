@extends('layouts.admin')
@section('content')
<div class="page-head">
    <div>
        <h1>View Gateways</h1>
        <p class="hint">Control credentials, QR, option visibility and method ordering.</p>
    </div>
    <a class="btn" href="{{ route('admin.methods.create') }}">+ Add Gateway</a>
</div>

<div class="table-card"><div class="table-scroll">
    <table>
        <tr>
            <th>Order</th><th>Method</th><th>Numbers</th><th>QR</th><th>Enabled options</th><th>Status</th><th>Action</th>
        </tr>
        @forelse($methods as $method)
            <tr>
                <td>{{ $method->sort_order }}</td>
                <td>
                    <b>{{ $method->name }}</b><br>
                    <small>{{ $method->name_bn ?: $method->slug }}</small>
                </td>
                <td>
                    @php($numbers = $method->config['option_numbers'] ?? [])
                    @php($sendNumber = $numbers['send_money'] ?? $method->payment_number)
                    @php($paymentNumber = $numbers['payment'] ?? null)
                    @php($remittanceNumber = $numbers['remittance'] ?? $method->remittance_number)
                    @if($method->slug === 'binance')
                        Binance UID: <b>{{ $method->config['binance_uid'] ?? '-' }}</b>
                    @else
                        @if($method->send_money_enabled)
                            Send Money: <b>{{ $sendNumber ?: '-' }}</b><br>
                        @endif
                        @if($method->slug === 'bkash' && $method->payment_enabled)
                            Payment: <b>{{ $paymentNumber ?: '-' }}</b><br>
                        @endif
                        @if(in_array($method->slug, ['bkash', 'nagad'], true) && $method->remittance_enabled)
                            Remittance: <b>{{ $remittanceNumber ?: '-' }}</b>
                        @endif
                    @endif
                </td>
                <td>
                    @php($qrs = $method->config['option_qr_paths'] ?? [])
                    <span class="pill {{ $method->qr_enabled ? 'active' : 'inactive' }}">{{ $method->qr_enabled ? 'Auto show' : 'Off' }}</span>
                    @if($method->qr_path || count(array_filter($qrs)))
                        <br><small>{{ count(array_filter($qrs)) ?: 1 }} QR uploaded</small>
                    @endif
                </td>
                <td>
                    @if($method->slug === 'binance')
                        <span class="pill active">Personal</span>
                    @else
                        @if($method->send_money_enabled)
                            <span class="pill active">Send Money</span>
                        @endif
                        @if($method->slug === 'bkash' && $method->payment_enabled)
                            <span class="pill active">Payment</span>
                        @endif
                        @if(in_array($method->slug, ['bkash', 'nagad'], true) && $method->remittance_enabled)
                            <span class="pill active">Remittance</span>
                        @endif
                    @endif
                </td>
                <td><span class="pill {{ $method->is_active ? 'active' : 'inactive' }}">{{ $method->is_active ? 'Active' : 'Inactive' }}</span></td>
                <td>
                    <div class="actions">
                        <a class="btn secondary" href="{{ route('admin.methods.edit',$method) }}">Edit</a>
                        <form method="post" action="{{ route('admin.methods.destroy',$method) }}" onsubmit="return confirm('Delete this method?')">
                            @csrf @method('delete')
                            <button class="btn danger">Delete</button>
                        </form>
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="empty-row">No payment methods found.</td></tr>
        @endforelse
    </table>
</div></div>
@endsection
