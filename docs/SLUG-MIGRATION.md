# Slug migration: `wc-stock-sync` to `NEW_SLUG`

Status: **blocked on the slug choice** (review finding D1, 2026-09-06). Nothing in this document is built. Once `NEW_SLUG` is chosen, this is a fill-in job: replace the placeholders, follow the steps in order, run the rehearsal, release.

Placeholders used throughout:

| Placeholder | Meaning | Example |
|---|---|---|
| `NEW_SLUG` | The new folder, main file and text domain. Must not contain `wc`, `woo` or `woocommerce` (WordPress.org restricted terms). | `hashy-stock-sync` |
| `NEW_NAME` | The new `Plugin Name`. Must not start with `WC` or `WooCommerce`. | `Hashy Stock Sync` |
| `NEW_REPO` | GitHub repo the new slug releases from until the directory takes over. | `https://github.com/Hashy-au/NEW_SLUG/` |
| `NEW_ASSET_URL` | Direct URL of the new slug's release zip, used by the shim. | `https://github.com/Hashy-au/NEW_SLUG/releases/latest/download/NEW_SLUG.zip` |

## Why a migration release

WordPress has no "plugin renamed" mechanism. The directory assigns the slug from the first submission and never changes it, and the bundled update checker cannot redirect one slug to another. The fleet (the Host at archery.solkarra.com.au and every Agent) updates silently through the checker today, so the cheapest safe route is a **final release under the old slug whose only new code is a shim** that installs and activates the new plugin, then deactivates itself. Thomas's decision (2026-09-06): new slug, fleet migrates through this update, no manual visits.

## What must stay identical between the two plugins

The new plugin is the same code under a new identity. Keep every one of these exactly as they are so no data migration is needed and mixed fleets keep talking to each other during the rollout:

- Option names: `hashy_au_settings`, `hashy_au_secrets`, `hashy_au_mappings`, `wcss_agent_sku_overrides`, `wcss_seen_orders`, `wcss_failed_requests`, `wcss_agent_outbox`, `wcss_agent_outbox_dead`, `wcss_host_push_queue`, `wcss_stocktake_job`, `wcss_log_ring`, the missing-SKU options, `wcss_self_check_pending`, and the transient prefixes (`wcss_agent_skus_`, `wcss_import_draft_`, `wcss_stocktake_draft_`, `wcss_norm_sku_map`).
- REST namespace and routes: `hashy-sync/v1` with `/agent/order-paid`, `/host/ping`, `/host/stock-update`, `/host/sku-index`, `/host/sku-index-detailed`. A Host on the new slug must still accept an Agent on the old one and the reverse.
- The signing scheme (`Hashy_AU_Crypto`), the header names, the 300 s skew window.
- Cron hook names: `hashy_au_daily_reconcile`, `wcss_retry_failed_requests`, `wcss_agent_process_outbox`, `wcss_drain_push_queue`.
- Product meta `_wcss_last_sync_ts`.
- Class names and constants (`WC_STOCK_SYNC_VERSION` and friends) can stay; nothing outside the plugin reads them. Renaming them is optional polish for the directory submission and is not part of the migration.

What changes: the folder, the main file name, `Plugin Name`, `Text Domain` (the plugin has no translation calls today, so this is a header edit only), `Update URI` if one is added, the update checker's repo URL and slug argument, the release asset name, the admin menu title and the `<h1>` strings, and `readme.txt`.

## Step 1: create the new plugin

1. Copy the `wc-stock-sync` source to a new repo `NEW_REPO` (history is optional; a fresh repo is fine).
2. Rename `wc-stock-sync.php` to `NEW_SLUG.php`. Set `Plugin Name: NEW_NAME`, `Text Domain: NEW_SLUG`, `Version: 1.0.0`.
3. In the update-checker block, change the repo URL to `NEW_REPO`, the slug argument to `'NEW_SLUG'`, and `enableReleaseAssets('/^NEW_SLUG\.zip$/')`.
4. `scripts/build-release.ps1`: the staged folder and zip name become `NEW_SLUG`.
5. `readme.txt`: `=== NEW_NAME ===`, and `Contributors:` becomes the WordPress.org username that will own the directory listing.
6. Add the refuse-to-boot guard (Step 2) to the new plugin's main file.
7. Build, then run the Playground smoke test from the review tooling against the staged copy: activation clean, `ADMIN_PROBE_OK`, no plugin-originated debug lines.

