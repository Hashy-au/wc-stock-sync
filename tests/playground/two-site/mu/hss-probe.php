<?php
/**
 * Test-only probe for the two-site Playground pair (one Host, one Agent),
 * mounted as a mu-plugin by run-two-site.ps1. Never shipped: /tests is
 * export-ignored.
 *
 * Opens unauthenticated REST routes under /hss-probe/v1 to seed fixtures,
 * change Host stock, run the batch syncs, send 0.7.0-shaped payloads and read
 * product state. It refuses to load on anything but a loopback address.
 *
 * @package Hashy_AU
 */

defined( 'ABSPATH' ) || exit;

if ( ! in_array( (string) wp_parse_url( home_url(), PHP_URL_HOST ), array( '127.0.0.1', 'localhost' ), true ) ) {
	return;
}

const HSS_PROBE_SECRET = 'probe-secret-0123456789abcdef0123456789abcdef';
const HSS_PROBE_SKUS   = array( 'HSS-A', 'HSS-B', 'HSS-C', 'HSS-D-1', 'HSS-E' );

/**
 * Fixture definitions for one side.
 *
 * Host: no sales except B. Agent: its own prices and sales, the way the
 * Solkarra Desktop App's set-sale writes them (price and dates).
 *
 * @param string $role host|agent.
 * @return array<string, array<string, mixed>>
 */
function hss_probe_fixtures( $role ) {
	$day = DAY_IN_SECONDS;
	$now = time();
	if ( 'host' === $role ) {
		return array(
			'HSS-A'   => array( 'regular' => '100', 'sale' => '', 'stock' => 10 ),
			'HSS-B'   => array( 'regular' => '100', 'sale' => '80', 'stock' => 10 ),
			'HSS-C'   => array( 'regular' => '100', 'sale' => '', 'stock' => 10 ),
			'HSS-D-1' => array( 'regular' => '50', 'sale' => '', 'stock' => 10, 'variation_of' => 'HSS-D' ),
			'HSS-E'   => array( 'regular' => '100', 'sale' => '', 'stock' => 10 ),
		);
	}
	return array(
		// The Agent's own running sale.
		'HSS-A'   => array( 'regular' => '120', 'sale' => '99', 'from' => $now - $day, 'to' => $now + 7 * $day, 'stock' => 10 ),
		// No sale here; the Host runs one.
		'HSS-B'   => array( 'regular' => '120', 'sale' => '', 'stock' => 10 ),
		// A scheduled sale that has not started.
		'HSS-C'   => array( 'regular' => '120', 'sale' => '99', 'from' => $now + 3 * $day, 'to' => $now + 10 * $day, 'stock' => 10 ),
		// A variation on its own sale.
		'HSS-D-1' => array( 'regular' => '60', 'sale' => '45', 'from' => $now - $day, 'to' => $now + 7 * $day, 'stock' => 10, 'variation_of' => 'HSS-D' ),
		// Control: the Host never changes this one's stock.
		'HSS-E'   => array( 'regular' => '120', 'sale' => '99', 'from' => $now - $day, 'to' => $now + 7 * $day, 'stock' => 10 ),
	);
}

/**
 * Create or reset every fixture product on this side, with pushes suppressed.
 *
 * @param string $role host|agent.
 * @return void
 */
