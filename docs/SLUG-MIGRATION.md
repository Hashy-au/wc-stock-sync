# Slug migration runbook: `wc-stock-sync` to `hashy-stock-sync`

Status: **built, rehearsed in Playground, not released** (2026-09-06). Thomas's decisions: new slug `hashy-stock-sync`, new name "Hashy Stock Sync", the fleet migrates through a one-off 0.6.0 update under the old slug. WordPress.org submission comes later; until then the fleet keeps updating from GitHub.

## What ships in 0.6.0

One GitHub release, `v0.6.0`, on the existing repo `Hashy-au/wc-stock-sync`, with two assets:

| Asset | Built by | Top folder | Who downloads it |
|---|---|---|---|
| `hashy-stock-sync.zip` | `scripts/build-release.ps1` (default) | `hashy-stock-sync/` | The renamed plugin itself (its update checker matches `/^hashy-stock-sync\.zip$/`), and the shim below. |
| `wc-stock-sync.zip` | `scripts/build-release.ps1 -Shim` | `wc-stock-sync/` | Every 0.5.x install: their update checker matches `/^wc-stock-sync\.zip$/`, sees 0.6.0 > 0.5.x, and updates in place. |

A third zip, `hashy-stock-sync-directory.zip` (`-Directory`), is the WordPress.org submission build: no `includes/github-updates.php`, no `includes/lib/plugin-update-checker/`, readme without the automatic-updates text, GitHub token field hidden (it shows only while `WCSS_GITHUB_REPO` is defined by the updater file). It is never attached to a GitHub release. `-Publish` attaches every zip built in the run except that one.

## What is identical between the two plugins

The renamed plugin is the same code under a new folder, main file, `Plugin Name`, `Text Domain`, menu title and page headings. Everything a site stores or another site talks to is unchanged, so no data migration exists and a Host on either name keeps talking to Agents on either name:

- Options: `hashy_au_settings`, `hashy_au_secrets`, `hashy_au_mappings`, `wcss_agent_sku_overrides`, `wcss_seen_orders`, `wcss_failed_requests`, `wcss_agent_outbox`, `wcss_agent_outbox_dead`, `wcss_host_push_queue`, `wcss_stocktake_job`, `wcss_log_ring`, the missing-SKU options, `wcss_self_check_pending`, and the transient prefixes.
- REST namespace and routes: `hashy-sync/v1` with `/agent/order-paid`, `/host/ping`, `/host/stock-update`, `/host/sku-index`, `/host/sku-index-detailed`; the signing scheme (`Hashy_AU_Crypto`), header names and 300 s skew.
- Cron hooks: `hashy_au_daily_reconcile`, `wcss_retry_failed_requests`, `wcss_agent_process_outbox`, `wcss_drain_push_queue`.
- Product meta `_wcss_last_sync_ts`.
- Class names (`Hashy_AU_*`), constants (`WC_STOCK_SYNC_VERSION`, `WC_STOCK_SYNC_PLUGIN_DIR`, `HASHY_AU_PLUGIN_FILE`), the `wcss_` prefix, the admin page slugs (`wcss`, `wcss-logs`, ...).

Also unchanged: the update checker's repo URL (`WCSS_GITHUB_REPO`) and the `WCSS_GITHUB_TOKEN` constant. Changed: the WooCommerce log source is `hashy-stock-sync` (was `wc-stock-sync`).

## How a site migrates

