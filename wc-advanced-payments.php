<?php
/**
 * Plugin Name: Advanced WooCommerce Payment Gateways
 * Description: Custom integration for Stripe and PayPal with subscription and refund support
 * Version: 1.0.0
 * Author: Arotiana Randrianasolo
 */

if (!defined('ABSPATH')) {
    exit;
}

class Advanced_WC_Payment_Gateways {
    public function __construct() {
        // Load dependencies
        require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';
        
        // Initialize Stripe
        \Stripe\Stripe::setApiKey(get_option('stripe_secret_key'));
        
        // Add hooks
        add_filter('woocommerce_payment_gateways', [$this, 'add_gateways']);
        add_action('plugins_loaded', [$this, 'init_gateways']);
        add_action('woocommerce_api_webhook_handler', [$this, 'handle_webhooks']);
    }

    public function add_gateways($gateways) {
        $gateways[] = 'WC_Gateway_Stripe_Custom';
        $gateways[] = 'WC_Gateway_PayPal_Custom';
        return $gateways;
    }

    public function init_gateways() {
        if (!class_exists('WC_Payment_Gateway')) return;
        
        require_once plugin_dir_path(__FILE__) . 'includes/class-wc-gateway-stripe-custom.php';
        require_once plugin_dir_path(__FILE__) . 'includes/class-wc-gateway-paypal-custom.php';
    }
}

// Stripe Gateway Implementation
class WC_Gateway_Stripe_Custom extends WC_Payment_Gateway {
    public function __construct() {
        $this->id = 'stripe_custom';
        $this->method_title = 'Stripe Custom';
        $this->method_description = 'Custom Stripe integration with subscription support';
        $this->supports = [
            'products',
            'refunds',
            'subscriptions',
            'subscription_cancellation',
            'subscription_suspension',
            'subscription_reactivation',
            'subscription_amount_changes',
            'subscription_date_changes',
        ];

        $this->init_form_fields();
        $this->init_settings();

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_scheduled_subscription_payment_' . $this->id, [$this, 'process_subscription_payment'], 10, 2);
    }

    public function process_payment($order_id) {
        try {
            $order = wc_get_order($order_id);
            $subscription = $this->get_subscription_from_order($order);

            if ($subscription) {
                return $this->process_subscription_payment($order, $subscription);
            }

            // Regular payment processing
            $payment_intent = \Stripe\PaymentIntent::create([
                'amount' => $this->get_stripe_amount($order->get_total()),
                'currency' => strtolower($order->get_currency()),
                'description' => sprintf('Order #%s by %s', $order->get_order_number(), $order->get_billing_email()),
                'metadata' => [
                    'order_id' => $order->get_id(),
                    'customer_email' => $order->get_billing_email()
                ],
            ]);

            // Store payment intent ID
            $order->update_meta_data('_stripe_payment_intent', $payment_intent->id);
            $order->save();

            return [
                'result' => 'success',
                'redirect' => $this->get_payment_confirmation_url($payment_intent)
            ];

        } catch (\Stripe\Exception\ApiErrorException $e) {
            wc_add_notice($e->getMessage(), 'error');
            return ['result' => 'fail'];
        }
    }

    public function process_subscription_payment($order, $subscription) {
        try {
            // Create or retrieve Stripe Customer
            $customer = $this->get_or_create_stripe_customer($order);

            // Create subscription in Stripe
            $stripe_subscription = \Stripe\Subscription::create([
                'customer' => $customer->id,
                'items' => [[
                    'price' => $this->get_stripe_price_id($subscription),
                ]],
                'metadata' => [
                    'subscription_id' => $subscription->get_id(),
                    'order_id' => $order->get_id()
                ],
                'expand' => ['latest_invoice.payment_intent'],
            ]);

            // Store subscription ID
            $subscription->update_meta_data('_stripe_subscription_id', $stripe_subscription->id);
            $subscription->save();

            return [
                'result' => 'success',
                'redirect' => $this->get_payment_confirmation_url($stripe_subscription->latest_invoice->payment_intent)
            ];

        } catch (\Stripe\Exception\ApiErrorException $e) {
            wc_add_notice($e->getMessage(), 'error');
            return ['result' => 'fail'];
        }
    }

