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

// Automatic updates from the private GitHub repo. Wired at file scope (not
// inside the WooCommerce-gated bootstrap) so update checks keep working even
// when WooCommerce is deactivated. Requires a fine-grained GitHub token
// (Contents: Read-only on the repo) — set it under WC Stock Sync → Settings,
// or define WCSS_GITHUB_TOKEN in wp-config.php. With no token configured the
// checker is not instantiated at all, so private-repo 404s never spam logs.
if (!defined('WCSS_GITHUB_REPO')) {
    define('WCSS_GITHUB_REPO', 'https://github.com/hashy-au/wc-stock-sync/');
}
add_action('init', function () {
    if (defined('WCSS_GITHUB_TOKEN') && WCSS_GITHUB_TOKEN !== '') {
        $wcss_token = (string) WCSS_GITHUB_TOKEN;
    } else {
        // Read the option directly to avoid load-order dependencies.
        $wcss_settings = get_option('hashy_au_settings', []);
        $wcss_token = is_array($wcss_settings) ? (string) ($wcss_settings['updates']['github_token'] ?? '') : '';
    }
    if ('' === $wcss_token) {
        return;
    }

    require_once WC_STOCK_SYNC_PLUGIN_DIR . 'includes/lib/plugin-update-checker/plugin-update-checker.php';
    $wcss_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        WCSS_GITHUB_REPO,
        __FILE__,
        'wc-stock-sync'
    );
    $wcss_update_checker->setAuthentication($wcss_token);
    $wcss_update_checker->getVcsApi()->enableReleaseAssets('/^wc-stock-sync\.zip$/');
});

add_action('plugins_loaded', function () {
    Hashy_AU_Bootstrap::instance()->init();
});
