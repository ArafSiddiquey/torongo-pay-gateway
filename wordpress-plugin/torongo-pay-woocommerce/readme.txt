=== Torongo Pay for WooCommerce ===
Contributors: torongopay
Tags: woocommerce, payment gateway, bkash, nagad, rocket, binance
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Torongo Pay for WooCommerce connects your WooCommerce checkout with your self-hosted Torongo Pay payment gateway.

== Description ==

The plugin creates a Torongo Pay invoice when a WooCommerce customer chooses Torongo Pay at checkout. The customer is redirected to the Torongo Pay payment page. After Torongo Pay verifies the payment, the plugin receives the callback and updates the WooCommerce order automatically.

== Features ==

* Adds Torongo Pay as a WooCommerce payment method.
* Creates a hosted Torongo Pay invoice from WooCommerce order amount.
* Redirects the customer to the Torongo Pay checkout page.
* Saves the Torongo Pay invoice ID in WooCommerce order meta.
* Receives Torongo Pay callback at the WooCommerce API endpoint.
* Marks the WooCommerce order paid when payment is verified.
* Marks the WooCommerce order failed when Torongo Pay reports failed, expired or cancelled.
* Performs a return-page status check as a backup if the webhook is delayed.
* Supports WooCommerce HPOS custom order tables.

== Installation ==

1. Upload the `torongo-pay-woocommerce` folder to `wp-content/plugins/`.
2. Activate `Torongo Pay for WooCommerce` from WordPress admin.
3. Go to WooCommerce > Settings > Payments.
4. Open Torongo Pay.
5. Enter your Torongo Pay URL, for example `https://pay.yourdomain.com`.
6. Enter the same webhook secret that is configured in Torongo Pay admin.
7. Enable the gateway and save.

== Required Torongo Pay Settings ==

Your Torongo Pay installation must be publicly reachable from the WordPress site.

Use the same webhook secret in both systems:

* WordPress: WooCommerce > Settings > Payments > Torongo Pay > Webhook secret
* Torongo Pay: Admin Panel > Gateway Setup > Webhook secret

The callback URL shown in the plugin settings is automatically sent to Torongo Pay when an order is created.

== Changelog ==

= 1.0.0 =
* Initial Torongo Pay WooCommerce gateway release.
