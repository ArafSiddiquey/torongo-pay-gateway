# Testing Checklist

## Admin

- Admin login works.
- Dashboard stats load.
- Transactions list/search/filter works.
- Invoice page can create/view invoice.
- Payment Methods add/edit/delete works.
- SMS Devices add/delete works and API key is generated.
- Settings save works.
- Bangla/English text save works.

## Customer Payment

- Demo payment page opens.
- Customer must select a method and press Pay.
- bKash options: Send Money, Payment, Remittance.
- Nagad options: Send Money, Remittance.
- Rocket option: Send Money.
- Binance option opens Binance instruction flow.
- bKash/Nagad/Rocket number input length is enforced.
- Remittance does not ask customer number.
- QR auto shows when uploaded.
- Success page hides QR/payment number.
- Countdown stays stable after refresh.

## Verification

- Exact amount normal SMS verifies.
- Wrong amount does not verify.
- Wrong customer number does not verify.
- Wrong method does not verify.
- Duplicate TrxID is blocked.
- Same SMS duplicate is blocked.
- Remittance amount tolerance works.
- Binance Order ID match verifies.
- Binance underpaid status asks customer to contact support.
- 15 minute expired payment does not auto-success.

## Android

- SMS permission allowed.
- Official bKash/Nagad/Rocket SMS is queued.
- Non-official/fake SMS is ignored.
- Device API key required.
- Offline SMS stays queued.
- Online/boot/manual sync sends queued SMS.

## WooCommerce

- Plugin activates.
- Gateway appears in WooCommerce Payments.
- Order redirects to Torongo Pay payment page.
- Successful payment marks WooCommerce order paid.
- Failed/expired payment marks order failed.
- Return status check works if callback is delayed.

## Backup

- Google Sheet row is created on pending payment.
- Same row updates after success/failed/expired.
- Manual verification attempt is logged.
