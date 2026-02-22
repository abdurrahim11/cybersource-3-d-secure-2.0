<?php
if (!defined('ABSPATH')) {
    exit;
}

class WC_Gateway_Cybersource_3DS_REST extends WC_Payment_Gateway
{
    private $api;

    public function __construct()
    {
        $this->id = 'cybersource_3ds_rest';
        $this->method_title = 'Cybersource REST 3DS 2.x Lite';
        $this->method_description = 'Simple Cybersource REST + EMV 3DS 2.x gateway.';
        $this->has_fields = true;
        $this->supports = ['products'];

        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option('title', 'Credit/Debit Card (3DS)');
        $this->description = $this->get_option('description', 'Pay securely using 3D Secure 2.x.');

        $this->api = new CS3DS_API([
            'merchant_id' => $this->get_option('merchant_id'),
            'key_id' => $this->get_option('key_id'),
            'secret_key' => $this->get_option('secret_key'),
            'environment' => $this->get_option('environment', 'sandbox'),
            'debug' => $this->get_option('debug') === 'yes',
        ]);

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_receipt_' . $this->id, [$this, 'receipt_page']);
        add_action('woocommerce_api_wc_gateway_' . $this->id, [$this, 'handle_term_callback']);

        CS3DS_Webhook::init();
    }

    public function init_form_fields()
    {
        $this->form_fields = [
            'enabled' => [
                'title' => 'Enable/Disable',
                'type' => 'checkbox',
                'label' => 'Enable Cybersource REST 3DS gateway',
                'default' => 'no',
            ],
            'title' => [
                'title' => 'Title',
                'type' => 'text',
                'default' => 'Credit/Debit Card (3DS)',
            ],
            'description' => [
                'title' => 'Description',
                'type' => 'textarea',
                'default' => 'Pay securely using 3D Secure 2.x.',
            ],
            'environment' => [
                'title' => 'Environment',
                'type' => 'select',
                'default' => 'sandbox',
                'options' => [
                    'sandbox' => 'Sandbox',
                    'production' => 'Production',
                ],
            ],
            'merchant_id' => [
                'title' => 'Merchant ID',
                'type' => 'text',
            ],
            'key_id' => [
                'title' => 'Key ID',
                'type' => 'text',
            ],
            'secret_key' => [
                'title' => 'Shared Secret Key',
                'type' => 'password',
            ],
            'challenge_code' => [
                'title' => '3DS Challenge Code',
                'type' => 'text',
                'default' => '04',
                'description' => 'Common value 04 requests challenge if possible.',
            ],
            'debug' => [
                'title' => 'Debug Log',
                'type' => 'checkbox',
                'label' => 'Enable debug logging',
                'default' => 'yes',
            ],
        ];
    }

    public function payment_fields()
    {
        if (!empty($this->description)) {
            echo wpautop(wp_kses_post($this->description));
        }
        echo '<p><small>Demo card fields only. Use tokenization/hosted fields for real production PCI scope.</small></p>';
        echo '<p class="form-row form-row-wide"><label>Card Number <span class="required">*</span></label>';
        echo '<input type="text" name="cs3ds_card_number" autocomplete="cc-number" /></p>';
        echo '<p class="form-row form-row-first"><label>Expiry Month <span class="required">*</span></label>';
        echo '<input type="text" name="cs3ds_exp_month" placeholder="MM" autocomplete="cc-exp-month" /></p>';
        echo '<p class="form-row form-row-last"><label>Expiry Year <span class="required">*</span></label>';
        echo '<input type="text" name="cs3ds_exp_year" placeholder="YYYY" autocomplete="cc-exp-year" /></p>';
        echo '<div class="clear"></div>';
        echo '<p class="form-row form-row-wide"><label>CVC <span class="required">*</span></label>';
        echo '<input type="password" name="cs3ds_cvc" autocomplete="cc-csc" /></p>';
    }

