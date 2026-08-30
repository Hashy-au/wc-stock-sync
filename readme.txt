=== WC Stock Sync ===
Contributors: hashy-au
Tags: woocommerce, inventory, stock, sync, stocktake
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Host + Agent WooCommerce stock/price sync. Keep stock centralized on one primary store and mirrored to any number of storefronts.

== Description ==

WC Stock Sync runs in one of two modes:

* **Host (primary)** — the stock hub. Receives paid-order notifications from Agents, decrements matching SKUs, and pushes stock/status/price updates out to every Agent.
* **Agent (secondary)** — a storefront. Notifies the Host when an order is paid and applies stock updates pushed from the Host.

Features:

* Automatic propagation of stock **quantity** changes and stock **status-only** changes (e.g. a non-stock-managed product toggled out of stock). Any code path that uses the WooCommerce stock APIs — admin edits, the wc/v3 REST API, order reductions, `wc_update_product_stock()` — is picked up.
* Signed REST messaging (HMAC SHA256 over `<timestamp>.<raw_body>`, ±300s skew window, per-product replay guard on Agents).
* Retry queues on both sides: the Agent outbox re-signs and retries failed order notifications; the Host retries failed pushes hourly (max 12 attempts / 24h) via the existing agent config — secrets are never persisted into queue rows.
* SKU normalization for matching prefixed SKUs like `PRM-XXXX` / `ABA-XXXX` across stores (any leading 3-character alphanumeric prefix plus separator is stripped, all separators removed, uppercased), with per-agent mapping overrides and a CSV import/export workflow for maintaining them.
* **Stocktake (xlsx)** on the Host: download a spreadsheet (Name | SKU | Stock | New Stock | Product ID), count stock into the New Stock column offline, upload it back, review the previewed changes, and batch-apply. Blank cells are skipped (partial stocktakes are safe); a typed 0 sets stock to zero. Applied changes push to all Agents automatically via a background-drained queue.
* Manual batch stock/price sync per Agent, filtered to SKUs the Agent actually has.
* Missing-SKU reports on both sides and an in-admin log viewer.
* Automatic plugin updates from the GitHub repository (see below).

== Installation ==

1. Upload the plugin zip via **Plugins → Add New → Upload Plugin** and activate it.
2. Go to the top-level **WC Stock Sync** admin menu.
3. Set **Mode**: Host on the primary store, Agent on each secondary store.
4. Configure the shared secret:
   * On the Host, add each Agent row (name, URL) and generate a secret per Agent.
   * On each Agent, set the Host URL and paste that same secret. One secret per Agent is used in both directions.
5. Use **Test Host** / **Test Agent** buttons to verify connectivity.

== Automatic updates ==

The plugin updates itself from GitHub Releases of the public repo (https://github.com/Hashy-au/wc-stock-sync) — no configuration needed on the sites. The **GitHub Update Token** setting is optional: supply a fine-grained token (this repo only, **Contents: Read-only**) to raise the GitHub API rate limit, or if the repo is ever made private again (`WCSS_GITHUB_TOKEN` in `wp-config.php` also works). Release zips are built with `scripts/build-release.ps1` and attached to a `vX.Y.Z` release as `wc-stock-sync.zip`.

== REST endpoints ==

All endpoints are POST, signed with `X-Hashy-Timestamp` / `X-Hashy-Signature` headers, and return a uniform 403 on authentication failure.

Host side:

* `/wp-json/hashy-sync/v1/agent/order-paid` — Agent reports a paid order; Host decrements stock and fans updates out to all Agents. Deduplicated by order ID per Agent.
* `/wp-json/hashy-sync/v1/host/ping` — connectivity test.

Agent side:

* `/wp-json/hashy-sync/v1/host/ping` — connectivity test.
* `/wp-json/hashy-sync/v1/host/stock-update` — Host pushes stock/status/price for one SKU. Stale/replayed payloads (by `ts`) are acknowledged but not applied.
* `/wp-json/hashy-sync/v1/host/sku-index` — normalized SKU set, used to filter batch syncs.
* `/wp-json/hashy-sync/v1/host/sku-index-detailed` — SKU list with names, used by the Import/Export mapping tools.

== Frequently Asked Questions ==

= Does it support variations? =
Yes, as long as each variation has its own SKU. Variations without their own SKU are never pushed (WooCommerce would report the parent's SKU and the update would land on the wrong product).

= When does stock decrement happen? =
On paid events (payment complete / processing / completed). One send per order, deduplicated on both the Agent and the Host.

= What triggers a push from the Host? =
Stock quantity changes, stock status changes, and paid-order decrements. Price changes propagate via the manual per-agent batch sync (with the per-agent Price % multiplier), not automatically.

= What does Price % mean? =
A multiplier: 125 sends prices at +25%, 90 at −10%, 0 or empty leaves prices unchanged.

== Changelog ==

= 0.5.0 =

Fixed:

* Activation hooks never ran (registered too late), so the reconcile and retry cron jobs were never scheduled. Schedules now self-heal on load.
* The Agent retry outbox replayed stale HMAC signatures, so every retry failed; it now re-signs at send time.
* The Host failed-push queue never dropped delivered rows and re-sent them hourly for 24h.
* Non-stock-managed Host products forced Agents out of stock (null quantity was sent as 0).
* Inbound paid orders pushed every touched product to every Agent twice.
* `sku-index-detailed` endpoint was registered but not implemented (500), leaving the synced-SKU export silently empty.
* Export Local SKUs fataled on the first data row.
* The Missing SKUs page read a key the Host never wrote; Host-side reports were invisible.
* Normalized-SKU lookups scanned an arbitrary window of 50–200 products; now a cached full-catalogue map (deterministic, lowest ID wins on collision).
* Lowercase Agent Codes were erased by the sanitizer.
* Variations without their own SKU no longer push the parent's SKU.
* A mid-request failure while applying an order can no longer double-decrement on retry.

Security:

* Signature verification now happens before any logging or parsing side effects; authentication failures return a uniform 403 (no agent-URL enumeration) and log at most once per 5 minutes.
* CSRF nonces on all CSV export actions; spreadsheet formula injection neutralized in all exports; quoted download filenames.
* Shared secrets are no longer persisted into retry-queue rows, and secret fields are password inputs with show/hide.
* Test Agent requires https and rejects unsafe/internal URLs; remote response bodies are truncated in logs.
* Replay guard on Agent stock updates (per-product monotonic timestamp).
* HPOS (custom order tables) compatibility declared.

Added:

* Stocktake (xlsx) round-trip on the Host: export, count offline, upload, preview, batch-apply with automatic pushes to all Agents.
* Automatic updates from the GitHub repo (tokenless; optional token supported).
* Uninstall now removes all plugin options, transients, and cron events.

= 0.1.0 =
* Initial release: Host+Agent, signed REST endpoints, stock/status/price push, CSV exports, basic logs.
