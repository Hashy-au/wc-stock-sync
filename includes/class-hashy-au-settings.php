<?php
/**
 * Settings UI and option storage for WC Stock Sync.
 *
 * Admin UI:
 * - WC Stock Sync (top-level)
 *   - Settings
 *   - Import/Export
 *   - Missing SKUs
 *   - Logs
 *
 * @package WC_Stock_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Hashy_AU_Settings {

    private static $instance = null;
    private string $option_name = 'hashy_au_settings';

    public static function instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init(): void {
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);

        add_action('wp_ajax_wcss_generate_secret', [$this, 'ajax_generate_secret']);
        add_action('wp_ajax_wcss_test_agent', [$this, 'ajax_test_agent']);
        add_action('wp_ajax_wcss_test_host', [$this, 'ajax_test_host']);
        add_action('wp_ajax_wcss_send_test_order_paid', [$this, 'ajax_send_test_order_paid']);
        add_action('wp_ajax_wcss_start_price_sync', [$this, 'ajax_start_price_sync']);
        add_action('wp_ajax_wcss_run_price_sync_batch', [$this, 'ajax_run_price_sync_batch']);
        add_action('wp_ajax_wcss_start_stock_sync', [$this, 'ajax_start_stock_sync']);
        add_action('wp_ajax_wcss_run_stock_sync_batch', [$this, 'ajax_run_stock_sync_batch']);
        add_action('wp_ajax_wcss_clear_logs', [$this, 'ajax_clear_logs']);
    }

    public function get_all(): array {
        $defaults = [
            'mode' => 'agent',
            'normalize_skus' => 'yes',
            'host' => [
                'agents' => [], // array: id, name, url, shared_secret, price_pct, sync_prices
            ],
            'agent' => [
                'host_url' => '',
                'agent_code' => '',
                'shared_secret' => '', // single shared secret (sign outbound + verify inbound)
            ],
        ];

        $saved = get_option($this->option_name, []);
        if (!is_array($saved)) {
            $saved = [];
        }

        // Back-compat from earlier fields.
        if (!empty($saved['shared_secret']) && empty($saved['agent']['shared_secret'])) {
            $saved['agent']['shared_secret'] = (string) $saved['shared_secret'];
        }
        if (!empty($saved['agent']['host_shared_secret']) && empty($saved['agent']['shared_secret'])) {
            $saved['agent']['shared_secret'] = (string) $saved['agent']['host_shared_secret'];
        }

        return array_replace_recursive($defaults, $saved);
    }

    public function get_mode(): string {
        $s = $this->get_all();
        $mode = sanitize_key((string) ($s['mode'] ?? 'agent'));
        return in_array($mode, ['host', 'agent'], true) ? $mode : 'agent';
    }

    public function normalize_skus_enabled(): bool {
        $s = $this->get_all();
        return 'yes' === (string) ($s['normalize_skus'] ?? 'yes');
    }

    /** Host helpers */
    public function get_host_agents(): array {
        $s = $this->get_all();
        $agents = $s['host']['agents'] ?? [];
        return is_array($agents) ? $agents : [];
    }

    /** Agent helpers */
    public function get_agent_host_url(): string {
        $s = $this->get_all();
        return untrailingslashit((string) ($s['agent']['host_url'] ?? ''));
    }

    public function get_agent_shared_secret(): string {
        $s = $this->get_all();
        return (string) ($s['agent']['shared_secret'] ?? '');
    }

    public function register_admin_menu(): void {
        add_menu_page(
            'WC Stock Sync',
            'WC Stock Sync',
            'manage_woocommerce',
            'wcss',
            [$this, 'render_settings_page'],
            'dashicons-update',
            56
        );

        add_submenu_page('wcss', 'Settings', 'Settings', 'manage_woocommerce', 'wcss', [$this, 'render_settings_page']);
        add_submenu_page('wcss', 'Import/Export', 'Import/Export', 'manage_woocommerce', 'wcss-import-export', [$this, 'render_import_export_page']);
        add_submenu_page('wcss', 'Missing SKUs', 'Missing SKUs', 'manage_woocommerce', 'wcss-missing-skus', [$this, 'render_missing_skus_page']);
        add_submenu_page('wcss', 'Logs', 'Logs', 'manage_woocommerce', 'wcss-logs', [$this, 'render_logs_page']);
    }

    public function register_settings(): void {
        register_setting('wcss_settings', $this->option_name, [$this, 'sanitize_settings']);
    }

    public function sanitize_settings($input): array {
        $input = is_array($input) ? $input : [];
        $out = $this->get_all();

        $out['mode'] = isset($input['mode']) ? sanitize_key((string) $input['mode']) : $out['mode'];
        $out['mode'] = in_array($out['mode'], ['host', 'agent'], true) ? $out['mode'] : 'agent';

        $out['normalize_skus'] = (isset($input['normalize_skus']) && 'yes' === (string) $input['normalize_skus']) ? 'yes' : 'no';

        if (isset($input['agent']) && is_array($input['agent'])) {
            $out['agent']['host_url'] = esc_url_raw((string) ($input['agent']['host_url'] ?? ''));
            $out['agent']['agent_code'] = preg_replace('/[^A-Z0-9]/', '', strtoupper((string) ($input['agent']['agent_code'] ?? '')));
            $out['agent']['shared_secret'] = sanitize_text_field((string) ($input['agent']['shared_secret'] ?? ''));
        }

        if (isset($input['host']) && is_array($input['host'])) {
            $agents = $input['host']['agents'] ?? [];
            $clean_agents = [];

            if (is_array($agents)) {
                foreach ($agents as $agent) {
                    if (!is_array($agent)) {
                        continue;
                    }
                    $url = esc_url_raw((string) ($agent['url'] ?? ''));
                    if (empty($url)) {
                        continue;
                    }
                    $id = sanitize_key((string) ($agent['id'] ?? ''));
                    if (empty($id)) {
                        $id = md5(untrailingslashit($url));
                    }

                    $secret = sanitize_text_field((string) ($agent['shared_secret'] ?? ''));
                    if (empty($secret)) {
                        // Host should generate this, but keep validation permissive.
                        $secret = '';
                    }

                    $clean_agents[] = [
                        'id' => $id,
                        'name' => sanitize_text_field((string) ($agent['name'] ?? 'Agent')),
                        'url' => untrailingslashit($url),
                        'shared_secret' => $secret,
                        'price_pct' => is_numeric($agent['price_pct'] ?? null) ? (float) $agent['price_pct'] : 0.0,
                        'sync_prices' => (!empty($agent['sync_prices']) && 'yes' === (string) $agent['sync_prices']) ? 'yes' : 'no',
                    ];
                }
            }

            $out['host']['agents'] = $clean_agents;
        }

        return $out;
    }

    public function render_settings_page(): void {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $settings = $this->get_all();
        $mode = $this->get_mode();
        $option = $this->option_name;

        $nonce_gen = wp_create_nonce('wcss_generate_secret');
        $nonce_test = wp_create_nonce('wcss_test_agent');
        $nonce_test_host = wp_create_nonce('wcss_test_host');
        $nonce_test_order_paid = wp_create_nonce('wcss_send_test_order_paid');
        $nonce_sync = wp_create_nonce('wcss_start_price_sync');
        $nonce_stock = wp_create_nonce('wcss_start_stock_sync');

        ?>
        <div class="wrap">
            <h1>WC Stock Sync — Settings</h1>

            <form method="post" action="options.php">
                <?php settings_fields('wcss_settings'); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Mode</th>
                        <td>
                            <select name="<?php echo esc_attr($option); ?>[mode]">
                                <option value="host" <?php selected('host', $mode); ?>>Host</option>
                                <option value="agent" <?php selected('agent', $mode); ?>>Agent</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Normalize SKUs</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr($option); ?>[normalize_skus]" value="yes" <?php checked('yes', (string) ($settings['normalize_skus'] ?? 'yes')); ?> />
                                Enable SKU normalization (strip 3-letter prefixes like PRM-, remove -/_/spaces, uppercase)
                            </label>
                        </td>
                    </tr>
                </table>

                <?php if ('agent' === $mode) : ?>
                    <h2>Agent Settings</h2>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row">Host URL</th>
                            <td>
                                <input type="url" style="width: 420px" name="<?php echo esc_attr($option); ?>[agent][host_url]" value="<?php echo esc_attr((string) ($settings['agent']['host_url'] ?? '')); ?>" placeholder="https://host-site.com" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Agent Code (optional)</th>
                            <td>
                                <input type="text" name="<?php echo esc_attr($option); ?>[agent][agent_code]" value="<?php echo esc_attr((string) ($settings['agent']['agent_code'] ?? '')); ?>" placeholder="ABA" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Shared Secret</th>
                            <td>
                                <input id="wcss_agent_shared_secret" type="text" style="width: 420px" name="<?php echo esc_attr($option); ?>[agent][shared_secret]" value="<?php echo esc_attr((string) ($settings['agent']['shared_secret'] ?? '')); ?>" />
                                <a href="#" class="button" data-wcss-gen-target="#wcss_agent_shared_secret">Generate</a>
                                <a href="#" class="button" data-wcss-copy-target="#wcss_agent_shared_secret">Copy</a>
                                <a href="#" class="button" data-wcss-test-host="1">Test Host</a>
                                <input type="text" id="wcss_test_sku" value="9273829" style="max-width:160px;" />
                                <a href="#" class="button" data-wcss-send-test-order-paid="1">Send Test Order Paid</a>
                                <p class="description">This single secret must match the secret stored on the Host for this Agent.</p>
                            </td>
                        </tr>
                    </table>
                <?php else : ?>
                    <h2>Host Settings</h2>
                    <p>Register Agent stores here. Use one shared secret per Agent (used both directions).</p>

                    <table class="widefat striped" id="wcss_agents_table">
                        <thead>
                            <tr>
                                <th>Agent Name</th>
                                <th>Agent URL</th>
                                <th>Shared Secret</th>
                                <th>Price %</th>
                                <th>Sync Prices</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        $agents = $settings['host']['agents'] ?? [];
                        if (!is_array($agents)) {
                            $agents = [];
                        }
                        foreach ($agents as $i => $agent) :
                            $secret_id = 'wcss_agent_secret_' . (string) $i;
                            ?>
                            <tr class="wcss-agent-row">
                                <td>
                                    <input type="hidden" name="<?php echo esc_attr($option); ?>[host][agents][<?php echo esc_attr((string) $i); ?>][id]" value="<?php echo esc_attr((string) ($agent['id'] ?? '')); ?>" />
                                    <input type="text" name="<?php echo esc_attr($option); ?>[host][agents][<?php echo esc_attr((string) $i); ?>][name]" value="<?php echo esc_attr((string) ($agent['name'] ?? '')); ?>" />
                                </td>
                                <td>
                                    <input type="url" style="width:100%" name="<?php echo esc_attr($option); ?>[host][agents][<?php echo esc_attr((string) $i); ?>][url]" value="<?php echo esc_attr((string) ($agent['url'] ?? '')); ?>" placeholder="https://agent-site.com" />
                                </td>
                                <td>
                                    <input id="<?php echo esc_attr($secret_id); ?>" type="text" style="width:70%" name="<?php echo esc_attr($option); ?>[host][agents][<?php echo esc_attr((string) $i); ?>][shared_secret]" value="<?php echo esc_attr((string) ($agent['shared_secret'] ?? '')); ?>" />
                                    <a href="#" class="button" data-wcss-gen-target="#<?php echo esc_attr($secret_id); ?>">Generate</a>
                                    <a href="#" class="button" data-wcss-copy-target="#<?php echo esc_attr($secret_id); ?>">Copy</a>
                                </td>
                                <td>
                                    <input type="number" step="0.01" name="<?php echo esc_attr($option); ?>[host][agents][<?php echo esc_attr((string) $i); ?>][price_pct]" value="<?php echo esc_attr((string) ($agent['price_pct'] ?? 0)); ?>" />
                                </td>
                                <td style="text-align:center;">
                                    <label>
                                        <input type="checkbox" name="<?php echo esc_attr($option); ?>[host][agents][<?php echo esc_attr((string) $i); ?>][sync_prices]" value="yes" <?php checked('yes', (string) ($agent['sync_prices'] ?? 'no')); ?> />
                                    </label>
                                </td>
                                <td>
                                    <a href="#" class="button wcss-test-agent" data-agent-index="<?php echo esc_attr((string) $i); ?>">Test</a>
                                    <a href="#" class="button wcss-sync-prices" data-agent-index="<?php echo esc_attr((string) $i); ?>">Sync prices</a>
                                    <a href="#" class="button wcss-sync-stock" data-agent-index="<?php echo esc_attr((string) $i); ?>">Sync stock</a>
                                    <a href="#" class="button link-delete wcss-remove-row">Remove</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>

                    <p style="margin-top:10px;">
                        <a href="#" class="button button-primary" id="wcss_add_agent">Add Agent</a>
                    </p>
                    <p class="description">Tip: click “Test” to verify the Agent endpoint and shared secret before relying on sync.</p>
                <?php endif; ?>

                <?php submit_button(); ?>
            </form>

            <p class="description">
                Price sync runs in background batches. Check <strong>WC Stock Sync → Logs</strong> for progress and errors.
            </p>
        </div>

<script>
(function(){
    const ajaxurl = window.ajaxurl || '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';

    function postForm(action, nonce, extra){
        const data = new FormData();
        data.append('action', action);
        data.append('nonce', nonce);
        if(extra){
            Object.keys(extra).forEach(k => data.append(k, extra[k]));
        }
        return fetch(ajaxurl, {method:'POST', body:data, credentials:'same-origin'}).then(r => r.json());
    }

    function genSecret(){
        return postForm('wcss_generate_secret', '<?php echo esc_js($nonce_gen); ?>', {}).then(j => (j && j.success && j.data && j.data.secret) ? j.data.secret : '');
    }

    async function copyToClipboard(text){
        if(!text) return;
        try { await navigator.clipboard.writeText(text); } catch(e) {}
    }

    function buildAgentRow(index){
        const option = <?php echo wp_json_encode($option); ?>;
        const secretId = 'wcss_agent_secret_' + index;
        return `
            <tr class="wcss-agent-row">
                <td>
                    <input type="hidden" name="${option}[host][agents][${index}][id]" value="" />
                    <input type="text" name="${option}[host][agents][${index}][name]" value="Agent" />
                </td>
                <td>
                    <input type="url" style="width:100%" name="${option}[host][agents][${index}][url]" value="" placeholder="https://agent-site.com" />
                </td>
                <td>
                    <input id="${secretId}" type="text" style="width:70%" name="${option}[host][agents][${index}][shared_secret]" value="" />
                    <a href="#" class="button" data-wcss-gen-target="#${secretId}">Generate</a>
                    <a href="#" class="button" data-wcss-copy-target="#${secretId}">Copy</a>
                </td>
                <td>
                    <input type="number" step="0.01" name="${option}[host][agents][${index}][price_pct]" value="0" />
                </td>
                <td style="text-align:center;">
                    <label>
                        <input type="checkbox" name="${option}[host][agents][${index}][sync_prices]" value="yes" />
                    </label>
                </td>
                <td>
                    <a href="#" class="button wcss-test-agent" data-agent-index="${index}">Test</a>
                    <a href="#" class="button wcss-sync-prices" data-agent-index="${index}">Sync prices</a>
                    <a href="#" class="button wcss-sync-stock" data-agent-index="${index}">Sync stock</a>
                    <a href="#" class="button link-delete wcss-remove-row">Remove</a>
                </td>
            </tr>
        `;
    }

    document.addEventListener('click', async function(e){
        const genBtn = e.target.closest('[data-wcss-gen-target]');
        if(genBtn){
            e.preventDefault();
            const sel = genBtn.getAttribute('data-wcss-gen-target');
            const el = document.querySelector(sel);
            if(!el) return;
            const secret = await genSecret();
            if(secret) el.value = secret;
            return;
        }

        const copyBtn = e.target.closest('[data-wcss-copy-target]');
        if(copyBtn){
            e.preventDefault();
            const sel = copyBtn.getAttribute('data-wcss-copy-target');
            const el = document.querySelector(sel);
            if(!el) return;
            await copyToClipboard(el.value);
            return;
        }

        const removeBtn = e.target.closest('.wcss-remove-row');
        if(removeBtn){
            e.preventDefault();
            const row = removeBtn.closest('tr');
            if(row) row.remove();
            return;
        }

        const addBtn = e.target.closest('#wcss_add_agent');
        if(addBtn){
            e.preventDefault();
            const tbody = document.querySelector('#wcss_agents_table tbody');
            if(!tbody) return;
            const index = tbody.querySelectorAll('tr.wcss-agent-row').length;
            tbody.insertAdjacentHTML('beforeend', buildAgentRow(index));
            // auto-generate secret for convenience
            const secret = await genSecret();
            const el = document.getElementById('wcss_agent_secret_' + index);
            if(el && secret) el.value = secret;
            return;
        }

        const testBtn = e.target.closest('.wcss-test-agent');
        if(testBtn){
            e.preventDefault();
            const row = testBtn.closest('tr');
            const url = row.querySelector('input[type="url"]').value;
            const secret = row.querySelector('input[id^="wcss_agent_secret_"]').value;
            testBtn.classList.add('disabled');
            const res = await postForm('wcss_test_agent', '<?php echo esc_js($nonce_test); ?>', {agent_url:url, agent_secret:secret});
            testBtn.classList.remove('disabled');
            alert(res && res.success ? (res.data && res.data.message ? res.data.message : 'OK') : (res && res.data && res.data.message ? res.data.message : 'Failed'));
            return;
        }

        const syncBtn = e.target.closest('.wcss-sync-prices');
        if(syncBtn){
            e.preventDefault();
            const idx = syncBtn.getAttribute('data-agent-index');
            syncBtn.classList.add('disabled');

            const runBatch = async (page) => {
                const r = await postForm('wcss_run_price_sync_batch', '<?php echo esc_js($nonce_sync); ?>', {agent_index: idx, page: String(page)});
                if(!r || !r.success){
                    throw new Error(r && r.data && r.data.message ? r.data.message : 'Batch failed');
                }
                return r.data || {};
            };

            try{
                const start = await postForm('wcss_start_price_sync', '<?php echo esc_js($nonce_sync); ?>', {agent_index: idx});
                if(!start || !start.success){
                    throw new Error(start && start.data && start.data.message ? start.data.message : 'Failed to start price sync');
                }
                let data = start.data || {};
                while(data && !data.done){
                    data = await runBatch(data.next_page || 2);
                }
                alert('Price sync finished. See Logs for details.');
            }catch(err){
                alert(err && err.message ? err.message : 'Failed');
            }finally{
                syncBtn.classList.remove('disabled');
            }
            return;
        }

        const stockBtn = e.target.closest('.wcss-sync-stock');
        if(stockBtn){
            e.preventDefault();
            const idx = stockBtn.getAttribute('data-agent-index');
            stockBtn.classList.add('disabled');

            const runBatch = async (page) => {
                const r = await postForm('wcss_run_stock_sync_batch', '<?php echo esc_js($nonce_stock); ?>', {agent_index: idx, page: String(page)});
                if(!r || !r.success){
                    throw new Error(r && r.data && r.data.message ? r.data.message : 'Batch failed');
                }
                return r.data || {};
            };

            try{
                const start = await postForm('wcss_start_stock_sync', '<?php echo esc_js($nonce_stock); ?>', {agent_index: idx});
                if(!start || !start.success){
                    throw new Error(start && start.data && start.data.message ? start.data.message : 'Failed to start stock sync');
                }
                let data = start.data || {};
                while(data && !data.done){
                    data = await runBatch(data.next_page || 2);
                }
                alert('Stock sync finished. See Logs for details.');
            }catch(err){
                alert(err && err.message ? err.message : 'Failed');
            }finally{
                stockBtn.classList.remove('disabled');
            }
            return;
        }
    });

    // Agent -> Host test
    document.querySelectorAll('[data-wcss-test-host]').forEach(btn => {
        btn.addEventListener('click', async function(e){
            e.preventDefault();
            const r = await postForm('wcss_test_host', '<?php echo esc_js($nonce_test_host); ?>', {});
            if(r && r.success){
                alert('Host test OK');
            }else{
                alert((r && r.data && r.data.message) ? r.data.message : 'Host test failed');
            }
        });
    });
})();
</script>
        <?php
    }

    public function render_import_export_page(): void {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $mode = $this->get_mode();
        $agents = $this->get_host_agents();
        $agent_keys = [];
        foreach ($agents as $a) {
            $url = (string) ($a['url'] ?? '');
            $host = parse_url($url, PHP_URL_HOST);
            if (!is_string($host) || $host === '') {
                continue;
            }
            $key = preg_replace('/[^a-z0-9]+/', '_', strtolower($host));
            $agent_keys[$key] = [
                'name' => (string) ($a['name'] ?? $host),
                'url' => (string) ($a['url'] ?? ''),
            ];
        }

        $draft = get_transient('wcss_import_draft_' . get_current_user_id());
        $has_draft = is_array($draft) && !empty($draft['changes']);

        $msg = sanitize_key((string) ($_GET['wcss_msg'] ?? ''));
        $show_draft = ('1' === (string) ($_GET['wcss_draft'] ?? '')) && $has_draft;

        $agents_query = [];
        foreach (array_keys($agent_keys) as $k) {
            $agents_query[] = 'agents[]=' . rawurlencode($k);
        }
        $agents_qs = implode('&', $agents_query);

        ?>
        <div class="wrap">
            <h1>WC Stock Sync — Import/Export</h1>

            <?php if ('host' !== $mode) : ?>
                <div class="notice notice-warning" style="padding:12px;">
                    <p><strong>Import/Export mapping tools are available in Host mode only.</strong></p>
                    <p>You can still export this site’s purchasable SKUs to start mapping.</p>
                    <p><a class="button button-primary" href="<?php echo esc_url(admin_url('admin-post.php?action=wcss_export_local_skus')); ?>">Export Local SKUs (CSV)</a></p>
                </div>
            <?php else : ?>

                <?php if ($msg === 'import_applied') : ?>
                    <div class="notice notice-success"><p>Import applied successfully.</p></div>
                <?php elseif ($msg === 'no_draft') : ?>
                    <div class="notice notice-warning"><p>No draft import found. Please upload a CSV first.</p></div>
                <?php elseif ($msg !== '') : ?>
                    <div class="notice notice-warning"><p>Operation message: <?php echo esc_html($msg); ?></p></div>
                <?php endif; ?>

                <h2>Agents included</h2>
                <p class="description">Select which agents to include in exports. Import will use whatever agent columns exist in the CSV.</p>

                <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
                    <input type="hidden" name="page" value="wcss-import-export" />
                    <table class="widefat striped" style="max-width:900px;">
                        <thead><tr><th style="width:40px;"></th><th>Agent</th><th>Domain</th></tr></thead>
                        <tbody>
                        <?php foreach ($agent_keys as $k => $info) :
                            $checked = isset($_GET['agents']) ? in_array($k, (array) $_GET['agents'], true) : true;
                            $domain = parse_url((string) $info['url'], PHP_URL_HOST);
                            ?>
                            <tr>
                                <td><input type="checkbox" name="agents[]" value="<?php echo esc_attr($k); ?>" <?php checked(true, $checked); ?> /></td>
                                <td><?php echo esc_html((string) $info['name']); ?></td>
                                <td><code><?php echo esc_html((string) $domain); ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p style="margin-top:10px;">
                        <button class="button">Update selection</button>
                    </p>
                </form>

                <?php
                $selected_agents = isset($_GET['agents']) ? (array) $_GET['agents'] : array_keys($agent_keys);
                $selected_agents = array_filter(array_map('sanitize_key', $selected_agents));
                $agents_query = [];
                foreach ($selected_agents as $k) {
                    $agents_query[] = 'agents[]=' . rawurlencode($k);
                }
                $agents_qs = implode('&', $agents_query);
                ?>

                <h2>Exports</h2>
                <p>
                    <a class="button button-primary" href="<?php echo esc_url(admin_url('admin-post.php?action=wcss_export_all_skus&' . $agents_qs)); ?>">Export All SKUs (CSV)</a>
                    <a class="button" href="<?php echo esc_url(admin_url('admin-post.php?action=wcss_export_synced_skus&' . $agents_qs)); ?>">Export Synced SKUs (CSV)</a>
                </p>
                <p class="description">
                    “All SKUs” groups Host + selected Agents by <code>normalized_key</code>. “Synced SKUs” includes only groups with a Host SKU and at least one Agent SKU.
                </p>

                <hr />

                <h2>Import mappings</h2>
                <p class="description">
                    Upload a CSV shaped like “Synced SKUs”. Only <code>host_sku</code> and <code>*_sku</code> columns are required.
                    Blank agent cells are ignored (leave as-is; auto-matching still applies).
                </p>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php?action=wcss_import_sku_mappings_draft')); ?>" enctype="multipart/form-data">
                    <?php wp_nonce_field('wcss_import_sku_mappings'); ?>
                    <input type="file" name="wcss_csv" accept=".csv,text/csv" required />
                    <button class="button button-primary">Upload (Draft)</button>
                </form>

                <?php if ($show_draft) : ?>
                    <?php
                    $changes = is_array($draft['changes'] ?? null) ? $draft['changes'] : [];
                    $warnings = is_array($draft['warnings'] ?? null) ? $draft['warnings'] : [];
                    $only_warnings = ('1' === (string) ($_GET['wcss_only_warnings'] ?? '0'));
                    ?>
                    <h3 style="margin-top:18px;">Draft preview</h3>
                    <p class="description">Review changes before applying. Use the filter to show only warnings/conflicts.</p>

                    <p>
                        <a class="button" href="<?php echo esc_url(add_query_arg(['page' => 'wcss-import-export', 'wcss_draft' => '1', 'wcss_only_warnings' => $only_warnings ? '0' : '1'], admin_url('admin.php'))); ?>">
                            <?php echo $only_warnings ? 'Show all' : 'Show only warnings/conflicts'; ?>
                        </a>
                    </p>

                    <table class="widefat striped" style="max-width:1100px;">
                        <thead>
                            <tr>
                                <th>Agent</th>
                                <th>Agent SKU</th>
                                <th>Host SKU</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            if (!$only_warnings) :
                                foreach (array_slice($changes, 0, 500) as $c) :
                                    ?>
                                    <tr>
                                        <td><code><?php echo esc_html((string) ($c['agent'] ?? '')); ?></code></td>
                                        <td><?php echo esc_html((string) ($c['agent_sku'] ?? '')); ?></td>
                                        <td><?php echo esc_html((string) ($c['host_sku'] ?? '')); ?></td>
                                        <td><?php echo esc_html((string) ($c['action'] ?? '')); ?></td>
                                    </tr>
                                <?php endforeach;
                            else :
                                foreach ($warnings as $w) : ?>
                                    <tr>
                                        <td><code><?php echo esc_html((string) ($w['agent'] ?? '')); ?></code></td>
                                        <td><?php echo esc_html((string) ($w['agent_sku'] ?? '')); ?></td>
                                        <td><?php echo esc_html((string) (($w['host_sku'] ?? ($w['host_sku_a'] ?? '')))); ?></td>
                                        <td><?php echo esc_html((string) ($w['type'] ?? 'warning')); ?></td>
                                    </tr>
                                <?php endforeach;
                            endif;
                            ?>
                        </tbody>
                    </table>

                    <?php if (count($changes) > 500 && !$only_warnings) : ?>
                        <p class="description">Showing first 500 changes (<?php echo esc_html((string) count($changes)); ?> total).</p>
                    <?php endif; ?>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php?action=wcss_import_sku_mappings_apply')); ?>">
                        <?php wp_nonce_field('wcss_import_sku_mappings_apply'); ?>
                        <p style="margin-top:12px;">
                            <button class="button button-primary">Apply import</button>
                        </p>
                    </form>
                <?php endif; ?>

            <?php endif; ?>
        </div>
        <?php
    }

    public function render_missing_skus_page(): void {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $host_missing = get_option('hashy_au_host_missing_skus', []);
        if (!is_array($host_missing)) {
            $host_missing = [];
        }
        // Merge entries recorded under the legacy (misread) option key.
        $legacy_missing = get_option('hashy_au_missing_skus', []);
        if (is_array($legacy_missing) && !empty($legacy_missing)) {
            $host_missing = array_replace_recursive($legacy_missing, $host_missing);
        }
        $agent_missing = get_option('hashy_au_agent_missing_skus', []);
        if (!is_array($agent_missing)) {
            $agent_missing = [];
        }

        ?>
        <div class="wrap">
            <h1>WC Stock Sync — Missing SKUs</h1>

            <h2>Host missing (reported by Agents)</h2>
            <p class="description">Incoming order items that could not be matched to a Host SKU/product.</p>
            <pre style="background:#fff; padding:12px; border:1px solid #ddd; max-height:380px; overflow:auto;"><?php echo esc_html(wp_json_encode($host_missing, JSON_PRETTY_PRINT)); ?></pre>

            <h2>Agent missing (reported on stock update)</h2>
            <p class="description">Host pushed updates for SKUs not found on an Agent store.</p>
            <pre style="background:#fff; padding:12px; border:1px solid #ddd; max-height:380px; overflow:auto;"><?php echo esc_html(wp_json_encode($agent_missing, JSON_PRETTY_PRINT)); ?></pre>
        </div>
        <?php
    }

    public function render_logs_page(): void {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $logs = Hashy_AU_Logger::instance()->get_logs(250, 0);
        $nonce_clear = wp_create_nonce('wcss_clear_logs');

        ?>
        <div class="wrap">
            <h1>WC Stock Sync — Logs</h1>

            <p>
                <a href="#" class="button" id="wcss_clear_logs">Clear logs</a>
            </p>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th style="width:160px;">Time</th>
                        <th style="width:80px;">Level</th>
                        <th>Message</th>
                        <th>Context</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $row) : ?>
                        <tr>
                            <td><?php echo esc_html(gmdate('Y-m-d H:i:s', (int) ($row['ts'] ?? time()))); ?> UTC</td>
                            <td><?php echo esc_html((string) ($row['level'] ?? '')); ?></td>
                            <td><?php echo esc_html((string) ($row['message'] ?? '')); ?></td>
                            <td><code><?php echo esc_html(wp_json_encode($row['context'] ?? [])); ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

