<?php
/**
 * Plugin Name: WC Stock Sync
 * Plugin URI: https://hashy.com.au
 * Description: Host + Agent WooCommerce stock/price sync.
 * Version: 0.4.12
 * Author: Hashy-au
 * Text Domain: wc-stock-sync
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 9.0
 *
 * @package Hashy_AU
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WC_STOCK_SYNC_VERSION', '0.4.12');
define('HASHY_AU_PLUGIN_FILE', __FILE__);
define('WC_STOCK_SYNC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WC_STOCK_SYNC_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once WC_STOCK_SYNC_PLUGIN_DIR . 'includes/class-wc-stock-sync-bootstrap.php';

// Activation hooks must be registered at file scope: during the activation
// request the plugin file is included after plugins_loaded has already fired,
// so anything registered inside the bootstrap's init() never runs.
register_activation_hook(__FILE__, ['Hashy_AU_Bootstrap', 'activate']);
register_deactivation_hook(__FILE__, ['Hashy_AU_Bootstrap', 'deactivate']);

// HPOS (custom order tables) compatibility.
add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

add_action('plugins_loaded', function () {
    Hashy_AU_Bootstrap::instance()->init();
});
