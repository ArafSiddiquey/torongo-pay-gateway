@extends('layouts.admin')
@section('content')
@php
    $statuses = ['pending' => 'Pending', 'success' => 'Success', 'failed' => 'Failed', 'expired' => 'Expired'];
    $perPageOptions = [10, 50, 100];
@endphp

<div class="page-head">
    <div>
        <h1>Invoice Management</h1>
        <p class="hint">Create payment links, search invoices and review payment status.</p>
    </div>
</div>

<div class="invoice-toolbar">
    <button class="btn success-btn" type="button" onclick="document.getElementById('invoiceModal').showModal()">+ Create Custom Invoice</button>
    <form class="invoice-filter" method="get">
        <label class="entries-control">
            <span>Show</span>
            <select name="per_page" onchange="this.form.submit()">
                @foreach($perPageOptions as $option)
                    <option value="{{ $option }}" @selected($perPage === $option)>{{ $option }}</option>
                @endforeach
            </select>
            <span>entries</span>
        </label>
        <input name="q" value="{{ request('q') }}" placeholder="Search by ID, customer, sender no, trx id, or amount...">
        <select name="method">
            <option value="">All Methods</option>
            @foreach($methods as $method)
                <option value="{{ $method->slug }}" @selected(request('method') === $method->slug)>{{ $method->name }}</option>
            @endforeach
        </select>
        <select name="status">
            <option value="">All</option>
            @foreach($statuses as $value => $label)
                <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <button class="btn" type="submit">Filter</button>
    </form>
</div>

<div class="table-card"><div class="table-scroll">
    <table>
        <tr>
            <th><input type="checkbox" aria-label="Select all invoices"></th>
            <th>Sender No</th>
            <th>Brand Name</th>
            <th>Method</th>
            <th>Txn ID</th>
            <th>Amount</th>
            <th>Status</th>
            <th>Date</th>
            <th>Action</th>
        </tr>
        @forelse($transactions as $trx)
            @php
                $invoiceUrl = route('payment.invoice', ['transaction' => $trx->invoice_id, 'token' => $trx->signed_token]);
                $submittedTrxId = $trx->latestManualVerification?->trx_id;
                $paidAmount = $trx->paidAmount();
                $dueAmount = $trx->dueAmount();
                $officialSender = $trx->officialSenderNumber();
                $inputSender = $trx->customer_number;
                $senderMismatch = $officialSender && $inputSender && $officialSender !== $inputSender;
            @endphp
            <tr>
                <td><input type="checkbox" aria-label="Select invoice {{ $trx->invoice_id }}"></td>
                <td>
                    <b>{{ $officialSender ?: ($inputSender ?: '-') }}</b><br>
                    @if($senderMismatch)
                        <small>Input: {{ $inputSender }}</small><br>
                    @endif
                    <small>{{ $trx->invoice_id }}</small>
                </td>
                <td>{{ $trx->metadata['brand_name'] ?? config('app.name', 'Torongo Pay') }}</td>
                <td>{{ strtoupper($trx->method_slug ?: '-') }}<br><small>{{ $trx->method_option ?: '-' }}</small></td>
                <td>
                    {{ $trx->trx_id ?: ($submittedTrxId ?: '-') }}
                    @if(! $trx->trx_id && $submittedTrxId)
                        <br><small>Submitted by customer</small>
                    @endif
                </td>
                <td>
                    <b>{{ number_format($trx->amount, 2) }}</b><br>
                    @if($paidAmount > 0)
                        <small>Paid {{ number_format($paidAmount, 2) }}</small><br>
                    @endif
                    @if($dueAmount > 0)
                        <small>Due {{ number_format($dueAmount, 2) }}</small>
                    @else
                        <small>{{ $trx->currency }}</small>
                    @endif
                </td>
                <td><span class="pill {{ $trx->status }}">{{ $trx->status }}</span></td>
                <td>{{ $trx->created_at?->format('d M Y') }}<br><small>{{ $trx->created_at?->format('h:i A') }}</small></td>
                <td>
                    <div class="actions">
                        <a class="btn ghost" href="{{ $invoiceUrl }}" target="_blank">Open</a>
                        <button class="btn secondary" type="button" onclick="navigator.clipboard.writeText('{{ $invoiceUrl }}'); this.textContent='Copied'">Copy</button>
                        @if($dueAmount > 0 && $paidAmount > 0 && $trx->status === \App\Models\Transaction::STATUS_PENDING)
                            <form method="post" action="{{ route('admin.invoices.discount_due', $trx) }}" onsubmit="return confirm('Discount due amount for this invoice?')">
                                @csrf
                                <button class="btn secondary" type="submit">Discount: {{ number_format($dueAmount, 2) }}</button>
                            </form>
                        @endif
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="9" class="empty-row">No invoices found matching current criteria.</td></tr>
        @endforelse
    </table>
</div></div>

<div class="pager compact-pager">
    <div>
        Showing {{ $transactions->firstItem() ?? 0 }} to {{ $transactions->lastItem() ?? 0 }} of {{ $transactions->total() }} invoices
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

<dialog id="invoiceModal" class="modal">
    <form method="post" action="{{ route('admin.invoices.store') }}">
        @csrf
        <div class="modal-head">
            <div>
                <h2>Create Custom Invoice</h2>
                <p class="hint">Generate a manual payment link using your configured gateway methods.</p>
            </div>
            <button type="button" class="modal-close" onclick="document.getElementById('invoiceModal').close()">x</button>
        </div>

        <div class="form-grid">
            <div class="field">
                <label>Select Brand *</label>
                <select name="brand_name" required>
                    <option value="{{ config('app.name', 'Torongo Pay') }}">{{ config('app.name', 'Torongo Pay') }}</option>
                </select>
            </div>
            <div class="field">
                <label>Amount (BDT) *</label>
                <input name="amount" type="number" min="1" step="0.01" placeholder="e.g. 500" required>
            </div>
            <div class="field">
                <label>Payment Method</label>
                <select name="payment_method_id">
                    <option value="">Any active method</option>
                    @foreach($methods as $method)
                        <option value="{{ $method->id }}">{{ $method->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label>Invoice ID</label>
                <input name="invoice_id" placeholder="Auto generated if empty">
            </div>
            <div class="field">
                <label>Order ID</label>
                <input name="order_id" placeholder="Optional order reference">
            </div>
            <div class="field">
                <label>Customer Name</label>
                <input name="customer_name" placeholder="e.g. John Doe">
            </div>
        </div>

        <div class="modal-section-title">Redirect URLs</div>
        <div class="field">
            <label>Success Redirect URL</label>
            <input name="success_url" type="url" placeholder="https://yourdomain.com/success">
        </div>
        <div class="field">
            <label>Cancel Redirect URL</label>
            <input name="failed_url" type="url" placeholder="https://yourdomain.com/cancel">
        </div>
        <div class="field">
            <label>Webhook URL (IPN)</label>
            <input name="callback_url" type="url" placeholder="https://yourdomain.com/webhook">
            <small class="muted">This URL receives verification callback after successful SMS match.</small>
        </div>

        <div class="modal-actions">
            <button class="btn secondary" type="button" onclick="document.getElementById('invoiceModal').close()">Cancel</button>
            <button class="btn" type="submit">Generate Payment Link</button>
        </div>
    </form>
</dialog>
@if(request()->boolean('create'))
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            document.getElementById('invoiceModal')?.showModal();
        });
    </script>
@endif
@endsection
