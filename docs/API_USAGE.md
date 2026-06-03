# API Usage

## Create payment

```bash
curl -X POST https://your-domain.com/api/v1/payments \
  -H "Content-Type: application/json" \
  -H "X-Webhook-Secret: your-production-webhook-secret" \
  -d '{"invoice_id":"INV-1001","amount":1250,"order_id":"ORD-1001","success_url":"https://shop.com/order-received","failed_url":"https://shop.com/checkout","callback_url":"https://shop.com/wc-api/smspaybd_callback"}'
```

Response:

```json
{
  "invoice_id": "INV-1001",
  "payment_url": "https://your-domain.com/pay/INV-1001?token=...",
  "expires_at": "2026-05-25T10:00:00.000000Z"
}
```

## Check payment status

```bash
curl https://your-domain.com/api/v1/payments/INV-1001/status \
  -H "X-Webhook-Secret: your-production-webhook-secret"
```

Response:

```json
{
  "invoice_id": "INV-1001",
  "order_id": "ORD-1001",
  "amount": "1250.00",
  "currency": "BDT",
    "method": "bkash",
  "status": "success",
  "trx_id": "ABC123XYZ",
  "verified_at": "2026-05-25T15:35:00+06:00"
}
```

## Verification callback

If `callback_url` is sent while creating payment, the gateway will POST this payload after successful SMS verification:

```json
{
  "event": "verified",
  "invoice_id": "INV-1001",
  "order_id": "ORD-1001",
  "amount": "1250.00",
  "currency": "BDT",
  "method": "bkash",
  "method_option": "send_money",
  "status": "success",
  "trx_id": "ABC123XYZ",
  "verified_at": "2026-05-25T15:35:00+06:00"
}
```

Callback request includes `X-Webhook-Secret`, so the receiver must verify the same secret.

## SMS sync

Android app sends:

```json
{
  "messages": [
    {
      "sender": "bKash",
      "body": "You have received Tk 1250.00 from 01XXXXXXXXX. TrxID ABC123XYZ",
      "received_at": "2026-05-25T15:30:00+06:00"
    }
  ]
}
```

## Remittance SMS amount rule

Normal Send Money/Payment transaction exact amount match kore.

Remittance transaction official SMS amount `+/-2.5%` tolerance diye match kore. Example: invoice amount `1250`, SMS total `1218.75` theke `1281.25` range hole eligible.

## Binance personal flow

Binance is handled as a personal receive/order ID flow:

- Admin adds Binance UID/API key/secret/QR/exchange rate in Payment Methods.
- Customer selects Binance and submits the Binance Order ID.
- Gateway checks recent Binance Pay transaction history using the configured API key.
- If Order ID and amount match, payment becomes successful.
- If paid amount is lower than required, customer is asked to contact support.
- If paid amount is higher than required, payment is accepted.

Binance setup depends on account/API permission. Live verification must be tested with the actual Binance account credentials before production.
