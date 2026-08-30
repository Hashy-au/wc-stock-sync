<?php
/**
 * Mapping utilities + CSV tools.
 *
 * @package Hashy_AU
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Hashy_AU_Mapping {

    private static $instance = null;
    private string $option_name = 'hashy_au_mappings';
    private string $agent_overrides_option = 'wcss_agent_sku_overrides';

    public static function instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init(): void {
        add_action('admin_post_hashy_au_export_skus', [$this, 'export_skus']);
        add_action('admin_post_wcss_export_all_skus', [$this, 'export_all_skus']);
        add_action('admin_post_wcss_export_synced_skus', [$this, 'export_synced_skus']);
        add_action('admin_post_wcss_export_local_skus', [$this, 'export_local_skus']);
        add_action('admin_post_wcss_import_sku_mappings_draft', [$this, 'import_sku_mappings_draft']);
        add_action('admin_post_wcss_import_sku_mappings_apply', [$this, 'import_sku_mappings_apply']);
        add_action('admin_post_hashy_au_export_mappings', [$this, 'export_mappings']);

        // Back-compat (if triggered via admin-ajax).
        add_action('wp_ajax_hashy_au_export_skus', [$this, 'export_skus']);
        add_action('wp_ajax_hashy_au_export_mappings', [$this, 'export_mappings']);
    }

    /**
     * Get mappings: normalized_sku => host_sku.
     */
    public function get_mappings(): array {
        $m = get_option($this->option_name, []);
        return is_array($m) ? $m : [];
    }

    public function set_mappings(array $mappings): void {
        update_option($this->option_name, $mappings, false);
    }


    /**
     * Per-agent overrides: overrides[agent_key][normalized_agent_sku] = host_sku.
     */
    public function get_agent_overrides(): array {
        $m = get_option($this->agent_overrides_option, []);
        return is_array($m) ? $m : [];
    }

    public function set_agent_overrides(array $overrides): void {
        update_option($this->agent_overrides_option, $overrides, false);
    }

    public function lookup_host_sku_for_agent(string $agent_key, string $incoming_agent_sku, bool $normalize_enabled = true): string {
        $agent_key = sanitize_key($agent_key);
        $sku_norm = $normalize_enabled ? Hashy_AU_SKU::normalize($incoming_agent_sku) : strtoupper(trim($incoming_agent_sku));
        $all = $this->get_agent_overrides();
        if (isset($all[$agent_key]) && is_array($all[$agent_key]) && isset($all[$agent_key][$sku_norm]) && $all[$agent_key][$sku_norm] !== '') {
            return (string) $all[$agent_key][$sku_norm];
        }
        return $incoming_agent_sku;
    }


    public function lookup_host_sku(string $incoming_sku, bool $normalize_enabled = true): string {
        $sku = $normalize_enabled ? Hashy_AU_SKU::normalize($incoming_sku) : strtoupper(trim($incoming_sku));
        $m = $this->get_mappings();
        if (isset($m[$sku]) && !empty($m[$sku])) {
            return (string) $m[$sku];
        }
        return $incoming_sku;
    }


/**
 * Back-compat alias used by Host when receiving events from Agents.
 *
 * Returns mapped host SKU if an override exists, otherwise returns empty string.
 */
