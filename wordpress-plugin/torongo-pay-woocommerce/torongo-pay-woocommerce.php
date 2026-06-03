<?php
/**
 * Plugin Name: Torongo Pay for WooCommerce
 * Plugin URI: https://torongopay.com
 * Description: WooCommerce payment gateway integration for your self-hosted Torongo Pay checkout.
 * Version: 1.0.0
 * Author: Torongo Pay
 * Text Domain: torongo-pay-woocommerce
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * WC requires at least: 7.0
 * WC tested up to: 9.9
 * Requires Plugins: woocommerce
 */

if (! defined('ABSPATH')) {
    exit;
}

define('TORONGO_PAY_WC_VERSION', '1.0.0');
define('TORONGO_PAY_WC_PLUGIN_FILE', __FILE__);

add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            TORONGO_PAY_WC_PLUGIN_FILE,
            true
        );
    }
});

add_action('plugins_loaded', function () {
    if (! class_exists('WC_Payment_Gateway')) {
        return;
    }

    class WC_Gateway_Torongo_Pay extends WC_Payment_Gateway
    {
        public function __construct()
        {
            $this->id = 'torongo_pay';
            $this->icon = '';
            $this->has_fields = false;
            $this->method_title = 'Torongo Pay';
            $this->method_description = 'Redirect customers to your Torongo Pay hosted checkout and verify orders through SMS/API callbacks.';
            $this->supports = ['products'];

            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option('title', 'Torongo Pay');
            $this->description = $this->get_option('description', 'Pay securely using bKash, Nagad, Rocket, remittance or Binance.');
            $this->enabled = $this->get_option('enabled', 'no');

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
            add_action('woocommerce_api_torongo_pay_callback', [$this, 'handle_callback']);
            add_action('woocommerce_thankyou_' . $this->id, [$this, 'verify_return_status']);
        }

        public function init_form_fields()
        {
            $callbackUrl = function_exists('WC') && WC() ? WC()->api_request_url('torongo_pay_callback') : home_url('/wc-api/torongo_pay_callback');

            $this->form_fields = [
                'enabled' => [
                    'title' => 'Enable/Disable',
                    'type' => 'checkbox',
                    'label' => 'Enable Torongo Pay',
                    'default' => 'no',
                ],
                'title' => [
                    'title' => 'Checkout title',
                    'type' => 'text',
                    'default' => 'Torongo Pay',
                    'desc_tip' => true,
                    'description' => 'This name appears on the WooCommerce checkout payment method list.',
                ],
                'description' => [
                    'title' => 'Checkout description',
                    'type' => 'textarea',
                    'default' => 'Pay securely using bKash, Nagad, Rocket, remittance or Binance.',
                ],
                'gateway_url' => [
                    'title' => 'Torongo Pay URL',
                    'type' => 'url',
                    'description' => 'Example: https://pay.yourdomain.com. Do not use a localhost URL on a live WordPress site.',
                    'default' => '',
                ],
                'webhook_secret' => [
                    'title' => 'Webhook secret',
                    'type' => 'password',
                    'description' => 'Must match the webhook secret in Torongo Pay admin Gateway Setup.',
                    'default' => '',
                ],
                'callback_url' => [
                    'title' => 'Callback URL',
                    'type' => 'title',
                    'description' => '<code>' . esc_html($callbackUrl) . '</code><br>Torongo Pay will call this URL after payment verification.',
                ],
                'debug' => [
                    'title' => 'Debug log',
                    'type' => 'checkbox',
                    'label' => 'Enable WooCommerce logs for Torongo Pay',
                    'default' => 'no',
                ],
            ];
        }

        public function process_admin_options()
        {
            $saved = parent::process_admin_options();

            $gatewayUrl = untrailingslashit(esc_url_raw(trim((string) $this->get_option('gateway_url'))));
            $secret = sanitize_text_field(trim((string) $this->get_option('webhook_secret')));

            $this->update_option('gateway_url', $gatewayUrl);
            $this->update_option('webhook_secret', $secret);

            return $saved;
        }

        public function is_available()
        {
            return parent::is_available()
                && $this->gateway_url() !== ''
                && $this->webhook_secret() !== '';
        }

        public function process_payment($order_id)
        {
            $order = wc_get_order($order_id);
            if (! $order) {
                wc_add_notice('Invalid order.', 'error');
                return ['result' => 'failure'];
            }

            if ($this->gateway_url() === '' || $this->webhook_secret() === '') {
                wc_add_notice('Torongo Pay is not configured.', 'error');
                return ['result' => 'failure'];
            }

            $callbackUrl = WC()->api_request_url('torongo_pay_callback');
            $successUrl = add_query_arg(
                ['torongo_pay_return' => '1', 'key' => $order->get_order_key()],
                $this->get_return_url($order)
            );

            $payload = [
                'order_id' => (string) $order->get_id(),
                'amount' => (float) $order->get_total(),
                'success_url' => $successUrl,
                'failed_url' => $order->get_cancel_order_url_raw(),
                'callback_url' => $callbackUrl,
                'metadata' => $this->order_metadata($order),
            ];

            $response = wp_remote_post($this->gateway_url() . '/api/v1/payments', [
                'timeout' => 25,
                'redirection' => 2,
                'headers' => [
                    'Content-Type' => 'application/json; charset=utf-8',
                    'Accept' => 'application/json',
                    'X-Webhook-Secret' => $this->webhook_secret(),
                ],
                'body' => wp_json_encode($payload),
            ]);

            if (is_wp_error($response)) {
                $this->log('Create payment request failed: ' . $response->get_error_message());
                wc_add_notice('Could not connect to Torongo Pay. Please try again.', 'error');
                return ['result' => 'failure'];
            }

            $statusCode = (int) wp_remote_retrieve_response_code($response);
            $body = json_decode((string) wp_remote_retrieve_body($response), true);

            if ($statusCode < 200 || $statusCode >= 300 || empty($body['payment_url']) || empty($body['invoice_id'])) {
                $this->log('Create payment invalid response: HTTP ' . $statusCode . ' ' . wp_remote_retrieve_body($response));
                wc_add_notice('Torongo Pay returned an invalid payment response.', 'error');
                return ['result' => 'failure'];
            }

            $invoiceId = sanitize_text_field($body['invoice_id']);
            $paymentUrl = esc_url_raw($body['payment_url']);

            $order->update_meta_data('_torongo_pay_invoice_id', $invoiceId);
            $order->update_meta_data('_torongo_pay_payment_url', $paymentUrl);
            $order->update_meta_data('_torongo_pay_expires_at', sanitize_text_field($body['expires_at'] ?? ''));
            $order->update_status('on-hold', 'Torongo Pay payment pending. Invoice: ' . $invoiceId);
            $order->save();

            if (function_exists('wc_reduce_stock_levels')) {
                wc_reduce_stock_levels($order->get_id());
            }

            if (WC()->cart) {
                WC()->cart->empty_cart();
            }

            return [
                'result' => 'success',
                'redirect' => $paymentUrl,
            ];
        }

        public function handle_callback()
        {
            $incomingSecret = isset($_SERVER['HTTP_X_WEBHOOK_SECRET'])
                ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_WEBHOOK_SECRET']))
                : '';

            if ($this->webhook_secret() === '' || ! hash_equals($this->webhook_secret(), $incomingSecret)) {
                status_header(403);
                wp_send_json(['ok' => false, 'message' => 'Invalid webhook secret']);
            }

            $payload = json_decode((string) file_get_contents('php://input'), true);
            if (! is_array($payload)) {
                status_header(400);
                wp_send_json(['ok' => false, 'message' => 'Invalid JSON payload']);
            }

            $orderId = isset($payload['order_id']) ? absint($payload['order_id']) : 0;
            $order = $orderId ? wc_get_order($orderId) : false;
            if (! $order) {
                status_header(404);
                wp_send_json(['ok' => false, 'message' => 'Order not found']);
            }

            $invoiceId = sanitize_text_field($payload['invoice_id'] ?? '');
            if (! $this->invoice_matches_order($order, $invoiceId)) {
                status_header(422);
                wp_send_json(['ok' => false, 'message' => 'Invoice mismatch']);
            }

            $this->apply_gateway_status($order, $payload, 'callback');
            wp_send_json(['ok' => true]);
        }

        public function verify_return_status($order_id)
        {
            $order = wc_get_order($order_id);
            if (! $order || $order->is_paid()) {
                return;
            }

            $invoiceId = (string) $order->get_meta('_torongo_pay_invoice_id');
            if ($invoiceId === '' || $this->gateway_url() === '' || $this->webhook_secret() === '') {
                return;
            }

            $response = wp_remote_get($this->gateway_url() . '/api/v1/payments/' . rawurlencode($invoiceId) . '/status', [
                'timeout' => 15,
                'redirection' => 2,
                'headers' => [
                    'Accept' => 'application/json',
                    'X-Webhook-Secret' => $this->webhook_secret(),
                ],
            ]);

            if (is_wp_error($response)) {
                $this->log('Return status check failed: ' . $response->get_error_message());
                return;
            }

            $body = json_decode((string) wp_remote_retrieve_body($response), true);
            if (! is_array($body) || empty($body['invoice_id']) || ! $this->invoice_matches_order($order, sanitize_text_field($body['invoice_id']))) {
                return;
            }

            $this->apply_gateway_status($order, $body, 'return_check');
        }

        private function apply_gateway_status($order, array $payload, string $source): void
        {
            $status = sanitize_text_field($payload['status'] ?? '');
            $invoiceId = sanitize_text_field($payload['invoice_id'] ?? '');
            $trxId = sanitize_text_field($payload['trx_id'] ?? '');
            $method = sanitize_text_field($payload['method'] ?? '');

            if ($status === 'success') {
                if (! $order->is_paid()) {
                    $order->payment_complete($trxId);
                    $order->add_order_note(sprintf(
                        'Torongo Pay payment verified via %s. Invoice: %s. Method: %s. TrxID: %s',
                        $source,
                        $invoiceId,
                        $method ?: '-',
                        $trxId ?: '-'
                    ));
                }
                return;
            }

            if (in_array($status, ['failed', 'expired', 'cancelled'], true)) {
                if (! $order->is_paid()) {
                    $order->update_status('failed', sprintf(
                        'Torongo Pay payment %s via %s. Invoice: %s',
                        $status,
                        $source,
                        $invoiceId ?: (string) $order->get_meta('_torongo_pay_invoice_id')
                    ));
                }
                return;
            }

            if ($status !== '') {
                $order->add_order_note('Torongo Pay status update via ' . $source . ': ' . $status);
            }
        }

        private function invoice_matches_order($order, string $invoiceId): bool
        {
            $savedInvoiceId = (string) $order->get_meta('_torongo_pay_invoice_id');
            if ($savedInvoiceId === '') {
                $legacyInvoiceId = (string) $order->get_meta('_smspaybd_invoice_id');
                $savedInvoiceId = $legacyInvoiceId;
            }

            return $invoiceId !== '' && $savedInvoiceId !== '' && hash_equals($savedInvoiceId, $invoiceId);
        }

        private function order_metadata($order): array
        {
            $items = [];
            foreach ($order->get_items() as $item) {
                $product = $item->get_product();
                $items[] = [
                    'name' => $item->get_name(),
                    'quantity' => (int) $item->get_quantity(),
                    'total' => (float) $item->get_total(),
                    'sku' => $product ? $product->get_sku() : '',
                ];
            }

            return [
                'source' => 'woocommerce',
                'brand_name' => get_bloginfo('name'),
                'site_url' => home_url(),
                'order_number' => $order->get_order_number(),
                'order_key' => $order->get_order_key(),
                'customer_name' => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                'customer_phone' => $order->get_billing_phone(),
                'customer_email' => $order->get_billing_email(),
                'billing_country' => $order->get_billing_country(),
                'currency' => $order->get_currency(),
                'items' => $items,
            ];
        }

        private function gateway_url(): string
        {
            return untrailingslashit((string) $this->get_option('gateway_url'));
        }

        private function webhook_secret(): string
        {
            return (string) $this->get_option('webhook_secret');
        }

        private function log(string $message): void
        {
            if ($this->get_option('debug') !== 'yes') {
                return;
            }

            wc_get_logger()->info($message, ['source' => 'torongo-pay']);
        }
    }
});

add_filter('woocommerce_payment_gateways', function ($gateways) {
    $gateways[] = 'WC_Gateway_Torongo_Pay';
    return $gateways;
});

add_filter('plugin_action_links_' . plugin_basename(TORONGO_PAY_WC_PLUGIN_FILE), function ($links) {
    $settingsUrl = admin_url('admin.php?page=wc-settings&tab=checkout&section=torongo_pay');
    array_unshift($links, '<a href="' . esc_url($settingsUrl) . '">Settings</a>');
    return $links;
});
