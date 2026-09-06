<?php
/**
 * Plugin Name: WC Stock Sync
 * Plugin URI: https://hashy.com.au
 * Description: Host + Agent WooCommerce stock/price sync.
 * Version: 0.5.1
 * Author: Hashy-au
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wc-stock-sync
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

define( 'WC_STOCK_SYNC_VERSION', '0.5.1' );
define( 'HASHY_AU_PLUGIN_FILE', __FILE__ );
define( 'WC_STOCK_SYNC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WC_STOCK_SYNC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once WC_STOCK_SYNC_PLUGIN_DIR . 'includes/class-wc-stock-sync-bootstrap.php';
// Loaded at file scope (not only inside the WooCommerce-gated bootstrap)
// because the update checker below reads the GitHub token through it.
require_once WC_STOCK_SYNC_PLUGIN_DIR . 'includes/class-hashy-au-secrets.php';

// Activation hooks must be registered at file scope: during the activation
// request the plugin file is included after plugins_loaded has already fired,
// so anything registered inside the bootstrap's init() never runs.
register_activation_hook( __FILE__, array( 'Hashy_AU_Bootstrap', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Hashy_AU_Bootstrap', 'deactivate' ) );

// HPOS (custom order tables) compatibility.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

// Automatic updates from the public GitHub repo. Wired at file scope (not
// inside the WooCommerce-gated bootstrap) so update checks keep working even
// when WooCommerce is deactivated. No token is required for a public repo;
// one may still be supplied (WCSS_GITHUB_TOKEN in wp-config.php, or the
// settings field) to raise the GitHub API rate limit or if the repo is ever
// made private again.
if ( ! defined( 'WCSS_GITHUB_REPO' ) ) {
	define( 'WCSS_GITHUB_REPO', 'https://github.com/Hashy-au/wc-stock-sync/' );
}
add_action(
	'init',
	function () {
		require_once WC_STOCK_SYNC_PLUGIN_DIR . 'includes/lib/plugin-update-checker/plugin-update-checker.php';
		$wcss_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			WCSS_GITHUB_REPO,
			__FILE__,
			'wc-stock-sync'
		);

		if ( defined( 'WCSS_GITHUB_TOKEN' ) && WCSS_GITHUB_TOKEN !== '' ) {
			$wcss_token = (string) WCSS_GITHUB_TOKEN;
		} elseif ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			// The token lives in the non-autoloaded hashy_au_secrets option, so it
			// is only looked up where the update checker can actually run a check
			// (its hooks are admin_init, the load-update-* screens, its cron event
			// and WP-CLI). Front-end requests never need it.
			$wcss_token = Hashy_AU_Secrets::get_github_token();
		} else {
			$wcss_token = '';
		}
		if ( '' !== $wcss_token ) {
			$wcss_update_checker->setAuthentication( $wcss_token );
		}

		$wcss_update_checker->getVcsApi()->enableReleaseAssets( '/^wc-stock-sync\.zip$/' );
	}
);

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
