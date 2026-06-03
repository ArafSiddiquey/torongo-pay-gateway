@php
    $metadata = $transaction->metadata ?? [];
    $paidAmount = $transaction->paidAmount();
    $discountAmount = $transaction->discountAmount();
    $dueAmount = $transaction->dueAmount();
    $logo = $settings->invoiceLogoPdfSrc();
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
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { size: A4; margin: 0; }
        * { box-sizing: border-box; font-family: Calibri, Carlito, Arial, sans-serif; }
        body { margin: 0; color: #111827; background: #fff; font-size: 14px; }
        b, strong, h1, h2, h3, th { font-weight: 700; }
        .sheet { width: 794px; min-height: 1123px; margin: 0 auto; background: #fff; position: relative; overflow: hidden; }
        .bg-shape-one { position: absolute; left: -74px; top: -90px; width: 165px; height: 1303px; background: #c6e8a4; opacity: .68; transform: rotate(-15deg); border-radius: 50%; }
        .bg-shape-two { position: absolute; left: 3px; top: -90px; width: 105px; height: 1303px; background: #a4e0dd; opacity: .48; transform: rotate(13deg); border-radius: 50%; }
        .bg-shape-three { position: absolute; left: 42px; top: -90px; width: 64px; height: 1303px; background: #edf1a9; opacity: .48; transform: rotate(-9deg); border-radius: 50%; }
        .content { position: relative; z-index: 2; }
        .hero { height: 128px; color: #1d3f68; padding: 38px 52px 14px; position: relative; }
        .hero h1 { margin: 0; color: #1f4f83; font-size: 48px; line-height: .95; letter-spacing: 0; font-weight: 900; }
        .logo-box { position: absolute; right: 52px; top: 38px; width: 190px; height: 92px; text-align: right; }
        .logo-box img { max-width: 190px; max-height: 92px; object-fit: contain; }
        .meta-wrap { padding: 0 52px 8px; }
        .meta { width: 310px; margin-left: auto; text-align: right; font-size: 14px; line-height: 1.55; font-weight: 700; color: #111827; }
        .meta p { margin: 0; }
        .parties { clear: both; padding: 22px 52px 18px; }
        .party { width: 45%; display: inline-block; vertical-align: top; }
        .party.right { margin-left: 9%; }
        .party h3 { margin: 0 0 8px; font-size: 14px; }
        .party p { margin: 0 0 5px; font-size: 14px; }
        table { width: 690px; margin: 14px 52px 24px; border-collapse: collapse; }
        th, td { border-bottom: 1px solid #c9d2df; padding: 12px 8px; text-align: left; vertical-align: top; font-size: 14px; }
        th { font-weight: 500; border-top: 1px solid #111827; border-bottom: 1px solid #111827; }
        th:first-child { border-left: 1px solid #111827; }
        th:last-child { border-right: 1px solid #111827; }
        th:not(:first-child), td:not(:first-child) { text-align: right; white-space: nowrap; }
        td b { display: block; margin-bottom: 12px; }
        td small { display: block; margin-top: 5px; }
        .totals { width: 280px; margin: 0 52px 0 auto; }
        .total-row { clear: both; border-bottom: 1px solid #d9e0ea; padding: 9px 0; min-height: 32px; font-size: 14px; }
        .total-row span { float: left; font-weight: 700; }
        .total-row b { float: right; }
        .muted { background: #e7edf7; border-bottom: 0; }
        .muted span { padding-left: 12px; }
        .muted b { padding-right: 12px; }
        .muted.first-muted { margin-top: 8px; }
    </style>
</head>
<body>
<main class="sheet">
    <div class="bg-shape-one"></div>
    <div class="bg-shape-two"></div>
    <div class="bg-shape-three"></div>
    <div class="content">
    <header class="hero">
        <h1>INVOICE</h1>
        <div class="logo-box"><img src="{{ $logo }}" alt="Torongo Pay"></div>
    </header>
    <section class="meta-wrap">
        <div class="meta">
            <p><b>Invoice:</b> {{ $transaction->invoice_id }}</p>
            <p><b>Total:</b> BDT {{ number_format($transaction->amount, 2) }}</p>
            <p><b>Issued:</b> {{ $transaction->created_at?->format('j F Y') }}</p>
        </div>
    </section>
    <section class="parties">
        <div class="party">
            <h3>From:</h3>
            @foreach($fromLines as $line)
                @if(trim($line) !== '')<p>{{ $line }}</p>@endif
            @endforeach
        </div>
        <div class="party right">
            <h3>Bill to:</h3>
            <p>{{ $billToName }}</p>
            @if($billToNumber)<p>{{ $billToNumber }}</p>@endif
            @if($country)<p>{{ $country }}</p>@endif
        </div>
    </section>
    <table>
        <thead><tr><th>Description</th><th>Qty</th><th>Rate</th><th>Total</th></tr></thead>
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
    <section class="totals">
        <div class="total-row"><span>Sub total</span><b>BDT {{ number_format($transaction->amount, 2) }}</b></div>
        @if($discountAmount > 0)
            <div class="total-row"><span>Discount</span><b>- BDT {{ number_format($discountAmount, 2) }}</b></div>
        @endif
        <div class="total-row"><span>Total</span><b>BDT {{ number_format(max((float) $transaction->amount - $discountAmount, 0), 2) }}</b></div>
        <div class="total-row muted first-muted"><span>Payment total</span><b>BDT {{ number_format($paidAmount, 2) }}</b></div>
        <div class="total-row muted"><span>Amount due</span><b>BDT {{ number_format($dueAmount, 2) }}</b></div>
    </section>
    </div>
</main>
</body>
</html>
