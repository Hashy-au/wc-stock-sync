<?php
/**
 * Uninstall cleanup.
 *
 * @package Hashy_AU
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('hashy_au_settings');
delete_option('hashy_au_mappings');
delete_option('hashy_au_processed_events');
delete_option('hashy_au_missing_skus');
delete_option('hashy_au_agent_missing_skus');
