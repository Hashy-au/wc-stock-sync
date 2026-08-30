<?php
/**
 * Host mode.
 *
 * @package Hashy_AU
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Hashy_AU_Host {

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

        add_action('woocommerce_product_set_stock', [$this, 'on_stock_changed'], 10, 1);
        add_action('woocommerce_variation_set_stock', [$this, 'on_stock_changed'], 10, 1);

        // Retry queue.
        add_action('wcss_retry_failed_requests', [$this, 'process_failed_queue']);
        add_action('hashy_au_daily_reconcile', [$this, 'daily_reconcile']);
    }

    public function register_routes(): void {
        register_rest_route($this->route_namespace, '/agent/order-paid', [
            'methods' => 'POST',
            'callback' => [$this, 'rest_agent_order_paid'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($this->route_namespace, '/host/ping', [
            'methods' => 'POST',
            'callback' => [$this, 'rest_host_ping'],
            'permission_callback' => '__return_true',
        ]);
    }

    
    public function rest_host_ping(WP_REST_Request $request): WP_REST_Response {
        $raw_body = (string) $request->get_body();
        $timestamp = (string) $request->get_header('x-hashy-timestamp');
        $signature = (string) $request->get_header('x-hashy-signature');

        $data = json_decode($raw_body, true);
        if (!is_array($data)) {
            Hashy_AU_Logger::instance()->warning('Host ping invalid_json');
            return new WP_REST_Response(['ok' => false, 'error' => 'invalid_json'], 400);
        }

        $agent_url = untrailingslashit((string) ($data['agent_url'] ?? ''));
        if (empty($agent_url)) {
            Hashy_AU_Logger::instance()->warning('Host ping missing agent_url');
            return new WP_REST_Response(['ok' => false, 'error' => 'missing_agent_url'], 400);
        }

        $agent = $this->find_agent_by_url($agent_url);
        if (!$agent) {
            Hashy_AU_Logger::instance()->warning('Host ping unknown_agent', ['agent_url' => $agent_url]);
            return new WP_REST_Response(['ok' => false, 'error' => 'unknown_agent'], 403);
        }

        $secret = (string) ($agent['shared_secret'] ?? '');
        if (empty($secret) || !Hashy_AU_Crypto::verify($secret, $timestamp, $raw_body, $signature)) {
            Hashy_AU_Logger::instance()->warning('Host ping bad_signature', ['agent_url' => $agent_url]);
            return new WP_REST_Response(['ok' => false, 'error' => 'bad_signature'], 403);
        }

        Hashy_AU_Logger::instance()->info('Host ping OK', ['agent_url' => $agent_url]);
        return new WP_REST_Response(['ok' => true], 200);
    }

public function rest_agent_order_paid(WP_REST_Request $request): WP_REST_Response {
        $raw_body = (string) $request->get_body();
        $timestamp = (string) $request->get_header('x-hashy-timestamp');
        $signature = (string) $request->get_header('x-hashy-signature');

        $data = json_decode($raw_body, true);
        if (!is_array($data)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'invalid_json'], 400);
        }

        $agent_url = untrailingslashit((string) ($data['agent_url'] ?? ''));
        $order_id = (int) ($data['order_id'] ?? 0);
        $items = isset($data['items']) && is_array($data['items']) ? $data['items'] : [];

        if (empty($agent_url) || $order_id <= 0 || empty($items)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'missing_fields'], 400);
        }

        Hashy_AU_Logger::instance()->info('Inbound agent order-paid received', [
            'agent_url' => $agent_url,
            'order_id' => $order_id,
            'items_count' => is_array($items) ? count($items) : 0,
        ]);

        $agent = $this->find_agent_by_url($agent_url);

        $agent_host = parse_url($agent_url, PHP_URL_HOST);
        $agent_key = is_string($agent_host) ? preg_replace('/[^a-z0-9]+/', '_', strtolower($agent_host)) : '';
        if (!$agent) {
            Hashy_AU_Logger::instance()->warning('Unknown agent URL', ['agent_url' => $agent_url]);
            return new WP_REST_Response(['ok' => false, 'error' => 'unknown_agent'], 403);
        }

        $secret = (string) ($agent['shared_secret'] ?? '');
        if (empty($secret) || !Hashy_AU_Crypto::verify($secret, $timestamp, $raw_body, $signature)) {
            Hashy_AU_Logger::instance()->warning('Bad signature on agent order-paid', ['agent_url' => $agent_url]);
            return new WP_REST_Response(['ok' => false, 'error' => 'bad_signature'], 403);
        }

        if ($this->is_order_seen($agent_url, $order_id)) {
            return new WP_REST_Response(['ok' => true, 'deduped' => true], 200);
        }

        $touched_ids = [];
        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sku = (string) ($row['sku'] ?? '');
            $qty = (int) ($row['qty'] ?? 0);
            if (empty($sku) || $qty <= 0) {
                continue;
            }

            $product_id = $this->find_host_product_id_by_sku($sku, $agent_key);
            if (!$product_id) {
                $this->record_missing_host_sku($agent_url, $sku);
                continue;
            }

            $product = wc_get_product($product_id);
            if (!$product) {
                continue;
            }

            if ($product->managing_stock()) {
                wc_update_product_stock($product, $qty, 'decrease');
            }

            $touched_ids[] = (int) $product_id;
        }

        $this->mark_order_seen($agent_url, $order_id);

        // Push updated stock to ALL agents for all touched SKUs.
        $touched_ids = array_values(array_unique(array_filter($touched_ids)));
        foreach ($touched_ids as $pid) {
            $p = wc_get_product($pid);
            if (!$p) {
                continue;
            }
            $payload_base = $this->build_payload($p);
            $this->push_to_all_agents($payload_base, false);
        }

        Hashy_AU_Logger::instance()->info('Agent order-paid applied', [
            'agent_url' => $agent_url,
            'order_id' => $order_id,
            'touched' => count($touched_ids),
        ]);

        return new WP_REST_Response(['ok' => true, 'touched' => count($touched_ids)], 200);
    }

    public function on_stock_changed($product): void {
        if (!$product instanceof WC_Product) {
            return;
        }

        // Prevent loops if the change originated from remote sync.
        $flag = (string) $product->get_meta('_hashy_au_remote_sync');
        if ($flag === '1') {
            return;
        }

        $payload = $this->build_payload($product);
        $this->push_to_all_agents($payload, false);
    }

    public function process_price_sync_batch_once(array $agent, int $page): array {
        $agent_url = untrailingslashit((string) ($agent['url'] ?? ''));
        if (empty($agent_url)) {
            return ['done' => true, 'next_page' => $page, 'processed' => 0];
        }

        $agent_skus = $this->get_agent_normalized_skus_cached($agent);
        $per_page = 50;
        $ids = $this->get_products_with_sku_page($page, $per_page);

        if (empty($ids)) {
            Hashy_AU_Logger::instance()->info('Price sync finished', ['agent_url' => $agent_url]);
            return ['done' => true, 'next_page' => $page, 'processed' => 0];
        }

        $processed = 0;
        $pct = (float) ($agent['price_pct'] ?? 0.0);

        foreach ($ids as $pid) {
            $product = wc_get_product((int) $pid);
            if (!$product) {
                continue;
            }
            $sku = (string) $product->get_sku();
            if ($sku === '') {
                continue;
            }

            $norm = Hashy_AU_SKU::normalize($sku);
            if (!empty($agent_skus) && !isset($agent_skus[$norm])) {
                continue;
            }

            $payload = $this->build_payload($product);
            $payload['sync_prices_only'] = true;
            $payload['price_pct'] = $pct;
            $payload['price'] = $this->apply_price_pct($payload['price'], $pct);
            $payload['regular_price'] = $this->apply_price_pct($payload['regular_price'], $pct);
            $payload['sale_price'] = $this->apply_price_pct($payload['sale_price'], $pct);

            $this->do_push_stock_update($agent, $payload);
            $processed++;
        }

        Hashy_AU_Logger::instance()->info('Price sync batch processed', [
            'agent_url' => $agent_url,
            'page' => $page,
            'processed' => $processed,
        ]);

        return ['done' => false, 'next_page' => $page + 1, 'processed' => $processed];
    }

    public function process_stock_sync_batch_once(array $agent, int $page): array {
        $agent_url = untrailingslashit((string) ($agent['url'] ?? ''));
        if (empty($agent_url)) {
            return ['done' => true, 'next_page' => $page, 'processed' => 0];
        }

        $agent_skus = $this->get_agent_normalized_skus_cached($agent);
        $per_page = 50;
        $ids = $this->get_products_with_sku_page($page, $per_page);

        if (empty($ids)) {
            Hashy_AU_Logger::instance()->info('Stock sync finished', ['agent_url' => $agent_url]);
            return ['done' => true, 'next_page' => $page, 'processed' => 0];
        }

        $processed = 0;
        foreach ($ids as $pid) {
            $product = wc_get_product((int) $pid);
            if (!$product) {
                continue;
            }
            $sku = (string) $product->get_sku();
            if ($sku === '') {
                continue;
            }

            $norm = Hashy_AU_SKU::normalize($sku);
            if (!empty($agent_skus) && !isset($agent_skus[$norm])) {
                continue;
            }

            $payload = $this->build_payload($product);
            $this->do_push_stock_update($agent, $payload);
            $processed++;
        }

        Hashy_AU_Logger::instance()->info('Stock sync batch processed', [
            'agent_url' => $agent_url,
            'page' => $page,
            'processed' => $processed,
        ]);

        return ['done' => false, 'next_page' => $page + 1, 'processed' => $processed];
    }

    public function do_push_stock_update(array $agent, array $payload): void {
        $agent_url = untrailingslashit((string) ($agent['url'] ?? ''));
        $secret = (string) ($agent['shared_secret'] ?? '');

        if (empty($agent_url) || empty($secret)) {
            return;
        }

        $endpoint = $agent_url . '/wp-json/hashy-sync/v1/host/stock-update';
        $body = wp_json_encode($payload);

        $timestamp = (string) time();
        $signature = Hashy_AU_Crypto::sign($secret, $timestamp, (string) $body);

        $res = wp_remote_post($endpoint, [
            'timeout' => 20,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Hashy-Timestamp' => $timestamp,
                'X-Hashy-Signature' => $signature,
            ],
            'body' => $body,
        ]);

        if (is_wp_error($res)) {
            $this->queue_failed_request('stock_update', $agent, $endpoint, $payload, $timestamp, $signature, $res->get_error_message());
            Hashy_AU_Logger::instance()->error('Push update failed', [
                'agent_url' => $agent_url,
                'sku' => (string) ($payload['sku'] ?? ''),
                'error' => $res->get_error_message(),
            ]);
            return;
        }

        $code = (int) wp_remote_retrieve_response_code($res);
        if ($code < 200 || $code >= 300) {
            $this->queue_failed_request('stock_update', $agent, $endpoint, $payload, $timestamp, $signature, 'HTTP ' . $code);
            Hashy_AU_Logger::instance()->warning('Push update non-2xx', [
                'agent_url' => $agent_url,
                'sku' => (string) ($payload['sku'] ?? ''),
                'code' => $code,
                'body' => wp_remote_retrieve_body($res),
            ]);
            return;
        }

        Hashy_AU_Logger::instance()->info('Push update OK', [
            'agent_url' => $agent_url,
            'sku' => (string) ($payload['sku'] ?? ''),
            'code' => $code,
        ]);
    }

    public function process_failed_queue(): void {
        $key = 'wcss_failed_requests';
        $queue = get_option($key, []);
        if (!is_array($queue) || empty($queue)) {
            return;
        }

        $kept = [];
        foreach ($queue as $row) {
            if (!is_array($row)) {
                continue;
            }
            $created = (int) ($row['created'] ?? 0);
            if ($created <= 0 || (time() - $created) > DAY_IN_SECONDS) {
                continue;
            }

            $agent = isset($row['agent']) && is_array($row['agent']) ? $row['agent'] : [];
            $payload = isset($row['payload']) && is_array($row['payload']) ? $row['payload'] : [];

            $before = count(Hashy_AU_Logger::instance()->get_logs());
            $this->do_push_stock_update($agent, $payload);
            $after = count(Hashy_AU_Logger::instance()->get_logs());

            // If it still fails, keep it (we can't reliably detect success without duplicating request logic).
            // We keep it and rely on 24h window.
            $kept[] = $row;

            // minor safeguard: if queue explodes, cap.
            if (count($kept) > 2000) {
                $kept = array_slice($kept, -2000);
                break;
            }
        }

        update_option($key, $kept, false);
    }

    public function daily_reconcile(): void {
        if (!wp_next_scheduled('wcss_retry_failed_requests')) {
            wp_schedule_event(time() + 300, 'hourly', 'wcss_retry_failed_requests');
        }
        do_action('wcss_retry_failed_requests');
    }

    private function find_agent_by_url(string $agent_url): ?array {
        $agents = Hashy_AU_Settings::instance()->get_host_agents();
        foreach ($agents as $a) {
            if (!is_array($a)) {
                continue;
            }
            if (untrailingslashit((string) ($a['url'] ?? '')) === $agent_url) {
                return $a;
            }
        }
        return null;
    }

    private function build_payload(WC_Product $product): array {
        return [
            'host_url' => untrailingslashit(home_url()),
            'product_id' => (int) $product->get_id(),
            'sku' => (string) $product->get_sku(),
            'stock_qty' => (int) $product->get_stock_quantity(),
            'stock_status' => (string) $this->compute_stock_status($product),
            'manage_stock' => (bool) $product->managing_stock(),
            'price' => $product->get_price(),
            'regular_price' => $product->get_regular_price(),
            'sale_price' => $product->get_sale_price(),
            'ts' => time(),
        ];
    }

    private function compute_stock_status(WC_Product $product): string {
        if ($product->managing_stock()) {
            $qty = (int) $product->get_stock_quantity();
            return $qty > 0 ? 'instock' : 'outofstock';
        }
        return (string) $product->get_stock_status();
    }

    private function apply_price_pct($price, float $pct) {
        if ($price === '' || $price === null) {
            return $price;
        }

        $p = (float) $price;

        // UI expects "Price %" as a multiplier (e.g. 125 => +25%, 90 => -10%).
        // Treat 0 as "no override".
        if ($pct <= 0) {
            return wc_format_decimal($p, wc_get_price_decimals());
        }

        $p = $p * ($pct / 100.0);
        return wc_format_decimal($p, wc_get_price_decimals());
    }

    private function push_to_all_agents(array $payload_base, bool $prices_only): void {
        $agents = Hashy_AU_Settings::instance()->get_host_agents();
        foreach ($agents as $agent) {
            if (!is_array($agent) || empty($agent['url']) || empty($agent['shared_secret'])) {
                continue;
            }
            $payload = $payload_base;

            $payload['sync_prices_only'] = $prices_only;
            $pct = (float) ($agent['price_pct'] ?? 0.0);
            $payload['price_pct'] = $pct;

            if ($prices_only || ('yes' === (string) ($agent['sync_prices'] ?? 'no'))) {
                $payload['price'] = $this->apply_price_pct($payload_base['price'], $pct);
                $payload['regular_price'] = $this->apply_price_pct($payload_base['regular_price'], $pct);
                $payload['sale_price'] = $this->apply_price_pct($payload_base['sale_price'], $pct);
            }

            $this->do_push_stock_update($agent, $payload);
        }
    }

    private function get_products_with_sku_page(int $page, int $per_page): array {
        $offset = max(0, ($page - 1) * $per_page);
        $q = new WP_Query([
            'post_type' => ['product', 'product_variation'],
            'post_status' => ['publish', 'private', 'draft'],
            'posts_per_page' => $per_page,
            'offset' => $offset,
            'fields' => 'ids',
            'meta_query' => [
                [
                    'key' => '_sku',
                    'compare' => 'EXISTS',
                ],
            ],
        ]);
        return is_array($q->posts) ? array_map('intval', $q->posts) : [];
    }

    private function find_host_product_id_by_sku(string $incoming_sku, string $agent_key = ''): int {
        $incoming_sku = (string) $incoming_sku;
        if ($incoming_sku === '') {
            return 0;
        }

        // Mapping overrides (per-agent first).
        if ($agent_key !== '') {
            $incoming_sku = Hashy_AU_Mapping::instance()->lookup_host_sku_for_agent($agent_key, $incoming_sku, Hashy_AU_Settings::instance()->normalize_skus_enabled());
        } else {
            $mapped = Hashy_AU_Mapping::instance()->map_to_host_sku($incoming_sku);
            if ($mapped !== '') {
                $incoming_sku = $mapped;
            }
        }

        $pid = (int) wc_get_product_id_by_sku($incoming_sku);
        if ($pid > 0) {
            return $pid;
        }

        if (Hashy_AU_Settings::instance()->normalize_skus_enabled()) {
            $norm = Hashy_AU_SKU::normalize($incoming_sku);
            return $this->find_product_id_by_normalized_sku($norm);
        }

        return 0;
    }

    private function find_product_id_by_normalized_sku(string $normalized): int {
        if ($normalized === '') {
            return 0;
        }

        $q = new WP_Query([
            'post_type' => ['product', 'product_variation'],
            'post_status' => ['publish', 'private', 'draft'],
            'posts_per_page' => 200,
            'fields' => 'ids',
            'meta_query' => [
                [
                    'key' => '_sku',
                    'compare' => 'EXISTS',
                ],
            ],
        ]);

        if (!is_array($q->posts)) {
            return 0;
        }

        foreach ($q->posts as $pid) {
            $sku = (string) get_post_meta((int) $pid, '_sku', true);
            if ($sku === '') {
                continue;
            }
            if (Hashy_AU_SKU::normalize($sku) === $normalized) {
                return (int) $pid;
            }
        }

        return 0;
    }

    private function is_order_seen(string $agent_url, int $order_id): bool {
        $key = 'wcss_seen_orders';
        $seen = get_option($key, []);
        if (!is_array($seen)) {
            return false;
        }
        return !empty($seen[$agent_url . ':' . $order_id]);
    }

    private function mark_order_seen(string $agent_url, int $order_id): void {
        $key = 'wcss_seen_orders';
        $seen = get_option($key, []);
        if (!is_array($seen)) {
            $seen = [];
        }
        $seen[$agent_url . ':' . $order_id] = time();

        if (count($seen) > 5000) {
            // Trim oldest.
            asort($seen);
            $seen = array_slice($seen, -4000, true);
        }

        update_option($key, $seen, false);
    }

    private function record_missing_host_sku(string $agent_url, string $sku): void {
        $key = 'hashy_au_host_missing_skus';
        $existing = get_option($key, []);
        if (!is_array($existing)) {
            $existing = [];
        }
        $existing[] = ['ts' => time(), 'agent_url' => $agent_url, 'sku' => $sku];
        if (count($existing) > 500) {
            $existing = array_slice($existing, -500);
        }
        update_option($key, $existing, false);
    }

    private function queue_failed_request(string $type, array $agent, string $endpoint, array $payload, string $timestamp, string $signature, string $error): void {
        $key = 'wcss_failed_requests';
        $queue = get_option($key, []);
        if (!is_array($queue)) {
            $queue = [];
        }

        $now = time();
        $queue = array_values(array_filter($queue, function ($row) use ($now) {
            return is_array($row) && !empty($row['created']) && ($now - (int) $row['created'] <= DAY_IN_SECONDS);
        }));

        $queue[] = [
            'created' => $now,
            'type' => $type,
            'agent' => $agent,
            'endpoint' => $endpoint,
            'payload' => $payload,
            'timestamp' => $timestamp,
            'signature' => $signature,
            'error' => $error,
        ];

        update_option($key, $queue, false);
    }

    private function get_agent_normalized_skus_cached(array $agent): array {
        $agent_url = untrailingslashit((string) ($agent['url'] ?? ''));
        if ($agent_url === '') {
            return [];
        }

        $cache_key = 'wcss_agent_skus_' . md5($agent_url);
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $set = $this->fetch_agent_normalized_skus($agent);
        set_transient($cache_key, $set, HOUR_IN_SECONDS);
        return $set;
    }

    private function fetch_agent_normalized_skus(array $agent): array {
        $agent_url = untrailingslashit((string) ($agent['url'] ?? ''));
        $secret = (string) ($agent['shared_secret'] ?? '');
        if ($agent_url === '' || $secret === '') {
            return [];
        }

        $endpoint = $agent_url . '/wp-json/hashy-sync/v1/host/sku-index';
        $payload = ['ts' => time(), 'host_url' => untrailingslashit(home_url())];
        $body = wp_json_encode($payload);

        $timestamp = (string) time();
        $signature = Hashy_AU_Crypto::sign($secret, $timestamp, (string) $body);

        $res = wp_remote_post($endpoint, [
            'timeout' => 20,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Hashy-Timestamp' => $timestamp,
                'X-Hashy-Signature' => $signature,
            ],
            'body' => $body,
        ]);

        if (is_wp_error($res)) {
            Hashy_AU_Logger::instance()->warning('Agent SKU index fetch failed', [
                'agent_url' => $agent_url,
                'error' => $res->get_error_message(),
            ]);
            return [];
        }

        $code = (int) wp_remote_retrieve_response_code($res);
        if ($code < 200 || $code >= 300) {
            Hashy_AU_Logger::instance()->warning('Agent SKU index fetch non-2xx', [
                'agent_url' => $agent_url,
                'code' => $code,
                'body' => wp_remote_retrieve_body($res),
            ]);
            return [];
        }

        $data = json_decode((string) wp_remote_retrieve_body($res), true);
        $rows = is_array($data) ? ($data['skus'] ?? []) : [];
        if (!is_array($rows)) {
            return [];
        }

        $set = [];
        foreach ($rows as $norm) {
            $norm = (string) $norm;
            if ($norm !== '') {
                $set[$norm] = true;
            }
        }

        Hashy_AU_Logger::instance()->info('Agent SKU index fetched', [
            'agent_url' => $agent_url,
            'count' => count($set),
        ]);

        return $set;
    }

    /**
     * Fetch SKU items from an Agent for Import/Export tooling.
     *
     * Returns items: [ ['sku'=>string,'normalized_key'=>string,'product_name'=>string,'variation_name'=>string,'type'=>'simple|variation'], ... ]
     */
    public function fetch_agent_sku_items(array $agent): array {
        $agent_url = untrailingslashit((string) ($agent['url'] ?? ''));
        $secret = (string) ($agent['shared_secret'] ?? '');
        if ($agent_url === '' || $secret === '') {
            return [];
        }

        $endpoint = $agent_url . '/wp-json/hashy-sync/v1/host/sku-index-detailed';
        $payload = ['host_url' => untrailingslashit(home_url()), 'ts' => time()];
        $body = wp_json_encode($payload);

        $timestamp = (string) time();
        $signature = Hashy_AU_Crypto::sign($secret, $timestamp, (string) $body);

        $res = wp_remote_post($endpoint, [
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Hashy-Timestamp' => $timestamp,
                'X-Hashy-Signature' => $signature,
            ],
            'body' => $body,
        ]);

        if (is_wp_error($res)) {
            Hashy_AU_Logger::instance()->warning('Agent sku-index-detailed failed', ['agent_url' => $agent_url, 'error' => $res->get_error_message()]);
            return [];
        }

        $code = (int) wp_remote_retrieve_response_code($res);
        $raw = (string) wp_remote_retrieve_body($res);
        if ($code < 200 || $code >= 300) {
            Hashy_AU_Logger::instance()->warning('Agent sku-index-detailed non-2xx', ['agent_url' => $agent_url, 'code' => $code, 'body' => $raw]);
            return [];
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['ok']) || !isset($data['items']) || !is_array($data['items'])) {
            return [];
        }

        $items = [];
        foreach ($data['items'] as $it) {
            if (!is_array($it)) {
                continue;
            }
            $sku = (string) ($it['sku'] ?? '');
            $norm = (string) ($it['normalized_key'] ?? '');
            if ($sku === '' || $norm === '') {
                continue;
            }
            $items[] = [
                'sku' => $sku,
                'normalized_key' => $norm,
                'product_name' => (string) ($it['product_name'] ?? ''),
                'variation_name' => (string) ($it['variation_name'] ?? ''),
                'type' => (string) ($it['type'] ?? ''),
            ];
        }

        return $items;
    }

    /**
     * Get Host SKU items (purchasable SKUs only: simple products + variations).
     */
    public function get_host_sku_items(): array {
        $normalize = Hashy_AU_Settings::instance()->normalize_skus_enabled();

        $q = new WP_Query([
            'post_type' => ['product', 'product_variation'],
            'post_status' => ['publish', 'private', 'draft'],
            'posts_per_page' => 20000,
            'fields' => 'ids',
            'meta_query' => [
                [
                    'key' => '_sku',
                    'compare' => 'EXISTS',
                ],
            ],
        ]);

        $items = [];
        $seen = [];
        if (is_array($q->posts)) {
            foreach ($q->posts as $pid) {
                $pid = (int) $pid;
                $sku = (string) get_post_meta($pid, '_sku', true);
                if ($sku === '') {
                    continue;
                }
                $norm = $normalize ? Hashy_AU_SKU::normalize($sku) : strtoupper(trim($sku));
                if ($norm === '') {
                    continue;
                }

                $ptype = get_post_type($pid);
                $is_var = ('product_variation' === $ptype);
                if (isset($seen[$norm]) && !$is_var) {
                    continue;
                }
                $seen[$norm] = true;

                $product = wc_get_product($pid);
                if (!$product) {
                    continue;
                }

                $product_name = $product->get_name();
                $variation_name = '';
                $type = $is_var ? 'variation' : 'simple';
                if ($is_var) {
                    $parent_id = $product->get_parent_id();
                    $parent = $parent_id ? wc_get_product($parent_id) : null;
                    if ($parent) {
                        $product_name = $parent->get_name();
                    }
                    $variation_name = wc_get_formatted_variation($product, true, false, true);
                }

                $items[] = [
                    'sku' => $sku,
                    'normalized_key' => $norm,
                    'product_name' => $product_name,
                    'variation_name' => $variation_name,
                    'type' => $type,
                ];
            }
        }

        return $items;
    }

}