function hss_probe_seed( $role ) {
	Hashy_AU_Host::suppress_pushes( true );
	foreach ( hss_probe_fixtures( $role ) as $sku => $f ) {
		$id = wc_get_product_id_by_sku( $sku );
		if ( $id ) {
			$p = wc_get_product( $id );
		} elseif ( ! empty( $f['variation_of'] ) ) {
			$parent_id = wc_get_product_id_by_sku( $f['variation_of'] );
			if ( ! $parent_id ) {
				$parent = new WC_Product_Variable();
				$parent->set_name( 'Probe ' . $f['variation_of'] );
				$parent->set_sku( $f['variation_of'] );
				$attr = new WC_Product_Attribute();
				$attr->set_name( 'Size' );
				$attr->set_options( array( 'One' ) );
				$attr->set_visible( true );
				$attr->set_variation( true );
				$parent->set_attributes( array( $attr ) );
				$parent->set_status( 'publish' );
				$parent_id = $parent->save();
			}
			$p = new WC_Product_Variation();
			$p->set_parent_id( $parent_id );
			$p->set_attributes( array( 'size' => 'One' ) );
			$p->set_sku( $sku );
			$p->set_status( 'publish' );
		} else {
			$p = new WC_Product_Simple();
			$p->set_name( 'Probe ' . $sku );
			$p->set_sku( $sku );
			$p->set_status( 'publish' );
		}
		$p->set_regular_price( $f['regular'] );
		$p->set_sale_price( $f['sale'] );
		$p->set_date_on_sale_from( isset( $f['from'] ) ? $f['from'] : '' );
		$p->set_date_on_sale_to( isset( $f['to'] ) ? $f['to'] : '' );
		$p->set_manage_stock( true );
		$p->set_stock_quantity( $f['stock'] );
		$p->set_stock_status( 'instock' );
		$p->delete_meta_data( '_wcss_last_sync_ts' );
		$p->save();
	}
	Hashy_AU_Host::suppress_pushes( false );
}

/**
 * Stock, prices and sale dates of every fixture on this side.
 *
 * @return array<string, array<string, mixed>>
 */
function hss_probe_state() {
	$out = array();
	foreach ( HSS_PROBE_SKUS as $sku ) {
		$id = wc_get_product_id_by_sku( $sku );
		if ( ! $id ) {
			continue;
		}
		wc_delete_product_transients( $id );
		clean_post_cache( $id );
		$p           = wc_get_product( $id );
		$from        = $p->get_date_on_sale_from( 'edit' );
		$to          = $p->get_date_on_sale_to( 'edit' );
		$out[ $sku ] = array(
			'stock'      => (string) $p->get_stock_quantity(),
			'regular'    => (string) $p->get_regular_price( 'edit' ),
			'sale'       => (string) $p->get_sale_price( 'edit' ),
			'price_meta' => (string) get_post_meta( $id, '_price', true ),
			'dates'      => ( $from ? $from->date( 'Y-m-d' ) : '-' ) . '..' . ( $to ? $to->date( 'Y-m-d' ) : '-' ),
		);
	}
	return $out;
}

/**
 * The first (only) Agent row on the Host, with its secret.
 *
 * @return array<string, mixed>
 */
function hss_probe_agent() {
	$agents = Hashy_AU_Settings::instance()->get_host_agents();
	return isset( $agents[0] ) && is_array( $agents[0] ) ? $agents[0] : array();
}

/**
 * A payload shaped the way 0.7.0 built every stock push.
 *
 * @param string $sku   SKU.
 * @param int    $qty   Stock quantity.
 * @param array  $price price, regular_price, sale_price.
 * @return array<string, mixed>
 */
function hss_probe_legacy_payload( $sku, $qty, array $price ) {
	return array(
		'host_url'         => untrailingslashit( home_url() ),
		'product_id'       => (int) wc_get_product_id_by_sku( $sku ),
		'sku'              => $sku,
		'stock_qty'        => $qty,
		'stock_status'     => $qty > 0 ? 'instock' : 'outofstock',
		'manage_stock'     => true,
		'price'            => $price['price'],
		'regular_price'    => $price['regular_price'],
		'sale_price'       => $price['sale_price'],
		'ts'               => time(),
		'sync_prices_only' => false,
		'price_pct'        => 0.0,
	);
}