    public function validate_fields()
    {
        $number = isset($_POST['cs3ds_card_number']) ? preg_replace('/\s+/', '', wc_clean(wp_unslash($_POST['cs3ds_card_number']))) : '';
        $month = isset($_POST['cs3ds_exp_month']) ? wc_clean(wp_unslash($_POST['cs3ds_exp_month'])) : '';
        $year = isset($_POST['cs3ds_exp_year']) ? wc_clean(wp_unslash($_POST['cs3ds_exp_year'])) : '';
        $cvc = isset($_POST['cs3ds_cvc']) ? wc_clean(wp_unslash($_POST['cs3ds_cvc'])) : '';

        if ($number === '' || $month === '' || $year === '' || $cvc === '') {
            wc_add_notice('Please fill in card details.', 'error');
            return false;
        }

        if (!$this->api->is_configured()) {
            wc_add_notice('Payment gateway is not configured yet.', 'error');
            return false;
        }

        return true;
    }

    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);
        $card = $this->get_card_from_post();
        $term_url = add_query_arg(
            [
                'order_id' => $order->get_id(),
                'key' => $order->get_order_key(),
            ],
            WC()->api_request_url('wc_gateway_' . $this->id)
        );

        $setup = $this->api->create_authentication_setup($order);
        if (!$setup['ok']) {
            return $this->payment_error($order, 'Authentication setup failed', $setup);
        }
        $setup_id = !empty($setup['body']['consumerAuthenticationInformation']['referenceId'])
            ? $setup['body']['consumerAuthenticationInformation']['referenceId']
            : '';

        $enrollment = $this->api->check_enrollment(
            $order,
            $card,
            $setup_id,
            $term_url,
            $this->get_option('challenge_code', '04')
        );

        if (!$enrollment['ok']) {
            return $this->payment_error($order, '3DS enrollment/authentication call failed', $enrollment);
        }

        $body = $enrollment['body'];
        $cai = !empty($body['consumerAuthenticationInformation']) ? $body['consumerAuthenticationInformation'] : [];

        $challenge_required = !empty($cai['challengeRequired']) && $cai['challengeRequired'] === 'Y';
        $enrolled = !empty($cai['veresEnrolled']) ? $cai['veresEnrolled'] : '';

        $order->update_meta_data('_cs3ds_card', $card);
        $order->update_meta_data('_cs3ds_auth_initial', wp_json_encode($body));
        $order->save();

        if ($challenge_required) {
            $order->update_status('on-hold', '3DS challenge started.');
            $order->update_meta_data('_cs3ds_challenge_required', 'yes');
            $order->update_meta_data('_cs3ds_challenge_payload', wp_json_encode($body));
            $order->save();

            return [
                'result' => 'success',
                'redirect' => $order->get_checkout_payment_url(true),
            ];
        }

        if ($enrolled === 'N' || $enrolled === 'U' || $enrolled === '') {
            return $this->run_payment_after_auth($order, $card, $body);
        }

        return $this->run_payment_after_auth($order, $card, $body);
    }

    public function receipt_page($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            echo '<p>Invalid order.</p>';
            return;
        }

        $requires_challenge = $order->get_meta('_cs3ds_challenge_required') === 'yes';
        if (!$requires_challenge) {
            echo '<p>Processing payment...</p>';
            return;
        }

        $payload = json_decode($order->get_meta('_cs3ds_challenge_payload'), true);
        $cai = !empty($payload['consumerAuthenticationInformation']) ? $payload['consumerAuthenticationInformation'] : [];
        $stepup_url = !empty($cai['stepUpUrl']) ? $cai['stepUpUrl'] : '';
        $access_token = !empty($cai['accessToken']) ? $cai['accessToken'] : '';
        $pareq = !empty($cai['pareq']) ? $cai['pareq'] : '';

        if ($stepup_url === '' || ($access_token === '' && $pareq === '')) {
            echo '<p>Missing challenge parameters.</p>';
            return;
        }

        $jwt = $access_token !== '' ? $access_token : $pareq;

        echo '<p>Redirecting to bank challenge page...</p>';
        echo '<form id="cs3ds_challenge_form" method="POST" action="' . esc_url($stepup_url) . '">';
        echo '<input type="hidden" name="JWT" value="' . esc_attr($jwt) . '" />';
        echo '</form>';
        echo '<script>document.getElementById("cs3ds_challenge_form").submit();</script>';
    }

    public function handle_term_callback()
    {
        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        $key = isset($_GET['key']) ? wc_clean(wp_unslash($_GET['key'])) : '';
        $order = $order_id ? wc_get_order($order_id) : false;

        if (!$order || $key !== $order->get_order_key()) {
            status_header(400);
            echo 'invalid_order';
            exit;
        }

        $cai_initial = json_decode($order->get_meta('_cs3ds_auth_initial'), true);
        $initial_info = !empty($cai_initial['consumerAuthenticationInformation']) ? $cai_initial['consumerAuthenticationInformation'] : [];
        $tx_id = !empty($initial_info['authenticationTransactionId']) ? $initial_info['authenticationTransactionId'] : '';

        if ($tx_id === '') {
            $order->update_status('failed', 'Missing authentication transaction id.');
            wp_safe_redirect($order->get_checkout_order_received_url());
            exit;
        }

        $challenge_data = [
            'cres' => isset($_POST['cres']) ? wc_clean(wp_unslash($_POST['cres'])) : '',
            'pares' => isset($_POST['PaRes']) ? wc_clean(wp_unslash($_POST['PaRes'])) : '',
        ];

        $auth = $this->api->validate_authentication($order, $tx_id, $challenge_data);
        if (!$auth['ok']) {
            $this->payment_error($order, 'Challenge validation failed', $auth);
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }

        $order->update_meta_data('_cs3ds_auth_final', wp_json_encode($auth['body']));
        $order->update_meta_data('_cs3ds_challenge_required', 'no');
        $order->save();

        $card = $order->get_meta('_cs3ds_card');
        $result = $this->run_payment_after_auth($order, $card, $auth['body']);
        if (is_array($result) && !empty($result['redirect'])) {
            wp_safe_redirect($result['redirect']);
            exit;
        }

        wp_safe_redirect($order->get_checkout_order_received_url());
        exit;
    }

    private function run_payment_after_auth($order, $card, $auth_response)
    {
        $payment = $this->api->create_payment($order, $card, $auth_response);
        if (!$payment['ok']) {
            return $this->payment_error($order, 'Payment authorization/capture failed', $payment);
        }

        $decision = !empty($payment['body']['status']) ? $payment['body']['status'] : '';
        $reason_code = !empty($payment['body']['processorInformation']['responseCode'])
            ? $payment['body']['processorInformation']['responseCode']
            : '';

        $order->update_meta_data('_cs3ds_payment_response', wp_json_encode($payment['body']));
        $order->save();

        if (strtoupper($decision) === 'AUTHORIZED' || strtoupper($decision) === 'PENDING' || strtoupper($decision) === 'COMPLETED') {
            $transaction_id = !empty($payment['body']['id']) ? $payment['body']['id'] : '';
            if ($transaction_id !== '') {
                $order->set_transaction_id($transaction_id);
            }
            $order->payment_complete();
            $order->add_order_note('Cybersource payment success. ResponseCode: ' . $reason_code);
            WC()->cart->empty_cart();

            return [
                'result' => 'success',
                'redirect' => $this->get_return_url($order),
            ];
        }

        return $this->payment_error($order, 'Payment declined after 3DS', $payment);
    }

    private function payment_error($order, $message, $response)
    {
        $details = '';
        if (!empty($response['body']['message'])) {
            $details = ' - ' . sanitize_text_field($response['body']['message']);
        } elseif (!empty($response['body']['details'][0]['reason'])) {
            $details = ' - ' . sanitize_text_field($response['body']['details'][0]['reason']);
        }

        $order->update_status('failed', $message . $details);
        wc_add_notice($message . $details, 'error');

        return [
            'result' => 'failure',
            'redirect' => wc_get_checkout_url(),
        ];
    }

    private function get_card_from_post()
    {
        return [
            'number' => isset($_POST['cs3ds_card_number']) ? wc_clean(wp_unslash($_POST['cs3ds_card_number'])) : '',
            'exp_month' => isset($_POST['cs3ds_exp_month']) ? wc_clean(wp_unslash($_POST['cs3ds_exp_month'])) : '',
            'exp_year' => isset($_POST['cs3ds_exp_year']) ? wc_clean(wp_unslash($_POST['cs3ds_exp_year'])) : '',
            'cvc' => isset($_POST['cs3ds_cvc']) ? wc_clean(wp_unslash($_POST['cs3ds_cvc'])) : '',
        ];
    }
}

