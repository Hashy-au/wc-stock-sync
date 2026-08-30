<?php
/**
 * Plugin Name: WC Stock Sync
 * Plugin URI: https://hashy.com.au
 * Description: Host + Agent WooCommerce stock/price sync.
 * Version: 0.4.11
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

add_action('plugins_loaded', function () {
    Hashy_AU_Bootstrap::instance()->init();
});
