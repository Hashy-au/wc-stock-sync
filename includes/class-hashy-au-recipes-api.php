<?php
/**
 * The in-process surface the solkarra-ai connector calls for recipes and
 * the component ledger (design/26, connector work order 17).
 *
 * Not a REST route: the connector runs on the same site and calls this
 * class directly, exactly as it calls \SolkarraBundles\API. Loaded in every
 * mode so the connector's class_exists() check can answer; available()
 * says whether this site is the host and holds the table.
 *
 * @package Hashy_AU
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Hashy_AU_Recipes_API {

	private const WKEY_PREFIX = 'hashy_recipes_wkey_';

	/**
	 * Whether recipes can be read and written here.
	 *
	 * @return bool
	 */
	public static function available(): bool {
		return class_exists( 'Hashy_AU_Settings' )
			&& 'host' === Hashy_AU_Settings::instance()->get_mode()
			&& class_exists( 'Hashy_AU_Ledger' )
			&& Hashy_AU_Ledger::table_exists();
	}

	/**
	 * Plugin version.
	 *
	 * @return string
	 */
	public static function version(): string {
		return defined( 'WC_STOCK_SYNC_VERSION' ) ? (string) WC_STOCK_SYNC_VERSION : '';
	}

	/**
	 * The stored set as the app reads it.
	 *
	 * @return array{as_of: string, plugin_version: string, hash: string, updated_at: string, updated_by: int, source: string, recipes: array, aliases: array}
	 */
	public static function recipes(): array {
		$set = Hashy_AU_Recipes::instance()->get_set();
		return array(
			'as_of'          => gmdate( 'c' ),
			'plugin_version' => self::version(),
			'hash'           => (string) $set['hash'],
			'updated_at'     => (string) $set['updated_at'],
			'updated_by'     => (int) $set['updated_by'],
			'source'         => (string) $set['source'],
			'recipes'        => array_values( $set['recipes'] ),
			'aliases'        => (object) $set['aliases'],
		);
	}

	/**
	 * Replace the set. Idempotent by key for a day; refuses a stale
	 * expected hash so a push built on an old read never overwrites a newer
	 * set.
	 *
	 * @param array       $set             `{recipes, aliases}`.
	 * @param string      $idempotency_key Caller's key.
	 * @param int         $user_id         Who.
	 * @param string      $source          connector|admin|test.
	 * @param string|null $expected_hash   The hash the caller last read, or null to skip the check.
	 * @return array|WP_Error `{applied, replayed, hash, recipes, recompute}` or WP_Error validation|cycle|stale_hash.
	 */
	public static function write_recipes( array $set, string $idempotency_key, int $user_id, string $source, ?string $expected_hash ) {
		$idempotency_key = trim( $idempotency_key );
		if ( '' !== $idempotency_key ) {
			$replay = get_transient( self::WKEY_PREFIX . md5( $idempotency_key ) );
			if ( is_array( $replay ) ) {
				$replay['replayed'] = true;
				return $replay;
			}
		}

		$current = Hashy_AU_Recipes::instance()->stored_hash();
		if ( null !== $expected_hash && '' !== $current && $expected_hash !== $current ) {
			return new WP_Error(
				'stale_hash',
				'The recipe set changed on the hub since it was read.',
				array(
					'expected' => $expected_hash,
					'current'  => $current,
				)
			);
		}

		$stored = Hashy_AU_Recipes::instance()->set_set( $set, $user_id, $source );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		$result = array(
			'applied'   => true,
			'replayed'  => false,
			'hash'      => (string) $stored['hash'],
			'recipes'   => count( $stored['recipes'] ),
			'recompute' => $stored['recompute'],
		);
		if ( '' !== $idempotency_key ) {
			set_transient( self::WKEY_PREFIX . md5( $idempotency_key ), $result, DAY_IN_SECONDS );
		}
		return $result;
	}

	/**
	 * Ledger rows after a cursor.
	 *
	 * @param int $since_id Last id the caller holds.
	 * @param int $limit    1..500.
	 * @return array{as_of: string, plugin_version: string, rows: array, next_id: int, has_more: bool}
	 */
	public static function ledger_since( int $since_id, int $limit = 500 ): array {
		$page = Hashy_AU_Ledger::since( max( 0, $since_id ), $limit );
		return array(
			'as_of'          => gmdate( 'c' ),
			'plugin_version' => self::version(),
			'rows'           => $page['rows'],
			'next_id'        => $page['next_id'],
			'has_more'       => $page['has_more'],
		);
	}

	/**
	 * Acknowledge an unresolved row.
	 *
	 * @param int $ledger_id Row id.
	 * @param int $user_id   Who (logged).
	 * @return bool
	 */
	public static function acknowledge( int $ledger_id, int $user_id ): bool {
		$ok = Hashy_AU_Ledger::acknowledge( $ledger_id );
		if ( $ok ) {
			Hashy_AU_Logger::instance()->info(
				'Component ledger row acknowledged',
				array(
					'ledger_id' => $ledger_id,
					'user'      => $user_id,
				)
			);
		}
		return $ok;
	}
}
