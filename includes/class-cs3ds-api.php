<?php
if (!defined('ABSPATH')) {
    exit;
}

class CS3DS_API
{
    private $merchant_id;
    private $key_id;
    private $secret_key;
    private $environment;
    private $debug;

    public function __construct($config = [])
    {
        $this->merchant_id = isset($config['merchant_id']) ? trim($config['merchant_id']) : '';
        $this->key_id = isset($config['key_id']) ? trim($config['key_id']) : '';
        $this->secret_key = isset($config['secret_key']) ? trim($config['secret_key']) : '';
        $this->environment = isset($config['environment']) ? $config['environment'] : 'sandbox';
        $this->debug = !empty($config['debug']);
    }

    public function is_configured()
    {
        return $this->merchant_id !== '' && $this->key_id !== '' && $this->secret_key !== '';
    }

    public function create_authentication_setup($order)
    {
        $payload = [
            'clientReferenceInformation' => [
                'code' => (string) $order->get_id(),
            ],
        ];

        return $this->request('POST', '/risk/v1/authentication-setups', $payload);
    }

    public function check_enrollment($order, $card, $setup_id, $term_url, $challenge_code = '04')
    {
        $billing = $order->get_address('billing');
        $payload = [
            'clientReferenceInformation' => [
                'code' => (string) $order->get_id(),
            ],
            'paymentInformation' => [
                'card' => [
                    'number' => preg_replace('/\s+/', '', $card['number']),
                    'expirationMonth' => $card['exp_month'],
                    'expirationYear' => $card['exp_year'],
                ],
            ],
            'orderInformation' => [
                'amountDetails' => [
                    'totalAmount' => wc_format_decimal($order->get_total(), 2),
                    'currency' => $order->get_currency(),
                ],
                'billTo' => [
                    'firstName' => isset($billing['first_name']) ? $billing['first_name'] : '',
                    'lastName' => isset($billing['last_name']) ? $billing['last_name'] : '',
                    'address1' => isset($billing['address_1']) ? $billing['address_1'] : '',
                    'locality' => isset($billing['city']) ? $billing['city'] : '',
                    'administrativeArea' => isset($billing['state']) ? $billing['state'] : '',
                    'postalCode' => isset($billing['postcode']) ? $billing['postcode'] : '',
                    'country' => isset($billing['country']) ? $billing['country'] : '',
                    'email' => $order->get_billing_email(),
                    'phoneNumber' => $order->get_billing_phone(),
                ],
            ],
            'consumerAuthenticationInformation' => [
                'referenceId' => $setup_id,
                'returnUrl' => $term_url,
                'challengeCode' => $challenge_code,
            ],
        ];

        return $this->request('POST', '/risk/v1/authentications', $payload);
    }

    public function validate_authentication($order, $auth_transaction_id, $challenge_data = [])
    {
        $payload = [
            'clientReferenceInformation' => [
                'code' => (string) $order->get_id(),
            ],
            'consumerAuthenticationInformation' => [
                'authenticationTransactionId' => $auth_transaction_id,
            ],
        ];

        if (!empty($challenge_data['cres'])) {
            $payload['consumerAuthenticationInformation']['cres'] = $challenge_data['cres'];
        }
        if (!empty($challenge_data['pares'])) {
            $payload['consumerAuthenticationInformation']['pares'] = $challenge_data['pares'];
        }

        return $this->request('POST', '/risk/v1/authentications', $payload);
    }

