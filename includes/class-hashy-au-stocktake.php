<?php
/**
 * xlsx stocktake round-trip (Host mode only).
 *
 * Export: Name | SKU | Stock | New Stock | Product ID.
 * The user fills "New Stock" offline and uploads the file back:
 * blank = skip, a typed value (including 0) = set stock.
 * Two-step draft/preview -> batched apply, with agent pushes deferred
 * to a queue drained after the apply (or by cron if the tab closes).
 *
 * @package Hashy_AU
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Hashy_AU_Stocktake {

    private const DRAFT_TRANSIENT_PREFIX = 'wcss_stocktake_draft_';
    private const JOB_OPTION = 'wcss_stocktake_job';
    private const PUSH_QUEUE_OPTION = 'wcss_host_push_queue';
    private const CRON_HOOK = 'wcss_drain_push_queue';
    private const APPLY_BATCH_SIZE = 25;
    private const PUSH_TIME_BUDGET = 20; // seconds per drain request.
    private const MAX_UPLOAD_BYTES = 10485760; // 10 MB.

    private static $instance = null;

    public static function instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init(): void {
        add_action('admin_post_wcss_stocktake_export', [$this, 'handle_export']);
        add_action('admin_post_wcss_stocktake_draft', [$this, 'handle_draft']);
        add_action('wp_ajax_wcss_stocktake_apply_start', [$this, 'ajax_apply_start']);
        add_action('wp_ajax_wcss_stocktake_apply_batch', [$this, 'ajax_apply_batch']);
        add_action('wp_ajax_wcss_stocktake_push_batch', [$this, 'ajax_push_batch']);
        add_action(self::CRON_HOOK, [$this, 'cron_drain']);
    }

    /* ---------------------------------------------------------------- Export */

    public function handle_export(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Forbidden', 403);
        }
        check_admin_referer('wcss_stocktake_export');
        if ('host' !== Hashy_AU_Settings::instance()->get_mode()) {
            wp_die('Host mode only', 400);
        }

        require_once WC_STOCK_SYNC_PLUGIN_DIR . 'includes/lib/SimpleXLSXGen.php';

        // "\0" forces string cells: numeric-looking SKUs keep their leading
        // zeros, and names starting with = + - @ can't become formulas.
        $sheet = [['Name', 'SKU', 'Stock', 'New Stock', 'Product ID (do not edit)']];
        foreach (Hashy_AU_Catalog::instance()->get_stocktake_rows() as $row) {
            $sheet[] = [
                "\0" . $row['name'],
                "\0" . $row['sku'],
                $row['stock'],
                '',
                (int) $row['product_id'],
            ];
        }

        $host = (string) parse_url(home_url(), PHP_URL_HOST);
        $host = preg_replace('/[^a-z0-9.\-]/', '', strtolower($host));
        $filename = 'wcss-stocktake-' . $host . '-' . gmdate('Ymd-His') . '.xlsx';

        nocache_headers();
        \Shuchkin\SimpleXLSXGen::fromArray($sheet, 'Stocktake')->downloadAs($filename);
        exit;
    }

    /* ----------------------------------------------------------- Upload/draft */

    public function handle_draft(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Forbidden', 403);
        }
        check_admin_referer('wcss_stocktake_upload');
        if ('host' !== Hashy_AU_Settings::instance()->get_mode()) {
            wp_die('Host mode only', 400);
        }

        $file = $_FILES['wcss_xlsx'] ?? null;
        if (!is_array($file) || UPLOAD_ERR_OK !== (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE)
            || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            $this->redirect_with('wcss_msg', 'stocktake_missing_file');
        }
        if ((int) ($file['size'] ?? 0) > self::MAX_UPLOAD_BYTES) {
            $this->redirect_with('wcss_msg', 'stocktake_too_large');
        }

        require_once WC_STOCK_SYNC_PLUGIN_DIR . 'includes/lib/SimpleXLSX.php';
        $xlsx = \Shuchkin\SimpleXLSX::parseFile($file['tmp_name']);
        if (!$xlsx || !$xlsx->success()) {
            $this->redirect_with('wcss_msg', 'stocktake_invalid_file');
        }

        $rows = $xlsx->rows();
        if (count($rows) < 2) {
            $this->redirect_with('wcss_msg', 'stocktake_empty_file');
        }

        $cols = $this->map_columns(array_map('strval', (array) array_shift($rows)));
        if (null === $cols['sku'] || null === $cols['new_stock']) {
            $this->redirect_with('wcss_msg', 'stocktake_bad_header');
        }

        $changes = [];
        $warnings = [];
        $counts = ['blank' => 0, 'unchanged' => 0];

        foreach ($rows as $i => $row) {
            $line = $i + 2; // 1-based, after header.
            $sku = trim((string) ($row[$cols['sku']] ?? ''));
            $new_raw = trim((string) ($row[$cols['new_stock']] ?? ''));

            if ('' === $sku && '' === $new_raw) {
                continue; // Fully empty row.
            }
            if ('' === $new_raw) {
                $counts['blank']++;
                continue; // Blank New Stock = not counted, skip.
            }
            if (!is_numeric($new_raw) || (float) $new_raw < 0 || (float) $new_raw !== (float) (int) $new_raw) {
                $warnings[] = ['line' => $line, 'sku' => $sku, 'code' => 'invalid_value', 'detail' => $new_raw];
                continue;
            }
            $to = (int) $new_raw;

            $product = $this->resolve_row_product($sku, (null !== $cols['product_id']) ? (int) ($row[$cols['product_id']] ?? 0) : 0, $warnings, $line);
            if (!$product) {
                continue;
            }

            $managing = (bool) $product->managing_stock();
            $from = $managing ? (int) $product->get_stock_quantity() : null;
            if ($managing && $from === $to) {
                $counts['unchanged']++;
                continue;
            }

            $changes[] = [
                'product_id' => (int) $product->get_id(),
                'sku' => $sku,
                'name' => (string) $product->get_name(),
                'from' => $from,
                'to' => $to,
                'enable_ms' => !$managing,
            ];
        }

        set_transient(self::DRAFT_TRANSIENT_PREFIX . get_current_user_id(), [
            'ts' => time(),
            'changes' => $changes,
            'warnings' => $warnings,
            'counts' => $counts,
        ], 2 * HOUR_IN_SECONDS);

        wp_safe_redirect(add_query_arg([
            'page' => 'wcss-import-export',
            'wcss_stocktake_draft' => '1',
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * Case-insensitive header mapping; 'Product ID (do not edit)' matches by
     * prefix so a trimmed header still works. Returns column indices or null.
     *
     * @param string[] $header Header row.
     * @return array{sku: ?int, new_stock: ?int, product_id: ?int}
     */
    private function map_columns(array $header): array {
        $cols = ['sku' => null, 'new_stock' => null, 'product_id' => null];
        foreach ($header as $idx => $label) {
            $label = strtolower(trim($label));
            if ('sku' === $label) {
                $cols['sku'] = $idx;
            } elseif ('new stock' === $label) {
                $cols['new_stock'] = $idx;
            } elseif (0 === strpos($label, 'product id')) {
                $cols['product_id'] = $idx;
            }
        }
        return $cols;
    }

    /**
     * Product ID is the primary key with an exact-SKU cross-check; a file
     * whose ID column was deleted falls back to exact SKU. No normalization:
     * within one store it can merge distinct SKUs and set the wrong product.
     */
    private function resolve_row_product(string $sku, int $pid, array &$warnings, int $line): ?WC_Product {
        if ($pid > 0) {
            $product = wc_get_product($pid);
            if (!$product) {
                $warnings[] = ['line' => $line, 'sku' => $sku, 'code' => 'product_not_found', 'detail' => (string) $pid];
                return null;
            }
            if ('' !== $sku && (string) $product->get_sku('edit') !== $sku) {
                $warnings[] = ['line' => $line, 'sku' => $sku, 'code' => 'sku_mismatch', 'detail' => (string) $product->get_sku('edit')];
                return null;
            }
            return $product;
        }

        if ('' === $sku) {
            $warnings[] = ['line' => $line, 'sku' => '', 'code' => 'missing_sku', 'detail' => ''];
            return null;
        }
        $found = (int) wc_get_product_id_by_sku($sku);
        if ($found <= 0) {
            $warnings[] = ['line' => $line, 'sku' => $sku, 'code' => 'sku_not_found', 'detail' => ''];
            return null;
        }
        $product = wc_get_product($found);
        return $product ?: null;
    }

    public function get_draft(): ?array {
        $draft = get_transient(self::DRAFT_TRANSIENT_PREFIX . get_current_user_id());
        return (is_array($draft) && isset($draft['changes'])) ? $draft : null;
    }

    /* ------------------------------------------------------------------ Apply */

    private function ajax_guard(): void {
        check_ajax_referer('wcss_stocktake_apply', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'forbidden'], 403);
        }
        if ('host' !== Hashy_AU_Settings::instance()->get_mode()) {
            wp_send_json_error(['message' => 'Host mode only'], 400);
        }
    }

    public function ajax_apply_start(): void {
        $this->ajax_guard();

        $draft = $this->get_draft();
        if (!$draft || empty($draft['changes'])) {
            wp_send_json_error(['message' => 'No stocktake draft to apply. Upload a file first.'], 400);
        }

        update_option(self::JOB_OPTION, [
            'rows' => array_values($draft['changes']),
            'cursor' => 0,
            'applied' => 0,
            'errors' => [],
            'started' => time(),
        ], false);
        delete_transient(self::DRAFT_TRANSIENT_PREFIX . get_current_user_id());

        // Safety net: if the browser dies mid-apply, cron drains the pushes
        // for whatever was already applied.
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_single_event(time() + 5 * MINUTE_IN_SECONDS, self::CRON_HOOK);
        }

        Hashy_AU_Logger::instance()->info('Stocktake apply started', ['rows' => count($draft['changes'])]);
        wp_send_json_success(['total' => count($draft['changes'])]);
    }

    public function ajax_apply_batch(): void {
        $this->ajax_guard();

        $job = get_option(self::JOB_OPTION, []);
        if (!is_array($job) || empty($job['rows'])) {
            wp_send_json_error(['message' => 'No stocktake apply in progress.'], 400);
        }

        $rows = $job['rows'];
        $cursor = (int) ($job['cursor'] ?? 0);
        $slice = array_slice($rows, $cursor, self::APPLY_BATCH_SIZE);

        $touched = [];
        Hashy_AU_Host::suppress_pushes(true);
        foreach ($slice as $row) {
            $result = $this->apply_row(is_array($row) ? $row : []);
            if (true === $result) {
                $job['applied'] = (int) ($job['applied'] ?? 0) + 1;
                $touched[] = (int) $row['product_id'];
            } else {
                $job['errors'][] = $result;
            }
        }
        Hashy_AU_Host::suppress_pushes(false);

        $this->queue_pushes($touched);

        $cursor += count($slice);
        $job['cursor'] = $cursor;
        $done = $cursor >= count($rows);

        if ($done) {
            delete_option(self::JOB_OPTION);
            Hashy_AU_Logger::instance()->info('Stocktake apply finished', [
                'applied' => (int) $job['applied'],
                'errors' => count($job['errors']),
            ]);
        } else {
            update_option(self::JOB_OPTION, $job, false);
        }

        wp_send_json_success([
            'done' => $done,
            'cursor' => $cursor,
            'total' => count($rows),
            'applied' => (int) $job['applied'],
            'errors' => array_values(array_slice((array) $job['errors'], -20)),
        ]);
    }

    /**
     * @return true|array True on success, or an error row for the report.
     */
    private function apply_row(array $row) {
        $pid = (int) ($row['product_id'] ?? 0);
        $sku = (string) ($row['sku'] ?? '');
        $to = (int) ($row['to'] ?? 0);

        $product = $pid > 0 ? wc_get_product($pid) : null;
        if (!$product) {
            return ['sku' => $sku, 'code' => 'product_not_found'];
        }
        // Re-verify identity at apply time; the catalogue may have changed
        // since the draft was built.
        if ('' !== $sku && (string) $product->get_sku('edit') !== $sku) {
            return ['sku' => $sku, 'code' => 'sku_mismatch'];
        }

        if (!$product->managing_stock()) {
            $product->set_manage_stock(true);
        }
        wc_update_product_stock($product, $to, 'set');
        $product->set_stock_status($to > 0 ? 'instock' : 'outofstock');
        $product->save();

        return true;
    }

    /* ------------------------------------------------------------ Push queue */

    private function queue_pushes(array $product_ids): void {
        if (empty($product_ids)) {
            return;
        }
        $queue = get_option(self::PUSH_QUEUE_OPTION, []);
        if (!is_array($queue)) {
            $queue = [];
        }
        $queue = array_values(array_unique(array_merge(array_map('intval', $queue), array_map('intval', $product_ids))));
        update_option(self::PUSH_QUEUE_OPTION, $queue, false);
    }

    public function ajax_push_batch(): void {
        $this->ajax_guard();
        $result = $this->drain_push_queue(self::PUSH_TIME_BUDGET);
        wp_send_json_success($result);
    }

    public function cron_drain(): void {
        $result = $this->drain_push_queue(self::PUSH_TIME_BUDGET);
        if ($result['remaining'] > 0 && !wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::CRON_HOOK);
        }
    }

    /**
     * Push queued products to all agents under a wall-clock budget (each push
     * is a synchronous HTTP request per agent; a slow agent must not blow
     * through PHP's execution limit). Failures land in the host's existing
     * failed-request retry queue via do_push_stock_update.
     *
     * @return array{done: bool, remaining: int, pushed: int}
     */
    private function drain_push_queue(int $budget_seconds): array {
        $queue = get_option(self::PUSH_QUEUE_OPTION, []);
        if (!is_array($queue) || empty($queue)) {
            return ['done' => true, 'remaining' => 0, 'pushed' => 0];
        }

        $deadline = time() + max(5, $budget_seconds);
        $pushed = 0;
        while (!empty($queue) && time() < $deadline) {
            $pid = (int) array_shift($queue);
            if ($pid > 0) {
                Hashy_AU_Host::instance()->push_product_now($pid);
                $pushed++;
            }
        }

        update_option(self::PUSH_QUEUE_OPTION, array_values($queue), false);
        return ['done' => empty($queue), 'remaining' => count($queue), 'pushed' => $pushed];
    }

    /* -------------------------------------------------------------------- UI */

    private function redirect_with(string $key, string $value): void {
        wp_safe_redirect(add_query_arg(['page' => 'wcss-import-export', $key => $value], admin_url('admin.php')));
        exit;
    }

    /**
     * Stocktake section for the Import/Export admin page (Host mode only).
     */
    public function render_admin_section(): void {
        if ('host' !== Hashy_AU_Settings::instance()->get_mode()) {
            return;
        }

        $export_url = wp_nonce_url(admin_url('admin-post.php?action=wcss_stocktake_export'), 'wcss_stocktake_export');
        $draft = $this->get_draft();
        $show_draft = $draft && !empty($_GET['wcss_stocktake_draft']);
        $nonce_apply = wp_create_nonce('wcss_stocktake_apply');
        $msg = isset($_GET['wcss_msg']) ? sanitize_key((string) $_GET['wcss_msg']) : '';
        $messages = [
            'stocktake_missing_file' => 'No file was uploaded.',
            'stocktake_too_large' => 'The uploaded file is too large (10 MB max).',
            'stocktake_invalid_file' => 'That file could not be read as .xlsx.',
            'stocktake_empty_file' => 'The spreadsheet has no data rows.',
            'stocktake_bad_header' => 'The header row must contain at least "SKU" and "New Stock" columns.',
        ];
        ?>
        <hr />
        <h2>Stocktake (xlsx)</h2>
        <?php if ('' !== $msg && isset($messages[$msg])) : ?>
            <div class="notice notice-error"><p><?php echo esc_html($messages[$msg]); ?></p></div>
        <?php endif; ?>
        <p>Download the sheet, count your stock into the <strong>New Stock</strong> column, then upload it back.
           Blank New Stock cells are skipped; a typed <code>0</code> sets stock to zero. Changes push to all agents automatically.</p>
        <p><a class="button button-primary" href="<?php echo esc_url($export_url); ?>">Download Stocktake (xlsx)</a></p>

        <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="wcss_stocktake_draft" />
            <?php wp_nonce_field('wcss_stocktake_upload'); ?>
            <input type="file" name="wcss_xlsx" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required />
            <button type="submit" class="button">Upload &amp; Preview</button>
        </form>

        <?php if ($show_draft) : ?>
            <?php
            $changes = (array) $draft['changes'];
            $warnings = (array) ($draft['warnings'] ?? []);
            $counts = (array) ($draft['counts'] ?? []);
            ?>
            <h3>Preview</h3>
            <p>
                <strong><?php echo (int) count($changes); ?></strong> change(s) to apply,
                <strong><?php echo (int) ($counts['unchanged'] ?? 0); ?></strong> unchanged,
                <strong><?php echo (int) ($counts['blank'] ?? 0); ?></strong> blank (skipped),
                <strong><?php echo (int) count($warnings); ?></strong> warning(s).
            </p>

            <?php if (!empty($warnings)) : ?>
                <details <?php echo empty($changes) ? 'open' : ''; ?>>
                    <summary><strong>Warnings (<?php echo (int) count($warnings); ?>)</strong> — these rows will NOT be applied</summary>
                    <table class="widefat striped" style="max-width:900px; margin-top:8px;">
                        <thead><tr><th>Row</th><th>SKU</th><th>Problem</th><th>Detail</th></tr></thead>
                        <tbody>
                        <?php foreach (array_slice($warnings, 0, 200) as $w) : ?>
                            <tr>
                                <td><?php echo (int) ($w['line'] ?? 0); ?></td>
                                <td><?php echo esc_html((string) ($w['sku'] ?? '')); ?></td>
                                <td><?php echo esc_html((string) ($w['code'] ?? '')); ?></td>
                                <td><?php echo esc_html((string) ($w['detail'] ?? '')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </details>
            <?php endif; ?>

            <?php if (!empty($changes)) : ?>
                <table class="widefat striped" style="max-width:900px; margin-top:8px;">
                    <thead><tr><th>Product</th><th>SKU</th><th>Current</th><th>New</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($changes, 0, 500) as $c) : ?>
                        <tr>
                            <td><?php echo esc_html((string) ($c['name'] ?? '')); ?></td>
                            <td><?php echo esc_html((string) ($c['sku'] ?? '')); ?></td>
                            <td><?php echo null === ($c['from'] ?? null) ? '<em>not managed</em>' : (int) $c['from']; ?></td>
                            <td><strong><?php echo (int) ($c['to'] ?? 0); ?></strong></td>
                            <td><?php echo !empty($c['enable_ms']) ? '<em>will enable stock management</em>' : ''; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if (count($changes) > 500) : ?>
                    <p><em>Showing the first 500 of <?php echo (int) count($changes); ?> changes. Applying commits all of them.</em></p>
                <?php endif; ?>

                <p style="margin-top:12px;">
                    <button type="button" class="button button-primary" id="wcss_stocktake_apply">Apply <?php echo (int) count($changes); ?> change(s)</button>
                    <span id="wcss_stocktake_progress" style="margin-left:10px;"></span>
                </p>
                <script>
                (function(){
                    const btn = document.getElementById('wcss_stocktake_apply');
                    const progress = document.getElementById('wcss_stocktake_progress');
                    if (!btn) return;

                    const post = async (action, extra) => {
                        const fd = new FormData();
                        fd.append('action', action);
                        fd.append('nonce', '<?php echo esc_js($nonce_apply); ?>');
                        Object.entries(extra || {}).forEach(([k, v]) => fd.append(k, v));
                        const res = await fetch(ajaxurl, {method: 'POST', body: fd, credentials: 'same-origin'});
                        const json = await res.json();
                        if (!json || !json.success) {
                            throw new Error(json && json.data && json.data.message ? json.data.message : 'Request failed');
                        }
                        return json.data || {};
                    };

                    btn.addEventListener('click', async function(){
                        if (!confirm('Apply the stocktake now? Stock levels will be updated and pushed to all agents.')) return;
                        btn.disabled = true;
                        try {
                            const start = await post('wcss_stocktake_apply_start');
                            progress.textContent = 'Applying 0/' + start.total + '…';
                            let data = {done: false};
                            while (!data.done) {
                                data = await post('wcss_stocktake_apply_batch');
                                progress.textContent = 'Applying ' + data.cursor + '/' + data.total + '…';
                            }
                            const errs = (data.errors || []).length;
                            progress.textContent = 'Applied ' + data.applied + ' change(s)' + (errs ? ', ' + errs + ' error(s) — see Logs' : '') + '. Pushing to agents…';
                            let push = {done: false};
                            while (!push.done) {
                                push = await post('wcss_stocktake_push_batch');
                                progress.textContent = 'Pushing to agents… ' + push.remaining + ' product(s) remaining.';
                            }
                            progress.textContent = 'Stocktake applied and pushed to all agents.';
                        } catch (err) {
                            progress.textContent = 'Failed: ' + (err && err.message ? err.message : err);
                            btn.disabled = false;
                        }
                    });
                })();
                </script>
            <?php else : ?>
                <p><em>Nothing to apply.</em></p>
            <?php endif; ?>
        <?php endif; ?>
        <?php
    }
}