<script>
(function(){
    const ajaxurl = window.ajaxurl || '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
    const btn = document.getElementById('wcss_clear_logs');
    if(!btn) return;
    btn.addEventListener('click', async function(e){
        e.preventDefault();
        const data = new FormData();
        data.append('action', 'wcss_clear_logs');
        data.append('nonce', '<?php echo esc_js($nonce_clear); ?>');
        const r = await fetch(ajaxurl, {method:'POST', body:data, credentials:'same-origin'}).then(r => r.json()).catch(()=>null);
        if(r && r.success) location.reload();
        else alert('Failed to clear logs');
    });
})();
</script>
        <?php
    }

    /** AJAX */

    public function ajax_generate_secret(): void {
        check_ajax_referer('wcss_generate_secret', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'forbidden'], 403);
        }
        $secret = Hashy_AU_Crypto::random_secret(48);
        wp_send_json_success(['secret' => $secret]);
    }

    public function ajax_test_agent(): void {
        check_ajax_referer('wcss_test_agent', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'forbidden'], 403);
        }

        if ($this->get_mode() !== 'host') {
            wp_send_json_error(['message' => 'not_host'], 400);
        }

        $agent_url = isset($_POST['agent_url']) ? esc_url_raw((string) wp_unslash($_POST['agent_url'])) : '';
        $secret = isset($_POST['agent_secret']) ? (string) wp_unslash($_POST['agent_secret']) : '';

        if (empty($agent_url) || empty($secret)) {
            wp_send_json_error(['message' => 'agent_url/secret missing'], 400);
        }

        $endpoint = untrailingslashit($agent_url) . '/wp-json/hashy-sync/v1/host/ping';
        $payload = [
            'host_url' => untrailingslashit(home_url()),
            'ts' => time(),
        ];
        $body = wp_json_encode($payload);
        $timestamp = (string) time();
        $signature = Hashy_AU_Crypto::sign($secret, $timestamp, (string) $body);

        $resp = wp_remote_post($endpoint, [
            'timeout' => 15,
            'headers' => [
                'Content-Type' => 'application/json; charset=utf-8',
                'x-hashy-timestamp' => $timestamp,
                'x-hashy-signature' => $signature,
            ],
            'body' => $body,
        ]);

        if (is_wp_error($resp)) {
            wp_send_json_error(['message' => $resp->get_error_message()], 500);
        }

        $code = (int) wp_remote_retrieve_response_code($resp);
        $resp_body = (string) wp_remote_retrieve_body($resp);

        if ($code >= 200 && $code < 300) {
            wp_send_json_success(['code' => $code, 'body' => $resp_body]);
        }

        wp_send_json_error(['code' => $code, 'body' => $resp_body], $code ?: 500);
    }

    public function ajax_test_host(): void {
        check_ajax_referer('wcss_test_host', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'forbidden'], 403);
        }

        if ($this->get_mode() !== 'agent') {
            wp_send_json_error(['message' => 'not_agent'], 400);
        }

        $host_url = $this->get_agent_host_url();
        $secret = $this->get_agent_shared_secret();

        if (empty($host_url) || empty($secret)) {
            wp_send_json_error(['message' => 'host_url/shared_secret missing'], 400);
        }

        $endpoint = untrailingslashit($host_url) . '/wp-json/hashy-sync/v1/host/ping';
        $payload = [
            'agent_url' => untrailingslashit(home_url()),
            'ts' => time(),
        ];
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
            Hashy_AU_Logger::instance()->error('Test host failed (wp_error)', ['error' => $res->get_error_message()]);
            wp_send_json_error(['message' => $res->get_error_message()], 500);
        }

        $code = (int) wp_remote_retrieve_response_code($res);
        $resp_body = (string) wp_remote_retrieve_body($res);

        if ($code < 200 || $code >= 300) {
            Hashy_AU_Logger::instance()->warning('Test host non-2xx', ['code' => $code, 'body' => substr($resp_body, 0, 500)]);
            wp_send_json_error(['message' => 'HTTP ' . $code . ': ' . substr($resp_body, 0, 200)], 500);
        }

        Hashy_AU_Logger::instance()->info('Test host OK', ['code' => $code]);
        wp_send_json_success(['ok' => true, 'code' => $code]);
    }

