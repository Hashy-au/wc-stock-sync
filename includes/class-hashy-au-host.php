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

    /**
     * When true, stock hooks do not trigger outbound pushes (used while
     * applying inbound changes or batch-applying a stocktake).
     */
    private static bool $suppress_push = false;

    /**
     * Product IDs already pushed during this request (coalesces the qty and
     * stock-status hooks firing for the same save).
     *
     * @var array<int, bool>
     */
    private static array $pushed_ids = [];

    public static function suppress_pushes(bool $suppress): void {
        self::$suppress_push = $suppress;
    }

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
        add_action('woocommerce_product_set_stock_status', [$this, 'on_stock_status_changed'], 10, 3);
        add_action('woocommerce_variation_set_stock_status', [$this, 'on_stock_status_changed'], 10, 3);

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

        // Unauthenticated input: no logging and a uniform error until the
        // signature verifies (log writes rewrite a wp_options ring — a flood
        // vector — and distinct errors let an attacker enumerate agent URLs).
        $data = json_decode($raw_body, true);
        $agent_url = is_array($data) ? untrailingslashit((string) ($data['agent_url'] ?? '')) : '';
        $agent = ('' !== $agent_url) ? $this->find_agent_by_url($agent_url) : null;
        $secret = is_array($agent) ? (string) ($agent['shared_secret'] ?? '') : '';

        if (empty($secret) || !Hashy_AU_Crypto::verify($secret, $timestamp, $raw_body, $signature)) {
            $this->log_auth_failure('ping');
            return new WP_REST_Response(['ok' => false, 'error' => 'forbidden'], 403);
        }

        Hashy_AU_Logger::instance()->info('Host ping OK', ['agent_url' => $agent_url]);
        return new WP_REST_Response(['ok' => true], 200);
    }

    /**
     * Throttled warning for failed REST authentication: at most one log line
     * per 5-minute window, so unauthenticated requests can't churn the ring.
     */
    private function log_auth_failure(string $context): void {
        if (get_transient('wcss_auth_fail_throttle')) {
            return;
        }
        set_transient('wcss_auth_fail_throttle', 1, 5 * MINUTE_IN_SECONDS);
        Hashy_AU_Logger::instance()->warning('Rejected unauthenticated sync request(s)', ['context' => $context]);
    }

