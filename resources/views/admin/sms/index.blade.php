@extends('layouts.admin')
@section('content')
<div class="page-head">
    <div>
        <h1>SMS Data</h1>
        <p class="hint">Official SMS logs and parsed payment proof data.</p>
    </div>
</div>

<div class="table-card"><div class="table-scroll">
    <table>
        <tr><th>Sender</th><th>Method</th><th>Amount</th><th>Customer</th><th>TrxID</th><th>Device</th><th>SMS</th></tr>
        @forelse($logs as $log)
            <tr>
                <td>{{ $log->raw_sender }}</td>
                <td>{{ strtoupper($log->method_slug ?: '-') }}</td>
                <td>{{ $log->parsed_amount ? number_format($log->parsed_amount, 2).' BDT' : '-' }}</td>
                <td>{{ $log->parsed_customer_number ?: '-' }}</td>
                <td>{{ $log->parsed_trx_id ?: '-' }}</td>
                <td>{{ $log->smsDevice?->name ?: '-' }}</td>
                <td><small>{{ $log->raw_body }}</small></td>
            </tr>
        @empty
            <tr><td colspan="7" class="empty-row">No SMS logs found.</td></tr>
        @endforelse
    </table>
</div></div>
<div class="pager">{{ $logs->links() }}</div>
@endsection
