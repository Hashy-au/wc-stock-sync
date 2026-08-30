=== WC Stock Sync ===
Contributors: hashy-au
Tags: woocommerce, inventory, stock, sync
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.4.12
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Host + Agent WooCommerce stock/price sync. Connect multiple stores to keep stock centralized on one primary store.

== Description ==

Hashy-au provides a *Host (primary)* mode and an *Agent (secondary)* mode:

* Agent notifies the Host when an order is paid.
* Host decrements stock for matching SKUs (including variations).
* Host pushes stock/status/pricing updates to all Agents.
* SKU normalization helps match prefixed SKUs like PRM-XXXX, ABA-XXXX, etc.
* CSV export tools:
  * Export SKU list (Product Name | Variation Name | SKU)
  * Export mappings (normalized_sku | host_sku)

V0.1 focuses on the core “paid order -> stock reduce -> push updates” flow.

== Installation ==

1. Upload the plugin zip via **Plugins → Add New → Upload Plugin**.
2. Activate **Hashy-au**.
3. Go to **WooCommerce → Hashy-au Sync**.
4. Set **Mode**:
   * Host on the primary store
   * Agent on each secondary store
5. Configure secrets/URLs:
   * Host: add each Agent URL + its shared secret.
   * Agent: set Host URL + shared secret (used to sign outbound requests) + Host secret (used to verify inbound updates).

== How it works (V0.1) ==

Agent → Host:
* Endpoint: /wp-json/hashy-sync/v1/agent/order-paid
* Trigger: payment complete / processing / completed
* Signed with HMAC SHA256 over: "<timestamp>.<raw_body>"

Host → Agent:
* Endpoint: /wp-json/hashy-sync/v1/host/stock-update
* Trigger: stock/price/status change
* Signed with HMAC SHA256

== Security ==

* Each Agent has its own shared secret configured on Host.
* Agent verifies Host using a “Host Secret” configured on the Agent.

== CSV Import (planned) ==

V0.1 includes export tooling only.
V0.2 will add:
* CSV import for mapping table
* Admin UI for mapping suggestions + missing SKU reports

== Frequently Asked Questions ==

= Does it support variations? =
Yes, as long as variations have SKUs.

= When does stock decrement happen? =
On “paid” events (payment complete / processing / completed). The plugin deduplicates by order id per Agent.

== Changelog ==

= 0.1.0 =
* Initial release: Host+Agent, signed REST endpoints, stock/status/price push, CSV exports, basic logs.

