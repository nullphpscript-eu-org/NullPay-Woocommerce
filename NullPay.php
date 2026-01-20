<?php
/*
 * Plugin Name: NullPay
 * Plugin URI: https://wordpress.org/plugins/nullpay/
 * Description: This plugin allows your customers to pay with Bkash, Nagad, Rocket, and all BD gateways via NullPay.
 * Author: Nullphpscript.eu.org
 * Author URI: https://nullphpscript.eu.org/
 * Version: 1.0.0
 * Requires at least: 5.2
 * Requires PHP: 7.2
 * License: GPL v2 or later
 * Text Domain: nullpay
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/*
 * This action hook registers our PHP class as a WooCommerce payment gateway
 */
add_action('plugins_loaded', 'nullpay_init_gateway_class');

function nullpay_init_gateway_class()
{
    if (!class_exists('WC_Payment_Gateway')) return;

    class WC_nullpay_Gateway extends WC_Payment_Gateway
    {
        public function __construct()
        {
            $this->id = 'nullpay';
            $this->icon = '#';
            $this->has_fields = false;
            $this->method_title = esc_html__('NullPay', 'Nullpay');
            $this->method_description = esc_html__('Pay With NullPay', 'Nullpay');

            $this->supports = array('products');

            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->enabled = $this->get_option('enabled');

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_api_' . strtolower(get_class($this)), array($this, 'handle_webhook'));
        }

        public function init_form_fields()
        {
            $this->form_fields = array(
                'enabled' => array(
                    'title'       => 'Enable/Disable',
                    'label'       => 'Enable NullPay',
                    'type'        => 'checkbox',
                    'description' => '',
                    'default'     => 'no'
                ),
                'title' => array(
                    'title'       => 'Title',
                    'type'        => 'text',
                    'description' => 'This controls the title which the user sees during checkout.',
                    'default'     => 'NullPay Gateway',
                    'desc_tip'    => true,
                ),
                'apikeys' => array(
                    'title'       => 'Enter API Key',
                    'type'        => 'text',
                    'description' => '',
                    'default'     => '',
                    'desc_tip'    => true,
                ),
                'currency_rate' => array(
                    'title'       => 'Enter USD Rate',
                    'type'        => 'number',
                    'description' => '',
                    'default'     => '125',
                    'desc_tip'    => true,
                ),
                'is_digital' => array(
                    'title'       => 'Enable/Disable Digital product',
                    'label'       => 'Enable Digital product',
                    'type'        => 'checkbox',
                    'description' => '',
                    'default'     => 'no'
                ),
                'payment_site' => array(
                    'title'             => 'Payment Site URL',
                    'type'              => 'text',
                    'description'        => '',
                    'default'           => 'https://secure-pay.nullphpscript.eu.org/',
                    'desc_tip'          => true,
                ),
            );
        }

        public function process_payment($order_id)
        {
            $order = wc_get_order($order_id);
            $current_user = wp_get_current_user();
            $total = $order->get_total();

            if ($order->get_currency() == 'USD') {
                $total = $total * (float) $this->get_option('currency_rate');
            }

            if ($order->get_status() != 'completed') {
                $order->update_status('pending', esc_html__('Customer is being redirected to NullPay', 'Nullpay'));
            }

            $data = array(
                "cus_name"    => $current_user->display_name,
                "cus_email"   => $current_user->user_email,
                "amount"      => $total,
                "webhook_url" => site_url('/?wc-api=wc_nullpay_gateway&order_id=' . $order->get_id()),
                "success_url" => $this->get_return_url($order),
                "cancel_url"  => wc_get_checkout_url()
            );

            $header = array(
                "api" => $this->get_option('apikeys'),
                "url" => $this->get_option('payment_site') . "api/payment/create"
            );

            $response = $this->create_payment($data, $header);
            $data_res = json_decode($response, true);

            if (isset($data_res['payment_url'])) {
                return array(
                    'result'   => 'success',
                    'redirect' => $data_res['payment_url']
                );
            } else {
                wc_add_notice(esc_html__('Payment gateway error. Please try again.', 'Nullpay'), 'error');
                return;
            }
        }

        public function create_payment($data = "", $header = '')
        {
            $url = $header['url'];
            $args = array(
                'body'        => json_encode($data),
                'timeout'     => 45,
                'headers'     => array(
                    'Content-Type' => 'application/json',
                    'API-KEY'      => $header['api'],
                ),
                'sslverify'   => false,
            );

            $response = wp_remote_post($url, $args);

            if (is_wp_error($response)) {
                return '';
            }

            return wp_remote_retrieve_body($response);
        }

        public function update_order_status($order)
        {
            $transactionId = isset($_REQUEST['transactionId']) ? sanitize_text_field(wp_unslash($_REQUEST['transactionId'])) : '';
            if (empty($transactionId)) return;

            $data = array("transaction_id" => $transactionId);
            $header = array(
                "api" => $this->get_option('apikeys'),
                "url" => $this->get_option('payment_site') . "api/payment/verify"
            );

            $response = $this->create_payment($data, $header);
            $data_res = json_decode($response, true);

            if ($order->get_status() !== 'completed' && isset($data_res['status']) && $data_res['status'] == "COMPLETED") {
                
                $transaction_id = $data_res['transaction_id'];
                $amount         = $data_res['amount'];
                $sender_number  = $data_res['cus_email'];
                $payment_method = 'NullPay';

                if ($this->get_option('is_digital') === 'yes') {
                    /* translators: 1: Payment method name, 2: Transaction ID, 3: Sender Number */
                    $message = sprintf(
                        esc_html__('NullPay payment was successfully completed. Payment Method: %1$s, Transaction ID: %2$s, Sender Number: %3$s', 'Nullpay'),
                        $payment_method,
                        $transaction_id,
                        $sender_number
                    );
                    $order->update_status('completed', $message);
                    $order->reduce_order_stock();
                    
                    /* translators: %s: Transaction ID */
                    $note = sprintf(
                        esc_html__('Payment completed via PGW URL checkout. trx id: %s', 'Nullpay'),
                        $transaction_id
                    );
                    $order->add_order_note($note);
                    $order->payment_complete($transaction_id);
                } else {
                    /* translators: 1: Payment method name, 2: Transaction ID, 3: Amount, 4: Sender Number */
                    $message = sprintf(
                        esc_html__('NullPay payment was successfully processed. Payment Method: %1$s, Transaction ID: %2$s, Amount: %3$s, Sender Number: %4$s', 'Nullpay'),
                        $payment_method,
                        $transaction_id,
                        $amount,
                        $sender_number
                    );
                    $order->update_status('processing', $message);
                    $order->reduce_order_stock();
                    $order->payment_complete($transaction_id);
                }
                return true;
            } else {
                $order->update_status('on-hold', esc_html__('NullPay payment was successfully on-hold. Transaction id not found. Please check it manually.', 'Nullpay'));
                return true;
            }
        }

        public function handle_webhook()
        {
            $order_id = isset($_GET['order_id']) ? absint(wp_unslash($_GET['order_id'])) : 0;
            $order = wc_get_order($order_id);

            if ($order) {
                $this->update_order_status($order);
            }

            status_header(200);
            echo json_encode(['message' => 'Webhook received and processed.']);
            exit();
        }
    }

    function nullpay_add_gateway_class($gateways)
    {
        $gateways[] = 'WC_nullpay_Gateway';
        return $gateways;
    }
    add_filter('woocommerce_payment_gateways', 'nullpay_add_gateway_class');
}