    public function create_payment($order, $card, $auth_response = [])
    {
        $billing = $order->get_address('billing');
        $commerce_indicator = 'internet';
        $cavv = '';
        $xid = '';
        $eci = '';

        if (!empty($auth_response['consumerAuthenticationInformation'])) {
            $cai = $auth_response['consumerAuthenticationInformation'];
            $commerce_indicator = !empty($cai['commerceIndicator']) ? $cai['commerceIndicator'] : $commerce_indicator;
            $cavv = !empty($cai['cavv']) ? $cai['cavv'] : '';
            $xid = !empty($cai['xid']) ? $cai['xid'] : '';
            $eci = !empty($cai['eciRaw']) ? $cai['eciRaw'] : '';
        }

        $payload = [
            'clientReferenceInformation' => [
                'code' => (string) $order->get_id(),
            ],
            'processingInformation' => [
                'capture' => true,
                'commerceIndicator' => $commerce_indicator,
            ],
            'paymentInformation' => [
                'card' => [
                    'number' => preg_replace('/\s+/', '', $card['number']),
                    'expirationMonth' => $card['exp_month'],
                    'expirationYear' => $card['exp_year'],
                    'securityCode' => $card['cvc'],
                ],
            ],
            'orderInformation' => [
                'amountDetails' => [
                    'totalAmount' => wc_format_decimal($order->get_total(), 2),
                    'currency' => $order->get_currency(),
                ],
                'billTo' => [
                    'firstName' => isset($billing['first_name']) ? $billing['first_name'] : '',
                    'lastName' => isset($billing['last_name']) ? $billing['last_name'] : '',
                    'address1' => isset($billing['address_1']) ? $billing['address_1'] : '',
                    'locality' => isset($billing['city']) ? $billing['city'] : '',
                    'administrativeArea' => isset($billing['state']) ? $billing['state'] : '',
                    'postalCode' => isset($billing['postcode']) ? $billing['postcode'] : '',
                    'country' => isset($billing['country']) ? $billing['country'] : '',
                    'email' => $order->get_billing_email(),
                    'phoneNumber' => $order->get_billing_phone(),
                ],
            ],
        ];

        if ($cavv !== '' || $xid !== '' || $eci !== '') {
            $payload['consumerAuthenticationInformation'] = [
                'cavv' => $cavv,
                'xid' => $xid,
                'eciRaw' => $eci,
            ];
        }

        return $this->request('POST', '/pts/v2/payments', $payload);
    }

    private function request($method, $resource, $payload = [])
    {
        $host = $this->environment === 'production' ? 'api.cybersource.com' : 'apitest.cybersource.com';
        $url = 'https://' . $host . $resource;
        $body = wp_json_encode($payload);
        $date = gmdate('D, d M Y H:i:s') . ' GMT';
        $digest = 'SHA-256=' . base64_encode(hash('sha256', $body, true));
        $signature_header = $this->build_signature($host, $resource, $date, $digest);

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'v-c-merchant-id' => $this->merchant_id,
            'Date' => $date,
            'Host' => $host,
            'Digest' => $digest,
            'Signature' => $signature_header,
        ];

        $args = [
            'method' => strtoupper($method),
            'headers' => $headers,
            'body' => $body,
            'timeout' => 45,
        ];

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            return ['ok' => false, 'status' => 0, 'body' => ['message' => $response->get_error_message()]];
        }

        $status = wp_remote_retrieve_response_code($response);
        $raw_body = wp_remote_retrieve_body($response);
        $parsed = json_decode($raw_body, true);
        if (!is_array($parsed)) {
            $parsed = ['raw' => $raw_body];
        }

        if ($this->debug && function_exists('wc_get_logger')) {
            wc_get_logger()->debug(
                sprintf('Cybersource %s %s -> %s', strtoupper($method), $resource, $status),
                ['source' => 'wc-cybersource-3ds-lite']
            );
            wc_get_logger()->debug(wp_json_encode($parsed), ['source' => 'wc-cybersource-3ds-lite']);
        }

        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'body' => $parsed,
        ];
    }

    private function build_signature($host, $resource, $date, $digest)
    {
        $signature_string = '(request-target): post ' . strtolower($resource) . "\n" .
            'host: ' . $host . "\n" .
            'date: ' . $date . "\n" .
            'digest: ' . $digest . "\n" .
            'v-c-merchant-id: ' . $this->merchant_id;

        $decoded_secret = base64_decode($this->secret_key, true);
        if ($decoded_secret === false) {
            $decoded_secret = $this->secret_key;
        }

        $hashed = hash_hmac('sha256', $signature_string, $decoded_secret, true);
        $signature = base64_encode($hashed);

        return sprintf(
            'keyid="%s", algorithm="HmacSHA256", headers="(request-target) host date digest v-c-merchant-id", signature="%s"',
            $this->key_id,
            $signature
        );
    }
}

