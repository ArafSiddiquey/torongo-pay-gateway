@extends('layouts.admin')
@section('content')
@php
    $isPendingPage = request('status') === \App\Models\Transaction::STATUS_PENDING;
    $selectedStatus = $status ?? \App\Models\Transaction::STATUS_SUCCESS;
    $perPageOptions = [10, 50, 100];
@endphp
<div class="page-head">
    <div>
        <h1>{{ $isPendingPage ? 'Pending Requests' : 'Transactions' }}</h1>
        <p class="hint">Search, review and manually approve or reject payments.</p>
    </div>
    <div class="toolbar-group transaction-toolbar-group">
        <form class="toolbar transaction-toolbar" method="get">
            <label class="entries-control">
                <span>Show</span>
                <select name="per_page" onchange="this.form.submit()">
                    @foreach($perPageOptions as $option)
                        <option value="{{ $option }}" @selected($perPage === $option)>{{ $option }}</option>
                    @endforeach
                </select>
                <span>entries</span>
            </label>
            <input class="search" name="q" value="{{ request('q') }}" placeholder="Search invoice, number, trx id">
            @if($isPendingPage)
                <input type="hidden" name="status" value="pending">
            @else
                <select name="status">
                    @foreach(['success','failed','expired'] as $optionStatus)
                        <option value="{{ $optionStatus }}" @selected($selectedStatus === $optionStatus)>{{ ucfirst($optionStatus) }}</option>
                    @endforeach
                </select>
            @endif
            <button class="btn secondary">Filter</button>
            @if($isPendingPage)
                <span class="toolbar-divider"></span>
                <button class="btn danger" type="submit" form="rejectAllPendingForm">Reject All</button>
            @endif
        </form>
        @if($isPendingPage)
            <form id="rejectAllPendingForm" method="post" action="{{ route('admin.transactions.reject_all_pending') }}" onsubmit="return confirm('Reject all pending payments? This cannot be undone.')">
                @csrf
            </form>
        @endif
    </div>
</div>

<div class="table-card"><div class="table-scroll">
    <table>
        <tr>
            <th>Invoice/order</th>
            <th>Customer</th>
            <th>Amount</th>
            <th>Method</th>
            <th>TrxID</th>
            <th>Status</th>
            <th>SMS device</th>
            <th>Times</th>
            @if($isPendingPage)
                <th>Action</th>
            @endif
        </tr>
        @forelse($transactions as $trx)
            @php
                $submittedTrxId = $trx->latestManualVerification?->trx_id;
                $officialSender = $trx->officialSenderNumber();
                $inputSender = $trx->customer_number;
                $senderMismatch = $officialSender && $inputSender && $officialSender !== $inputSender;
            @endphp
            <tr>
                <td><b>{{ $trx->invoice_id }}</b><br><small>{{ $trx->order_id ?: 'No order ID' }}</small></td>
                <td>
                    <b>{{ $officialSender ?: '-' }}</b>
                    @if($senderMismatch)
                        <br><small>Input: {{ $inputSender }}</small>
                    @endif
                </td>
                <td>{{ number_format($trx->amount, 2) }} BDT</td>
                <td>{{ strtoupper($trx->method_slug ?: '-') }}<br><small>{{ $trx->method_option ?: '-' }}</small></td>
                <td>
                    {{ $trx->trx_id ?: ($submittedTrxId ?: '-') }}
                    @if(! $trx->trx_id && $submittedTrxId)
                        <br><small>Submitted by customer</small>
                    @endif
                </td>
                <td><span class="pill {{ $trx->status }}">{{ $trx->status }}</span></td>
                <td>{{ $trx->smsDevice?->name ?: '-' }}</td>
                <td>
                    <small>Created</small><br>{{ $trx->created_at?->format('d M Y, h:i A') }}<br>
                    <small>Verified</small><br>{{ $trx->verified_at?->format('d M Y, h:i A') ?: '-' }}
                </td>
                @if($isPendingPage)
                    <td>
                        <div class="action-stack">
                        <form class="inline-form" method="post" action="{{ route('admin.transactions.approve',$trx) }}">
                            @csrf
                            <button class="btn">Approve</button>
                        </form>
                        <form method="post" action="{{ route('admin.transactions.reject',$trx) }}" onsubmit="return confirm('Reject this transaction?')">
                            @csrf
                            <button class="btn danger">Reject</button>
                        </form>
                        </div>
                    </td>
                @endif
            </tr>
        @empty
            <tr><td colspan="{{ $isPendingPage ? 9 : 8 }}" class="empty-row">No transactions found.</td></tr>
        @endforelse
    </table>
</div></div>
<div class="pager compact-pager">
    <div>
        Showing {{ $transactions->firstItem() ?? 0 }} to {{ $transactions->lastItem() ?? 0 }} of {{ $transactions->total() }} transactions
    </div>
    <div class="pager-actions">
        @if($transactions->onFirstPage())
            <span class="pager-btn disabled">Previous</span>
        @else
            <a class="pager-btn" href="{{ $transactions->previousPageUrl() }}">Previous</a>
        @endif

        <span class="pager-current">Page {{ $transactions->currentPage() }} of {{ $transactions->lastPage() }}</span>

        @if($transactions->hasMorePages())
            <a class="pager-btn" href="{{ $transactions->nextPageUrl() }}">Next</a>
        @else
            <span class="pager-btn disabled">Next</span>
        @endif
    </div>
</div>
@endsection
