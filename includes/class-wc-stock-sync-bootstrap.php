<?php
/**
 * Bootstrap.
 *
 * @package Hashy_AU
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Hashy_AU_Bootstrap {

    private static $instance = null;

    public static function instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init(): void {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', [$this, 'notice_requires_woocommerce']);
            return;
        }

        require_once WC_STOCK_SYNC_PLUGIN_DIR . 'includes/class-hashy-au-settings.php';
        require_once WC_STOCK_SYNC_PLUGIN_DIR . 'includes/class-hashy-au-crypto.php';
        require_once WC_STOCK_SYNC_PLUGIN_DIR . 'includes/class-hashy-au-logger.php';
        require_once WC_STOCK_SYNC_PLUGIN_DIR . 'includes/class-hashy-au-sku.php';
        require_once WC_STOCK_SYNC_PLUGIN_DIR . 'includes/class-hashy-au-mapping.php';
        require_once WC_STOCK_SYNC_PLUGIN_DIR . 'includes/class-hashy-au-host.php';
        require_once WC_STOCK_SYNC_PLUGIN_DIR . 'includes/class-hashy-au-agent.php';

        Hashy_AU_Settings::instance()->init();
        Hashy_AU_Mapping::instance()->init();

        $mode = Hashy_AU_Settings::instance()->get_mode();

        if ('host' === $mode) {
            Hashy_AU_Host::instance()->init();
        } else {
            Hashy_AU_Agent::instance()->init();
        }

        add_action('admin_init', [$this, 'self_check_if_needed']);
        add_action('admin_notices', [$this, 'maybe_show_self_check_notice']);

        register_activation_hook(HASHY_AU_PLUGIN_FILE, [$this, 'activate']);
        register_deactivation_hook(HASHY_AU_PLUGIN_FILE, [$this, 'deactivate']);
    }

    public function activate(): void {
        update_option('wcss_self_check_pending', '1', false);
        if (!wp_next_scheduled('hashy_au_daily_reconcile')) {
            wp_schedule_event(time() + 300, 'daily', 'hashy_au_daily_reconcile');
        }
        if (!wp_next_scheduled('wcss_retry_failed_requests')) {
            wp_schedule_event(time() + 600, 'hourly', 'wcss_retry_failed_requests');
        }
    }

    public function deactivate(): void {
        $ts = wp_next_scheduled('hashy_au_daily_reconcile');
        if ($ts) {
            wp_unschedule_event($ts, 'hashy_au_daily_reconcile');
        }
        $ts2 = wp_next_scheduled('wcss_retry_failed_requests');
        if ($ts2) {
            wp_unschedule_event($ts2, 'wcss_retry_failed_requests');
        }
    }


    /**
     * Run a lightweight self-check after activation and surface issues as an admin notice.
     *
     * This cannot catch PHP parse errors (those prevent activation), but it can catch missing classes/methods
     * and mis-wired hooks that would otherwise fail silently.
     */
    public function self_check_if_needed(): void {
        if (!is_admin() || !current_user_can('manage_woocommerce')) {
            return;
        }
        $pending = get_option('wcss_self_check_pending', '');
        if ($pending !== '1') {
            return;
        }
        delete_option('wcss_self_check_pending');

        $errors = [];

        $required_classes = [
            'Hashy_AU_Settings' => ['instance', 'get_mode'],
            'Hashy_AU_Mapping' => ['instance', 'get_mappings'],
            'Hashy_AU_Host' => ['instance', 'init'],
            'Hashy_AU_Agent' => ['instance', 'init'],
        ];

        foreach ($required_classes as $class => $methods) {
            if (!class_exists($class)) {
                $errors[] = 'Missing class: ' . $class;
                continue;
            }
            foreach ($methods as $method) {
                if (!method_exists($class, $method)) {
                    $errors[] = 'Missing method: ' . $class . '::' . $method . '()';
                }
            }
        }

        if (!empty($errors)) {
            set_transient('wcss_self_check_errors', $errors, 10 * MINUTE_IN_SECONDS);
            Hashy_AU_Logger::instance()->error('Self-check failed', ['errors' => $errors]);
        } else {
            delete_transient('wcss_self_check_errors');
            Hashy_AU_Logger::instance()->info('Self-check OK');
        }
    }

    public function maybe_show_self_check_notice(): void {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        $errors = get_transient('wcss_self_check_errors');
        if (!is_array($errors) || empty($errors)) {
            return;
        }
        echo '<div class="notice notice-error"><p><strong>WC Stock Sync</strong> self-check found issues:</p><ul style="margin-left:18px; list-style:disc;">';
        foreach ($errors as $e) {
            echo '<li>' . esc_html((string) $e) . '</li>';
        }
        echo '</ul><p>See <strong>WC Stock Sync → Logs</strong> for details.</p></div>';
    }

    public function notice_requires_woocommerce(): void {
        echo '<div class="notice notice-error"><p><strong>WC Stock Sync</strong> requires WooCommerce to be installed and active.</p></div>';
    }
}
