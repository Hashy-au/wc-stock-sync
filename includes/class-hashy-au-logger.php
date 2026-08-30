<?php
/**
 * Logger wrapper + in-plugin log ring (for admin UI).
 *
 * @package WC_Stock_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Hashy_AU_Logger {

    private static $instance = null;
    private $logger = null;
    private string $source = 'wc-stock-sync';
    private string $ring_option = 'wcss_log_ring';
    private int $ring_max = 1000;

    public static function instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init(): void {
        if (function_exists('wc_get_logger')) {
            $this->logger = wc_get_logger();
        }
    }

    public function info(string $message, array $context = []): void {
        $this->log('info', $message, $context);
    }

    public function warning(string $message, array $context = []): void {
        $this->log('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void {
        $this->log('error', $message, $context);
    }

    public function get_logs(int $limit = 200, int $offset = 0): array {
        $ring = get_option($this->ring_option, []);
        if (!is_array($ring)) {
            return [];
        }
        $ring = array_reverse($ring); // newest first
        return array_slice($ring, max(0, $offset), max(1, $limit));
    }

    public function clear_logs(): void {
        update_option($this->ring_option, [], false);
    }

    private function log(string $level, string $message, array $context = []): void {
        $ctx = array_merge(['source' => $this->source], $context);

        // Woo logger (if available)
        if ($this->logger) {
            $this->logger->log($level, $message, $ctx);
        } elseif (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[wc-stock-sync][' . $level . '] ' . $message . ' ' . wp_json_encode($ctx));
        }

        // Admin-visible ring buffer (always)
        $this->append_to_ring($level, $message, $ctx);
    }

    private function append_to_ring(string $level, string $message, array $context): void {
        $ring = get_option($this->ring_option, []);
        if (!is_array($ring)) {
            $ring = [];
        }

        $ring[] = [
            'ts' => time(),
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ];

        if (count($ring) > $this->ring_max) {
            $ring = array_slice($ring, -$this->ring_max);
        }

        update_option($this->ring_option, $ring, false);
    }
}
