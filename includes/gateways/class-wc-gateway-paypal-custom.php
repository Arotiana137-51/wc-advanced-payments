<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Custom PayPal Payment Gateway
 *
 * @class WC_Gateway_PayPal_Custom
 * @extends WC_Payment_Gateway
 */
class WC_Gateway_PayPal_Custom extends WC_Payment_Gateway {
    /**
     * Constructor for the gateway.
     */
    public function __construct() {
        $this->id                 = 'paypal_custom';
        $this->icon              = apply_filters('woocommerce_paypal_icon', '');
        $this->has_fields        = true;
        $this->method_title      = __('PayPal Custom', 'wc-advanced-payments');
        $this->method_description = __('Accept payments through PayPal.', 'wc-advanced-payments');
        $this->supports          = array(
            'products',
            'refunds',
            'subscriptions',
            'subscription_cancellation',
            'subscription_suspension',
            'subscription_reactivation',
            'subscription_amount_changes',
            'subscription_date_changes',
        );

        // Load the settings
        $this->init_form_fields();
        $this->init_settings();

        // Define user set variables
        $this->title        = $this->get_option('title');
        $this->description  = $this->get_option('description');
        $this->enabled      = $this->get_option('enabled');
        $this->testmode     = 'yes' === $this->get_option('testmode');
        $this->client_id    = $this->testmode ? $this->get_option('test_client_id') : $this->get_option('client_id');
        $this->secret_key   = $this->testmode ? $this->get_option('test_secret_key') : $this->get_option('secret_key');
        
        // API endpoints
        $this->api_endpoint = $this->testmode 
            ? 'https://api-m.sandbox.paypal.com' 
            : 'https://api-m.paypal.com';

        // Hooks
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));
        add_action('woocommerce_api_wc_gateway_paypal_custom', array($this, 'webhook_handler'));
    }

    /**
     * Initialize Gateway Settings Form Fields
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'       => __('Enable/Disable', 'wc-advanced-payments'),
                'label'       => __('Enable PayPal Custom', 'wc-advanced-payments'),
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no'
            ),
            'title' => array(
                'title'       => __('Title', 'wc-advanced-payments'),
                'type'        => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'wc-advanced-payments'),
                'default'     => __('PayPal', 'wc-advanced-payments'),
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __('Description', 'wc-advanced-payments'),
                'type'        => 'textarea',
                'description' => __('This controls the description which the user sees during checkout.', 'wc-advanced-payments'),
                'default'     => __('Pay securely via PayPal.', 'wc-advanced-payments'),
                'desc_tip'    => true,
            ),
            'testmode' => array(
                'title'       => __('Test mode', 'wc-advanced-payments'),
                'label'       => __('Enable Test Mode', 'wc-advanced-payments'),
                'type'        => 'checkbox',
                'description' => __('Place the payment gateway in test mode using sandbox API credentials.', 'wc-advanced-payments'),
                'default'     => 'yes',
                'desc_tip'    => true,
            ),
            'test_client_id' => array(
                'title'       => __('Sandbox Client ID', 'wc-advanced-payments'),
                'type'        => 'text',
                'description' => __('Get your API credentials from PayPal Developer Dashboard.', 'wc-advanced-payments'),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'test_secret_key' => array(
                'title'       => __('Sandbox Secret Key', 'wc-advanced-payments'),
                'type'        => 'password',
                'description' => __('Get your API credentials from PayPal Developer Dashboard.', 'wc-advanced-payments'),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'client_id' => array(
                'title'       => __('Live Client ID', 'wc-advanced-payments'),
                'type'        => 'text',
                'description' => __('Get your API credentials from PayPal Developer Dashboard.', 'wc-advanced-payments'),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'secret_key' => array(
                'title'       => __('Live Secret Key', 'wc-advanced-payments'),
                'type'        => 'password',
                'description' => __('Get your API credentials from PayPal Developer Dashboard.', 'wc-advanced-payments'),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'advanced_settings' => array(
                'title'       => __('Advanced Settings', 'wc-advanced-payments'),
                'type'        => 'title',
                'description' => '',
            ),
            'webhook_secret' => array(
                'title'       => __('Webhook Secret', 'wc-advanced-payments'),
                'type'        => 'password',
                'description' => __('Secret key for validating webhook notifications.', 'wc-advanced-payments'),
                'default'     => '',
                'desc_tip'    => true,
            ),
        );
    }

    /**
     * Get PayPal access token
     */
    private function get_access_token() {
        $response = wp_remote_post($this->api_endpoint . '/v1/oauth2/token', array(
            'method'      => 'POST',
            'timeout'     => 45,
            'redirection' => 5,
            'httpversion' => '1.0',
            'blocking'    => true,
            'headers'     => array(
                'Accept'        => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($this->client_id . ':' . $this->secret_key),
            ),
            'body'        => 'grant_type=client_credentials',
        ));

        if (is_wp_error($response)) {
            throw new Exception(__('Failed to get PayPal access token.', 'wc-advanced-payments'));
        }

        $body = json_decode(wp_remote_retrieve_body($response));
        return $body->access_token;
    }

    /**
     * Process the payment
     *
     * @param int $order_id
     * @return array
     */
    public function process_payment($order_id) {
        try {
            $order = wc_get_order($order_id);
            
            // Get access token
            $access_token = $this->get_access_token();

            // Create PayPal order
            $paypal_order = $this->create_paypal_order($order, $access_token);

            // Process the payment
            $payment = $this->process_paypal_payment($paypal_order->id, $access_token);

            if ($payment->status === 'COMPLETED') {
                // Payment complete
                $order->payment_complete($payment->id);
                
                // Add order note
                $order->add_order_note(
                    sprintf(__('PayPal payment successful (Payment ID: %s)', 'wc-advanced-payments'), 
                    $payment->id)
                );

                // Remove cart
                WC()->cart->empty_cart();

                return array(
                    'result'   => 'success',
                    'redirect' => $this->get_return_url($order),
                );
            } else {
                throw new Exception(__('Payment failed. Please try again.', 'wc-advanced-payments'));
            }

        } catch (Exception $e) {
            wc_add_notice($e->getMessage(), 'error');
            return array(
                'result'   => 'fail',
                'redirect' => '',
            );
        }
    }

    /**
     * Create PayPal order
     */
    private function create_paypal_order($order, $access_token) {
        $request = array(
            'intent' => 'CAPTURE',
            'purchase_units' => array(
                array(
                    'reference_id' => $order->get_id(),
                    'amount' => array(
                        'currency_code' => $order->get_currency(),
                        'value' => $order->get_total(),
                        'breakdown' => array(
                            'item_total' => array(
                                'currency_code' => $order->get_currency(),
                                'value' => $order->get_subtotal()
                            ),
                            'shipping' => array(
                                'currency_code' => $order->get_currency(),
                                'value' => $order->get_shipping_total()
                            ),
                            'tax_total' => array(
                                'currency_code' => $order->get_currency(),
                                'value' => $order->get_total_tax()
                            )
                        )
                    )
                )
            )
        );

        $response = wp_remote_post($this->api_endpoint . '/v2/checkout/orders', array(
            'method'      => 'POST',
            'timeout'     => 45,
            'redirection' => 5,
            'httpversion' => '1.0',
            'blocking'    => true,
            'headers'     => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $access_token,
            ),
            'body'        => json_encode($request),
        ));

        if (is_wp_error($response)) {
            throw new Exception(__('Failed to create PayPal order.', 'wc-advanced-payments'));
        }

        return json_decode(wp_remote_retrieve_body($response));
    }

    /**
     * Process PayPal payment
     */
    private function process_paypal_payment($order_id, $access_token) {
        $response = wp_remote_post($this->api_endpoint . '/v2/checkout/orders/' . $order_id . '/capture', array(
            'method'      => 'POST',
            'timeout'     => 45,
            'redirection' => 5,
            'httpversion' => '1.0',
            'blocking'    => true,
            'headers'     => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $access_token,
            ),
        ));

        if (is_wp_error($response)) {
            throw new Exception(__('Failed to process PayPal payment.', 'wc-advanced-payments'));
        }

        return json_decode(wp_remote_retrieve_body($response));
    }

    /**
     * Process refund
     *
     * @param int $order_id
     * @param float $amount
     * @param string $reason
     * @return bool
     */
    public function process_refund($order_id, $amount = null, $reason = '') {
        $order = wc_get_order($order_id);

        if (!$order) {
            return false;
        }

        try {
            $access_token = $this->get_access_token();
            $capture_id = $order->get_transaction_id();

            $request = array(
                'amount' => array(
                    'value' => $amount,
                    'currency_code' => $order->get_currency(),
                ),
                'note_to_payer' => $reason,
            );

            $response = wp_remote_post($this->api_endpoint . '/v2/payments/captures/' . $capture_id . '/refund', array(
                'method'      => 'POST',
                'timeout'     => 45,
                'redirection' => 5,
                'httpversion' => '1.0',
                'blocking'    => true,
                'headers'     => array(
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $access_token,
                ),
                'body'        => json_encode($request),
            ));

            if (is_wp_error($response)) {
                throw new Exception(__('Failed to process refund.', 'wc-advanced-payments'));
            }

            $refund = json_decode(wp_remote_retrieve_body($response));

            if ($refund->status === 'COMPLETED') {
                $order->add_order_note(
                    sprintf(__('Refunded %1$s - Refund ID: %2$s', 'wc-advanced-payments'),
                    $amount,
                    $refund->id
                ));
                return true;
            }

            return false;

        } catch (Exception $e) {
            $order->add_order_note(
                sprintf(__('Refund Failed: %s', 'wc-advanced-payments'),
                $e->getMessage()
            ));
            return false;
        }
    }

    /**
     * Add payment scripts
     */
    public function payment_scripts() {
        if (!is_cart() && !is_checkout() && !isset($_GET['pay_for_order'])) {
            return;
        }

        wp_enqueue_script(
            'paypal-sdk',
            'https://www.paypal.com/sdk/js?' . http_build_query(array(
                'client-id' => $this->client_id,
                'currency'  => get_woocommerce_currency(),
            )),
            array(),
            null,
            true
        );

        wp_enqueue_script(
            'wc-paypal-custom',
            WC_ADVANCED_PAYMENTS_PLUGIN_URL . 'assets/js/paypal-handler.js',
            array('jquery', 'paypal-sdk'),
            WC_ADVANCED_PAYMENTS_VERSION,
            true
        );

        wp_localize_script('wc-paypal-custom', 'wc_paypal_custom_params', array(
            'ajax_url' => WC_AJAX::get_endpoint('%%endpoint%%'),
        ));
    }

    /**
     * Payment form on checkout page
     */
    public function payment_fields() {
        // Load the template
        include_once WC_ADVANCED_PAYMENTS_PLUGIN_DIR . 'templates/paypal/button.php';
    }

    /**
     * Webhook handler
     */
    public function webhook_handler() {
        $webhook_handler = new WC_PayPal_Webhook_Handler();
        $webhook_handler->handle_webhook();
    }

    /**
     * Log PayPal events
     */
    private function log($message) {
        if (