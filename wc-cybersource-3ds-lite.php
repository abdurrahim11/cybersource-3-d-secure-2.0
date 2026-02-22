<?php
/**
 * Plugin Name: WC Cybersource 3DS 2.x Lite
 * Description: Simple WooCommerce Cybersource REST gateway with 3DS 2.x enrollment/auth/challenge callbacks.
 * Version: 1.0.0
 * Author: আব্দুর রহিম
 * Text Domain: wc-cybersource-3ds-lite
 */
if (!defined('ABSPATH')) {
    exit;
}

define('WCCS3DS_LITE_VERSION', '1.0.0');
define('WCCS3DS_LITE_FILE', __FILE__);
define('WCCS3DS_LITE_PATH', plugin_dir_path(__FILE__));
define('WCCS3DS_LITE_URL', plugin_dir_url(__FILE__));

add_action('plugins_loaded', function () {
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    require_once WCCS3DS_LITE_PATH . 'includes/class-cs3ds-api.php';
    require_once WCCS3DS_LITE_PATH . 'includes/class-cs3ds-webhook.php';
    require_once WCCS3DS_LITE_PATH . 'includes/class-wc-gateway-cybersource-3ds.php';

    add_filter('woocommerce_payment_gateways', function ($gateways) {
        $gateways[] = 'WC_Gateway_Cybersource_3DS_REST';
        return $gateways;
    });
});

