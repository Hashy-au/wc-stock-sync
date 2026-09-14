<?php
/**
 * The component consumption ledger (Host mode): one row per sold line per
 * recipe row, saying which component variation an arrow line consumed, how
 * much, how it matched, and whether stock actually moved (design/26,
 * D-36.1, D-36.9).
 *
 * A table, not an option: rows accumulate one per sale line per component,
 * the Solkarra Desktop App reads them by cursor, and an option blob rewritten
 * on every sale is the flood shape the log ring already worries about.
 *
 * Trail rule, the same one the desktop app's stock_writes follows: a row is
 * inserted with applied = 0 BEFORE the stock call and marked applied AFTER,
 * so a fatal between the two leaves a visible unapplied row rather than a
 * silent gap.
 *
 * @package Hashy_AU
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Hashy_AU_Ledger {

	public const DB_VERSION_OPTION = 'hashy_au_db_version';

	/** Rows that resolved and, when live, moved stock. */
	public const RESOLVED = array( 'match', 'default', 'value_map' );

	/**
	 * Table name with prefix.
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'hashy_component_ledger';
	}

	/**
	 * Run dbDelta once per plugin version. Re-checked on init because
	 * in-place updates never fire the activation hook.
	 *
	 * @return void
	 */
	public static function maybe_install(): void {
		if ( WC_STOCK_SYNC_VERSION !== (string) get_option( self::DB_VERSION_OPTION, '' ) ) {
			self::install();
		}
	}

	/**
	 * Create or update the table. Safe to call repeatedly.
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$charset = $wpdb->get_charset_collate();
		$table   = self::table();

		// dbDelta is particular about this layout: one field per line, two
		// spaces before the key column list, no backticks.
		$sql = "CREATE TABLE {$table} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	created_at datetime NOT NULL,
	site varchar(190) NOT NULL DEFAULT '',
	order_id bigint(20) unsigned NOT NULL DEFAULT 0,
	line_id varchar(64) NOT NULL DEFAULT '',
	line_sku varchar(120) NOT NULL DEFAULT '',
	line_qty int(11) NOT NULL DEFAULT 0,
	arrow_product_id bigint(20) unsigned NOT NULL DEFAULT 0,
	arrow_sku varchar(120) NOT NULL DEFAULT '',
	recipe_id varchar(64) NOT NULL DEFAULT '',
	component_product_id bigint(20) unsigned NOT NULL DEFAULT 0,
	component_sku varchar(120) NOT NULL DEFAULT '',
	qty_per int(11) NOT NULL DEFAULT 0,
	qty int(11) NOT NULL DEFAULT 0,
	matched varchar(16) NOT NULL DEFAULT 'unresolved',
	reason varchar(24) NOT NULL DEFAULT '',
	wanted text NULL,
	mode varchar(8) NOT NULL DEFAULT 'shadow',
	applied tinyint(1) NOT NULL DEFAULT 0,
	reversed_qty int(11) NOT NULL DEFAULT 0,
	reversed_at datetime NULL,
	acknowledged_at datetime NULL,
	recipe_hash char(64) NOT NULL DEFAULT '',
	PRIMARY KEY  (id),
	KEY site_order (site(100), order_id, line_id),
	KEY component (component_product_id),
	KEY created (created_at),
	KEY matched (matched),
	UNIQUE KEY dedupe (site(100), order_id, line_id, recipe_id)
) {$charset};";
		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, WC_STOCK_SYNC_VERSION, false );
	}

	/**
	 * Whether the table exists (a fresh install before init has run once).
	 *
	 * @return bool
	 */
	public static function table_exists(): bool {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/**
	 * Insert one row (applied = 0). Returns the id, or 0 when the dedupe key
	 * already holds a row for this site/order/line/recipe.
	 *
	 * @param array $row Column => value; `wanted` may be an array.
	 * @return int
	 */
	public static function insert( array $row ): int {
		global $wpdb;

		$existing = self::find( (string) $row['site'], (int) $row['order_id'], (string) $row['line_id'], (string) $row['recipe_id'] );
		if ( null !== $existing ) {
			return 0;
		}

		$data = array(
			'created_at'           => (string) ( $row['created_at'] ?? current_time( 'mysql', true ) ),
			'site'                 => mb_substr( (string) $row['site'], 0, 190 ),
			'order_id'             => (int) $row['order_id'],
			'line_id'              => mb_substr( (string) $row['line_id'], 0, 64 ),
			'line_sku'             => mb_substr( (string) ( $row['line_sku'] ?? '' ), 0, 120 ),
			'line_qty'             => (int) ( $row['line_qty'] ?? 0 ),
			'arrow_product_id'     => (int) ( $row['arrow_product_id'] ?? 0 ),
			'arrow_sku'            => mb_substr( (string) ( $row['arrow_sku'] ?? '' ), 0, 120 ),
			'recipe_id'            => mb_substr( (string) $row['recipe_id'], 0, 64 ),
			'component_product_id' => (int) ( $row['component_product_id'] ?? 0 ),
			'component_sku'        => mb_substr( (string) ( $row['component_sku'] ?? '' ), 0, 120 ),
			'qty_per'              => (int) ( $row['qty_per'] ?? 0 ),
			'qty'                  => (int) ( $row['qty'] ?? 0 ),
			'matched'              => mb_substr( (string) ( $row['matched'] ?? 'unresolved' ), 0, 16 ),
			'reason'               => mb_substr( (string) ( $row['reason'] ?? '' ), 0, 24 ),
			'wanted'               => wp_json_encode( is_array( $row['wanted'] ?? null ) ? (object) $row['wanted'] : new stdClass() ),
			'mode'                 => mb_substr( (string) ( $row['mode'] ?? 'shadow' ), 0, 8 ),
			'applied'              => 0,
			'reversed_qty'         => 0,
			'recipe_hash'          => mb_substr( (string) ( $row['recipe_hash'] ?? '' ), 0, 64 ),
		);
		$formats = array( '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ok = $wpdb->insert( self::table(), $data, $formats );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * The row for one site/order/line/recipe, or null.
	 *
	 * @param string $site      Site URL.
	 * @param int    $order_id  Order id on that site.
	 * @param string $line_id   Line id on that site.
	 * @param string $recipe_id Recipe row id.
	 * @return array|null
	 */
	public static function find( string $site, int $order_id, string $line_id, string $recipe_id ): ?array {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE site = %s AND order_id = %d AND line_id = %s AND recipe_id = %s LIMIT 1", $site, $order_id, $line_id, $recipe_id ), ARRAY_A );
		return is_array( $row ) ? self::decode( $row ) : null;
	}

	/**
	 * Mark a row applied (stock moved).
	 *
	 * @param int $id Row id.
	 * @return void
	 */
	public static function mark_applied( int $id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update( self::table(), array( 'applied' => 1 ), array( 'id' => $id ), array( '%d' ), array( '%d' ) );
	}

	/**
	 * Rows for one sold line.
	 *
	 * @param string $site     Site URL.
	 * @param int    $order_id Order id.
	 * @param string $line_id  Line id.
	 * @return array<int, array>
	 */
	public static function rows_for_line( string $site, int $order_id, string $line_id ): array {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE site = %s AND order_id = %d AND line_id = %s ORDER BY id ASC", $site, $order_id, $line_id ), ARRAY_A );
		return array_map( array( __CLASS__, 'decode' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Rows for one order and one arrow product (the hub refund path, which
	 * names the product but not the line).
	 *
	 * @param string $site             Site URL.
	 * @param int    $order_id         Order id.
	 * @param int    $arrow_product_id Arrow product id.
	 * @return array<int, array>
	 */
	public static function rows_for_order_product( string $site, int $order_id, int $arrow_product_id ): array {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE site = %s AND order_id = %d AND arrow_product_id = %d ORDER BY id ASC", $site, $order_id, $arrow_product_id ), ARRAY_A );
		return array_map( array( __CLASS__, 'decode' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Record a reversal on a row (component units).
	 *
	 * @param int $id    Row id.
	 * @param int $units Component units reversed now.
	 * @return void
	 */
	public static function add_reversed( int $id, int $units ): void {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET reversed_qty = reversed_qty + %d, reversed_at = %s WHERE id = %d", $units, current_time( 'mysql', true ), $id ) );
	}

	/**
	 * The reversal plan for a set of rows when `restored_units` of the
	 * assembled product come back (a cancel or a refund).
	 *
	 * Pure and static so tests/test-ledger-reverse.php can run it. Per row:
	 * the component units still outstanding are `qty - reversed_qty`; the
	 * units to give back now are the smaller of that and
	 * `restored_units * qty_per`. A row moves stock only when it was applied
	 * and live and resolved; a shadow row is still marked reversed so
	 * "would have moved" nets to the right figure.
	 *
	 * @param array<int, array> $rows           Ledger rows for one line.
	 * @param int               $restored_units Assembled units restored.
	 * @return array<int, array{id: int, units: int, moves: bool, component_product_id: int, component_sku: string}>
	 */
	public static function reverse_plan( array $rows, int $restored_units ): array {
		$plan = array();
		if ( $restored_units <= 0 ) {
			return $plan;
		}
		foreach ( $rows as $row ) {
			$qty       = (int) ( $row['qty'] ?? 0 );
			$reversed  = (int) ( $row['reversed_qty'] ?? 0 );
			$remaining = max( 0, $qty - $reversed );
			$per       = max( 1, (int) ( $row['qty_per'] ?? 1 ) );
			$units     = min( $remaining, $restored_units * $per );
			if ( $units <= 0 ) {
				continue;
			}
			$resolved = in_array( (string) ( $row['matched'] ?? '' ), self::RESOLVED, true );
			$plan[]   = array(
				'id'                   => (int) ( $row['id'] ?? 0 ),
				'units'                => $units,
				'moves'                => ! empty( $row['applied'] ) && 'live' === (string) ( $row['mode'] ?? '' ) && $resolved,
				'component_product_id' => (int) ( $row['component_product_id'] ?? 0 ),
				'component_sku'        => (string) ( $row['component_sku'] ?? '' ),
			);
		}
		return $plan;
	}

	/**
	 * Set acknowledged_at on an unresolved row.
	 *
	 * @param int $id Row id.
	 * @return bool
	 */
	public static function acknowledge( int $id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$n = $wpdb->update( self::table(), array( 'acknowledged_at' => current_time( 'mysql', true ) ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
		return false !== $n && $n > 0;
	}

	/**
	 * Rows after a cursor, oldest first, for the desktop app's pull.
	 *
	 * @param int $since_id Last id seen.
	 * @param int $limit    1..500.
	 * @return array{rows: array<int, array>, next_id: int, has_more: bool}
	 */
	public static function since( int $since_id, int $limit ): array {
		global $wpdb;
		$limit = max( 1, min( 500, $limit ) );
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT %d", $since_id, $limit + 1 ), ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();
		$more = count( $rows ) > $limit;
		if ( $more ) {
			array_pop( $rows );
		}
		$rows = array_map( array( __CLASS__, 'decode' ), $rows );
		$next = empty( $rows ) ? $since_id : (int) end( $rows )['id'];
		return array(
			'rows'     => $rows,
			'next_id'  => $next,
			'has_more' => $more,
		);
	}

	/**
	 * Newest rows for the admin page.
	 *
	 * @param int  $limit           How many.
	 * @param bool $unresolved_only Only rows that could not resolve.
	 * @return array<int, array>
	 */
	public static function recent( int $limit, bool $unresolved_only ): array {
		global $wpdb;
		$limit = max( 1, min( 500, $limit ) );
		$table = self::table();
		if ( $unresolved_only ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE matched IN ('unresolved','ambiguous') ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A );
		}
		return array_map( array( __CLASS__, 'decode' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Unresolved rows nobody has acknowledged.
	 *
	 * @return int
	 */
	public static function count_unresolved(): int {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE matched IN ('unresolved','ambiguous') AND acknowledged_at IS NULL" );
	}

	/**
	 * Daily pruning: applied or reversed rows older than 400 days, shadow
	 * rows older than 120, unresolved rows only once acknowledged and older
	 * than 120. Returns rows removed.
	 *
	 * @return int
	 */
	public static function prune(): int {
		global $wpdb;
		$table = self::table();
		$old   = gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS );
		$mid   = gmdate( 'Y-m-d H:i:s', time() - 120 * DAY_IN_SECONDS );
		$n     = 0;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s AND (applied = 1 OR reversed_at IS NOT NULL)", $old ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s AND mode = 'shadow' AND matched IN ('match','default','value_map')", $mid ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s AND matched IN ('unresolved','ambiguous') AND acknowledged_at IS NOT NULL", $mid ) );
		return $n;
	}

	/**
	 * Typed row with `wanted` decoded.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	private static function decode( array $row ): array {
		$wanted = json_decode( (string) ( $row['wanted'] ?? '' ), true );
		foreach ( array( 'id', 'order_id', 'line_qty', 'arrow_product_id', 'component_product_id', 'qty_per', 'qty', 'applied', 'reversed_qty' ) as $k ) {
			$row[ $k ] = (int) ( $row[ $k ] ?? 0 );
		}
		$row['wanted'] = is_array( $wanted ) ? $wanted : array();
		return $row;
	}
}