## Step 2: the new plugin refuses to run beside the old one

A failed or half-finished shim must never leave two sets of hooks live (double pushes, double decrements). At the top of `NEW_SLUG.php`, before anything is hooked:

```php
// Refuse to boot while the old plugin is active; the old plugin's migration
// shim deactivates it, but a half-finished run must not leave both sets of
// hooks live.
if (!function_exists('is_plugin_active')) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
if (is_plugin_active('wc-stock-sync/wc-stock-sync.php')) {
    add_action('admin_notices', function () {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        $url = wp_nonce_url(
            add_query_arg(['action' => 'deactivate', 'plugin' => 'wc-stock-sync/wc-stock-sync.php'], admin_url('plugins.php')),
            'deactivate-plugin_wc-stock-sync/wc-stock-sync.php'
        );
        echo '<div class="notice notice-error"><p><strong>NEW_NAME</strong> is installed but paused because the old <strong>WC Stock Sync</strong> plugin is still active. <a href="' . esc_url($url) . '">Deactivate WC Stock Sync</a> and this plugin starts on the next page load. Your settings, secrets and queues are shared and unaffected.</p></div>';
    });
    return; // Nothing else in this file runs.
}
```

The `return` at file scope stops the rest of the main file (no constants, no bootstrap, no update checker) without a fatal error.

## Step 3: the shim in the old plugin, released as 0.6.0

The old plugin's last release. Its only new code is `includes/class-hashy-au-slug-migration.php`, wired from `wc-stock-sync.php` at file scope (the shim must run even when WooCommerce is inactive, so do not put it in the WooCommerce-gated bootstrap).

Behaviour, in order, every step idempotent and every outcome written to the existing log ring (`Hashy_AU_Logger`, which is safe to load standalone):

