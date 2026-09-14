<?php
/**
 * Uninstall cleanup.
 *
 * @package Hashy_AU
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Options.
$wcss_options = array(
	'hashy_au_settings',
	'hashy_au_secrets',
	'hashy_au_mappings',
	'hashy_au_processed_events',
	'hashy_au_missing_skus',
	'hashy_au_host_missing_skus',
	'hashy_au_agent_missing_skus',
	'wcss_agent_sku_overrides',
	'wcss_log_ring',
	'wcss_failed_requests',
	'wcss_seen_orders',
	'wcss_agent_outbox',
	'wcss_agent_outbox_dead',
	'wcss_self_check_pending',
	'wcss_stocktake_job',
	'wcss_host_push_queue',
	'hashy_au_recipes',
	'hashy_au_db_version',
	'hashy_au_recompute_queue',
	'wcss_seen_restores',
);
foreach ( $wcss_options as $wcss_option ) {
	delete_option( $wcss_option );
}

// Transients (including per-user / per-agent dynamic keys).
delete_transient( 'wcss_self_check_errors' );
delete_transient( 'wcss_norm_sku_map' );

global $wpdb;
$wcss_like_patterns = array(
	$wpdb->esc_like( '_transient_wcss_agent_skus_' ) . '%',
	$wpdb->esc_like( '_transient_timeout_wcss_agent_skus_' ) . '%',
	$wpdb->esc_like( '_transient_wcss_import_draft_' ) . '%',
	$wpdb->esc_like( '_transient_timeout_wcss_import_draft_' ) . '%',
	$wpdb->esc_like( '_transient_wcss_stocktake_draft_' ) . '%',
	$wpdb->esc_like( '_transient_timeout_wcss_stocktake_draft_' ) . '%',
	$wpdb->esc_like( '_transient_hashy_recipe_index_' ) . '%',
	$wpdb->esc_like( '_transient_timeout_hashy_recipe_index_' ) . '%',
	$wpdb->esc_like( '_transient_hashy_recipes_wkey_' ) . '%',
	$wpdb->esc_like( '_transient_timeout_hashy_recipes_wkey_' ) . '%',
);
foreach ( $wcss_like_patterns as $wcss_pattern ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wcss_pattern ) );
}

// Cron.
wp_clear_scheduled_hook( 'hashy_au_daily_reconcile' );
wp_clear_scheduled_hook( 'wcss_retry_failed_requests' );
wp_clear_scheduled_hook( 'wcss_agent_process_outbox' );
wp_clear_scheduled_hook( 'wcss_drain_push_queue' );
wp_clear_scheduled_hook( 'hashy_au_recompute_derived' );

// The component ledger table (design/26).
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'hashy_component_ledger' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
