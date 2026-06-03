@extends('layouts.payment')
@section('content')
@php
    $metadata = $transaction->metadata ?? [];
    $paidAmount = $transaction->paidAmount();
    $discountAmount = $transaction->discountAmount();
    $dueAmount = $transaction->dueAmount();
    $logo = $settings->invoiceLogoUrl();
    $fromLines = preg_split('/\r\n|\r|\n/', (string) $settings->get('invoice_from_details', "Torongo Pay\nBangladesh\nsupport@example.com"));
    $country = 'Bangladesh';
    if ($transaction->method_option === 'binance') {
        $country = '';
    } elseif ($transaction->method_option === 'remittance') {
        $digits = preg_replace('/\D+/', '', (string) $transaction->customer_number);
        $country = str_starts_with($digits, '61') ? 'Australia' : (str_starts_with($digits, '358') ? 'Finland' : 'Bangladesh');
    }
    $billToNumber = $transaction->officialSenderNumber() ?: $transaction->customer_number;
    $billToName = $transaction->method_option === 'binance'
        ? 'Binance User'
        : ($metadata['customer_name'] ?? $billToNumber ?? 'Customer');
    $description = $metadata['product_name'] ?? $metadata['description'] ?? $transaction->order_id ?? 'Digital product / service';
    $details = array_filter([
        !empty($metadata['validity']) ? 'Validity: '.$metadata['validity'] : null,
        !empty($metadata['account_type']) ? 'Account type: '.$metadata['account_type'] : null,
        !empty($metadata['domain']) ? 'Domain: '.$metadata['domain'] : null,
        !empty($metadata['expire_date']) ? 'Expire Date: '.$metadata['expire_date'] : null,
        !empty($metadata['renew_date']) ? 'Renew Date: '.$metadata['renew_date'] : null,
    ]);
@endphp

<main class="invoice-page">
    <div class="invoice-download-bar">
        <a class="invoice-download-link" href="{{ route('payment.invoice.download', ['transaction' => $transaction->invoice_id, 'token' => $transaction->signed_token]) }}">
            <span aria-hidden="true">↓</span>
            Download PDF
        </a>
    </div>
    <section class="invoice-sheet">
        <header class="invoice-hero">
            <h1>INVOICE</h1>
            <div class="invoice-logo-box"><img src="{{ $logo }}" alt="Torongo Pay"></div>
        </header>
        <section class="invoice-meta-row">
            <div class="invoice-meta">
                <p><b>Invoice:</b> {{ $transaction->invoice_id }}</p>
                <p><b>Total:</b> BDT {{ number_format($transaction->amount, 2) }}</p>
                <p><b>Issued:</b> {{ $transaction->created_at?->format('j F Y') }}</p>
            </div>
        </section>

        <div class="invoice-parties">
            <div>
                <h3>From:</h3>
                @foreach($fromLines as $line)
                    @if(trim($line) !== '')<p>{{ $line }}</p>@endif
                @endforeach
            </div>
            <div>
                <h3>Bill to:</h3>
                <p>{{ $billToName }}</p>
                @if($billToNumber)<p>{{ $billToNumber }}</p>@endif
                @if($country)<p>{{ $country }}</p>@endif
            </div>
        </div>

        <table class="invoice-items">
            <thead>
                <tr><th>Description</th><th>Qty</th><th>Rate</th><th>Total</th></tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        <b>{{ $description }}</b>
                        @foreach($details as $detail)
                            <small>{{ $detail }}</small>
                        @endforeach
                    </td>
                    <td>1</td>
                    <td>BDT {{ number_format($transaction->amount, 2) }}</td>
                    <td>BDT {{ number_format($transaction->amount, 2) }}</td>
                </tr>
            </tbody>
        </table>

        <div class="invoice-totals">
            <div class="invoice-total-row"><span>Sub total</span><b>BDT {{ number_format($transaction->amount, 2) }}</b></div>
            @if($discountAmount > 0)
                <div class="invoice-total-row"><span>Discount</span><b>- BDT {{ number_format($discountAmount, 2) }}</b></div>
            @endif
            <div class="invoice-total-row"><span>Total</span><b>BDT {{ number_format(max((float) $transaction->amount - $discountAmount, 0), 2) }}</b></div>
            <div class="invoice-total-row muted-total"><span>Payment total</span><b>BDT {{ number_format($paidAmount, 2) }}</b></div>
            <div class="invoice-total-row due-total"><span>Amount due</span><b>BDT {{ number_format($dueAmount, 2) }}</b></div>
        </div>
    </section>
</main>
@endsection
