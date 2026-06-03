# WordPress/WooCommerce Plugin Setup

এই plugin WooCommerce checkout থেকে customer-কে Torongo Pay payment page-এ পাঠাবে। Payment verify হলে WooCommerce order automatic paid হবে।

## আগে যা লাগবে

1. WordPress website-এ WooCommerce installed থাকতে হবে।
2. Torongo Pay Laravel gateway live domain-এ চলতে হবে।
3. Torongo Pay admin panel থেকে Webhook Secret set করা থাকতে হবে।

## Plugin Install

1. `dist/smspaybd-woocommerce.zip` নিন।
2. WordPress admin > Plugins > Add New > Upload Plugin।
3. ZIP upload করে Activate করুন।

## WooCommerce Settings

1. WordPress admin > WooCommerce > Settings > Payments।
2. `Torongo Pay` enable করুন।
3. Manage এ গিয়ে দিন:
   - Gateway URL: যেমন `https://pay.yourdomain.com`
   - Webhook secret: Torongo Pay admin panel-এর একই secret
   - Checkout title: customer checkout-এ দেখানো নাম
   - Description: short payment instruction
4. Save করুন।

## Test Flow

1. WooCommerce test product checkout করুন।
2. Payment method হিসেবে `Torongo Pay` select করুন।
3. Customer Torongo Pay payment page-এ যাবে।
4. Payment verify হলে callback WooCommerce order paid করবে।
5. Customer return করলে plugin status recheck করবে।

## Notes

- Plugin bKash/Nagad/Rocket official API ব্যবহার করে না।
- Binance personal flow gateway-side verification দিয়ে handle হবে।
- Webhook Secret WordPress plugin এবং Torongo Pay admin panel-এ একই হতে হবে।
- Gateway URL শেষে slash থাকলেও সমস্যা নেই।