add_action(
	'rest_api_init',
	function () {
		$open = '__return_true';

		register_rest_route(
			'hss-probe/v1',
			'/setup',
			array(
				'methods'             => 'POST',
				'permission_callback' => $open,
				'callback'            => function ( WP_REST_Request $r ) {
					$role = (string) $r->get_param( 'role' );
					$peer = (string) $r->get_param( 'peer' );
					if ( 'host' === $role ) {
						$in = array(
							'mode'           => 'host',
							'normalize_skus' => 'yes',
							'host'           => array(
								'agents' => array(
									array(
										'id'            => 'mirror',
										'name'          => 'Mirror',
										'url'           => $peer,
										'shared_secret' => HSS_PROBE_SECRET,
										'price_pct'     => (string) $r->get_param( 'price_pct' ),
										'sync_prices'   => (string) $r->get_param( 'sync_prices' ),
									),
								),
							),
						);
					} else {
						$in = array(
							'mode'           => 'agent',
							'normalize_skus' => 'yes',
							'agent'          => array(
								'host_url'      => $peer,
								'agent_code'    => 'MIR',
								'shared_secret' => HSS_PROBE_SECRET,
							),
						);
					}
					update_option( 'hashy_au_settings', Hashy_AU_Settings::instance()->sanitize_settings( $in ), false );
					if ( $r->get_param( 'seed' ) ) {
						hss_probe_seed( $role );
					}
					return array(
						'version' => WC_STOCK_SYNC_VERSION,
						'mode'    => Hashy_AU_Settings::instance()->get_mode(),
						'state'   => hss_probe_state(),
					);
				},
			)
		);

		register_rest_route(
			'hss-probe/v1',
			'/state',
			array(
				'methods'             => 'GET',
				'permission_callback' => $open,
				'callback'            => 'hss_probe_state',
			)
		);

		register_rest_route(
			'hss-probe/v1',
			'/set-stock',
			array(
				'methods'             => 'POST',
				'permission_callback' => $open,
				'callback'            => function ( WP_REST_Request $r ) {
					foreach ( (array) $r->get_param( 'skus' ) as $sku => $qty ) {
						$p = wc_get_product( wc_get_product_id_by_sku( $sku ) );
						if ( $p ) {
							wc_update_product_stock( $p, (int) $qty, 'set' );
						}
					}
					return array( 'log' => array_slice( (array) get_option( 'wcss_log_ring', array() ), -8 ) );
				},
			)
		);

		register_rest_route(
			'hss-probe/v1',
			'/batch',
			array(
				'methods'             => 'POST',
				'permission_callback' => $open,
				'callback'            => function ( WP_REST_Request $r ) {
					$kind  = 'prices' === (string) $r->get_param( 'kind' ) ? 'prices' : 'stock';
					$agent = hss_probe_agent();
					$out   = array();
					$page  = 1;
					do {
						$res   = 'prices' === $kind
							? Hashy_AU_Host::instance()->process_price_sync_batch_once( $agent, $page )
							: Hashy_AU_Host::instance()->process_stock_sync_batch_once( $agent, $page );
						$out[] = $res;
						$page  = (int) $res['next_page'];
					} while ( empty( $res['done'] ) && $page < 20 );
					return $out;
				},
			)
		);

		register_rest_route(
			'hss-probe/v1',
			'/legacy-push',
			array(
				'methods'             => 'POST',
				'permission_callback' => $open,
				'callback'            => function ( WP_REST_Request $r ) {
					$payload = hss_probe_legacy_payload( (string) $r->get_param( 'sku' ), (int) $r->get_param( 'qty' ), (array) $r->get_param( 'price' ) );
					return array( 'ok' => Hashy_AU_Host::instance()->do_push_stock_update( hss_probe_agent(), $payload, false ) );
				},
			)
		);

		register_rest_route(
			'hss-probe/v1',
			'/legacy-queue',
			array(
				'methods'             => 'POST',
				'permission_callback' => $open,
				'callback'            => function ( WP_REST_Request $r ) {
					$agent = hss_probe_agent();
					update_option(
						'wcss_failed_requests',
						array(
							array(
								'created'   => time(),
								'type'      => 'stock_update',
								'agent_id'  => (string) ( $agent['id'] ?? '' ),
								'agent_url' => (string) ( $agent['url'] ?? '' ),
								'payload'   => hss_probe_legacy_payload( (string) $r->get_param( 'sku' ), (int) $r->get_param( 'qty' ), (array) $r->get_param( 'price' ) ),
								'error'     => 'probe',
								'attempts'  => 0,
							),
						),
						false
					);
					Hashy_AU_Host::instance()->process_failed_queue();
					return array( 'left' => count( (array) get_option( 'wcss_failed_requests', array() ) ) );
				},
			)
		);
	}
);