public function map_to_host_sku(string $incoming_sku): string {
    $settings = Hashy_AU_Settings::instance()->get_all();
    $normalize_enabled = (bool) ('yes' === (string) ($settings['normalize_skus'] ?? 'yes'));

    $normalized = $normalize_enabled ? Hashy_AU_SKU::normalize($incoming_sku) : strtoupper(trim($incoming_sku));
    $m = $this->get_mappings();
    if (isset($m[$normalized]) && !empty($m[$normalized])) {
        return (string) $m[$normalized];
    }
    return '';
}

    
    public function export_all_skus(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Forbidden', 403);
        }
        check_admin_referer('wcss_export');
        if ('host' !== Hashy_AU_Settings::instance()->get_mode()) {
            wp_die('Host mode only', 400);
        }

        $selected = isset($_GET['agents']) ? (array) $_GET['agents'] : [];
        $selected = array_filter(array_map('sanitize_key', $selected));

        $host_items = Hashy_AU_Host::instance()->get_host_sku_items();
        $agents = Hashy_AU_Settings::instance()->get_host_agents();

        $agent_rows = [];
        foreach ($agents as $agent) {
            $key = $this->agent_key_from_url((string) ($agent['url'] ?? ''));
            if ($key === '') {
                continue;
            }
            if (!empty($selected) && !in_array($key, $selected, true)) {
                continue;
            }
            $agent_rows[$key] = [
                'meta' => $agent,
                'items' => Hashy_AU_Host::instance()->fetch_agent_sku_items($agent),
            ];
        }

        $groups = $this->build_normalized_groups($host_items, $agent_rows);

        $filename = 'wcss-all-skus-' . gmdate('Ymd-His') . '.csv';
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');

        $headers = ['normalized_key', 'match_source', 'host_product_name', 'host_variation_name', 'host_sku', 'host_type'];
        foreach ($agent_rows as $k => $info) {
            $headers[] = $k . '_name';
            $headers[] = $k . '_sku';
        }
        $this->write_csv_row($out, $headers);

        foreach ($groups as $g) {
            $row = [
                (string) ($g['normalized_key'] ?? ''),
                (string) ($g['match_source'] ?? ''),
                (string) ($g['host_product_name'] ?? ''),
                (string) ($g['host_variation_name'] ?? ''),
                (string) ($g['host_sku'] ?? ''),
                (string) ($g['host_type'] ?? ''),
            ];
            foreach ($agent_rows as $k => $info) {
                $row[] = (string) (($info['meta']['name'] ?? '') !== '' ? $info['meta']['name'] : (string) $k);
                $row[] = (string) (($g['agents'][$k]['sku'] ?? '') ?: '');
            }
            $this->write_csv_row($out, $row);
        }

        fclose($out);
        exit;
    }

    public function export_synced_skus(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Forbidden', 403);
        }
        check_admin_referer('wcss_export');
        if ('host' !== Hashy_AU_Settings::instance()->get_mode()) {
            wp_die('Host mode only', 400);
        }

        $selected = isset($_GET['agents']) ? (array) $_GET['agents'] : [];
        $selected = array_filter(array_map('sanitize_key', $selected));

        $host_items = Hashy_AU_Host::instance()->get_host_sku_items();
        $agents = Hashy_AU_Settings::instance()->get_host_agents();

        $agent_rows = [];
        foreach ($agents as $agent) {
            $key = $this->agent_key_from_url((string) ($agent['url'] ?? ''));
            if ($key === '') {
                continue;
            }
            if (!empty($selected) && !in_array($key, $selected, true)) {
                continue;
            }
            $agent_rows[$key] = [
                'meta' => $agent,
                'items' => Hashy_AU_Host::instance()->fetch_agent_sku_items($agent),
            ];
        }

        $groups = $this->build_normalized_groups($host_items, $agent_rows);

        // Keep only rows with a Host SKU and at least one agent SKU.
        $groups = array_values(array_filter($groups, function($g) use ($agent_rows) {
            if (empty($g['host_sku'])) return false;
            foreach ($agent_rows as $k => $_) {
                if (!empty($g['agents'][$k]['sku'])) return true;
            }
            return false;
        }));

        $filename = 'wcss-synced-skus-' . gmdate('Ymd-His') . '.csv';
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');

        $headers = ['normalized_key', 'match_source', 'host_product_name', 'host_variation_name', 'host_sku', 'host_type'];
        foreach ($agent_rows as $k => $info) {
            $headers[] = $k . '_name';
            $headers[] = $k . '_sku';
        }
        $this->write_csv_row($out, $headers);

        foreach ($groups as $g) {
            $row = [
                (string) ($g['normalized_key'] ?? ''),
                (string) ($g['match_source'] ?? ''),
                (string) ($g['host_product_name'] ?? ''),
                (string) ($g['host_variation_name'] ?? ''),
                (string) ($g['host_sku'] ?? ''),
                (string) ($g['host_type'] ?? ''),
            ];
            foreach ($agent_rows as $k => $info) {
                $row[] = (string) (($info['meta']['name'] ?? '') !== '' ? $info['meta']['name'] : (string) $k);
                $row[] = (string) (($g['agents'][$k]['sku'] ?? '') ?: '');
            }
            $this->write_csv_row($out, $row);
        }

        fclose($out);
        exit;
    }

    private function agent_key_from_url(string $url): string {
        $url = untrailingslashit($url);
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return '';
        }
        $host = strtolower($host);
        return preg_replace('/[^a-z0-9]+/', '_', $host);
    }

    /**
     * Build normalized groups across Host + Agents.
     */
    private function build_normalized_groups(array $host_items, array $agent_rows): array {
        $groups = [];
        foreach ($host_items as $it) {
            if (!is_array($it)) continue;
            $key = (string) ($it['normalized_key'] ?? '');
            if ($key === '') continue;
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'normalized_key' => $key,
                    'host_sku' => '',
                    'host_product_name' => '',
                    'host_variation_name' => '',
                    'host_type' => '',
                    'agents' => [],
                    'match_source' => '',
                ];
            }
            $groups[$key]['host_sku'] = (string) ($it['sku'] ?? '');
            $groups[$key]['host_product_name'] = (string) ($it['product_name'] ?? '');
            $groups[$key]['host_variation_name'] = (string) ($it['variation_name'] ?? '');
            $groups[$key]['host_type'] = (string) ($it['type'] ?? '');
        }

        foreach ($agent_rows as $agent_key => $info) {
            $items = (array) ($info['items'] ?? []);
            foreach ($items as $it) {
                if (!is_array($it)) continue;
                $key = (string) ($it['normalized_key'] ?? '');
                if ($key === '') continue;
                if (!isset($groups[$key])) {
                    $groups[$key] = [
                        'normalized_key' => $key,
                        'host_sku' => '',
                        'host_product_name' => '',
                        'host_variation_name' => '',
                        'host_type' => '',
                        'agents' => [],
                        'match_source' => '',
                    ];
                }
                $groups[$key]['agents'][$agent_key] = [
                    'sku' => (string) ($it['sku'] ?? ''),
                    'product_name' => (string) ($it['product_name'] ?? ''),
                    'variation_name' => (string) ($it['variation_name'] ?? ''),
                    'type' => (string) ($it['type'] ?? ''),
                ];
            }
        }

        // Compute match_source (auto/override/mixed)
        $overrides = $this->get_agent_overrides();
        foreach ($groups as $key => &$g) {
            $sources = [];
            foreach ($agent_rows as $agent_key => $_) {
                $agent_sku = (string) (($g['agents'][$agent_key]['sku'] ?? '') ?: '');
                if ($agent_sku === '' || empty($g['host_sku'])) {
                    continue;
                }
                $norm_agent = Hashy_AU_SKU::normalize($agent_sku);
                $is_override = isset($overrides[$agent_key]) && is_array($overrides[$agent_key]) && isset($overrides[$agent_key][$norm_agent]) && $overrides[$agent_key][$norm_agent] !== '';
                $sources[] = $is_override ? 'override' : 'auto';
            }
            $sources = array_values(array_unique($sources));
            if (count($sources) === 0) {
                $g['match_source'] = '';
            } elseif (count($sources) === 1) {
                $g['match_source'] = $sources[0];
            } else {
                $g['match_source'] = 'mixed';
            }
        }
        unset($g);

        ksort($groups);
        return array_values($groups);
    }

    /**
     * Export local store purchasable SKUs (simple products + variations).
     *
     * Useful for agents to quickly export their SKU list for mapping.
     */
    public function export_local_skus(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Forbidden', 403);
        }
        check_admin_referer('wcss_export');

        $items = Hashy_AU_Host::instance()->get_host_sku_items();
        $host = parse_url((string) home_url(), PHP_URL_HOST);
        $filename = 'wcss-local-skus-' . ($host ? preg_replace('/[^a-z0-9]+/i', '-', (string) $host) : 'site') . '-' . gmdate('Ymd-His') . '.csv';

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');
        $this->write_csv_row($out, ['product_name', 'variation_name', 'sku', 'type', 'normalized_key']);

        foreach ($items as $it) {
            if (!is_array($it)) {
                continue;
            }
            $sku = (string) ($it['sku'] ?? '');
            if ($sku === '') {
                continue;
            }
            $norm = (string) ($it['normalized_key'] ?? Hashy_AU_SKU::normalize($sku));
            $this->write_csv_row($out, [
                (string) ($it['product_name'] ?? ''),
                (string) ($it['variation_name'] ?? ''),
                $sku,
                (string) ($it['type'] ?? ''),
                $norm,
            ]);
        }
        fclose($out);
        exit;
    }

