<?php
if (!defined('ABSPATH')) {
    exit;
}

class CS3DS_Webhook
{
    public static function init()
    {
        add_action('woocommerce_api_cs3ds_webhook', [__CLASS__, 'handle']);
    }

    public static function handle()
    {
        $payload = file_get_contents('php://input');
        $data = json_decode($payload, true);

        if (!is_array($data)) {
            status_header(400);
            echo 'invalid_payload';
            exit;
        }

        $order_id = 0;
        if (!empty($data['clientReferenceInformation']['code'])) {
            $order_id = absint($data['clientReferenceInformation']['code']);
        }

        if ($order_id > 0) {
            $order = wc_get_order($order_id);
            if ($order) {
                $event_type = !empty($data['eventType']) ? $data['eventType'] : 'unknown';
                $order->add_order_note('Cybersource webhook: ' . sanitize_text_field($event_type));
                $order->update_meta_data('_cs3ds_last_webhook', wp_json_encode($data));
                $order->save();
            }
        }

        status_header(200);
        echo 'ok';
        exit;
    }
}