public function rest_agent_order_paid(WP_REST_Request $request): WP_REST_Response {
        $raw_body = (string) $request->get_body();
        $timestamp = (string) $request->get_header('x-hashy-timestamp');
        $signature = (string) $request->get_header('x-hashy-signature');

        // Verify before parsing details or logging anything — see rest_host_ping.
        $data = json_decode($raw_body, true);
        $agent_url = is_array($data) ? untrailingslashit((string) ($data['agent_url'] ?? '')) : '';
        $agent = ('' !== $agent_url) ? $this->find_agent_by_url($agent_url) : null;
        $secret = is_array($agent) ? (string) ($agent['shared_secret'] ?? '') : '';

        if (empty($secret) || !Hashy_AU_Crypto::verify($secret, $timestamp, $raw_body, $signature)) {
            $this->log_auth_failure('order-paid');
            return new WP_REST_Response(['ok' => false, 'error' => 'forbidden'], 403);
        }

        $order_id = (int) ($data['order_id'] ?? 0);
        $items = isset($data['items']) && is_array($data['items']) ? $data['items'] : [];

        if ($order_id <= 0 || empty($items)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'missing_fields'], 400);
        }

        Hashy_AU_Logger::instance()->info('Inbound agent order-paid received', [
            'agent_url' => $agent_url,
            'order_id' => $order_id,
            'items_count' => count($items),
        ]);

        $agent_host = parse_url($agent_url, PHP_URL_HOST);
        $agent_key = is_string($agent_host) ? preg_replace('/[^a-z0-9]+/', '_', strtolower($agent_host)) : '';

        if ($this->is_order_seen($agent_url, $order_id)) {
            return new WP_REST_Response(['ok' => true, 'deduped' => true], 200);
        }

        // Mark seen BEFORE applying: if the request dies mid-loop, an agent
        // retry must not decrement the already-applied items a second time.
        $this->mark_order_seen($agent_url, $order_id);

        // Suppress hook-driven pushes while decrementing; the explicit loop
        // below pushes once per unique touched product instead.
        self::suppress_pushes(true);

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

        self::suppress_pushes(false);

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
        $this->maybe_push_product($product);
    }

    /**
     * Stock-status-only changes (e.g. toggling a non-stock-managed product
     * out of stock) don't fire the set_stock hooks, so listen separately.
     *
     * @param int|mixed        $product_id Product/variation ID.
     * @param string|mixed     $status     New stock status (unused; payload rebuilds it).
     * @param WC_Product|mixed $product    Product object when provided by WC.
     */
    public function on_stock_status_changed($product_id, $status = '', $product = null): void {
        if (!$product instanceof WC_Product) {
            $product = wc_get_product((int) $product_id);
        }
        if (!$product instanceof WC_Product) {
            return;
        }
        $this->maybe_push_product($product);
    }

    /**
     * Single outbound-push funnel: honors suppression, coalesces multiple
     * hooks per product within one request, and refuses variations without
     * their own SKU (get_sku() would fall back to the parent SKU and agents
     * would apply the variation's stock to the parent product).
     */
    private function maybe_push_product(WC_Product $product): void {
        if (self::$suppress_push) {
            return;
        }

        $pid = (int) $product->get_id();
        if (isset(self::$pushed_ids[$pid])) {
            return;
        }
        self::$pushed_ids[$pid] = true;

        if ($product->is_type('variation') && '' === (string) $product->get_sku('edit')) {
            Hashy_AU_Logger::instance()->info('Skipping push for variation without own SKU', ['product_id' => $pid]);
            return;
        }

        $payload = $this->build_payload($product);
        $this->push_to_all_agents($payload, false);
    }

    /**
     * Immediately push one product's current state to all agents (used by the
     * stocktake push queue). Bypasses the per-request coalescing set but not
     * the variation own-SKU rule.
     */
    public function push_product_now(int $product_id): bool {
        $product = wc_get_product($product_id);
        if (!$product instanceof WC_Product) {
            return false;
        }
        if ($product->is_type('variation') && '' === (string) $product->get_sku('edit')) {
            return false;
        }
        $this->push_to_all_agents($this->build_payload($product), false);
        return true;
    }

    public function process_price_sync_batch_once(array $agent, int $page): array {
        $agent_url = untrailingslashit((string) ($agent['url'] ?? ''));
        if (empty($agent_url)) {
            return ['done' => true, 'next_page' => $page, 'processed' => 0];
        }

        $agent_skus = $this->get_agent_normalized_skus_cached($agent);
        if (null === $agent_skus) {
            return ['done' => true, 'next_page' => $page, 'processed' => 0, 'error' => 'Could not fetch the agent SKU index — aborting price sync. See Logs.'];
        }
        if (empty($agent_skus)) {
            return ['done' => true, 'next_page' => $page, 'processed' => 0, 'error' => 'Agent reports no SKUs — aborting price sync.'];
        }

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
            if (!isset($agent_skus[$norm])) {
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
        if (null === $agent_skus) {
            return ['done' => true, 'next_page' => $page, 'processed' => 0, 'error' => 'Could not fetch the agent SKU index — aborting stock sync. See Logs.'];
        }
        if (empty($agent_skus)) {
            return ['done' => true, 'next_page' => $page, 'processed' => 0, 'error' => 'Agent reports no SKUs — aborting stock sync.'];
        }

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
            if (!isset($agent_skus[$norm])) {
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

    public function do_push_stock_update(array $agent, array $payload, bool $queue_on_failure = true): bool {
        $agent_url = untrailingslashit((string) ($agent['url'] ?? ''));
        $secret = (string) ($agent['shared_secret'] ?? '');

        if (empty($agent_url) || empty($secret)) {
            return false;
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
            if ($queue_on_failure) {
                $this->queue_failed_request('stock_update', $agent, $payload, $res->get_error_message());
            }
            Hashy_AU_Logger::instance()->error('Push update failed', [
                'agent_url' => $agent_url,
                'sku' => (string) ($payload['sku'] ?? ''),
                'error' => $res->get_error_message(),
            ]);
            return false;
        }

        $code = (int) wp_remote_retrieve_response_code($res);
        if ($code < 200 || $code >= 300) {
            if ($queue_on_failure) {
                $this->queue_failed_request('stock_update', $agent, $payload, 'HTTP ' . $code);
            }
            Hashy_AU_Logger::instance()->warning('Push update non-2xx', [
                'agent_url' => $agent_url,
                'sku' => (string) ($payload['sku'] ?? ''),
                'code' => $code,
                'body' => mb_substr((string) wp_remote_retrieve_body($res), 0, 500),
            ]);
            return false;
        }

        Hashy_AU_Logger::instance()->info('Push update OK', [
            'agent_url' => $agent_url,
            'sku' => (string) ($payload['sku'] ?? ''),
            'code' => $code,
        ]);
        return true;
    }

    public function process_failed_queue(): void {
        $key = 'wcss_failed_requests';
        $queue = get_option($key, []);
        if (!is_array($queue) || empty($queue)) {
            return;
        }

        $max_attempts = 12;
        $kept = [];
        foreach ($queue as $row) {
            if (!is_array($row)) {
                continue;
            }
            $created = (int) ($row['created'] ?? 0);
            if ($created <= 0 || (time() - $created) > DAY_IN_SECONDS) {
                continue;
            }

            $payload = isset($row['payload']) && is_array($row['payload']) ? $row['payload'] : [];
            $agent = $this->resolve_queued_agent($row);
            if (!$agent || empty($payload)) {
                Hashy_AU_Logger::instance()->warning('Dropping queued push (agent no longer configured)', [
                    'agent_url' => (string) ($row['agent_url'] ?? ''),
                ]);
                continue;
            }

            // Retry without re-queueing on failure; retention is handled here.
            $ok = $this->do_push_stock_update($agent, $payload, false);
            if ($ok) {
                continue;
            }

            $row['attempts'] = (int) ($row['attempts'] ?? 0) + 1;
            if ($row['attempts'] >= $max_attempts) {
                Hashy_AU_Logger::instance()->warning('Dropping queued push after max attempts', [
                    'agent_url' => (string) ($row['agent_url'] ?? ''),
                    'sku' => (string) ($payload['sku'] ?? ''),
                ]);
                continue;
            }
            $kept[] = $row;

            // minor safeguard: if queue explodes, cap.
            if (count($kept) > 2000) {
                $kept = array_slice($kept, -2000);
                break;
            }
        }

        update_option($key, $kept, false);
    }

    /**
     * Resolve a queued row back to a live agent config (by id, then URL).
     * Queue rows deliberately don't store the shared secret.
     */
    private function resolve_queued_agent(array $row): ?array {
        $agent_id = (string) ($row['agent_id'] ?? '');
        $agent_url = untrailingslashit((string) ($row['agent_url'] ?? ($row['agent']['url'] ?? '')));

        foreach (Hashy_AU_Settings::instance()->get_host_agents() as $a) {
            if (!is_array($a) || empty($a['shared_secret'])) {
                continue;
            }
            if ('' !== $agent_id && (string) ($a['id'] ?? '') === $agent_id) {
                return $a;
            }
            if ('' !== $agent_url && untrailingslashit((string) ($a['url'] ?? '')) === $agent_url) {
                return $a;
            }
        }
        return null;
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
            'stock_qty' => $product->managing_stock() ? (int) $product->get_stock_quantity() : null,
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
            'orderby' => 'ID',
            'order' => 'ASC',
            'no_found_rows' => true,
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
        return Hashy_AU_Catalog::instance()->find_product_id_by_normalized_sku($normalized);
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

    private function queue_failed_request(string $type, array $agent, array $payload, string $error): void {
        $key = 'wcss_failed_requests';
        $queue = get_option($key, []);
        if (!is_array($queue)) {
            $queue = [];
        }

        $now = time();
        $queue = array_values(array_filter($queue, function ($row) use ($now) {
            return is_array($row) && !empty($row['created']) && ($now - (int) $row['created'] <= DAY_IN_SECONDS);
        }));

        // Reference the agent by id/url only; the shared secret must not be
        // persisted into wp_options, and retries re-sign with the live config.
        $queue[] = [
            'created' => $now,
            'type' => $type,
            'agent_id' => (string) ($agent['id'] ?? ''),
            'agent_url' => untrailingslashit((string) ($agent['url'] ?? '')),
            'payload' => $payload,
            'error' => $error,
            'attempts' => 0,
        ];

        update_option($key, $queue, false);
    }

    /**
     * Returns the agent's normalized SKU set, or null when the index could
     * not be fetched. Callers must treat null as a hard failure — an empty
     * set must never silently widen a filtered sync to the whole catalogue.
     *
     * @return array<string, true>|null
     */
    private function get_agent_normalized_skus_cached(array $agent): ?array {
        $agent_url = untrailingslashit((string) ($agent['url'] ?? ''));
        if ($agent_url === '') {
            return null;
        }

        $cache_key = 'wcss_agent_skus_' . md5($agent_url);
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }
        if ('fetch_failed' === $cached) {
            return null;
        }

        $set = $this->fetch_agent_normalized_skus($agent);
        if (null === $set) {
            // Cache the failure briefly so a flapping agent doesn't get
            // hammered, but recover quickly once it's back.
            set_transient($cache_key, 'fetch_failed', 5 * MINUTE_IN_SECONDS);
            return null;
        }
        set_transient($cache_key, $set, HOUR_IN_SECONDS);
        return $set;
    }

    /**
     * @return array<string, true>|null Null on fetch/parse failure.
     */
    private function fetch_agent_normalized_skus(array $agent): ?array {
        $agent_url = untrailingslashit((string) ($agent['url'] ?? ''));
        $secret = (string) ($agent['shared_secret'] ?? '');
        if ($agent_url === '' || $secret === '') {
            return null;
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
            return null;
        }

        $code = (int) wp_remote_retrieve_response_code($res);
        if ($code < 200 || $code >= 300) {
            Hashy_AU_Logger::instance()->warning('Agent SKU index fetch non-2xx', [
                'agent_url' => $agent_url,
                'code' => $code,
                'body' => mb_substr((string) wp_remote_retrieve_body($res), 0, 500),
            ]);
            return null;
        }

        $data = json_decode((string) wp_remote_retrieve_body($res), true);
        $rows = is_array($data) ? ($data['skus'] ?? []) : [];
        if (!is_array($rows)) {
            return null;
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
            Hashy_AU_Logger::instance()->warning('Agent sku-index-detailed non-2xx', ['agent_url' => $agent_url, 'code' => $code, 'body' => mb_substr($raw, 0, 500)]);
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
        return Hashy_AU_Catalog::instance()->get_local_sku_items();
    }

}