1. **Trigger points**: `admin_init` (any admin page load) and once from `upgrader_process_complete` when the completed upgrade includes `wc-stock-sync/wc-stock-sync.php`. Also run it from the existing `hashy_au_daily_reconcile` cron so a Host or Agent nobody visits still migrates within a day.
2. **Lock**: `get_transient('wcss_slug_migration_lock')`; return if set; otherwise set it for 10 minutes. Two admin tabs or cron plus admin must not both install.
3. **Already done?** If `file_exists(WP_PLUGIN_DIR . '/NEW_SLUG/NEW_SLUG.php')` and `is_plugin_active('NEW_SLUG/NEW_SLUG.php')`: deactivate self if still active (see 7), log `already migrated`, delete the lock, return.
4. **Preconditions** (log a distinct message and set the failure notice on each): `DISALLOW_FILE_MODS` not defined or false; `wp_is_file_mod_allowed('capability_update_core')`; `WP_Filesystem()` returns true with the `direct` method (`get_filesystem_method()`), because a credentials prompt cannot be answered in the background; current user can `install_plugins` when running from admin (skip the capability test when running from cron, which has no user, and rely on the filesystem test).
5. **Install** if the folder is missing: load `wp-admin/includes/class-wp-upgrader.php`, `wp-admin/includes/plugin-install.php`, `wp-admin/includes/file.php`, `wp-admin/includes/misc.php`; `$upgrader = new Plugin_Upgrader(new WP_Ajax_Upgrader_Skin())`; `$result = $upgrader->install(WCSS_MIGRATION_PACKAGE)`. `WCSS_MIGRATION_PACKAGE` defaults to `NEW_ASSET_URL` and can be overridden by a `define()` in `wp-config.php` (the rehearsal points it at a local zip; `download_package()` accepts a local path). On `WP_Error` or `false`, log `$result->get_error_message()` (never the package URL if it ever carries a token), set the failure notice, delete the lock, return. The public repo needs no token.
6. **Activate**: `activate_plugin('NEW_SLUG/NEW_SLUG.php', '', false, true)` (silent, so the new plugin's own activation hook still runs but no redirect). On `WP_Error`, log, notice, unlock, return. The new plugin sees the old one still active and pauses itself (Step 2) until the next step.
7. **Deactivate self**: `deactivate_plugins(plugin_basename(HASHY_AU_PLUGIN_FILE), true)` (silent, so `Hashy_AU_Bootstrap::deactivate()` does not run and the shared cron hooks stay scheduled for the new plugin, which re-creates them anyway). Log `migrated to NEW_SLUG`, store `update_option('wcss_slug_migrated', time(), false)`, delete the lock.
8. **Failure notice**: `set_transient('wcss_slug_migration_error', $message, DAY_IN_SECONDS)`; an `admin_notices` callback shows it to `activate_plugins` users with the manual path (install `NEW_NAME` from `NEW_ASSET_URL`, activate it, deactivate WC Stock Sync) and a "Retry" link that deletes the lock. The notice is the only UI.
9. **Do not delete** the old plugin folder from the shim. Deleting the running plugin's own files mid-request is fragile; the administrator deletes it from the Plugins screen once the fleet is across (the wp-admin list then shows `NEW_NAME` active and `WC Stock Sync` inactive).

After step 7 the request continues with the old plugin's hooks already registered for that request; that is harmless because both plugins refuse to double-register (the new one is paused for this request by Step 2, and from the next request only the new one loads).

## Step 4: release order

1. Publish the new slug's first release (`v1.0.0`, asset `NEW_SLUG.zip`) on `NEW_REPO` and confirm `NEW_ASSET_URL` downloads without authentication.
2. Verify Step 3's preconditions by hand on **one Agent** first: in wp-admin, install and activate the new plugin from the zip, confirm the pause notice, deactivate the old plugin, confirm sync still works both ways (Test Host / Test Agent, one real order-paid). This proves the identical-options claim on a live site before the shim automates it.
3. Publish `wc-stock-sync` `v0.6.0` with the shim. The fleet's update checker picks it up within its 12-hour window; each site migrates on its next admin visit or daily reconcile run.
4. Watch the Host first (it is the one site whose downtime matters), then the Agents. Each site's log ring shows `migrated to NEW_SLUG` or a distinct failure line.
5. Rollback on any site: deactivate `NEW_NAME`, reactivate WC Stock Sync (0.6.0 will attempt the shim again on the next admin load; define `WCSS_MIGRATION_DISABLED` as true in `wp-config.php` to stop it, and make the shim honour that constant first of all).

## Step 5: after the fleet is across (finding D2 and D6)

- Delete the old plugin folder on every site from the Plugins screen.
- Retire the old GitHub release flow. The new slug keeps its GitHub edition (with the update checker) until the directory listing is live.
- The directory build of `NEW_SLUG` must not contain `includes/lib/plugin-update-checker/`, the update-checker block in the main file, or the GitHub token setting and its `Hashy_AU_Secrets` getter. Add a `-Directory` switch to `build-release.ps1` that omits them and strips the "Automatic updates" section from `readme.txt`; Plugin Check must then report no `update_checker` or trademark items.
- Once the directory serves updates, publish one last GitHub edition whose only change is removing the update checker, so the fleet stops polling GitHub.

## Playground rehearsal (before publishing 0.6.0)

Uses the review tooling at `wp_plugins/REVIEW/tooling/playground/` (the runner, `run.sh`, mounts a staged plugin and executes the blueprint). The rehearsal needs a blueprint of its own rather than the smoke-test template, because it installs two plugins and drives the shim; copy `blueprint-template.json` to `blueprint-slug-migration.json` and adapt.

1. **Stage the old plugin at 0.6.0** (the shim build) as `wc-stock-sync`, staged the usual way (no `.git`, `dist`, `scripts`, `tests`, `docs`).
2. **Build `NEW_SLUG.zip`** and mount it read-only at `/wordpress/review-in/NEW_SLUG.zip`.
3. **Blueprint steps**, in order: install and activate WooCommerce; `defineWpConfigConsts` with `WCSS_MIGRATION_PACKAGE` = `/wordpress/review-in/NEW_SLUG.zip` and `WP_DEBUG` on; activate `wc-stock-sync`; seed state with `wp option update hashy_au_settings '<json>' --format=json` (host mode, two agent rows with ids), `wp option update hashy_au_secrets ...` (the sealed form is site-specific, so save through the settings sanitiser instead: `wp eval 'Hashy_AU_Secrets::save([...]);'` after the plugin is loaded), `wp option update hashy_au_mappings '{"ABC123":"HOST-1"}' --format=json`, and one row in `wcss_failed_requests`.
4. **Fire the shim** the way a real site would: run the admin probe (`review-tools/admin-probe.php`, which loads wp-admin as user 1 and fires `admin_init`). Then, in a second pass, reset (`wp plugin deactivate NEW_SLUG; wp plugin delete NEW_SLUG; wp plugin activate wc-stock-sync; wp transient delete wcss_slug_migration_lock`) and fire it from cron instead: `wp cron event run hashy_au_daily_reconcile`.
5. **Assert after each pass** (write the results to `review-out/`):
   - `wp plugin list --format=json`: `NEW_SLUG` active, `wc-stock-sync` inactive, both present.
   - `wp option get hashy_au_settings --format=json` is byte-identical to what was seeded (minus nothing; the shim never touches it).
   - `wp eval 'echo count(Hashy_AU_Settings::instance()->get_host_agents());'` returns 2 and each row still has a non-empty `shared_secret` (the secrets option decrypts on the new plugin because `wp_salt('auth')` is the same site).
   - `wp option get hashy_au_mappings` and `wcss_failed_requests` unchanged.
   - `wp cron event list` still shows `hashy_au_daily_reconcile` and `wcss_retry_failed_requests`.
   - The log ring (`wp option get wcss_log_ring`) contains exactly one `migrated to NEW_SLUG` line per pass, and the transient `wcss_slug_migration_error` does not exist.
   - `wp-content/debug.log` has no plugin-originated lines.
   - A signed `POST /wp-json/hashy-sync/v1/host/ping` with one seeded agent's secret returns 200 (proves the routes survived the swap).
6. **Failure paths**, each its own pass: `WCSS_MIGRATION_PACKAGE` pointing at a missing file (expect the failure notice, old plugin still active, lock cleared); `DISALLOW_FILE_MODS` true (expect the precondition message and no install attempt); run the shim twice in one pass (expect the second run to log `already migrated` and do nothing).
7. **Mixed fleet**: with the Host on `NEW_SLUG` in one Playground and an Agent still on `wc-stock-sync` 0.5.1 in another, the two cannot reach each other across sandboxes, so this is proven on the live pilot Agent in Step 4.2 instead, not in Playground.

## Fill-in checklist

- [ ] `NEW_SLUG`, `NEW_NAME`, `NEW_REPO` chosen; WordPress.org username for `Contributors:` confirmed.
- [ ] New repo created, plugin renamed (Step 1), pause guard added (Step 2), smoke test clean.
- [ ] Shim class written (Step 3), `WCSS_MIGRATION_PACKAGE` and `WCSS_MIGRATION_DISABLED` constants honoured.
- [ ] Rehearsal passes, including the three failure paths (Playground section).
- [ ] Pilot Agent migrated by hand and verified (Step 4.2).
- [ ] `v1.0.0` on `NEW_REPO` published; `v0.6.0` on `wc-stock-sync` published.
- [ ] Every fleet site shows `NEW_NAME` active; old folders deleted; GitHub edition retired (Step 5).
