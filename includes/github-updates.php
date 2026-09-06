<?php
/**
 * GitHub edition: automatic updates from GitHub Releases.
 *
 * Required by the main file when present. The WordPress.org directory build
 * (scripts/build-release.ps1 -Directory) omits this file together with
 * includes/lib/plugin-update-checker/; the directory serves updates itself
 * and does not permit bundled updaters. The settings page shows the GitHub
 * token field only while WCSS_GITHUB_REPO (defined here) exists.
 *
 * Wired at plugin file scope, not inside the WooCommerce-gated bootstrap, so
 * update checks keep working even when WooCommerce is deactivated. No token
 * is required for a public repo; one may still be supplied (WCSS_GITHUB_TOKEN
 * in wp-config.php, or the settings field) to raise the GitHub API rate limit
 * or if the repo is ever made private again.
 *
 * @package Hashy_AU
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WCSS_GITHUB_REPO' ) ) {
	define( 'WCSS_GITHUB_REPO', 'https://github.com/Hashy-au/wc-stock-sync/' );
}

add_action(
	'init',
	function () {
		require_once WC_STOCK_SYNC_PLUGIN_DIR . 'includes/lib/plugin-update-checker/plugin-update-checker.php';
		$wcss_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			WCSS_GITHUB_REPO,
			HASHY_AU_PLUGIN_FILE,
			'hashy-stock-sync'
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

		// The release carries two assets: hashy-stock-sync.zip (this plugin) and,
		// for v0.6.0 only, wc-stock-sync.zip (the migration shim the old slug's
		// installs download). Match ours by name.
		$wcss_update_checker->getVcsApi()->enableReleaseAssets( '/^hashy-stock-sync\.zip$/' );
	}
);