1. The 0.5.x install updates to 0.6.0. The in-place update replaces the whole `wc-stock-sync/` folder with the shim's two files (`wc-stock-sync.php`, `LICENSE`); the plugin stays active. From this moment the site has no sync code until step 3 completes, which is why the triggers below are as eager as they are.
2. The shim (`shim/wc-stock-sync/wc-stock-sync.php`, class `WCSS_Slug_Migration`) runs on the first of: an administrator's admin page load (`admin_init`, AJAX requests excluded, `activate_plugins` required), `upgrader_process_complete` for a plugin upgrade that included it, or the `hashy_au_daily_reconcile` cron event, which the old plugin left scheduled, so a site nobody visits still migrates within a day.
3. Under a 10-minute lock (`wcss_slug_migration_lock`) it: checks `hashy-stock-sync/hashy-stock-sync.php`; if missing, requires `DISALLOW_FILE_MODS` off and the `direct` filesystem method, then `Plugin_Upgrader::install()` with `WP_Ajax_Upgrader_Skin` from the package URL; `activate_plugin()` on the new plugin; `deactivate_plugins( plugin_basename( __FILE__ ), true )` on itself; sets `wcss_slug_migrated` and a one-day `wcss_slug_migration_done` transient. Every line goes to `wcss_log_ring` in the logger's row shape (`ts`, `level`, `message`, `context.source = wc-stock-sync`, `context.step = slug-migration`, `context.trigger`) and to the WooCommerce logger when present.
4. In the migrating request the shim shows a success notice; on the next admin load the new plugin shows its own one-time notice and removes the transient. The old folder is left in place for manual deletion (deleting a running plugin's own files mid-request is fragile).
5. On failure the error goes to `wcss_slug_migration_error` (one day) and the lock is left to expire, so a transient fault retries by itself in 10 minutes without hitting the network on every admin page load. The notice shows the message, a nonce-protected "retry now" link (clears lock and error), and the manual path: download `hashy-stock-sync.zip` from the release, upload it under Plugins, activate it, deactivate WC Stock Sync.
6. Idempotent: if the new plugin is already installed and active, the shim only deactivates itself and logs `already migrated`. If it is installed but inactive (a rollback), it activates it and steps aside again, unless `WCSS_MIGRATION_DISABLED` is true.

The new plugin refuses to boot while `wc-stock-sync/wc-stock-sync.php` is in `active_plugins` (or network-active): its main file returns before any constant, class or hook, and shows an error notice with a one-click deactivate link for the old plugin. The check is `is_plugin_active()` inlined, so `wp-admin/includes/plugin.php` is not loaded on every front-end request; the notice re-checks at render time, so the page on which the shim completes does not also show "paused". A failed or half-finished shim therefore never leaves two sets of hooks live.

Overrides for a site administrator, all in `wp-config.php`: `WCSS_MIGRATION_DISABLED` (true stops the shim; use it for a rollback to 0.6.0 shim + manual reactivation), `WCSS_MIGRATION_PACKAGE` (another URL or a local zip path; the `wcss_migration_package_url` filter does the same in code).

## Release order

1. Commit the working tree (renamed plugin, shim, docs; patch `REVIEW/patches/wc-stock-sync-slug-migration.patch`), tag `v0.6.0`, push the tag. Whether v0.5.1 is released as well is optional: the update checker offers only the latest release, so a fleet on 0.5.0 goes straight to the 0.6.0 shim, and the 0.5.1 fixes are in the renamed plugin.
2. Build: `powershell -ExecutionPolicy Bypass -File scripts\build-release.ps1 -Shim` (add `-Directory` only when the submission build is wanted). Confirm `dist\hashy-stock-sync.zip` and `dist\wc-stock-sync.zip` exist and that the script printed the forward-slash entry check for both.
3. Publish `v0.6.0` with both assets attached (`-Publish` does `gh release create v0.6.0 dist\hashy-stock-sync.zip dist\wc-stock-sync.zip`). Confirm `https://github.com/Hashy-au/wc-stock-sync/releases/download/v0.6.0/hashy-stock-sync.zip` downloads without authentication: that exact URL is compiled into the shim.
4. Migrate the Host (archery.solkarra.com.au) first, by hand rather than waiting for its 12-hour update window: Dashboard, Updates, update WC Stock Sync, then load any admin page. Expect the success notice, `Hashy Stock Sync` active and `WC Stock Sync` inactive on the Plugins screen, and the Logs page showing `installing`, `installed`, `activated`, `migrated to hashy-stock-sync; WC Stock Sync deactivated`. Then Settings: mode, agent rows and secrets present; Test Agent to one Agent still on the old name succeeds (proves the mixed-fleet claim on a live pair, which Playground cannot).
5. Agents: either the same by hand, or let them self-update; each finishes on its next admin visit or its next daily reconcile run. Check each site's Plugins screen and Logs page.
6. After every site shows the new plugin active: delete `WC Stock Sync` from each Plugins screen (it is inactive and its folder holds only the shim). This is safe only because the shim ships no `uninstall.php`. WordPress runs a plugin's `uninstall.php` whenever it is deleted from the Plugins screen (or with `wp plugin delete`), and this plugin's `uninstall.php` removes every option both names share: never delete a `wc-stock-sync` folder that is still at 0.5.x, and never delete Hashy Stock Sync itself while the data is wanted. Deactivating is always safe.
7. Rollback on any site: deactivate Hashy Stock Sync, define `WCSS_MIGRATION_DISABLED` true, reactivate WC Stock Sync (0.6.0 shim; note it has no sync code, so a true rollback means re-uploading the 0.5.1 zip built from tag `v0.5.1`). In practice the fix for a bad 0.6.0 is a 0.6.1 of the new plugin, which the new plugin's own updater delivers.

## Rehearsal (Playground, WordPress 7.1 / PHP 8.3 / WooCommerce)

Scripts and outputs: `wp_plugins/REVIEW/raw/wc-stock-sync/slug-migration/` (`run.sh`, `blueprint.json`, `scripts/`, results in `out/`; the guard pass against a real 0.5.1 is preserved from an earlier run in `out-run4-passA/`). It boots a throwaway site, copies the 0.5.1 tree (from tag `v0.5.1`, plus the update checker's untracked `vendor/` folder) into `wp-content/plugins/wc-stock-sync`, activates it, seeds a Host with one Agent row through the Settings API sanitiser (so the secret lands sealed in `hashy_au_secrets`), plus a mapping and a failed-request row, updates it to the shim with `Plugin_Upgrader::bulk_upgrade()` (what the Plugins screen's "Update now" does), then runs the passes below, each followed by a fresh-request assertion script. A review-owned mu-plugin steers the shim through options: the `wcss_migration_package_url` filter (pointed at the locally mounted `hashy-stock-sync.zip`), `DISALLOW_FILE_MODS`, `WCSS_MIGRATION_DISABLED`.

- **A, the guard against 0.5.1** (`out-run4-passA/`): the new zip installed and activated beside 0.5.1; an admin request completes without a redeclaration fatal, 0.5.1 is the plugin that booted, the new file stopped at its guard and registered no hook, the paused notice carries the nonce-protected deactivate link.
- **E, `DISALLOW_FILE_MODS`**: precondition message, exactly one log row (`file_mods_disallowed`), no install attempted, notice shows it.
- **D, missing package**: failure logged with code `install_failed`, error transient and notice with Retry and the manual path, lock left as backoff, old plugin still active, nothing installed.
- **B, the real path**: one administrator page load after the update. New plugin installed and active, old inactive, settings byte-identical, secrets option untouched and decrypting through the new plugin's getters, REST routes registered, a signed `/host/ping` returns 200 and an unsigned one 403, cron scheduled, the four log lines present exactly once with trigger `admin_init`, no error or lock transient, success notice in the migrating request and the one-time notice on the next.
- **C, idempotent**: the shim re-activated beside the migrated plugin logs `already migrated`, deactivates itself, installs nothing.
- **F, cron trigger**: with the new plugin installed but inactive and the shim active, `hashy_au_daily_reconcile` fired under `DOING_CRON` with no user activates it and steps aside; trigger recorded as `cron`; the data and REST checks of B repeat.
- **G, the guard against the shim**: `WCSS_MIGRATION_DISABLED` true, both active; the new plugin pauses (no constants, no classes, no routes), the paused notice shows, the shim writes nothing.

Why no pass deletes the new plugin's folder: `wp plugin delete` and the Plugins-screen Delete run `uninstall.php` (which removes every shared option), and a plain recursive unlink from PHP left a delete-pending ghost of `hashy-stock-sync.php` on Playground's Windows-backed filesystem that made every later pass fail with "The plugin does not have a valid header" (runs 3 and 4). The rehearsal therefore installs once and exercises the install-failure paths before that install.

Results are in `out/summary.txt`; the review's progress log records the counts. Also run against the renamed tree: the two `tests/` suites and the standard smoke test (`bash tooling/playground/run.sh hashy-stock-sync "<stage>"`).

## WordPress.org submission (later)

1. `Contributors:` in `readme.txt` must be the WordPress.org username that will own the listing (it is still `hashy-au`).
2. Build with `-Directory`; the script refuses to produce the zip if any `PucFactory`, `YahnisElsts` or `plugin-update-checker` string remains in the staged copy. Run Plugin Check on that zip: the `update_checker`/`updater` items and the `trademarked_term` items are gone by construction; fix anything new.
3. Submit `hashy-stock-sync-directory.zip`. The slug is assigned from this first submission and cannot change, so it must be `hashy-stock-sync`.
4. Once the directory listing is live and serving updates, publish one last GitHub edition whose only change is removing `includes/github-updates.php` and the library, so the fleet stops polling GitHub; from then on the directory delivers updates. The token field disappears with the updater file.
