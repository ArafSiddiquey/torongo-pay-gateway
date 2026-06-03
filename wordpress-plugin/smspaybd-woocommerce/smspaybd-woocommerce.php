<?php
/**
 * Plugin Name: Torongo Pay WooCommerce Gateway
 * Description: Connect WooCommerce checkout with a self-hosted Torongo Pay semi-auto payment gateway.
 * Version: 1.0.0
 * Author: Torongo Pay
 * Text Domain: smspaybd-woocommerce
 * Requires Plugins: woocommerce
 */

if (! defined('ABSPATH')) {
    exit;
}

add_action('plugins_loaded', function () {
    if (! class_exists('WC_Payment_Gateway')) {
        return;
    }

    class WC_Gateway_SMSPayBD extends WC_Payment_Gateway
    {
        public function __construct()
        {
            $this->id = 'smspaybd';
            $this->method_title = 'Torongo Pay';
            $this->method_description = 'Redirect customers to your self-hosted Torongo Pay payment page for bKash, Nagad, Rocket and Binance.';
            $this->has_fields = false;
            $this->supports = ['products'];

            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option('title', 'Mobile Banking Payment');
            $this->description = $this->get_option('description', 'Pay securely using bKash, Nagad, Rocket or Binance.');
            $this->enabled = $this->get_option('enabled', 'no');

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
            add_action('woocommerce_api_smspaybd_callback', [$this, 'handle_callback']);
            add_action('woocommerce_thankyou_' . $this->id, [$this, 'verify_return_status']);
        }

        public function init_form_fields()
        {
            $this->form_fields = [
                'enabled' => [
                    'title' => 'Enable/Disable',
                    'type' => 'checkbox',
                    'label' => 'Enable Torongo Pay gateway',
                    'default' => 'no',
                ],
                'title' => [
                    'title' => 'Checkout title',
                    'type' => 'text',
                    'default' => 'Mobile Banking Payment',
                ],
                'description' => [
                    'title' => 'Checkout description',
                    'type' => 'textarea',
                    'default' => 'Pay using bKash, Nagad, Rocket or Binance.',
                ],
                'gateway_url' => [
                    'title' => 'Gateway URL',
                    'type' => 'text',
                    'description' => 'Example: https://pay.yourdomain.com',
                    'default' => '',
                ],
                'webhook_secret' => [
                    'title' => 'Webhook secret',
                    'type' => 'password',
                    'description' => 'Must match WEBHOOK_SECRET / admin Gateway Setup in Torongo Pay.',
                    'default' => '',
                ],
                'debug' => [
                    'title' => 'Debug log',
                    'type' => 'checkbox',
                    'label' => 'Enable WooCommerce logs',
                    'default' => 'no',
                ],
            ];
        }

        public function process_admin_options()
        {
            $saved = parent::process_admin_options();

            $gatewayUrl = trim((string) $this->get_option('gateway_url'));
            $secret = trim((string) $this->get_option('webhook_secret'));
            $this->update_option('gateway_url', untrailingslashit($gatewayUrl));
            $this->update_option('webhook_secret', $secret);

            return $saved;
        }

        public function process_payment($order_id)
        {
            $order = wc_get_order($order_id);
            if (! $order) {
                wc_add_notice('Invalid order.', 'error');
                return ['result' => 'failure'];
            }

            $gatewayUrl = untrailingslashit($this->get_option('gateway_url'));
            $secret = (string) $this->get_option('webhook_secret');
            if (! $gatewayUrl || ! $secret) {
                wc_add_notice('Payment gateway is not configured.', 'error');
                return ['result' => 'failure'];
            }

            $invoiceId = 'WC-' . $order->get_id() . '-' . time();
            $callbackUrl = WC()->api_request_url('smspaybd_callback');
            $successUrl = add_query_arg([
                'smspaybd_return' => '1',
                'invoice_id' => $invoiceId,
                'key' => $order->get_order_key(),
            ], $this->get_return_url($order));

            $payload = [
                'invoice_id' => $invoiceId,
                'order_id' => (string) $order->get_id(),
                'amount' => (float) $order->get_total(),
                'success_url' => $successUrl,
                'failed_url' => $order->get_cancel_order_url_raw(),
                'callback_url' => $callbackUrl,
                'metadata' => [
                    'source' => 'woocommerce',
                    'site_url' => home_url(),
                    'order_key' => $order->get_order_key(),
                ],
            ];

            $response = wp_remote_post($gatewayUrl . '/api/v1/payments', [
                'timeout' => 20,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'X-Webhook-Secret' => $secret,
                ],
                'body' => wp_json_encode($payload),
            ]);

            if (is_wp_error($response)) {
                $this->log('Create payment failed: ' . $response->get_error_message());
                wc_add_notice('Payment gateway connection failed.', 'error');
                return ['result' => 'failure'];
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (wp_remote_retrieve_response_code($response) >= 300 || empty($body['payment_url'])) {
                $this->log('Create payment invalid response: ' . wp_remote_retrieve_body($response));
                wc_add_notice('Payment gateway returned an invalid response.', 'error');
                return ['result' => 'failure'];
            }

            $order->update_meta_data('_smspaybd_invoice_id', $invoiceId);
            $order->update_meta_data('_smspaybd_payment_url', esc_url_raw($body['payment_url']));
            $order->update_status('on-hold', 'Torongo Pay payment pending. Invoice: ' . $invoiceId);
            $order->save();

            return [
                'result' => 'success',
                'redirect' => esc_url_raw($body['payment_url']),
            ];
        }

        public function handle_callback()
        {
            $secret = (string) $this->get_option('webhook_secret');
            $incoming = isset($_SERVER['HTTP_X_WEBHOOK_SECRET']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_WEBHOOK_SECRET'])) : '';
            if (! hash_equals($secret, $incoming)) {
                status_header(403);
                wp_send_json(['ok' => false, 'message' => 'Invalid secret']);
            }

            $payload = json_decode(file_get_contents('php://input'), true);
            $orderId = isset($payload['order_id']) ? absint($payload['order_id']) : 0;
            $order = $orderId ? wc_get_order($orderId) : false;
            if (! $order) {
                status_header(404);
                wp_send_json(['ok' => false, 'message' => 'Order not found']);
            }

            $invoiceId = sanitize_text_field($payload['invoice_id'] ?? '');
            $savedInvoiceId = (string) $order->get_meta('_smspaybd_invoice_id');
            if ($invoiceId && $savedInvoiceId && ! hash_equals($savedInvoiceId, $invoiceId)) {
                status_header(422);
                wp_send_json(['ok' => false, 'message' => 'Invoice mismatch']);
            }

            $status = sanitize_text_field($payload['status'] ?? '');
            if ($status === 'success') {
                $trxId = sanitize_text_field($payload['trx_id'] ?? '');
                if (! $order->is_paid()) {
                    $order->payment_complete($trxId);
                    $order->add_order_note('Torongo Pay payment verified. TrxID: ' . $trxId);
                }
            } elseif (in_array($status, ['failed', 'expired', 'cancelled'], true)) {
                if (! $order->is_paid()) {
                    $order->update_status('failed', 'Torongo Pay payment ' . $status . '. Invoice: ' . ($invoiceId ?: $savedInvoiceId));
                }
            } elseif ($status) {
                $order->add_order_note('Torongo Pay callback received with status: ' . $status);
            }

            wp_send_json(['ok' => true]);
        }

        public function verify_return_status($order_id)
        {
            $order = wc_get_order($order_id);
            if (! $order || $order->is_paid()) {
                return;
            }

            $invoiceId = $order->get_meta('_smspaybd_invoice_id');
            if (! $invoiceId) {
                return;
            }

            $gatewayUrl = untrailingslashit($this->get_option('gateway_url'));
            $secret = (string) $this->get_option('webhook_secret');
            $response = wp_remote_get($gatewayUrl . '/api/v1/payments/' . rawurlencode($invoiceId) . '/status', [
                'timeout' => 15,
                'headers' => [
                    'Accept' => 'application/json',
                    'X-Webhook-Secret' => $secret,
                ],
            ]);

            if (is_wp_error($response)) {
                $this->log('Return status check failed: ' . $response->get_error_message());
                return;
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (($body['status'] ?? '') === 'success') {
                $trxId = sanitize_text_field($body['trx_id'] ?? '');
                $order->payment_complete($trxId);
                $order->add_order_note('Torongo Pay payment verified on return. TrxID: ' . $trxId);
            } elseif (in_array(($body['status'] ?? ''), ['failed', 'expired', 'cancelled'], true)) {
                $order->update_status('failed', 'Torongo Pay payment ' . sanitize_text_field($body['status']) . ' on return check.');
            }
        }

        private function log(string $message): void
        {
            if ($this->get_option('debug') !== 'yes') {
                return;
            }

            wc_get_logger()->info($message, ['source' => 'smspaybd']);
        }
    }
});

add_filter('woocommerce_payment_gateways', function ($gateways) {
    $gateways[] = 'WC_Gateway_SMSPayBD';
    return $gateways;
});
