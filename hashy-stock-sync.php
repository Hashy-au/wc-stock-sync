<?php
/**
 * Plugin Name: Hashy Stock Sync
 * Plugin URI: https://hashy.com.au
 * Description: Host + Agent WooCommerce stock/price sync.
 * Version: 0.7.0
 * Author: Hashy-au
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: hashy-stock-sync
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 7.0
 * WC tested up to: 11.1
 *
 * @package Hashy_AU
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether the pre-rename plugin (WC Stock Sync, slug wc-stock-sync) is active.
 *
 * The same test as is_plugin_active(), inlined so that
 * wp-admin/includes/plugin.php is not loaded on every front-end request.
 */
function hashy_stock_sync_old_plugin_active(): bool {
	$old = 'wc-stock-sync/wc-stock-sync.php';
	if ( in_array( $old, (array) get_option( 'active_plugins', array() ), true ) ) {
		return true;
	}
	if ( is_multisite() ) {
		$network = get_site_option( 'active_sitewide_plugins', array() );
		return is_array( $network ) && isset( $network[ $old ] );
	}
	return false;
}

// Refuse to boot while the old plugin is active. Its 0.6.0 migration shim
// activates this plugin and then deactivates itself, but a half-finished run,
// or a manual install beside it, must never leave two sets of hooks live
// (double pushes, double decrements). Settings, secrets, queues and the log
// live in options both plugins share, so nothing is lost while paused.
if ( hashy_stock_sync_old_plugin_active() ) {
	add_action(
		'admin_notices',
		function () {
			// Re-check at render time: when the shim deactivates the old plugin
			// in this same request, the pause is already over.
			if ( ! current_user_can( 'activate_plugins' ) || ! hashy_stock_sync_old_plugin_active() ) {
				return;
			}
			$old = 'wc-stock-sync/wc-stock-sync.php';
			$url = wp_nonce_url(
				add_query_arg(
					array(
						'action' => 'deactivate',
						'plugin' => $old,
					),
					admin_url( 'plugins.php' )
				),
				'deactivate-plugin_' . $old
			);
			echo '<div class="notice notice-error"><p><strong>Hashy Stock Sync</strong> is installed but paused because the old <strong>WC Stock Sync</strong> plugin is still active. <a href="' . esc_url( $url ) . '">Deactivate WC Stock Sync</a> and this plugin starts on the next page load. Settings, secrets and queues are shared and unaffected.</p></div>';
		}
	);
	return; // Nothing else in this file runs: no constants, no bootstrap, no update checker.
}

define( 'WC_STOCK_SYNC_VERSION', '0.7.0' );
define( 'HASHY_AU_PLUGIN_FILE', __FILE__ );
define( 'WC_STOCK_SYNC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WC_STOCK_SYNC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once WC_STOCK_SYNC_PLUGIN_DIR . 'includes/class-hashy-au-bootstrap.php';
// Loaded at file scope (not only inside the WooCommerce-gated bootstrap)
// because the GitHub update checker reads the GitHub token through it.
require_once WC_STOCK_SYNC_PLUGIN_DIR . 'includes/class-hashy-au-secrets.php';

// Activation hooks must be registered at file scope: during the activation
// request the plugin file is included after plugins_loaded has already fired,
// so anything registered inside the bootstrap's init() never runs.
register_activation_hook( __FILE__, array( 'Hashy_AU_Bootstrap', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Hashy_AU_Bootstrap', 'deactivate' ) );

// One-time notice after the old plugin's migration shim has handed over.
add_action( 'admin_notices', array( 'Hashy_AU_Bootstrap', 'maybe_show_migrated_notice' ) );

// HPOS (custom order tables) compatibility.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

// GitHub edition only: automatic updates from GitHub Releases. The
// WordPress.org directory build (scripts/build-release.ps1 -Directory) leaves
// this file and the update-checker library under includes/lib/ out, because
// the directory serves updates itself and does not permit bundled updaters.
if ( file_exists( WC_STOCK_SYNC_PLUGIN_DIR . 'includes/github-updates.php' ) ) {
	require_once WC_STOCK_SYNC_PLUGIN_DIR . 'includes/github-updates.php';
}

// Boot on init (priority 5), not plugins_loaded: the classes below reach
// WooCommerce APIs that translate strings in the woocommerce domain, and
// WordPress 6.7+ warns (_load_textdomain_just_in_time) when that happens
// before init. WooCommerce loads its own textdomain on init at priority 0.
add_action(
	'init',
	function () {
		Hashy_AU_Bootstrap::instance()->init();
	},
	5
);
