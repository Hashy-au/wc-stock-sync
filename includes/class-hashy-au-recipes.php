<?php
/**
 * Component recipes on the Host: arrows consume shafts and tips the moment
 * a sale lands anywhere in the fleet, and an arrow's own figure is derived
 * from its shafts (design/26 in the Solkarra Desktop App, D-36.1, D-36.2).
 *
 * The desktop app authors the recipe set and pushes it through the
 * solkarra-ai connector (Hashy_AU_Recipes_API); this class only stores it
 * and does the arithmetic at sale time. Nothing here is authored in
 * wp-admin.
 *
 * Three places a sale reaches this class:
 *   - an agent's order-paid, inside Hashy_AU_Host::rest_agent_order_paid
 *     (pushes suppressed there; touched ids are drained by that loop);
 *   - the hub's own checkout, through woocommerce_reduce_order_item_stock;
 *   - a restore (cancel or refund), from the agent endpoint or the hub's
 *     own restock hooks.
 *
 * And one place stock reaches it: every woocommerce_*_set_stock on the hub,
 * at priority 20 (the host pushes at 10), which recomputes the derived
 * figure of every arrow depending on the product that moved. That runs
 * whether or not pushes are suppressed, because the stocktake suppresses
 * them and the connector's adjust-stock does not, and both must recompute.
 *
 * @package Hashy_AU
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Hashy_AU_Recipes {

	public const OPTION                  = 'hashy_au_recipes';
	public const RECOMPUTE_QUEUE_OPTION  = 'hashy_au_recompute_queue';
	public const RECOMPUTE_CRON          = 'hashy_au_recompute_derived';
	public const INDEX_TRANSIENT_PREFIX  = 'hashy_recipe_index_';
	public const LEDGER_ITEM_META        = '_hashy_recipe_ledger';
	private const RECOMPUTE_BUDGET       = 20; // seconds.
	private const MAX_LINE_ATTRS         = 20;
	private const MAX_LINE_ATTR_LEN      = 120;

	private static $instance = null;

	/** Re-entrancy guard around the derived set_stock calls. */
	private static bool $recomputing = false;

	/**
	 * Product ids this request changed while pushes were suppressed; the
	 * caller that suppressed them drains and pushes.
	 *
	 * @var array<int, bool>
	 */
	private static array $touched = array();

	/**
	 * Per-request catalogue cache: parent SKU => rows.
	 *
	 * @var array<string, array<int, array>>
	 */
	private static array $catalogue_cache = array();

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init(): void {
		// The hub's own checkout, per line, after Woo's own decrement.
		add_action( 'woocommerce_reduce_order_item_stock', array( $this, 'on_hub_line_reduced' ), 10, 3 );
		add_action( 'woocommerce_reduce_order_stock', array( $this, 'on_hub_order_reduced' ), 20, 1 );

		// The hub's own restores.
		add_action( 'woocommerce_restore_order_item_stock', array( $this, 'on_hub_item_restored' ), 10, 4 );
		add_action( 'woocommerce_restock_refunded_item', array( $this, 'on_hub_refund_restocked' ), 10, 5 );

		// Derived figures follow their components.
		add_action( 'woocommerce_product_set_stock', array( $this, 'on_stock_changed' ), 20, 1 );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'on_stock_changed' ), 20, 1 );

		add_action( 'woocommerce_update_product', array( $this, 'invalidate_index' ) );
		add_action( 'woocommerce_delete_product', array( $this, 'invalidate_index' ) );
		add_action( self::RECOMPUTE_CRON, array( $this, 'cron_recompute' ) );
		add_action( 'hashy_au_daily_reconcile', array( $this, 'daily' ) );
	}

	/* ------------------------------------------------------------ the set */

	/**
	 * The stored set, or an empty one carrying the default aliases.
	 *
	 * @return array{schema: int, hash: string, updated_at: string, updated_by: int, source: string, recipes: array, aliases: array}
	 */
	public function get_set(): array {
		$raw = get_option( self::OPTION, null );
		if ( ! is_array( $raw ) || ! isset( $raw['recipes'] ) ) {
			return array(
				'schema'     => 1,
				'hash'       => '',
				'updated_at' => '',
				'updated_by' => 0,
				'source'     => '',
				'recipes'    => array(),
				'aliases'    => Hashy_AU_Recipe_Resolver::DEFAULT_ALIASES,
			);
		}
		$raw['recipes'] = is_array( $raw['recipes'] ) ? $raw['recipes'] : array();
		$raw['aliases'] = ! empty( $raw['aliases'] ) && is_array( $raw['aliases'] ) ? $raw['aliases'] : Hashy_AU_Recipe_Resolver::DEFAULT_ALIASES;
		return $raw;
	}

	/**
	 * The hash of the stored set ('' when none).
	 *
	 * @return string
	 */
	public function stored_hash(): string {
		return (string) ( $this->get_set()['hash'] ?? '' );
	}

	/**
	 * Replace the set. Validates, stores it normalised, drops the index,
	 * then recomputes every derived figure.
	 *
	 * @param array  $set     `{recipes, aliases}`.
	 * @param int    $user_id Who.
	 * @param string $source  connector|admin|test.
	 * @return array|WP_Error The stored meta, or WP_Error `validation` / `cycle`.
	 */
	public function set_set( array $set, int $user_id, string $source ) {
		$errors = Hashy_AU_Recipe_Resolver::validate( $set );
		if ( ! empty( $errors ) ) {
			$codes = array_unique( array_column( $errors, 'code' ) );
			return new WP_Error( in_array( 'cycle', $codes, true ) ? 'cycle' : 'validation', 'The recipe set did not validate.', array( 'errors' => $errors ) );
		}
		$normalised = Hashy_AU_Recipe_Resolver::normalise_set( $set );
		$hash       = Hashy_AU_Recipe_Resolver::hash( $set );
		$stored     = array(
			'schema'     => 1,
			'hash'       => $hash,
			'updated_at' => current_time( 'mysql', true ),
			'updated_by' => $user_id,
			'source'     => $source,
			'recipes'    => $normalised['recipes'],
			'aliases'    => ! empty( $normalised['aliases'] ) ? $normalised['aliases'] : Hashy_AU_Recipe_Resolver::DEFAULT_ALIASES,
		);
		update_option( self::OPTION, $stored, false );
		$this->invalidate_index();

		Hashy_AU_Logger::instance()->info(
			'Recipe set stored',
			array(
				'hash'    => $hash,
				'recipes' => count( $stored['recipes'] ),
				'source'  => $source,
				'user'    => $user_id,
			)
		);

		$stored['recompute'] = $this->recompute_all();
		return $stored;
	}

	/* ------------------------------------------------------------ index */

	/**
	 * The recipe index for the stored set, cached by hash:
	 * `by_assembled` (assembled parent SKU => rows) and `by_component`
	 * (component parent SKU => rows that could consume it, deriving or not).
	 *
	 * @return array{hash: string, by_assembled: array<string, array>, by_component: array<string, array>}
	 */
	public function index(): array {
		$set  = $this->get_set();
		$hash = (string) $set['hash'];
		if ( '' === $hash ) {
			return array(
				'hash'         => '',
				'by_assembled' => array(),
				'by_component' => array(),
			);
		}
		$key    = self::INDEX_TRANSIENT_PREFIX . $hash;
		$cached = get_transient( $key );
		if ( is_array( $cached ) && isset( $cached['by_assembled'] ) ) {
			return $cached;
		}
		$by_assembled = array();
		$by_component = array();
		foreach ( $set['recipes'] as $row ) {
			$row                                     = Hashy_AU_Recipe_Resolver::normalise_row( $row );
			$by_assembled[ $row['assembled_sku'] ][] = $row;
			$parents                                 = array();
			if ( null !== $row['component_sku'] ) {
				$parents[] = $row['component_sku'];
			}
			if ( is_array( $row['value_map'] ) ) {
				$parents = array_merge( $parents, array_values( $row['value_map']['map'] ) );
				if ( null !== $row['value_map']['default_parent'] ) {
					$parents[] = $row['value_map']['default_parent'];
				}
			}
			foreach ( array_unique( $parents ) as $p ) {
				$by_component[ $p ][] = $row;
			}
		}
		$index = array(
			'hash'         => $hash,
			'by_assembled' => $by_assembled,
			'by_component' => $by_component,
		);
		set_transient( $key, $index, 6 * HOUR_IN_SECONDS );
		return $index;
	}

	public function invalidate_index(): void {
		$hash = $this->stored_hash();
		if ( '' !== $hash ) {
			delete_transient( self::INDEX_TRANSIENT_PREFIX . $hash );
		}
		self::$catalogue_cache = array();
	}

	/* -------------------------------------------------------- catalogue */

	/**
	 * A product as the resolver sees it.
	 *
	 * @param WC_Product $product Product or variation.
	 * @return array{sku: string, kind: string, parent_sku: ?string, attrs: array<string, string>, product_id: int}
	 */
	public static function row_for_product( WC_Product $product ): array {
		$kind   = 'simple';
		$parent = null;
		if ( $product->is_type( 'variation' ) ) {
			$kind = 'variation';
			$pp   = $product->get_parent_id() ? wc_get_product( $product->get_parent_id() ) : null;
			if ( $pp instanceof WC_Product ) {
				$parent = strtoupper( trim( (string) $pp->get_sku( 'edit' ) ) );
			}
		} elseif ( $product->is_type( 'variable' ) ) {
			$kind = 'variable';
		}
		$attrs = array();
		if ( $product instanceof WC_Product_Variation ) {
			foreach ( (array) $product->get_attributes() as $k => $v ) {
				$attrs[ (string) $k ] = is_scalar( $v ) ? (string) $v : '';
			}
		}
		return array(
			'sku'        => strtoupper( trim( (string) $product->get_sku( 'edit' ) ) ),
			'kind'       => $kind,
			'parent_sku' => '' === (string) $parent ? null : $parent,
			'attrs'      => $attrs,
			'product_id' => (int) $product->get_id(),
		);
	}

	/**
	 * The hub product carrying a SKU: exact first, then the normalised map.
	 *
	 * @param string $sku SKU.
	 * @return WC_Product|null
	 */
	public static function product_for_sku( string $sku ): ?WC_Product {
		$sku = trim( $sku );
		if ( '' === $sku ) {
			return null;
		}
		$pid = (int) wc_get_product_id_by_sku( $sku );
		if ( $pid <= 0 && Hashy_AU_Settings::instance()->normalize_skus_enabled() ) {
			$pid = Hashy_AU_Catalog::instance()->find_product_id_by_normalized_sku( Hashy_AU_SKU::normalize( $sku ) );
		}
		if ( $pid <= 0 ) {
			return null;
		}
		$p = wc_get_product( $pid );
		return $p instanceof WC_Product ? $p : null;
	}

	/**
	 * The resolver's catalogue index for a list of parent SKUs: every
	 * sellable row under each (variations, or the product itself), keyed by
	 * parent SKU. Cached for the request.
	 *
	 * @param string[] $parent_skus Parent SKUs (upper-cased).
	 * @return array<string, array<int, array>>
	 */
	public function catalogue_for( array $parent_skus ): array {
		$out = array();
		foreach ( array_unique( array_filter( array_map( 'strval', $parent_skus ) ) ) as $sku ) {
			$sku = strtoupper( trim( $sku ) );
			if ( isset( self::$catalogue_cache[ $sku ] ) ) {
				$out[ $sku ] = self::$catalogue_cache[ $sku ];
				continue;
			}
			$rows   = array();
			$parent = self::product_for_sku( $sku );
			if ( $parent instanceof WC_Product ) {
				$parent_row        = self::row_for_product( $parent );
				$parent_row['sku'] = $sku; // Keyed as asked, whatever spelling the store holds.
				$rows[]            = $parent_row;
				if ( $parent->is_type( 'variable' ) ) {
					foreach ( (array) $parent->get_children() as $child_id ) {
						$child = wc_get_product( (int) $child_id );
						if ( ! $child instanceof WC_Product_Variation ) {
							continue;
						}
						if ( ! in_array( $child->get_status(), array( 'publish', 'private' ), true ) ) {
							continue;
						}
						$child_row               = self::row_for_product( $child );
						$child_row['parent_sku'] = $sku;
						$rows[]                  = $child_row;
					}
				}
			}
			self::$catalogue_cache[ $sku ] = $rows;
			$out[ $sku ]                   = $rows;
		}
		return $out;
	}

	/**
	 * Component product ids keyed by SKU for a catalogue index.
	 *
	 * @param array<string, array<int, array>> $by_parent Catalogue index.
	 * @return array<string, int>
	 */
	private static function ids_in( array $by_parent ): array {
		$ids = array();
		foreach ( $by_parent as $rows ) {
			foreach ( $rows as $row ) {
				if ( ! empty( $row['sku'] ) && ! empty( $row['product_id'] ) ) {
					$ids[ (string) $row['sku'] ] = (int) $row['product_id'];
				}
			}
		}
		return $ids;
	}

	/**
	 * The parent SKUs a list of recipe rows can consume.
	 *
	 * @param array<int, array> $rows Normalised rows.
	 * @return string[]
	 */
	private static function component_parents_of( array $rows ): array {
		$parents = array();
		foreach ( $rows as $row ) {
			if ( ! empty( $row['component_sku'] ) ) {
				$parents[] = (string) $row['component_sku'];
			}
			if ( isset( $row['value_map'] ) && is_array( $row['value_map'] ) ) {
				$parents = array_merge( $parents, array_values( (array) $row['value_map']['map'] ) );
				if ( ! empty( $row['value_map']['default_parent'] ) ) {
					$parents[] = (string) $row['value_map']['default_parent'];
				}
			}
		}
		return array_values( array_unique( $parents ) );
	}

	/**
	 * The attributes an order line carries, for the resolver: the
	 * variation's own attributes overlaid by the line's chosen ones (Woo
	 * stores a chosen "Any" attribute as item meta keyed by the attribute
	 * slug, `attribute_` stripped), then any other non-underscore meta key
	 * a default alias recognises. Capped so a hostile line cannot bloat a
	 * payload. The agent uses this too, so it is static and needs nothing
	 * from host mode.
	 *
	 * @param WC_Order_Item_Product $item    Line.
	 * @param WC_Product|null       $product The line's product (variation).
	 * @return array<string, string>
	 */
	public static function line_attrs_from_item( WC_Order_Item_Product $item, ?WC_Product $product ): array {
		$attrs = array();
		if ( $product instanceof WC_Product_Variation ) {
			foreach ( (array) $product->get_attributes() as $k => $v ) {
				$attrs[ (string) $k ] = is_scalar( $v ) ? (string) $v : '';
			}
		}
		$known = array_change_key_case( $attrs, CASE_LOWER );
		foreach ( $item->get_meta_data() as $meta ) {
			$data = $meta->get_data();
			$key  = (string) ( $data['key'] ?? '' );
			if ( '' === $key || '_' === $key[0] ) {
				continue;
			}
			$value = $data['value'] ?? '';
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$norm_key = Hashy_AU_Recipe_Resolver::norm_key( $key );
			if ( isset( $known[ $norm_key ] ) || null !== Hashy_AU_Recipe_Resolver::role_of_key( $key, Hashy_AU_Recipe_Resolver::DEFAULT_ALIASES ) ) {
				$attrs[ $norm_key ] = mb_substr( (string) $value, 0, self::MAX_LINE_ATTR_LEN );
			}
		}
		if ( count( $attrs ) > self::MAX_LINE_ATTRS ) {
			$attrs = array_slice( $attrs, 0, self::MAX_LINE_ATTRS, true );
		}
		return $attrs;
	}

	/* ---------------------------------------------------------- applying */

	/**
	 * Apply the recipes of one sold line. Returns the component product ids
	 * whose stock moved (the caller pushes when it suppressed pushes).
	 *
	 * @param string     $site        The selling site's URL (home_url() for the hub).
	 * @param int        $order_id    Order id on that site.
	 * @param string     $line_id     Line id on that site (or a synthetic index).
	 * @param string     $line_sku    The SKU the line named.
	 * @param int        $line_qty    Units sold.
	 * @param array      $line_attrs  The line's attributes.
	 * @param WC_Product $hub_product The hub product the line resolved to.
	 * @return int[]
	 */
	public function apply_line( string $site, int $order_id, string $line_id, string $line_sku, int $line_qty, array $line_attrs, WC_Product $hub_product ): array {
		if ( $line_qty <= 0 || ! Hashy_AU_Ledger::table_exists() ) {
			return array();
		}
		$index = $this->index();
		if ( empty( $index['by_assembled'] ) ) {
			return array();
		}
		$sold   = self::row_for_product( $hub_product );
		$parent = ( 'variation' === $sold['kind'] && null !== $sold['parent_sku'] ) ? $sold['parent_sku'] : $sold['sku'];
		$rows   = $index['by_assembled'][ $parent ] ?? array();
		if ( empty( $rows ) ) {
			return array();
		}

		$set       = $this->get_set();
		$by_parent = $this->catalogue_for( self::component_parents_of( $rows ) );
		$ids       = self::ids_in( $by_parent );
		$results   = Hashy_AU_Recipe_Resolver::resolve_line( array( 'aliases' => $set['aliases'], 'recipes' => $rows ), $sold, $line_attrs, $line_qty, $by_parent );

		$touched = array();
		foreach ( $results as $r ) {
			$resolved     = in_array( $r['matched'], Hashy_AU_Ledger::RESOLVED, true );
			$component_id = $resolved ? (int) ( $ids[ (string) $r['component_sku'] ] ?? 0 ) : 0;
			$ledger_id    = Hashy_AU_Ledger::insert(
				array(
					'site'                 => $site,
					'order_id'             => $order_id,
					'line_id'              => $line_id,
					'line_sku'             => $line_sku,
					'line_qty'             => $line_qty,
					'arrow_product_id'     => (int) $hub_product->get_id(),
					'arrow_sku'            => $sold['sku'],
					'recipe_id'            => $r['recipe_id'],
					'component_product_id' => $component_id,
					'component_sku'        => (string) ( $r['component_sku'] ?? '' ),
					'qty_per'              => (int) $r['qty_per'],
					'qty'                  => (int) $r['qty'],
					'matched'              => $r['matched'],
					'reason'               => (string) ( $r['reason'] ?? '' ),
					'wanted'               => $r['wanted'],
					'mode'                 => $r['mode'],
					'recipe_hash'          => (string) $index['hash'],
				)
			);
			if ( 0 === $ledger_id ) {
				continue; // Already applied for this line and recipe.
			}
			if ( ! $resolved ) {
				Hashy_AU_Logger::instance()->warning(
					'Component recipe could not resolve',
					array(
						'site'      => $site,
						'order_id'  => $order_id,
						'line_sku'  => $line_sku,
						'recipe_id' => $r['recipe_id'],
						'reason'    => $r['reason'],
						'wanted'    => $r['wanted'],
					)
				);
				continue;
			}
			if ( 'live' !== $r['mode'] || $component_id <= 0 ) {
				continue; // Shadow: recorded, nothing moves.
			}
			$component = wc_get_product( $component_id );
			if ( ! $component instanceof WC_Product ) {
				continue;
			}
			try {
				if ( $component->managing_stock() ) {
					wc_update_product_stock( $component, (int) $r['qty'], 'decrease' );
				}
				Hashy_AU_Ledger::mark_applied( $ledger_id );
				$touched[] = $component_id;
			} catch ( Throwable $e ) {
				Hashy_AU_Logger::instance()->error(
					'Component decrement failed',
					array(
						'ledger_id' => $ledger_id,
						'component' => $component_id,
						'error'     => $e->getMessage(),
					)
				);
			}
		}
		return array_values( array_unique( $touched ) );
	}

	/**
	 * One agent order-paid row, from Hashy_AU_Host::rest_agent_order_paid.
	 * Old agents send only sku and qty; line_id then falls back to the
	 * item's index and attributes to the hub variation's own.
	 *
	 * @param string     $agent_url Agent URL.
	 * @param int        $order_id  Order id on the agent.
	 * @param int        $index     Position of the row in the payload.
	 * @param array      $row       The payload row.
	 * @param WC_Product $product   The hub product it resolved to.
	 * @return int[] Touched component ids.
	 */
	public function apply_agent_row( string $agent_url, int $order_id, int $index, array $row, WC_Product $product ): array {
		$line_id = isset( $row['line_id'] ) && '' !== (string) $row['line_id'] ? (string) $row['line_id'] : 'i' . $index;
		$attrs   = array();
		if ( isset( $row['attributes'] ) && is_array( $row['attributes'] ) ) {
			foreach ( array_slice( $row['attributes'], 0, self::MAX_LINE_ATTRS, true ) as $k => $v ) {
				if ( is_scalar( $v ) ) {
					$attrs[ mb_substr( (string) $k, 0, 80 ) ] = mb_substr( (string) $v, 0, self::MAX_LINE_ATTR_LEN );
				}
			}
		}
		try {
			return $this->apply_line( $agent_url, $order_id, $line_id, (string) ( $row['sku'] ?? '' ), (int) ( $row['qty'] ?? 0 ), $attrs, $product );
		} catch ( Throwable $e ) {
			// The order was marked seen before this ran; a fault here must
			// not kill the request and leave the arrow half applied.
			Hashy_AU_Logger::instance()->error(
				'Component apply failed',
				array(
					'agent_url' => $agent_url,
					'order_id'  => $order_id,
					'error'     => $e->getMessage(),
				)
			);
			return array();
		}
	}

	/**
	 * The hub's own checkout: Woo has just decremented this line's product.
	 *
	 * @param WC_Order_Item_Product|mixed $item   Line.
	 * @param array|mixed                 $change `{product, from, to}`.
	 * @param WC_Order|mixed              $order  Order.
	 * @return void
	 */
	public function on_hub_line_reduced( $item, $change = array(), $order = null ): void {
		if ( ! $item instanceof WC_Order_Item_Product || ! $order instanceof WC_Order ) {
			return;
		}
		$this->apply_hub_item( $item, $order );
	}

	/**
	 * The hub's own checkout, per order: catches arrow lines whose product
	 * does not manage stock (the per-item hook only fires for managed ones).
	 *
	 * @param WC_Order|mixed $order Order.
	 * @return void
	 */
	public function on_hub_order_reduced( $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof WC_Order_Item_Product ) {
				$this->apply_hub_item( $item, $order );
			}
		}
	}

	/**
	 * Apply one hub line once; the item meta records the ledger ids.
	 *
	 * @param WC_Order_Item_Product $item  Line.
	 * @param WC_Order              $order Order.
	 * @return void
	 */
	private function apply_hub_item( WC_Order_Item_Product $item, WC_Order $order ): void {
		if ( '' !== (string) $item->get_meta( self::LEDGER_ITEM_META, true ) ) {
			return;
		}
		$product = $item->get_product();
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$qty = (int) $item->get_quantity();
		if ( $qty <= 0 ) {
			return;
		}
		$site  = untrailingslashit( home_url() );
		$attrs = self::line_attrs_from_item( $item, $product );
		try {
			$touched = $this->apply_line( $site, (int) $order->get_id(), (string) $item->get_id(), (string) $product->get_sku(), $qty, $attrs, $product );
		} catch ( Throwable $e ) {
			Hashy_AU_Logger::instance()->error( 'Hub component apply failed', array( 'order_id' => $order->get_id(), 'error' => $e->getMessage() ) );
			$touched = array();
		}
		$rows = Hashy_AU_Ledger::rows_for_line( $site, (int) $order->get_id(), (string) $item->get_id() );
		$item->update_meta_data( self::LEDGER_ITEM_META, wp_json_encode( array_column( $rows, 'id' ) ) );
		$item->save_meta_data();
		// Pushes are not suppressed on the hub's own checkout: the component
		// decrements pushed themselves through the host's hook. Only ids
		// touched under suppression wait in $touched.
		unset( $touched );
	}

	/* ---------------------------------------------------------- restoring */

	/**
	 * Give back the components of one restored line (a cancel or a refund).
	 * Returns the component product ids whose stock moved.
	 *
	 * @param string   $site             Site URL.
	 * @param int      $order_id         Order id on that site.
	 * @param string   $line_id          Line id, or '' to look up by product.
	 * @param int      $arrow_product_id The arrow's hub product id, used when line_id is ''.
	 * @param int      $restored_units   Assembled units restored.
	 * @return int[]
	 */
	public function reverse_line( string $site, int $order_id, string $line_id, int $arrow_product_id, int $restored_units ): array {
		if ( $restored_units <= 0 || ! Hashy_AU_Ledger::table_exists() ) {
			return array();
		}
		$rows = '' !== $line_id
			? Hashy_AU_Ledger::rows_for_line( $site, $order_id, $line_id )
			: Hashy_AU_Ledger::rows_for_order_product( $site, $order_id, $arrow_product_id );
		$plan    = Hashy_AU_Ledger::reverse_plan( $rows, $restored_units );
		$touched = array();
		foreach ( $plan as $step ) {
			if ( $step['moves'] && $step['component_product_id'] > 0 ) {
				$component = wc_get_product( $step['component_product_id'] );
				if ( $component instanceof WC_Product && $component->managing_stock() ) {
					wc_update_product_stock( $component, (int) $step['units'], 'increase' );
					$touched[] = (int) $step['component_product_id'];
				}
			}
			Hashy_AU_Ledger::add_reversed( (int) $step['id'], (int) $step['units'] );
		}
		return array_values( array_unique( $touched ) );
	}

	/**
	 * The hub's own cancel: Woo restored this line's product.
	 *
	 * @param WC_Order_Item_Product|mixed $item      Line.
	 * @param int|mixed                   $new_stock After.
	 * @param int|mixed                   $old_stock Before.
	 * @param WC_Order|mixed              $order     Order.
	 * @return void
	 */
	public function on_hub_item_restored( $item, $new_stock = 0, $old_stock = 0, $order = null ): void {
		if ( ! $item instanceof WC_Order_Item_Product || ! $order instanceof WC_Order ) {
			return;
		}
		$units = (int) $new_stock - (int) $old_stock;
		if ( $units <= 0 ) {
			$units = (int) $item->get_quantity();
		}
		$this->reverse_line( untrailingslashit( home_url() ), (int) $order->get_id(), (string) $item->get_id(), 0, $units );
	}

	/**
	 * The hub's own refund: Woo restocked a refunded item.
	 *
	 * @param int|mixed        $product_id Product id.
	 * @param int|mixed        $old_stock  Before.
	 * @param int|mixed        $new_stock  After.
	 * @param WC_Order|mixed   $order      Order.
	 * @param WC_Product|mixed $product    Product.
	 * @return void
	 */
	public function on_hub_refund_restocked( $product_id, $old_stock = 0, $new_stock = 0, $order = null, $product = null ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$units = (int) $new_stock - (int) $old_stock;
		if ( $units <= 0 ) {
			return;
		}
		$this->reverse_line( untrailingslashit( home_url() ), (int) $order->get_id(), '', (int) $product_id, $units );
	}

	/**
	 * Whether a hub product's figure is derived (its parent has a live,
	 * deriving recipe row). The restore endpoint skips the arrow increase
	 * for these: the component increase sets the figure.
	 *
	 * @param WC_Product $product Product or variation.
	 * @return bool
	 */
	public function is_derived( WC_Product $product ): bool {
		$sold   = self::row_for_product( $product );
		$parent = ( 'variation' === $sold['kind'] && null !== $sold['parent_sku'] ) ? $sold['parent_sku'] : $sold['sku'];
		foreach ( $this->index()['by_assembled'][ $parent ] ?? array() as $row ) {
			if ( 'live' === $row['mode'] && ! empty( $row['derives'] ) ) {
				return true;
			}
		}
		return false;
	}

	/* ---------------------------------------------------------- deriving */

	/**
	 * A component moved: recompute every arrow depending on it.
	 *
	 * @param WC_Product|mixed $product The product that moved.
	 * @return void
	 */
	public function on_stock_changed( $product ): void {
		if ( self::$recomputing || ! $product instanceof WC_Product ) {
			return;
		}
		$index = $this->index();
		if ( empty( $index['by_component'] ) ) {
			return;
		}
		$row    = self::row_for_product( $product );
		$parent = ( 'variation' === $row['kind'] && null !== $row['parent_sku'] ) ? $row['parent_sku'] : $row['sku'];
		$rows   = $index['by_component'][ $parent ] ?? array();
		$assembled = array();
		foreach ( $rows as $r ) {
			if ( 'live' === $r['mode'] && ! empty( $r['derives'] ) ) {
				$assembled[ $r['assembled_sku'] ] = true;
			}
		}
		foreach ( array_keys( $assembled ) as $sku ) {
			$this->recompute_parent( (string) $sku );
		}
	}

	/**
	 * Recompute the derived figure of every sellable variation of one
	 * assembled parent. Returns how many were set.
	 *
	 * @param string $assembled_sku Assembled parent SKU.
	 * @return int
	 */
	public function recompute_parent( string $assembled_sku ): int {
		$index = $this->index();
		$rows  = $index['by_assembled'][ $assembled_sku ] ?? array();
		$live  = array_filter(
			$rows,
			function ( array $r ): bool {
				return 'live' === $r['mode'] && ! empty( $r['derives'] );
			}
		);
		if ( empty( $live ) ) {
			return 0;
		}
		$set       = $this->get_set();
		$parents   = array_merge( array( $assembled_sku ), self::component_parents_of( $rows ) );
		$by_parent = $this->catalogue_for( $parents );
		$ids       = self::ids_in( $by_parent );
		$stocks    = array();
		foreach ( self::component_parents_of( $rows ) as $p ) {
			foreach ( Hashy_AU_Recipe_Resolver::sellable( $by_parent[ $p ] ?? array() ) as $c ) {
				$cp = isset( $c['product_id'] ) ? wc_get_product( (int) $c['product_id'] ) : null;
				if ( $cp instanceof WC_Product ) {
					$stocks[ (string) $c['sku'] ] = $cp->managing_stock() ? (int) $cp->get_stock_quantity() : PHP_INT_MAX;
				}
			}
		}

		$changed = 0;
		foreach ( Hashy_AU_Recipe_Resolver::sellable( $by_parent[ $assembled_sku ] ?? array() ) as $variation ) {
			$derived = Hashy_AU_Recipe_Resolver::derived_count( array( 'aliases' => $set['aliases'], 'recipes' => $rows ), $variation, $by_parent, $stocks );
			if ( null === $derived ) {
				continue;
			}
			$arrow_id = (int) ( $ids[ (string) $variation['sku'] ] ?? 0 );
			$arrow    = $arrow_id > 0 ? wc_get_product( $arrow_id ) : null;
			if ( ! $arrow instanceof WC_Product || ! $arrow->managing_stock() ) {
				continue;
			}
			if ( (int) $arrow->get_stock_quantity() === $derived ) {
				continue;
			}
			$suppressed = Hashy_AU_Host::pushes_suppressed();
			$already    = ! $suppressed && Hashy_AU_Host::was_pushed_this_request( $arrow_id );

			self::$recomputing = true;
			try {
				wc_update_product_stock( $arrow, $derived, 'set' );
				$arrow->set_stock_status( $derived > 0 ? 'instock' : 'outofstock' );
				$arrow->save();
			} finally {
				self::$recomputing = false;
			}
			++$changed;

			if ( $suppressed ) {
				self::$touched[ $arrow_id ] = true;
			} elseif ( $already ) {
				// The host already pushed this arrow earlier in the request
				// (Woo's own decrement at checkout); agents drop a payload
				// whose ts is not strictly newer, so bump it and push again.
				Hashy_AU_Host::bump_ts_for( $arrow_id );
				Hashy_AU_Host::instance()->push_product_now( $arrow_id );
			}
		}
		if ( $changed > 0 ) {
			Hashy_AU_Logger::instance()->info( 'Derived stock recomputed', array( 'assembled' => $assembled_sku, 'changed' => $changed ) );
		}
		return $changed;
	}

	/**
	 * Recompute every live deriving recipe's arrows under a time budget;
	 * whatever is left is queued for cron.
	 *
	 * @return array{arrows: int, changed: int, queued: int}
	 */
	public function recompute_all(): array {
		$index    = $this->index();
		$deadline = time() + self::RECOMPUTE_BUDGET;
		$pending  = array();
		foreach ( $index['by_assembled'] as $sku => $rows ) {
			foreach ( $rows as $r ) {
				if ( 'live' === $r['mode'] && ! empty( $r['derives'] ) ) {
					$pending[] = (string) $sku;
					break;
				}
			}
		}
		$out = array(
			'arrows'  => count( $pending ),
			'changed' => 0,
			'queued'  => 0,
		);
		$queue = array();
		foreach ( $pending as $sku ) {
			if ( time() >= $deadline ) {
				$queue[] = $sku;
				continue;
			}
			$out['changed'] += $this->recompute_parent( $sku );
		}
		if ( ! empty( $queue ) ) {
			update_option( self::RECOMPUTE_QUEUE_OPTION, array_values( array_unique( $queue ) ), false );
			if ( ! wp_next_scheduled( self::RECOMPUTE_CRON ) ) {
				wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::RECOMPUTE_CRON );
			}
			$out['queued'] = count( $queue );
		}
		return $out;
	}

	public function cron_recompute(): void {
		$queue = get_option( self::RECOMPUTE_QUEUE_OPTION, array() );
		if ( ! is_array( $queue ) || empty( $queue ) ) {
			return;
		}
		$deadline = time() + self::RECOMPUTE_BUDGET;
		while ( ! empty( $queue ) && time() < $deadline ) {
			$sku = (string) array_shift( $queue );
			$this->recompute_parent( $sku );
		}
		update_option( self::RECOMPUTE_QUEUE_OPTION, array_values( $queue ), false );
		if ( ! empty( $queue ) && ! wp_next_scheduled( self::RECOMPUTE_CRON ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::RECOMPUTE_CRON );
		}
	}

	/**
	 * Product ids changed under push suppression this request, cleared.
	 *
	 * @return int[]
	 */
	public function drain_touched(): array {
		$ids           = array_map( 'intval', array_keys( self::$touched ) );
		self::$touched = array();
		return $ids;
	}

	public function daily(): void {
		if ( Hashy_AU_Ledger::table_exists() ) {
			$n = Hashy_AU_Ledger::prune();
			if ( $n > 0 ) {
				Hashy_AU_Logger::instance()->info( 'Component ledger pruned', array( 'rows' => $n ) );
			}
		}
	}
}
