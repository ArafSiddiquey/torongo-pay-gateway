@extends('layouts.admin')
@section('content')
@php
    $formatBalanceAmount = function (mixed $value): string {
        $formatted = number_format((float) $value, 2, '.', ',');

        return rtrim(rtrim($formatted, '0'), '.');
    };
@endphp
<div class="page-head">
    <div>
        <h1>Dashboard</h1>
        <p class="hint">Overview of payment verification, SMS sync and pending actions.</p>
    </div>
    <a class="btn secondary" href="{{ route('admin.invoices', ['create' => 1]) }}">Create live invoice</a>
</div>

<section class="card hero-card">
    <div>
        <strong>Welcome back, Gateway Owner</strong>
        <p class="hint">Keep payment methods, SMS devices and pending requests healthy before going live.</p>
    </div>
    <div class="stat"><span>Today amount</span><b>{{ number_format($todayAmount, 2) }} BDT</b></div>
</section>

<h2>Invoice Analytics</h2>
<section class="metric-cards">
    <div class="metric-card metric-blue"><span>Total invoices</span><b>{{ $total }}</b></div>
    <div class="metric-card metric-green"><span>Successful</span><b>{{ $success }}</b><small>Verified payments</small></div>
    <div class="metric-card metric-yellow"><span>Pending recovery</span><b>{{ $pending }}</b><small>Awaiting verification</small></div>
    <div class="metric-card metric-red"><span>Needs review</span><b>{{ $failed }}</b><small>Failed or expired</small></div>
</section>

<h2>Account Balances</h2>
<section class="grid balance-grid">
    @forelse($balanceSummaries as $balance)
        @php($currency = $balance['currency'] ?? 'BDT')
        <div class="card stat account-balance-card">
            <span>{{ $balance['name'] }}</span>
            <b>{{ $formatBalanceAmount($balance['balance']) }} {{ $currency }}</b>
            <small>{{ $balance['account'] ?: 'No account number' }}</small>
            <small>
                Base {{ $formatBalanceAmount($balance['base_amount']) }} {{ $currency }} + received {{ $formatBalanceAmount($balance['received_amount']) }} {{ $currency }}
                @if(($balance['debit_amount'] ?? 0) > 0)
                    - spent {{ $formatBalanceAmount($balance['debit_amount']) }} {{ $currency }}
                @endif
            </small>
        </div>
    @empty
        <div class="card stat account-balance-card">
            <span>Account balances</span>
            <b>0.00 BDT</b>
            <small>Enable a method to track balance.</small>
        </div>
    @endforelse
</section>

<h2>System Metrics</h2>
<section class="grid">
    <div class="card stat"><span>Pending SMS data</span><b>{{ $pendingSms }}</b></div>
    <div class="card stat"><span>Active methods</span><b>{{ \App\Models\PaymentMethod::where('is_active', true)->count() }}</b></div>
    <div class="card stat"><span>SMS devices</span><b>{{ \App\Models\SmsDevice::count() }}</b></div>
    <div class="card stat"><span>Manual review</span><b>{{ \App\Models\ManualVerification::where('status', 'submitted')->count() }}</b></div>
    <div class="card stat"><span>Today success</span><b>{{ \App\Models\Transaction::where('status', \App\Models\Transaction::STATUS_SUCCESS)->whereDate('verified_at', today())->count() }}</b></div>
</section>

<h2>Recent transactions</h2>
<div class="table-card"><div class="table-scroll">
    <table>
        <tr><th>Invoice</th><th>Customer</th><th>Amount</th><th>Method</th><th>Status</th><th>Created</th></tr>
        @forelse($recent as $trx)
            <tr>
                <td><b>{{ $trx->invoice_id }}</b><br><small>{{ $trx->order_id ?: 'No order ID' }}</small></td>
                <td>{{ $trx->customer_number ?: '-' }}</td>
                <td>{{ number_format($trx->amount, 2) }} BDT</td>
                <td>{{ strtoupper($trx->method_slug ?: '-') }}<br><small>{{ $trx->method_option ?: '-' }}</small></td>
                <td><span class="pill {{ $trx->status }}">{{ $trx->status }}</span></td>
                <td>{{ $trx->created_at?->format('d M Y, h:i A') }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="empty-row">No transactions found.</td></tr>
        @endforelse
    </table>
</div></div>
@endsection
