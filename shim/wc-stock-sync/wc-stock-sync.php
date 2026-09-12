<?php
/**
 * Plugin Name: WC Stock Sync
 * Plugin URI: https://hashy.com.au
 * Description: Migration release. Installs and activates Hashy Stock Sync (the same plugin under its new name), then deactivates itself. Settings, secrets, mappings and queues carry over unchanged.
 * Version: 0.6.1
 * Author: Hashy-au
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wc-stock-sync
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * @package Hashy_AU
 */

/*
 * This is the last release under the wc-stock-sync slug. The old installs'
 * update checker offers it (same slug, same text domain, higher version) and
 * the in-place update replaces the whole plugin folder with this one file, so
 * nothing here may depend on the old plugin's classes. It does one job, in
 * order, every step idempotent:
 *
 *   1. if hashy-stock-sync/hashy-stock-sync.php is missing, install it from the
 *      v0.6.0 release asset (Plugin_Upgrader::install, direct filesystem only);
 *   2. activate it (it stays paused while this plugin is active);
 *   3. deactivate this plugin.
 *
 * Triggers: admin_init (an administrator's admin page load), once from
 * upgrader_process_complete, and the existing hashy_au_daily_reconcile cron
 * event, which the old plugin left scheduled, so a site nobody visits still
 * migrates within a day. Progress and failures go to the wcss_log_ring option
 * in the shape the plugin's own logger writes, and to an admin notice.
 *
 * Overrides: define WCSS_MIGRATION_DISABLED as true in wp-config.php to stop
 * it (rollback); define WCSS_MIGRATION_PACKAGE, or filter
 * wcss_migration_package_url, to install from another URL or a local zip.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WCSS_Slug_Migration {

	const NEW_SLUG     = 'hashy-stock-sync';
	const NEW_BASENAME = 'hashy-stock-sync/hashy-stock-sync.php';
	const NEW_NAME     = 'Hashy Stock Sync';
	const PACKAGE_URL  = 'https://github.com/Hashy-au/wc-stock-sync/releases/download/v0.6.1/hashy-stock-sync.zip';

	const LOCK_TRANSIENT  = 'wcss_slug_migration_lock';
	const ERROR_TRANSIENT = 'wcss_slug_migration_error';
	const DONE_TRANSIENT  = 'wcss_slug_migration_done';
	const LOCK_TTL        = 10 * MINUTE_IN_SECONDS;

	const RING_OPTION = 'wcss_log_ring';
	const RING_MAX    = 1000;

	/**
	 * Re-entrancy guard: the install fires upgrader_process_complete.
	 *
	 * @var bool
	 */
	private static $running = false;

	/**
	 * Set when this request completed the hand-over, for the notice.
	 *
	 * @var bool
	 */
	private static $migrated_now = false;

	public static function boot(): void {
		add_action( 'admin_init', array( __CLASS__, 'on_admin_init' ) );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'on_upgrader_process_complete' ), 10, 2 );
		add_action( 'hashy_au_daily_reconcile', array( __CLASS__, 'on_cron' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_notice' ) );
	}

	/**
	 * Admin page loads by an administrator. AJAX requests are skipped so a
	 * heartbeat or a WooCommerce screen never waits on the download.
	 */
	public static function on_admin_init(): void {
		if ( wp_doing_ajax() || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		self::maybe_handle_retry();
		self::run( 'admin_init' );
	}

	/**
	 * After a plugin upgrade that included this plugin. In practice the update
	 * that delivers this file runs the old code, so this mostly covers a bulk
	 * update finishing later in the same request; admin_init does the rest.
	 *
	 * @param WP_Upgrader $upgrader   Unused.
	 * @param array       $hook_extra Upgrade details.
	 */
	public static function on_upgrader_process_complete( $upgrader, $hook_extra ): void {
		if ( ! is_array( $hook_extra ) || 'plugin' !== ( $hook_extra['type'] ?? '' ) ) {
			return;
		}
		$plugins = isset( $hook_extra['plugins'] ) ? (array) $hook_extra['plugins'] : array();
		if ( isset( $hook_extra['plugin'] ) ) {
			$plugins[] = (string) $hook_extra['plugin'];
		}
		if ( ! in_array( plugin_basename( __FILE__ ), $plugins, true ) ) {
			return;
		}
		self::run( 'upgrader_process_complete' );
	}

	/** The old plugin's daily cron event, still scheduled after the update. */
	public static function on_cron(): void {
		self::run( 'cron' );
	}

	/**
	 * One migration attempt under a 10-minute lock.
	 *
	 * On failure the lock is left to expire, so a transient fault (GitHub
	 * unreachable) retries by itself without hitting the network on every
	 * admin page load; the notice's Retry link clears it at once.
	 */
	public static function run( string $trigger ): void {
		if ( self::$running ) {
			return;
		}
		if ( defined( 'WCSS_MIGRATION_DISABLED' ) && WCSS_MIGRATION_DISABLED ) {
			return;
		}
		if ( get_transient( self::LOCK_TRANSIENT ) ) {
			return;
		}
		set_transient( self::LOCK_TRANSIENT, time(), self::LOCK_TTL );
		self::$running = true;
		$result        = self::migrate( $trigger );
		self::$running = false;

		if ( is_wp_error( $result ) ) {
			self::log(
				'error',
				'Slug migration failed: ' . $result->get_error_message(),
				array(
					'code'    => $result->get_error_code(),
					'trigger' => $trigger,
				)
			);
			set_transient( self::ERROR_TRANSIENT, $result->get_error_message(), DAY_IN_SECONDS );
			return;
		}
		delete_transient( self::ERROR_TRANSIENT );
		delete_transient( self::LOCK_TRANSIENT );
	}

	/**
	 * The migration itself.
	 *
	 * @return true|WP_Error
	 */
	private static function migrate( string $trigger ) {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$new_file  = WP_PLUGIN_DIR . '/' . self::NEW_BASENAME;
		$installed = file_exists( $new_file );

		if ( $installed && is_plugin_active( self::NEW_BASENAME ) ) {
			self::deactivate_self( 'already migrated', $trigger );
			return true;
		}

		if ( ! $installed ) {
			if ( ! wp_is_file_mod_allowed( 'wcss_slug_migration' ) ) {
				return new WP_Error( 'file_mods_disallowed', 'DISALLOW_FILE_MODS is set on this site, so ' . self::NEW_NAME . ' cannot be installed automatically.' );
			}

			require_once ABSPATH . 'wp-admin/includes/file.php';
			$method = get_filesystem_method( array(), WP_PLUGIN_DIR );
			if ( 'direct' !== $method ) {
				return new WP_Error( 'filesystem_not_direct', 'The filesystem method is "' . $method . '", not "direct"; a credentials prompt cannot be answered in the background.' );
			}
			if ( ! WP_Filesystem() ) {
				return new WP_Error( 'filesystem_unavailable', 'WP_Filesystem() could not be initialised.' );
			}

			$package = self::package_url();
			self::log(
				'info',
				'Slug migration: installing ' . self::NEW_NAME . ' from ' . self::describe_package( $package ),
				array( 'trigger' => $trigger )
			);

			require_once ABSPATH . 'wp-admin/includes/misc.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';

			$upgrader = new Plugin_Upgrader( new WP_Ajax_Upgrader_Skin() );
			$result   = $upgrader->install( $package );
			if ( is_wp_error( $result ) ) {
				return new WP_Error( 'install_failed', 'Installing ' . self::NEW_NAME . ' failed: ' . $result->get_error_message() );
			}
			if ( true !== $result ) {
				$skin_errors = $upgrader->skin->get_errors();
				$detail      = ( is_wp_error( $skin_errors ) && $skin_errors->has_errors() ) ? $skin_errors->get_error_message() : 'the installer returned no result';
				return new WP_Error( 'install_failed', 'Installing ' . self::NEW_NAME . ' failed: ' . $detail );
			}
			if ( ! file_exists( $new_file ) ) {
				return new WP_Error( 'install_missing_file', 'The package installed but ' . self::NEW_BASENAME . ' is not present; the release asset is not the plugin zip.' );
			}
			self::log( 'info', 'Slug migration: installed ' . self::NEW_NAME, array( 'trigger' => $trigger ) );
		}

		// Not silent, so other plugins see the activation as usual. The new
		// plugin's own file sees this plugin still active and pauses itself for
		// the rest of this request; from the next request only it loads.
		$activated = activate_plugin( self::NEW_BASENAME );
		if ( is_wp_error( $activated ) ) {
			return new WP_Error( 'activate_failed', 'Activating ' . self::NEW_NAME . ' failed: ' . $activated->get_error_message() );
		}
		self::log( 'info', 'Slug migration: activated ' . self::NEW_NAME, array( 'trigger' => $trigger ) );

		self::deactivate_self( 'migrated to ' . self::NEW_SLUG, $trigger );
		update_option( 'wcss_slug_migrated', time(), false );
		set_transient( self::DONE_TRANSIENT, 1, DAY_IN_SECONDS );
		self::$migrated_now = true;
		return true;
	}

	/**
	 * Silent, so no deactivation hooks run and the cron events the old plugin
	 * scheduled stay in place for the new plugin (which re-creates them anyway).
	 */
	private static function deactivate_self( string $message, string $trigger ): void {
		$self = plugin_basename( __FILE__ );
		if ( is_plugin_active( $self ) ) {
			deactivate_plugins( $self, true );
			$message .= '; WC Stock Sync deactivated';
		}
		self::log( 'info', 'Slug migration: ' . $message, array( 'trigger' => $trigger ) );
	}

	private static function package_url(): string {
		$url = self::PACKAGE_URL;
		if ( defined( 'WCSS_MIGRATION_PACKAGE' ) && '' !== (string) WCSS_MIGRATION_PACKAGE ) {
			$url = (string) WCSS_MIGRATION_PACKAGE;
		}
		/**
		 * Filters where the new plugin's zip is downloaded from. A local path
		 * is accepted as well (the rehearsal uses one).
		 *
		 * @param string $url Package URL or local path.
		 */
		return (string) apply_filters( 'wcss_migration_package_url', $url );
	}

	/** For the log: no query string and no credentials, in case a URL ever carries a token. */
	private static function describe_package( string $package ): string {
		$parts = explode( '?', $package, 2 );
		return (string) preg_replace( '~^([a-z][a-z0-9+.-]*://)[^/@]*@~i', '$1', $parts[0] );
	}

	/** The Retry link in the failure notice clears the lock and the error. */
	private static function maybe_handle_retry(): void {
		if ( ! isset( $_GET['wcss_migration_retry'] ) ) {
			return;
		}
		check_admin_referer( 'wcss_migration_retry' );
		delete_transient( self::LOCK_TRANSIENT );
		delete_transient( self::ERROR_TRANSIENT );
	}

	public static function render_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		if ( self::$migrated_now ) {
			echo '<div class="notice notice-success is-dismissible"><p><strong>Hashy Stock Sync</strong> has replaced <strong>WC Stock Sync</strong> (the same plugin under its new name). Settings, secrets, mappings and queues carried over unchanged. WC Stock Sync is now deactivated and can be deleted from the <a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">Plugins screen</a>.</p></div>';
			return;
		}
		$error = get_transient( self::ERROR_TRANSIENT );
		if ( ! is_string( $error ) || '' === $error ) {
			return;
		}
		$retry = wp_nonce_url( add_query_arg( 'wcss_migration_retry', '1', admin_url( 'plugins.php' ) ), 'wcss_migration_retry' );
		echo '<div class="notice notice-error"><p><strong>WC Stock Sync</strong> could not hand over to <strong>Hashy Stock Sync</strong> (the same plugin under its new name): ' . esc_html( $error ) . '</p>';
		echo '<p>It retries by itself within 10 minutes, or <a href="' . esc_url( $retry ) . '">retry now</a>. To finish by hand: download <a href="' . esc_url( self::PACKAGE_URL ) . '">hashy-stock-sync.zip</a>, install it under Plugins, Add New Plugin, Upload Plugin, activate it, then deactivate WC Stock Sync. Settings, secrets, mappings and queues are shared and unaffected.</p></div>';
	}

	/**
	 * Same row shape as Hashy_AU_Logger::append_to_ring(), so the plugin's Logs
	 * page shows these lines with the rest. Also to the WooCommerce logger when
	 * it is available, under the source the plugin used.
	 */
	private static function log( string $level, string $message, array $context = array() ): void {
		$context = array_merge(
			array(
				'source' => 'wc-stock-sync',
				'step'   => 'slug-migration',
			),
			$context
		);

		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, $message, $context );
		}

		$ring = get_option( self::RING_OPTION, array() );
		if ( ! is_array( $ring ) ) {
			$ring = array();
		}
		$ring[] = array(
			'ts'      => time(),
			'level'   => $level,
			'message' => $message,
			'context' => $context,
		);
		if ( count( $ring ) > self::RING_MAX ) {
			$ring = array_slice( $ring, -self::RING_MAX );
		}
		update_option( self::RING_OPTION, $ring, false );
	}
}

WCSS_Slug_Migration::boot();

// HPOS (custom order tables) compatibility, as the old plugin declared it, so
// WooCommerce does not flag this interim file as an incompatible plugin.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);
