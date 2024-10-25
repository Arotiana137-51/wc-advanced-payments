<?php
// class-wc-gateway-stripe-custom.php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Custom Stripe Payment Gateway
 *
 * @class WC_Gateway_Stripe_Custom
 * @extends WC_Payment_Gateway
 */
class WC_Gateway_Stripe_Custom extends WC_Payment_Gateway {
    /**
     * Constructor for the gateway.
     */
    public function __construct() {
        $this->id                 = 'stripe_custom';
        $this->icon              = apply_filters('woocommerce_stripe_icon', '');
        $this->has_fields        = true;
        $this->method_title      = __('Stripe Custom', 'wc-advanced-payments');
        $this->method_description = __('Accept payments through Stripe.', 'wc-advanced-payments');
        $this->supports          = array(
            'products',
            'refunds',
            'tokenization',
            'add_payment_method',
            'subscriptions',
            'subscription_cancellation',
            'subscription_suspension',
            'subscription_reactivation',
            'subscription_amount_changes',
            'subscription_date_changes',
            'subscription_payment_method_change',
            'subscription_payment_method_change_customer',
        );

        // Load the settings
        $this->init_form_fields();
        $this->init_settings();

        // Define user set variables
        $this->title            = $this->get_option('title');
        $this->description      = $this->get_option('description');
        $this->enabled          = $this->get_option('enabled');
        $this->testmode        = 'yes' === $this->get_option('testmode');
        $this->private_key     = $this->testmode ? $this->get_option('test_private_key') : $this->get_option('private_key');
        $this->publishable_key = $this->testmode ? $this->get_option('test_publishable_key') : $this->get_option('publishable_key');

        // Hooks
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));
        add_action('woocommerce_api_wc_gateway_stripe_custom', array($this, 'webhook_handler'));
    }

    /**
     * Initialize Gateway Settings Form Fields
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'       => __('Enable/Disable', 'wc-advanced-payments'),
                'label'       => __('Enable Stripe Custom', 'wc-advanced-payments'),
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no'
            ),
            'title' => array(
                'title'       => __('Title', 'wc-advanced-payments'),
                'type'        => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'wc-advanced-payments'),
                'default'     => __('Credit Card (Stripe)', 'wc-advanced-payments'),
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __('Description', 'wc-advanced-payments'),
                'type'        => 'textarea',
                'description' => __('This controls the description which the user sees during checkout.', 'wc-advanced-payments'),
                'default'     => __('Pay with your credit card via Stripe.', 'wc-advanced-payments'),
                'desc_tip'    => true,
            ),
            'testmode' => array(
                'title'       => __('Test mode', 'wc-advanced-payments'),
                'label'       => __('Enable Test Mode', 'wc-advanced-payments'),
                'type'        => 'checkbox',
                'description' => __('Place the payment gateway in test mode using test API keys.', 'wc-advanced-payments'),
                'default'     => 'yes',
                'desc_tip'    => true,
            ),
            'test_private_key' => array(
                'title'       => __('Test Private Key', 'wc-advanced-payments'),
                'type'        => 'password',
                'description' => __('Get your API keys from your stripe account.', 'wc-advanced-payments'),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'test_publishable_key' => array(
                'title'       => __('Test Publishable Key', 'wc-advanced-payments'),
                'type'        => 'text',
                'description' => __('Get your API keys from your stripe account.', 'wc-advanced-payments'),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'private_key' => array(
                'title'       => __('Live Private Key', 'wc-advanced-payments'),
                'type'        => 'password',
                'description' => __('Get your API keys from your stripe account.', 'wc-advanced-payments'),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'publishable_key' => array(
                'title'       => __('Live Publishable Key', 'wc-advanced-payments'),
                'type'        => 'text',
                'description' => __('Get your API keys from your stripe account.', 'wc-advanced-payments'),
                'default'     => '',
                'desc_tip'    => true,
            ),
        );
    }

    /**
     * Payment form on checkout page
     */
    public function payment_fields() {
        // Load the template
        include_once WC_ADVANCED_PAYMENTS_PLUGIN_DIR . 'templates/stripe/payment-form.php';
    }

    /**
     * Add payment scripts
     */
    public function payment_scripts() {
        if (!is_cart() && !is_checkout() && !isset($_GET['pay_for_order'])) {
            return;
        }

        // Load Stripe JS
        wp_enqueue_script('stripe', 'https://js.stripe.com/v3/', array(), '3.0', true);
        wp_enqueue_script(
            'wc-stripe-custom',
            WC_ADVANCED_PAYMENTS_PLUGIN_URL . 'assets/js/stripe-handler.js',
            array('jquery', 'stripe'),
            WC_ADVANCED_PAYMENTS_VERSION,
            true
        );

        wp_localize_script('wc-stripe-custom', 'wc_stripe_custom_params', array(
            'publishable_key' => $this->publishable_key,
            'ajax_url'        => WC_AJAX::get_endpoint('%%endpoint%%'),
        ));
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
            
            // Initialize Stripe API
            \Stripe\Stripe::setApiKey($this->private_key);

            // Get payment source
            $payment_method = isset($_POST['stripe_payment_method']) ? wc_clean($_POST['stripe_payment_method']) : '';

            if (empty($payment_method)) {
                throw new Exception(__('Please provide your card details.', 'wc-advanced-payments'));
            }

            // Create payment intent
            $intent = \Stripe\PaymentIntent::create(array(
                'amount'               => $this->get_stripe_amount($order->get_total()),
                'currency'            => strtolower($order->get_currency()),
                'payment_method'       => $payment_method,
                'confirmation_method' => 'manual',
                'confirm'             => true,
                'description'         => sprintf(
                    __('Order #%1$s by %2$s', 'wc-advanced-payments'),
                    $order->get_order_number(),
                    $order->get_billing_first_name() . ' ' . $order->get_billing_last_name()
                ),
                'metadata'            => array(
                    'order_id'    => $order->get_id(),
                    'customer_id' => $order->get_customer_id(),
                ),
            ));

            if ($intent->status === 'succeeded') {
                // Payment complete
                $order->payment_complete($intent->id);
                
                // Add order note
                $order->add_order_note(
                    sprintf(__('Stripe payment successful (Payment Intent ID: %s)', 'wc-advanced-payments'), 
                    $intent->id)
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
     * Process refunds
     * 
     * @param int $order_id
     * @param float $amount
     * @param string $reason
     */
    public function process_refund($order_id, $amount = null, $reason = '') {
        $order = wc_get_order($order_id);

        if (!$order) {
            return false;
        }

        try {
            \Stripe\Stripe::setApiKey($this->private_key);
            
            $intent_id = $order->get_transaction_id();
            
            $refund = \Stripe\Refund::create(array(
                'payment_intent' => $intent_id,
                'amount'        => $this->get_stripe_amount($amount),
                'reason'        => 'requested_by_customer',
                'metadata'      => array(
                    'order_id'     => $order_id,
                    'reason'       => $reason,
                ),
            ));

            if ($refund->status === 'succeeded') {
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
     * Convert amount to Stripe format
     */
    private function get_stripe_amount($total) {
        return round($total * 100);
    }

    /**
     * Webhook handler
     */
    public function webhook_handler() {
        // Get the webhook handler instance
        $webhook_handler = new WC_Stripe_Webhook_Handler();
        $webhook_handler->handle_webhook();
    }
}

// class-wc-gateway-paypal-custom.php

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
                'default'     => __('Pay via PayPal.', 'wc-advanced-payments'),
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
                'description' => __('Get your API credentials from PayPal.', 'wc-advanced-payments'),