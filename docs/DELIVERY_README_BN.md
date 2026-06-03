# Torongo Pay Delivery README

এই package-এ যা আছে:

- Laravel/MySQL Torongo Pay gateway
- Dark admin panel
- Customer payment page
- Android SMS Reader source
- WooCommerce WordPress plugin
- cPanel setup guide
- Google Sheet backup guide
- Testing checklist

## Important

এটি official bKash/Nagad/Rocket API gateway নয়। এটি SMS-based semi-auto verification system। bKash/Nagad/Rocket verification Android SMS Reader দিয়ে হবে। Binance flow personal account receive/order ID check অনুযায়ী আলাদাভাবে handle হবে।

## Local Test

Laragon দিয়ে:

- Admin: `http://sms-semi-auto-gateway.test/admin/login`
- Admin: `http://sms-semi-auto-gateway.test/admin/login`

Default admin:

- Email: `admin@example.com`
- Password: `admin12345`

## Main Setup Order

1. Admin login করুন।
2. Gateway Setup update করুন।
3. Brand/payment methods add করুন।
4. SMS Device add করে Android app-এ API key দিন।
5. WooCommerce plugin configure করুন।
6. Test invoice/payment run করুন।

## Final Files

Final ZIP তৈরি হলে `dist/` folder-এ থাকবে:

- `smspaybd-hosting-ready.zip`
- `smspaybd-woocommerce.zip`
- Android source folder: `android-sms-reader`
- Bangla docs: `docs`