function import_sku_mappings_draft(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Forbidden', 403);
        }
        if ('host' !== Hashy_AU_Settings::instance()->get_mode()) {
            wp_die('Host mode only', 400);
        }
        check_admin_referer('wcss_import_sku_mappings');

        if (empty($_FILES['wcss_csv']['tmp_name'])) {
            wp_redirect(add_query_arg(['page' => 'wcss-import-export', 'wcss_msg' => 'missing_file'], admin_url('admin.php')));
            exit;
        }

        $csv = file_get_contents((string) $_FILES['wcss_csv']['tmp_name']);
        $rows = $this->parse_csv_string((string) $csv);
        if (count($rows) < 2) {
            wp_redirect(add_query_arg(['page' => 'wcss-import-export', 'wcss_msg' => 'invalid_csv'], admin_url('admin.php')));
            exit;
        }

        $header = array_map('trim', $rows[0]);
        $agents = Hashy_AU_Settings::instance()->get_host_agents();
        $agent_keys = [];
        foreach ($agents as $a) {
            $k = $this->agent_key_from_url((string) ($a['url'] ?? ''));
            if ($k !== '') $agent_keys[] = $k;
        }

        $agent_cols = [];
        foreach ($agent_keys as $k) {
            $col = $k . '_sku';
            $idx = array_search($col, $header, true);
            if ($idx !== false) {
                $agent_cols[$k] = (int) $idx;
            }
        }

        $host_sku_idx = array_search('host_sku', $header, true);
        if ($host_sku_idx === false) {
            wp_redirect(add_query_arg(['page' => 'wcss-import-export', 'wcss_msg' => 'missing_host_sku'], admin_url('admin.php')));
            exit;
        }

        $normalize = Hashy_AU_Settings::instance()->normalize_skus_enabled();
        $existing = $this->get_agent_overrides();

        $changes = [];
        $warnings = [];

        // Build agent SKU sets for existence validation.
        $agent_sets = [];
        foreach ($agents as $a) {
            $k = $this->agent_key_from_url((string) ($a['url'] ?? ''));
            if ($k === '' || !isset($agent_cols[$k])) continue;
            $items = Hashy_AU_Host::instance()->fetch_agent_sku_items($a);
            $set = [];
            foreach ($items as $it) {
                $set[Hashy_AU_SKU::normalize((string) $it['sku'])] = true;
            }
            $agent_sets[$k] = $set;
        }

        for ($r = 1; $r < count($rows); $r++) {
            $row = $rows[$r];
            $host_sku = trim((string) ($row[$host_sku_idx] ?? ''));
            if ($host_sku === '') continue;

            foreach ($agent_cols as $agent_key => $col_idx) {
                $agent_sku = trim((string) ($row[$col_idx] ?? ''));
                if ($agent_sku === '') {
                    continue; // leave as-is
                }

                $agent_norm = $normalize ? Hashy_AU_SKU::normalize($agent_sku) : strtoupper($agent_sku);
                if ($agent_norm === '') continue;

                // Validate existence on agent (warning only).
                if (isset($agent_sets[$agent_key]) && empty($agent_sets[$agent_key][$agent_norm])) {
                    $warnings[] = ['type' => 'sku_not_found_on_agent', 'agent' => $agent_key, 'agent_sku' => $agent_sku, 'host_sku' => $host_sku];
                }

                // Enforce 1 agent SKU -> 1 host SKU
                $prev = $existing[$agent_key][$agent_norm] ?? '';
                $action = ($prev === '') ? 'add' : (($prev === $host_sku) ? 'noop' : 'update');

                if ($action === 'noop') {
                    continue;
                }

                $changes[] = [
                    'agent' => $agent_key,
                    'agent_sku' => $agent_sku,
                    'host_sku' => $host_sku,
                    'action' => $action,
                ];
            }
        }

        // Detect conflicts: same agent_sku mapped multiple times within import.
        $seen = [];
        foreach ($changes as $c) {
            $k = $c['agent'] . '|' . ($normalize ? Hashy_AU_SKU::normalize($c['agent_sku']) : strtoupper($c['agent_sku']));
            if (isset($seen[$k]) && $seen[$k] !== $c['host_sku']) {
                $warnings[] = ['type' => 'conflict_duplicate_agent_sku', 'agent' => $c['agent'], 'agent_sku' => $c['agent_sku'], 'host_sku_a' => $seen[$k], 'host_sku_b' => $c['host_sku']];
            } else {
                $seen[$k] = $c['host_sku'];
            }
        }

        $draft = [
            'ts' => time(),
            'changes' => $changes,
            'warnings' => $warnings,
            'header' => $header,
        ];
        set_transient('wcss_import_draft_' . get_current_user_id(), $draft, 2 * HOUR_IN_SECONDS);

        wp_redirect(add_query_arg(['page' => 'wcss-import-export', 'wcss_draft' => '1'], admin_url('admin.php')));
        exit;
    }

    public function import_sku_mappings_apply(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Forbidden', 403);
        }
        if ('host' !== Hashy_AU_Settings::instance()->get_mode()) {
            wp_die('Host mode only', 400);
        }
        check_admin_referer('wcss_import_sku_mappings_apply');

        $draft = get_transient('wcss_import_draft_' . get_current_user_id());
        if (!is_array($draft) || empty($draft['changes']) || !is_array($draft['changes'])) {
            wp_redirect(add_query_arg(['page' => 'wcss-import-export', 'wcss_msg' => 'no_draft'], admin_url('admin.php')));
            exit;
        }

        $normalize = Hashy_AU_Settings::instance()->normalize_skus_enabled();
        $overrides = $this->get_agent_overrides();

        foreach ($draft['changes'] as $c) {
            $agent = sanitize_key((string) ($c['agent'] ?? ''));
            $agent_sku = (string) ($c['agent_sku'] ?? '');
            $host_sku = (string) ($c['host_sku'] ?? '');
            if ($agent === '' || $agent_sku === '' || $host_sku === '') continue;

            $agent_norm = $normalize ? Hashy_AU_SKU::normalize($agent_sku) : strtoupper(trim($agent_sku));
            if ($agent_norm === '') continue;

            if (!isset($overrides[$agent]) || !is_array($overrides[$agent])) {
                $overrides[$agent] = [];
            }
            $overrides[$agent][$agent_norm] = $host_sku;
        }

        $this->set_agent_overrides($overrides);
        delete_transient('wcss_import_draft_' . get_current_user_id());

        Hashy_AU_Logger::instance()->info('Import mappings applied', ['changes' => count($draft['changes']), 'warnings' => count((array) ($draft['warnings'] ?? []))]);

        wp_redirect(add_query_arg(['page' => 'wcss-import-export', 'wcss_msg' => 'import_applied'], admin_url('admin.php')));
        exit;
    }

    private function parse_csv_string(string $csv): array {
        $rows = [];
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $csv);
        rewind($fh);
        while (($data = fgetcsv($fh)) !== false) {
            $rows[] = $data;
        }
        fclose($fh);
        return $rows;
    }


