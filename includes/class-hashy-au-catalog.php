<?php
/**
 * Shared catalog lookups (mode-agnostic).
 *
 * @package Hashy_AU
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Hashy_AU_Catalog {

    private const NORM_MAP_TRANSIENT = 'wcss_norm_sku_map';

    private static $instance = null;

    public static function instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init(): void {
        // Keep the normalized-SKU map fresh when products change.
        add_action('woocommerce_new_product', [$this, 'invalidate_norm_map']);
        add_action('woocommerce_update_product', [$this, 'invalidate_norm_map']);
        add_action('woocommerce_new_product_variation', [$this, 'invalidate_norm_map']);
        add_action('woocommerce_update_product_variation', [$this, 'invalidate_norm_map']);
        add_action('woocommerce_delete_product', [$this, 'invalidate_norm_map']);
        add_action('woocommerce_trash_product', [$this, 'invalidate_norm_map']);
    }

    public function invalidate_norm_map(): void {
        delete_transient(self::NORM_MAP_TRANSIENT);
    }

    /**
     * All non-empty _sku rows for products/variations, as [post_id => sku].
     *
     * @return array<int, string>
     */
    public function get_all_sku_rows(): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT pm.post_id, pm.meta_value
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_sku'
               AND pm.meta_value <> ''
               AND p.post_type IN ('product', 'product_variation')
               AND p.post_status IN ('publish', 'private', 'draft')",
            ARRAY_A
        );

        $out = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $out[(int) $row['post_id']] = (string) $row['meta_value'];
            }
        }
        return $out;
    }

    /**
     * Deterministic normalized-SKU lookup over the whole catalogue.
     *
     * Replaces the old WP_Query window scans (200/50 arbitrary rows), which
     * only found a match if it happened to land in the first page of an
     * unordered query. On collision the lowest product ID wins and a warning
     * is logged once per map build.
     */
    public function find_product_id_by_normalized_sku(string $normalized): int {
        if ($normalized === '') {
            return 0;
        }
        $map = $this->get_normalized_sku_map();
        return isset($map[$normalized]) ? (int) $map[$normalized] : 0;
    }

    /**
     * @return array<string, int> normalized SKU => post_id (lowest ID wins).
     */
    private function get_normalized_sku_map(): array {
        $cached = get_transient(self::NORM_MAP_TRANSIENT);
        if (is_array($cached)) {
            return $cached;
        }

        $map = [];
        $collisions = [];
        $rows = $this->get_all_sku_rows();
        ksort($rows);
        foreach ($rows as $pid => $sku) {
            $norm = Hashy_AU_SKU::normalize($sku);
            if ($norm === '') {
                continue;
            }
            if (isset($map[$norm])) {
                $collisions[$norm] = ($collisions[$norm] ?? 1) + 1;
                continue;
            }
            $map[$norm] = (int) $pid;
        }

        if (!empty($collisions) && class_exists('Hashy_AU_Logger')) {
            Hashy_AU_Logger::instance()->warning('Normalized SKU collisions (lowest product ID wins)', [
                'keys' => array_slice(array_keys($collisions), 0, 20),
                'total' => count($collisions),
            ]);
        }

        set_transient(self::NORM_MAP_TRANSIENT, $map, 15 * MINUTE_IN_SECONDS);
        return $map;
    }

    /**
     * Local SKU items (purchasable SKUs only: simple products + variations),
     * deduped by normalized key. Shape:
     * [ ['sku','normalized_key','product_name','variation_name','type'], ... ]
     */
    public function get_local_sku_items(): array {
        $normalize = Hashy_AU_Settings::instance()->normalize_skus_enabled();

        $items = [];
        $seen = [];
        foreach ($this->get_all_sku_rows() as $pid => $sku) {
            $norm = $normalize ? Hashy_AU_SKU::normalize($sku) : strtoupper(trim($sku));
            if ($norm === '') {
                continue;
            }

            $is_var = ('product_variation' === get_post_type($pid));
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

        return $items;
    }

    /**
     * Neutralize spreadsheet formula injection: Excel executes cells that
     * start with = + - @ (or tab/CR). Prefix a single quote so the value is
     * treated as text.
     *
     * @param mixed $value Cell value.
     * @return mixed
     */
    public static function csv_safe($value) {
        if (!is_string($value) || $value === '') {
            return $value;
        }
        if (in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }
        return $value;
    }
}