    public function process_refund($order_id, $amount = null, $reason = '') {
        try {
            $order = wc_get_order($order_id);
            $payment_intent_id = $order->get_meta('_stripe_payment_intent');

            if (!$payment_intent_id) {
                throw new Exception('Payment intent ID not found');
            }

            $refund = \Stripe\Refund::create([
                'payment_intent' => $payment_intent_id,
                'amount' => $this->get_stripe_amount($amount),
                'metadata' => [
                    'order_id' => $order_id,
                    'reason' => $reason
                ]
            ]);

            $order->add_order_note(
                sprintf(__('Refunded %s via Stripe - Refund ID: %s', 'woocommerce'), 
                wc_price($amount), 
                $refund->id)
            );

            return true;

        } catch (Exception $e) {
            return new WP_Error('refund_error', $e->getMessage());
        }
    }

    private function get_or_create_stripe_customer($order) {
        $customer_id = get_user_meta($order->get_user_id(), '_stripe_customer_id', true);

        if ($customer_id) {
            return \Stripe\Customer::retrieve($customer_id);
        }

        $customer = \Stripe\Customer::create([
            'email' => $order->get_billing_email(),
            'name' => $order->get_formatted_billing_full_name(),
            'metadata' => [
                'wordpress_user_id' => $order->get_user_id(),
            ]
        ]);

        update_user_meta($order->get_user_id(), '_stripe_customer_id', $customer->id);

        return $customer;
    }
}

// PayPal Gateway Implementation
class WC_Gateway_PayPal_Custom extends WC_Payment_Gateway {
    public function __construct() {
        $this->id = 'paypal_custom';
        $this->method_title = 'PayPal Custom';
        $this->method_description = 'Custom PayPal integration with subscription support';
        $this->supports = [
            'products',
            'refunds',
            'subscriptions'
        ];

        $this->init_form_fields();
        $this->init_settings();

        // Initialize PayPal SDK
        $this->paypal = new PayPalHttp\Client([
            'mode' => $this->settings['environment'],
            'client_id' => $this->settings['client_id'],
            'client_secret' => $this->settings['client_secret']
        ]);
    }

    public function process_payment($order_id) {
        try {
            $order = wc_get_order($order_id);
            $subscription = $this->get_subscription_from_order($order);

            if ($subscription) {
                return $this->process_subscription_payment($order, $subscription);
            }

            // Create PayPal order
            $paypal_order = $this->create_paypal_order($order);

            // Store PayPal order ID
            $order->update_meta_data('_paypal_order_id', $paypal_order->id);
            $order->save();

            return [
                'result' => 'success',
                'redirect' => $paypal_order->links[1]->href // Checkout URL
            ];

        } catch (Exception $e) {
            wc_add_notice($e->getMessage(), 'error');
            return ['result' => 'fail'];
        }
    }

    public function process_subscription_payment($order, $subscription) {
        try {
            // Create PayPal subscription
            $paypal_subscription = $this->create_paypal_subscription($subscription);

            // Store subscription ID
            $subscription->update_meta_data('_paypal_subscription_id', $paypal_subscription->id);
            $subscription->save();

            return [
                'result' => 'success',
                'redirect' => $paypal_subscription->links[0]->href // Approval URL
            ];

        } catch (Exception $e) {
            wc_add_notice($e->getMessage(), 'error');
            return ['result' => 'fail'];
        }
    }

    public function process_refund($order_id, $amount = null, $reason = '') {
        try {
            $order = wc_get_order($order_id);
            $paypal_order_id = $order->get_meta('_paypal_order_id');

            if (!$paypal_order_id) {
                throw new Exception('PayPal order ID not found');
            }

            $refund = $this->paypal->refundOrder($paypal_order_id, [
                'amount' => [
                    'value' => $amount,
                    'currency_code' => $order->get_currency()
                ],
                'note_to_payer' => $reason
            ]);

            $order->add_order_note(
                sprintf(__('Refunded %s via PayPal - Refund ID: %s', 'woocommerce'), 
                wc_price($amount), 
                $refund->id)
            );

            return true;

        } catch (Exception $e) {
            return new WP_Error('refund_error', $e->getMessage());
        }
    }
}

// Initialize the plugin
new Advanced_WC_Payment_Gateways();