public function export_skus(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Forbidden', 403);
        }
        check_admin_referer('wcss_export');

        $filename = 'hashy-au-skus-' . gmdate('Ymd-His') . '.csv';

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');
        $this->write_csv_row($out, ['Product Name', 'Variation Name', 'SKU', 'Product ID', 'Variation ID']);

        $args = [
            'limit' => -1,
            'status' => ['publish', 'private', 'draft'],
            'type' => ['simple', 'variable', 'variation'],
            'return' => 'objects',
        ];

        $products = wc_get_products($args);

        foreach ($products as $product) {
            if (!$product) {
                continue;
            }

            if ($product->is_type('variation')) {
                $sku = (string) $product->get_sku();
                $parent = wc_get_product($product->get_parent_id());
                $parent_name = $parent ? $parent->get_name() : '';
                $this->write_csv_row($out, [
                    $parent_name,
                    $product->get_name(),
                    $sku,
                    $product->get_parent_id(),
                    $product->get_id(),
                ]);
                continue;
            }

            $sku = (string) $product->get_sku();
            $this->write_csv_row($out, [$product->get_name(), '', $sku, $product->get_id(), '']);
        }

        fclose($out);
        exit;
    }

    public function export_mappings(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Forbidden', 403);
        }
        check_admin_referer('wcss_export');

        $filename = 'hashy-au-mappings-' . gmdate('Ymd-His') . '.csv';

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');
        $this->write_csv_row($out, ['normalized_sku', 'host_sku']);

        $m = $this->get_mappings();
        foreach ($m as $k => $v) {
            $this->write_csv_row($out, [$k, $v]);
        }

        fclose($out);
        exit;
    }

    /**
     * Write one CSV row with formula-injection-safe cells and explicit
     * fputcsv parameters (the default $escape is deprecated in PHP 8.4).
     *
     * @param resource $handle Output stream.
     * @param array    $row    Row values.
     */
    private function write_csv_row($handle, array $row): void {
        fputcsv($handle, array_map([Hashy_AU_Catalog::class, 'csv_safe'], $row), ',', '"', '');
    }
}
