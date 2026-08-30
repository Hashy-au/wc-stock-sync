<?php
/**
 * Agent mode.
 *
 * @package Hashy_AU
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Hashy_AU_Agent {

    /**
     * True while an inbound host update is being applied. The agent registers
     * no stock hooks today, but the guard keeps any future hook additions
     * from echoing remote changes back out.
     */
    private static bool $applying_remote = false;

    private static $instance = null;
    private string $route_namespace = 'hashy-sync/v1';

    public static function instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init(): void {
        Hashy_AU_Logger::instance()->init();
        Hashy_AU_Mapping::instance()->init();

        add_action('rest_api_init', [$this, 'register_routes']);


        add_action('wcss_agent_process_outbox', [$this, 'process_outbox']);

        if (!wp_next_scheduled('wcss_agent_process_outbox')) {
            wp_schedule_event(time() + 300, 'hourly', 'wcss_agent_process_outbox');
        }


        add_action('woocommerce_payment_complete', [$this, 'on_payment_complete'], 10, 1);
        add_action('woocommerce_order_status_processing', [$this, 'on_order_processing'], 10, 1);
        add_action('woocommerce_order_status_completed', [$this, 'on_order_completed'], 10, 1);
        add_action('woocommerce_order_status_changed', [$this, 'on_status_changed'], 10, 4);

        add_action('hashy_au_daily_reconcile', [$this, 'daily_reconcile']);
    }

    public function register_routes(): void {
        register_rest_route($this->route_namespace, '/host/ping', [
            'methods' => 'POST',
            'callback' => [$this, 'rest_host_ping'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($this->route_namespace, '/host/stock-update', [
            'methods' => 'POST',
            'callback' => [$this, 'rest_host_stock_update'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($this->route_namespace, '/host/sku-index', [
            'methods' => 'POST',
            'callback' => [$this, 'rest_host_sku_index'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($this->route_namespace, '/host/sku-index-detailed', [
            'methods' => 'POST',
            'callback' => [$this, 'rest_host_sku_index_detailed'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function on_payment_complete($order_id): void {
        $this->send_order_paid((int) $order_id);
    }

    public function on_order_processing($order_id): void {
        $this->send_order_paid((int) $order_id);
    }

    public function on_order_completed($order_id): void {
        $this->send_order_paid((int) $order_id);
    }

    
    public function on_status_changed($order_id, $old_status, $new_status, $order): void {
        if (!$order_id) {
            return;
        }
        if (!($order instanceof WC_Order)) {
            $order = wc_get_order($order_id);
        }
        if (!$order) {
            return;
        }

        // Only trigger when the order is effectively paid.
        if (!$order->is_paid()) {
            return;
        }

        if (!in_array((string) $new_status, ['processing', 'completed'], true)) {
            return;
        }

        $this->send_order_paid((int) $order_id);
    }

private function send_order_paid(int $order_id): void {
        if ($order_id <= 0) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }


        Hashy_AU_Logger::instance()->info('Agent send_order_paid invoked', [
            'order_id' => $order_id,
            'status' => $order->get_status(),
            'is_paid' => $order->is_paid() ? 'yes' : 'no',
        ]);

        if ($order->get_meta('_hashy_au_sent_to_host') === 'yes') {
            return;
        }

        $host_url = Hashy_AU_Settings::instance()->get_agent_host_url();
        $secret = Hashy_AU_Settings::instance()->get_agent_shared_secret();

        if (empty($host_url) || empty($secret)) {
            Hashy_AU_Logger::instance()->warning('Agent not configured (host_url/shared_secret missing)', ['order_id' => $order_id]);
            return;
        }

        $items = [];
        foreach ($order->get_items() as $item) {
            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }
            $product = $item->get_product();
            if (!$product) {
                continue;
            }
            $sku = (string) $product->get_sku();
            $qty = (int) $item->get_quantity();

            if (empty($sku) || $qty <= 0) {
                continue;
            }

            $items[] = ['sku' => $sku, 'qty' => $qty];
        }

        $payload = [
            'agent_url' => untrailingslashit(home_url()),
            'order_id' => $order_id,
            'items' => $items,
            'ts' => time(),
        ];

        $endpoint = untrailingslashit($host_url) . '/wp-json/hashy-sync/v1/agent/order-paid';
        $body = wp_json_encode($payload);

        $timestamp = (string) time();
        $signature = Hashy_AU_Crypto::sign($secret, $timestamp, (string) $body);

        $res = wp_remote_post($endpoint, [
            'timeout' => 15,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Hashy-Timestamp' => $timestamp,
                'X-Hashy-Signature' => $signature,
            ],
            'body' => $body,
        ]);

        if (is_wp_error($res)) {
            Hashy_AU_Logger::instance()->error('Order paid notify failed', [
                'order_id' => $order_id,
                'error' => $res->get_error_message(),
            ]);

            $this->enqueue_outbox([
                'type' => 'order_paid',
                'order_id' => $order_id,
                'endpoint' => $endpoint,
                'body' => (string) $body,
                'timestamp' => $timestamp,
                'signature' => $signature,
                'attempts' => 0,
                'created_at' => time(),
                'next_try' => time() + 300,
            ]);
            return;
        }

        $code = (int) wp_remote_retrieve_response_code($res);
        if ($code < 200 || $code >= 300) {
            Hashy_AU_Logger::instance()->warning('Order paid notify non-2xx', [
                'order_id' => $order_id,
                'code' => $code,
                'body' => wp_remote_retrieve_body($res),
            ]);

            $this->enqueue_outbox([
                'type' => 'order_paid',
                'order_id' => $order_id,
                'endpoint' => $endpoint,
                'body' => (string) $body,
                'timestamp' => $timestamp,
                'signature' => $signature,
                'attempts' => 0,
                'created_at' => time(),
                'next_try' => time() + 300,
            ]);
            return;
        }

        $order->update_meta_data('_hashy_au_sent_to_host', 'yes');
        $order->save_meta_data();

        Hashy_AU_Logger::instance()->info('Order paid notify OK', ['order_id' => $order_id, 'code' => $code]);
    }

public function send_test_order_paid_by_sku(string $sku, int $qty = 1): array {
    $sku = trim($sku);
    $qty = max(1, (int) $qty);

    $host_url = Hashy_AU_Settings::instance()->get_agent_host_url();
    $secret = Hashy_AU_Settings::instance()->get_agent_shared_secret();
    if (empty($host_url) || empty($secret)) {
        Hashy_AU_Logger::instance()->warning('Agent test order-paid not configured (host_url/shared_secret missing)', ['sku' => $sku]);
        return ['ok' => false, 'error' => 'not_configured'];
    }

    $payload = [
        'agent_url' => untrailingslashit(home_url()),
        'order_id' => (int) (900000000 + (time() % 100000000)),
        'items' => [['sku' => $sku, 'qty' => $qty]],
        'timestamp' => time(),
        'test' => true,
    ];

    $endpoint = untrailingslashit($host_url) . '/wp-json/hashy-sync/v1/agent/order-paid';
    $body = wp_json_encode($payload);
    $timestamp = (string) time();
    $signature = Hashy_AU_Crypto::sign($secret, $timestamp, (string) $body);

    $res = wp_remote_post($endpoint, [
        'timeout' => 15,
        'headers' => [
            'Content-Type' => 'application/json',
            'X-Hashy-Timestamp' => $timestamp,
            'X-Hashy-Signature' => $signature,
        ],
        'body' => $body,
    ]);

    if (is_wp_error($res)) {
        Hashy_AU_Logger::instance()->error('Test order-paid notify failed', [
            'sku' => $sku,
            'error' => $res->get_error_message(),
        ]);
        return ['ok' => false, 'error' => $res->get_error_message()];
    }

    $code = (int) wp_remote_retrieve_response_code($res);
    $resp_body = (string) wp_remote_retrieve_body($res);

    Hashy_AU_Logger::instance()->info('Test order-paid notify response', [
        'sku' => $sku,
        'code' => $code,
        'body' => mb_substr($resp_body, 0, 500),
    ]);

    if ($code >= 200 && $code < 300) {
        return ['ok' => true, 'code' => $code];
    }
    return ['ok' => false, 'error' => 'http_' . $code, 'code' => $code, 'body' => $resp_body];
}


    /**
     * Host -> Agent stock update.
     *
     * Expected JSON:
     * {
     *  "host_url":"https://host.tld",
     *  "sku":"PRM-ABC-01",
     *  "stock_qty": 10,
     *  "stock_status":"instock",
     *  "manage_stock":true,
     *  "price":"29.00",
     *  "regular_price":"29.00",
     *  "sale_price":"",
     *  "ts": 123
     * }
     */
    
    public function rest_host_ping(WP_REST_Request $request): WP_REST_Response {
        $raw_body = $request->get_body();
        $timestamp = (string) $request->get_header('x-hashy-timestamp');
        $signature = (string) $request->get_header('x-hashy-signature');

        $secret = Hashy_AU_Settings::instance()->get_agent_shared_secret();
        if (empty($secret) || !Hashy_AU_Crypto::verify($secret, $timestamp, $raw_body, $signature)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'bad_signature'], 403);
        }
        return new WP_REST_Response(['ok' => true], 200);
    }

    public function rest_host_sku_index(WP_REST_Request $request): WP_REST_Response {
        $raw_body = (string) $request->get_body();
        $timestamp = (string) $request->get_header('x-hashy-timestamp');
        $signature = (string) $request->get_header('x-hashy-signature');

        $secret = Hashy_AU_Settings::instance()->get_agent_shared_secret();
        if (empty($secret) || !Hashy_AU_Crypto::verify($secret, $timestamp, $raw_body, $signature)) {
            Hashy_AU_Logger::instance()->warning('Bad signature on sku-index', []);
            return new WP_REST_Response(['ok' => false, 'error' => 'bad_signature'], 403);
        }

        $normalize = Hashy_AU_Settings::instance()->normalize_skus_enabled();

        $q = new WP_Query([
            'post_type' => ['product', 'product_variation'],
            'post_status' => ['publish', 'private', 'draft'],
            'posts_per_page' => 5000,
            'fields' => 'ids',
            'meta_query' => [
                [
                    'key' => '_sku',
                    'compare' => 'EXISTS',
                ],
            ],
        ]);

        $set = [];
        if (is_array($q->posts)) {
            foreach ($q->posts as $pid) {
                $sku = (string) get_post_meta((int) $pid, '_sku', true);
                if ($sku === '') {
                    continue;
                }
                $norm = $normalize ? Hashy_AU_SKU::normalize($sku) : $sku;
                if ($norm !== '') {
                    $set[$norm] = true;
                }
            }
        }

        return new WP_REST_Response(['ok' => true, 'skus' => array_keys($set)], 200);
    }


public function rest_host_stock_update(WP_REST_Request $request): WP_REST_Response {
        $raw_body = $request->get_body();
        $timestamp = (string) $request->get_header('x-hashy-timestamp');
        $signature = (string) $request->get_header('x-hashy-signature');

        $secret = Hashy_AU_Settings::instance()->get_agent_shared_secret();
        if (!Hashy_AU_Crypto::verify($secret, $timestamp, $raw_body, $signature)) {
            Hashy_AU_Logger::instance()->warning('Bad signature on stock update', []);
            return new WP_REST_Response(['ok' => false, 'error' => 'bad_signature'], 403);
        }

        $data = json_decode($raw_body, true);
        if (!is_array($data)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'invalid_json'], 400);
        }

        $prices_only = !empty($data['sync_prices_only']);

        $sku = (string) ($data['sku'] ?? '');
        if (empty($sku)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'missing_sku'], 400);
        }

        $normalize = Hashy_AU_Settings::instance()->normalize_skus_enabled();
        $mapped_sku = $sku;

        $product_id = wc_get_product_id_by_sku($mapped_sku);
        if (!$product_id && $normalize) {
            $product_id = $this->find_product_id_by_normalized_sku(Hashy_AU_SKU::normalize($mapped_sku));
        }

        if (!$product_id) {
            $this->record_missing([$sku]);
            Hashy_AU_Logger::instance()->warning('SKU not found on agent', ['sku' => $sku]);
            return new WP_REST_Response(['ok' => false, 'error' => 'sku_not_found'], 404);
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            return new WP_REST_Response(['ok' => false, 'error' => 'product_not_found'], 404);
        }

        // Replay / out-of-order guard: within the HMAC skew window a captured
        // request could be replayed, and concurrent pushes can arrive out of
        // order. Only apply payloads strictly newer than the last applied one.
        $incoming_ts = isset($data['ts']) ? (int) $data['ts'] : 0;
        if ($incoming_ts > 0) {
            $last_ts = (int) $product->get_meta('_wcss_last_sync_ts');
            if ($incoming_ts <= $last_ts) {
                return new WP_REST_Response(['ok' => true, 'stale' => true], 200);
            }
            $product->update_meta_data('_wcss_last_sync_ts', (string) $incoming_ts);
        }

        self::$applying_remote = true;

        // stock_qty is null when the host product doesn't manage stock.
        $qty = (isset($data['stock_qty']) && is_numeric($data['stock_qty'])) ? (int) $data['stock_qty'] : null;

        if (!$prices_only) {
            if (null !== $qty && !empty($data['manage_stock'])) {
                $product->set_manage_stock(true);
                wc_update_product_stock($product, $qty, 'set');
                // Keep status consistent with quantity to avoid stale outofstock/instock flags.
                $product->set_stock_status($qty > 0 ? 'instock' : 'outofstock');
            } else {
                // Host doesn't manage stock for this product: apply its stock
                // status only, and leave this product's manage_stock alone.
                $incoming_status = !empty($data['stock_status']) ? (string) $data['stock_status'] : '';
                if ($incoming_status !== '') {
                    $product->set_stock_status($incoming_status);
                }
            }
        }

        // Pricing sync (optional fields).
        foreach (['regular_price', 'sale_price'] as $p) {
            if (array_key_exists($p, $data)) {
                $product->{"set_{$p}"}($data[$p]);
            }
        }
        if (array_key_exists('price', $data)) {
            $product->set_price($data['price']);
        }

        $product->save();

        self::$applying_remote = false;

        Hashy_AU_Logger::instance()->info('Applied host update', [
            'sku' => $sku,
            'product_id' => $product_id,
            'prices_only' => $prices_only,
            'stock_qty' => $qty,
        ]);

        return new WP_REST_Response(['ok' => true], 200);
    }

    public function daily_reconcile(): void {
        Hashy_AU_Logger::instance()->info('Daily reconcile ran (agent)', []);
    }

    private function record_missing(array $skus): void {
        $key = 'hashy_au_agent_missing_skus';
        $existing = get_option($key, []);
        if (!is_array($existing)) {
            $existing = [];
        }
        $existing[] = ['ts' => time(), 'skus' => $skus];
        if (count($existing) > 200) {
            $existing = array_slice($existing, -200);
        }
        update_option($key, $existing, false);
    }

    private function find_product_id_by_normalized_sku(string $normalized): int {
        if (empty($normalized)) {
            return 0;
        }

        $q = new WP_Query([
            'post_type' => ['product', 'product_variation'],
            'post_status' => ['publish', 'private', 'draft'],
            'posts_per_page' => 50,
            'meta_query' => [
                [
                    'key' => '_sku',
                    'compare' => 'EXISTS',
                ],
            ],
            'fields' => 'ids',
        ]);

        foreach ($q->posts as $pid) {
            $sku = (string) get_post_meta($pid, '_sku', true);
            if (Hashy_AU_SKU::normalize($sku) === $normalized) {
                return (int) $pid;
            }
        }

        return 0;
    }

    private function enqueue_outbox(array $entry): void {
        $outbox = get_option('wcss_agent_outbox', []);
        if (!is_array($outbox)) {
            $outbox = [];
        }
        $outbox[] = $entry;
        update_option('wcss_agent_outbox', $outbox, false);

        if (!wp_next_scheduled('wcss_agent_process_outbox')) {
            wp_schedule_single_event(time() + 60, 'wcss_agent_process_outbox');
        }
    }

    public function process_outbox(): void {
        $outbox = get_option('wcss_agent_outbox', []);
        if (!is_array($outbox) || empty($outbox)) {
            return;
        }

        $now = time();
        $remaining = [];
        foreach ($outbox as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $next_try = (int) ($entry['next_try'] ?? 0);
            if ($next_try > $now) {
                $remaining[] = $entry;
                continue;
            }

            $endpoint = (string) ($entry['endpoint'] ?? '');
            $body = (string) ($entry['body'] ?? '');
            $timestamp = (string) ($entry['timestamp'] ?? '');
            $signature = (string) ($entry['signature'] ?? '');
            $order_id = (int) ($entry['order_id'] ?? 0);
            $attempts = (int) ($entry['attempts'] ?? 0);

            if (empty($endpoint) || empty($body) || empty($timestamp) || empty($signature)) {
                continue;
            }

            Hashy_AU_Logger::instance()->info('Outbox retry attempt', [
                'order_id' => $order_id,
                'attempts' => $attempts,
                'endpoint' => $endpoint,
            ]);

            $res = wp_remote_post($endpoint, [
                'timeout' => 15,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Hashy-Timestamp' => $timestamp,
                    'X-Hashy-Signature' => $signature,
                ],
                'body' => $body,
            ]);

            if (!is_wp_error($res)) {
                $code = (int) wp_remote_retrieve_response_code($res);
                $resp_body = (string) wp_remote_retrieve_body($res);

                if ($code >= 200 && $code < 300) {
                    Hashy_AU_Logger::instance()->info('Outbox retry OK', ['order_id' => $order_id, 'code' => $code]);

                    if ($order_id > 0) {
                        $order = wc_get_order($order_id);
                        if ($order) {
                            $order->update_meta_data('_hashy_au_sent_to_host', 'yes');
                            $order->save_meta_data();
                        }
                    }
                    continue;
                }

                Hashy_AU_Logger::instance()->warning('Outbox retry non-2xx', [
                    'order_id' => $order_id,
                    'code' => $code,
                    'body' => substr($resp_body, 0, 500),
                ]);
            } else {
                Hashy_AU_Logger::instance()->warning('Outbox retry wp_error', [
                    'order_id' => $order_id,
                    'error' => $res->get_error_message(),
                ]);
            }

            $attempts++;
            $delay = (int) min(3600, pow(2, min(10, $attempts)) * 60);
            $entry['attempts'] = $attempts;
            $entry['next_try'] = $now + $delay;

            $created_at = (int) ($entry['created_at'] ?? $now);
            $entry['created_at'] = $created_at;

            if ($now - $created_at <= DAY_IN_SECONDS) {
                $remaining[] = $entry;
            } else {
                $dead = get_option('wcss_agent_outbox_dead', []);
                if (!is_array($dead)) {
                    $dead = [];
                }
                $dead[] = $entry;
                update_option('wcss_agent_outbox_dead', $dead, false);
                Hashy_AU_Logger::instance()->error('Outbox dropped after 24h', ['order_id' => $order_id]);
            }
        }

        update_option('wcss_agent_outbox', $remaining, false);
    }


}
