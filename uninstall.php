<?php
/**
 * Uninstall cleanup.
 *
 * @package Hashy_AU
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Options.
$wcss_options = [
    'hashy_au_settings',
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
];
foreach ($wcss_options as $wcss_option) {
    delete_option($wcss_option);
}

// Transients (including per-user / per-agent dynamic keys).
delete_transient('wcss_self_check_errors');
delete_transient('wcss_norm_sku_map');

global $wpdb;
$wcss_like_patterns = [
    $wpdb->esc_like('_transient_wcss_agent_skus_') . '%',
    $wpdb->esc_like('_transient_timeout_wcss_agent_skus_') . '%',
    $wpdb->esc_like('_transient_wcss_import_draft_') . '%',
    $wpdb->esc_like('_transient_timeout_wcss_import_draft_') . '%',
    $wpdb->esc_like('_transient_wcss_stocktake_draft_') . '%',
    $wpdb->esc_like('_transient_timeout_wcss_stocktake_draft_') . '%',
];
foreach ($wcss_like_patterns as $wcss_pattern) {
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wcss_pattern));
}

// Cron.
wp_clear_scheduled_hook('hashy_au_daily_reconcile');
wp_clear_scheduled_hook('wcss_retry_failed_requests');
wp_clear_scheduled_hook('wcss_agent_process_outbox');
wp_clear_scheduled_hook('wcss_drain_push_queue');