public function ajax_send_test_order_paid(): void {
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => 'forbidden'], 403);
    }
    check_ajax_referer('wcss_send_test_order_paid', 'nonce');

    $sku = isset($_POST['sku']) ? sanitize_text_field(wp_unslash((string) $_POST['sku'])) : '';
    if (empty($sku)) {
        wp_send_json_error(['message' => 'missing_sku'], 400);
    }

    $res = Hashy_AU_Agent::instance()->send_test_order_paid_by_sku($sku, 1);
    if ($res['ok']) {
        wp_send_json_success($res);
    }
    wp_send_json_error(['message' => $res['error'] ?? 'failed', 'detail' => $res], 500);
}




    public function ajax_start_price_sync(): void {
        check_ajax_referer('wcss_start_price_sync', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'forbidden'], 403);
        }
        if ('host' !== $this->get_mode()) {
            wp_send_json_error(['message' => 'Host mode only'], 400);
        }

        $idx = isset($_POST['agent_index']) ? (int) $_POST['agent_index'] : -1;
        $agents = $this->get_host_agents();
        if (!isset($agents[$idx]) || !is_array($agents[$idx])) {
            wp_send_json_error(['message' => 'Unknown agent index'], 400);
        }

        $agent = $agents[$idx];
        if (empty($agent['url']) || empty($agent['shared_secret'])) {
            wp_send_json_error(['message' => 'Agent URL/secret missing'], 400);
        }

        if ('yes' !== (string) ($agent['sync_prices'] ?? 'no')) {
            wp_send_json_error(['message' => 'Enable “Sync Prices” for this agent first.'], 400);
        }

        Hashy_AU_Logger::instance()->info('Price sync started (admin)', ['agent_url' => (string) $agent['url']]);
        $res = Hashy_AU_Host::instance()->process_price_sync_batch_once($agent, 1);
        if (!empty($res['error'])) {
            wp_send_json_error(['message' => (string) $res['error']], 400);
        }
        wp_send_json_success($res);
    }

    public function ajax_run_price_sync_batch(): void {
        check_ajax_referer('wcss_start_price_sync', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'forbidden'], 403);
        }
        if ('host' !== $this->get_mode()) {
            wp_send_json_error(['message' => 'Host mode only'], 400);
        }

        $idx = isset($_POST['agent_index']) ? (int) $_POST['agent_index'] : -1;
        $page = isset($_POST['page']) ? max(1, (int) $_POST['page']) : 1;

        $agents = $this->get_host_agents();
        if (!isset($agents[$idx]) || !is_array($agents[$idx])) {
            wp_send_json_error(['message' => 'Unknown agent index'], 400);
        }

        $agent = $agents[$idx];
        if (empty($agent['url']) || empty($agent['shared_secret'])) {
            wp_send_json_error(['message' => 'Agent URL/secret missing'], 400);
        }

        $res = Hashy_AU_Host::instance()->process_price_sync_batch_once($agent, $page);
        if (!empty($res['error'])) {
            wp_send_json_error(['message' => (string) $res['error']], 400);
        }
        wp_send_json_success($res);
    }

    public function ajax_start_stock_sync(): void {
        check_ajax_referer('wcss_start_stock_sync', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'forbidden'], 403);
        }
        if ('host' !== $this->get_mode()) {
            wp_send_json_error(['message' => 'Host mode only'], 400);
        }

        $idx = isset($_POST['agent_index']) ? (int) $_POST['agent_index'] : -1;
        $agents = $this->get_host_agents();
        if (!isset($agents[$idx]) || !is_array($agents[$idx])) {
            wp_send_json_error(['message' => 'Unknown agent index'], 400);
        }

        $agent = $agents[$idx];
        if (empty($agent['url']) || empty($agent['shared_secret'])) {
            wp_send_json_error(['message' => 'Agent URL/secret missing'], 400);
        }

        Hashy_AU_Logger::instance()->info('Stock sync started (admin)', ['agent_url' => (string) $agent['url']]);
        $res = Hashy_AU_Host::instance()->process_stock_sync_batch_once($agent, 1);
        if (!empty($res['error'])) {
            wp_send_json_error(['message' => (string) $res['error']], 400);
        }
        wp_send_json_success($res);
    }

    public function ajax_run_stock_sync_batch(): void {
        check_ajax_referer('wcss_start_stock_sync', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'forbidden'], 403);
        }
        if ('host' !== $this->get_mode()) {
            wp_send_json_error(['message' => 'Host mode only'], 400);
        }

        $idx = isset($_POST['agent_index']) ? (int) $_POST['agent_index'] : -1;
        $page = isset($_POST['page']) ? max(1, (int) $_POST['page']) : 1;

        $agents = $this->get_host_agents();
        if (!isset($agents[$idx]) || !is_array($agents[$idx])) {
            wp_send_json_error(['message' => 'Unknown agent index'], 400);
        }

        $agent = $agents[$idx];
        if (empty($agent['url']) || empty($agent['shared_secret'])) {
            wp_send_json_error(['message' => 'Agent URL/secret missing'], 400);
        }

        $res = Hashy_AU_Host::instance()->process_stock_sync_batch_once($agent, $page);
        if (!empty($res['error'])) {
            wp_send_json_error(['message' => (string) $res['error']], 400);
        }
        wp_send_json_success($res);
    }

    public function ajax_clear_logs(): void {
        check_ajax_referer('wcss_clear_logs', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'forbidden'], 403);
        }
        Hashy_AU_Logger::instance()->clear_logs();
        wp_send_json_success(['message' => 'cleared']);
    }
}
