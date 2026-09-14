<?php
/**
 * Playground smoke test for component recipes (Hashy Stock Sync 0.7.0,
 * design/26). Plain PHP, no framework.
 *
 * Run through WP-CLI once WooCommerce and the plugin are active and
 * tests/playground/fixtures-recipes.php has seeded the store
 * (blueprint-recipes.json does all three, then runs this):
 *
 *     wp eval-file wp-content/plugins/hashy-stock-sync/tests/playground/smoke-recipes.php
 *
 * One install in host mode stands in for the hub; three agent rows stand
 * in for the stores. Every outbound push is captured by a pre_http_request
 * filter, and agent orders are fed in by signing a body with the agent's
 * secret and dispatching a WP_REST_Request at the host's callback.
 *
 * Groups: boot, write, derived, agent sale, old agent, unresolved, shadow,
 * dedupe, hub checkout, agent restore, hub refund, connector path,
 * stocktake path, api reads, acknowledge, log. Prints one line per
 * assertion and a tally, mirrors the text to tests/playground/smoke-result.txt,
 * and exits non-zero on any failure.
 *
 * Not loaded by the plugin. Nothing under tests/ ships in the release zip.
 *
 * @package Hashy_AU
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/fixtures-recipes.php';

$GLOBALS['hashy_smoke'] = array(
	'pass'   => 0,
	'fail'   => 0,
	'lines'  => array(),
	'pushes' => array(),
);

function hashy_smoke_say( $line ) {
	$GLOBALS['hashy_smoke']['lines'][] = $line;
	echo $line . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI text.
}

function hashy_smoke_group( $name ) {
	hashy_smoke_say( '' );
	hashy_smoke_say( '# ' . $name );
}

function hashy_smoke_ok( $ok, $label, $detail = '' ) {
	if ( $ok ) {
		++$GLOBALS['hashy_smoke']['pass'];
		hashy_smoke_say( '  ok    ' . $label );
		return;
	}
	++$GLOBALS['hashy_smoke']['fail'];
	hashy_smoke_say( '  FAIL  ' . $label . ( '' !== $detail ? '  ->  ' . $detail : '' ) );
}

function hashy_smoke_is( $actual, $expected, $label ) {
	hashy_smoke_ok( $actual === $expected, $label, 'got ' . wp_json_encode( $actual ) . ', expected ' . wp_json_encode( $expected ) );
}

function hashy_smoke_finish() {
	hashy_smoke_say( '' );
	hashy_smoke_say( sprintf( 'HASHY_RECIPES_SMOKE %d passed, %d failed', $GLOBALS['hashy_smoke']['pass'], $GLOBALS['hashy_smoke']['fail'] ) );
	$state = $GLOBALS['hashy_smoke'];
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test-only mirror.
	file_put_contents( __DIR__ . '/smoke-result.txt', implode( PHP_EOL, $state['lines'] ) . PHP_EOL );
	if ( $state['fail'] > 0 ) {
		if ( class_exists( 'WP_CLI' ) ) {
			WP_CLI::halt( 1 );
		}
		exit( 1 );
	}
}

/** Stock of a SKU on the hub. */
function hashy_smoke_stock( $sku ) {
	$p = wc_get_product( wc_get_product_id_by_sku( $sku ) );
	return $p ? (int) $p->get_stock_quantity() : null;
}

/** Product id of a SKU. */
function hashy_smoke_id( $sku ) {
	return (int) wc_get_product_id_by_sku( $sku );
}

/** Pushes captured so far for one SKU: list of [agent host, stock_qty, ts]. */
function hashy_smoke_pushes_for( $sku ) {
	$out = array();
	foreach ( $GLOBALS['hashy_smoke']['pushes'] as $p ) {
		if ( $p['sku'] === $sku ) {
			$out[] = $p;
		}
	}
	return $out;
}

function hashy_smoke_clear_pushes() {
	$GLOBALS['hashy_smoke']['pushes'] = array();
}

/** Dispatch a signed agent request at a host callback. */
function hashy_smoke_agent_call( $agent, $route, $method, array $payload ) {
	$body = wp_json_encode( $payload );
	$ts   = (string) time();
	$sig  = Hashy_AU_Crypto::sign( $agent['secret'], $ts, $body );
	$req  = new WP_REST_Request( 'POST', '/hashy-sync/v1/' . $route );
	$req->set_header( 'Content-Type', 'application/json' );
	$req->set_header( 'X-Hashy-Timestamp', $ts );
	$req->set_header( 'X-Hashy-Signature', $sig );
	$req->set_body( $body );
	$resp = Hashy_AU_Host::instance()->$method( $req );
	return array( $resp->get_status(), $resp->get_data() );
}

/** A ledger row by site/order/recipe. */
function hashy_smoke_ledger_row( $site, $order_id, $recipe_id ) {
	global $wpdb;
	$table = Hashy_AU_Ledger::table();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE site = %s AND order_id = %d AND recipe_id = %s ORDER BY id DESC LIMIT 1", $site, $order_id, $recipe_id ), ARRAY_A );
}

// Capture every outbound push instead of sending it.
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) {
		if ( false === strpos( (string) $url, '/hashy-sync/v1/host/stock-update' ) ) {
			return $pre;
		}
		$body = json_decode( (string) ( $args['body'] ?? '' ), true );
		$GLOBALS['hashy_smoke']['pushes'][] = array(
			'agent' => (string) wp_parse_url( (string) $url, PHP_URL_HOST ),
			'sku'   => (string) ( $body['sku'] ?? '' ),
			'qty'   => isset( $body['stock_qty'] ) ? (int) $body['stock_qty'] : null,
			'ts'    => (int) ( $body['ts'] ?? 0 ),
		);
		return array(
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'body'     => '{"ok":true}',
			'headers'  => array(),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);

$agents  = hashy_recipes_fixture_agents();
$aba     = $agents['aba'];
$fixture = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/resolver-cases.json' ), true );
$set     = array(
	'aliases' => $fixture['aliases'],
	'recipes' => $fixture['recipes'],
);
$hub     = untrailingslashit( home_url() );

/* ------------------------------------------------------------------ boot */
hashy_smoke_group( 'boot' );
hashy_smoke_is( defined( 'WC_STOCK_SYNC_VERSION' ) ? WC_STOCK_SYNC_VERSION : '', '0.7.0', 'plugin version is 0.7.0' );
hashy_smoke_is( Hashy_AU_Settings::instance()->get_mode(), 'host', 'the install is in host mode' );
$host_agents = Hashy_AU_Settings::instance()->get_host_agents();
hashy_smoke_is( count( $host_agents ), 3, 'three agents are configured' );
hashy_smoke_is( (string) ( $host_agents[0]['shared_secret'] ?? '' ), $aba['secret'], 'the first agent secret round-tripped through the sealed store' );
hashy_smoke_ok( class_exists( 'Hashy_AU_Recipes' ) && class_exists( 'Hashy_AU_Ledger' ) && class_exists( 'Hashy_AU_Recipes_API' ) && class_exists( 'Hashy_AU_Recipe_Resolver' ), 'the four recipe classes are loaded' );
hashy_smoke_ok( Hashy_AU_Ledger::table_exists(), 'the ledger table exists after one init' );
hashy_smoke_is( get_option( Hashy_AU_Ledger::DB_VERSION_OPTION ), '0.7.0', 'the db version option is stamped' );
hashy_smoke_ok( Hashy_AU_Recipes_API::available(), 'the API reports available' );
hashy_smoke_is( Hashy_AU_Recipes::instance()->stored_hash(), '', 'no recipe set is stored yet' );
hashy_smoke_is( hashy_smoke_stock( 'ATLAS-SLATE-FA-500' ), 3, 'the fixture arrow figure is deliberately wrong before the first write' );
hashy_smoke_ok( has_action( 'woocommerce_variation_set_stock', array( Hashy_AU_Recipes::instance(), 'on_stock_changed' ) ) === 20, 'the derived recompute hooks set_stock at priority 20' );

/* ----------------------------------------------------------------- write */
hashy_smoke_group( 'write' );
$bad = Hashy_AU_Recipes_API::write_recipes( array( 'aliases' => array(), 'recipes' => $fixture['invalid_sets'][1]['recipes'] ), 'smoke-bad', 1, 'test', null );
hashy_smoke_ok( is_wp_error( $bad ) && 'cycle' === $bad->get_error_code(), 'a cyclic set is refused as cycle' );
$bad = Hashy_AU_Recipes_API::write_recipes( array( 'aliases' => array(), 'recipes' => $fixture['invalid_sets'][2]['recipes'] ), 'smoke-bad2', 1, 'test', null );
hashy_smoke_ok( is_wp_error( $bad ) && 'validation' === $bad->get_error_code(), 'a bad qty_per is refused as validation' );
hashy_smoke_is( Hashy_AU_Recipes::instance()->stored_hash(), '', 'a refused write stores nothing' );

hashy_smoke_clear_pushes();
$written = Hashy_AU_Recipes_API::write_recipes( $set, 'smoke-write-1', 1, 'test', null );
hashy_smoke_ok( is_array( $written ) && true === $written['applied'] && false === $written['replayed'], 'the fixture set is written' );
hashy_smoke_is( is_array( $written ) ? $written['hash'] : '', $fixture['hash'], 'the hub hash equals the fixture hash pinned on both sides' );
hashy_smoke_is( is_array( $written ) ? $written['recipes'] : 0, 5, 'five rows stored' );
hashy_smoke_is( Hashy_AU_Recipes_API::recipes()['hash'], $fixture['hash'], 'recipes() answers the stored hash' );
$replay = Hashy_AU_Recipes_API::write_recipes( $set, 'smoke-write-1', 1, 'test', null );
hashy_smoke_ok( is_array( $replay ) && true === $replay['replayed'], 'the same idempotency key replays without writing' );
$stale = Hashy_AU_Recipes_API::write_recipes( $set, 'smoke-write-2', 1, 'test', str_repeat( '0', 64 ) );
hashy_smoke_ok( is_wp_error( $stale ) && 'stale_hash' === $stale->get_error_code(), 'a stale expected hash is refused' );
$again = Hashy_AU_Recipes_API::write_recipes( $set, 'smoke-write-3', 1, 'test', $fixture['hash'] );
hashy_smoke_ok( is_array( $again ) && true === $again['applied'], 'the right expected hash writes' );
hashy_smoke_ok( false !== strpos( wp_json_encode( Hashy_AU_Recipes_API::recipes()['aliases'] ), 'arrow spine (carbon)' ), 'aliases are stored lower-cased' );

/* --------------------------------------------------------------- derived */
hashy_smoke_group( 'derived' );
hashy_smoke_is( is_array( $written ) ? $written['recompute']['arrows'] : 0, 1, 'one assembled parent has a live deriving row (Atlas)' );
hashy_smoke_is( is_array( $written ) ? $written['recompute']['changed'] : 0, 5, 'all five Atlas arrows were set on the first write' );
hashy_smoke_is( hashy_smoke_stock( 'ATLAS-SLATE-FA-500' ), 20, 'FA-500 now equals its shafts (20)' );
hashy_smoke_is( hashy_smoke_stock( 'ATLAS-SLATE-FA-800' ), 20, 'FA-800 now equals its shafts (20)' );
hashy_smoke_is( hashy_smoke_stock( '17GB4-01' ), 50, 'the wood arrow (shadow) keeps its own figure' );
$fa_pushes = hashy_smoke_pushes_for( 'ATLAS-SLATE-FA-500' );
hashy_smoke_is( count( $fa_pushes ), 3, 'FA-500 was pushed to the three agents' );
hashy_smoke_is( $fa_pushes[0]['qty'] ?? null, 20, 'the pushed figure is the derived one' );

/* ------------------------------------------------------------ agent sale */
hashy_smoke_group( 'agent sale' );
hashy_smoke_clear_pushes();
list( $status, $data ) = hashy_smoke_agent_call(
	$aba,
	'agent/order-paid',
	'rest_agent_order_paid',
	array(
		'agent_url' => $aba['url'],
		'order_id'  => 1001,
		'items'     => array(
			array(
				'sku'          => 'ABA_ATLAS-SLATE-FA-500',
				'qty'          => 1,
				'line_id'      => 77,
				'variation_id' => 0,
				'attributes'   => array( 'pa_spine' => '500' ),
			),
		),
		'ts'        => time(),
	)
);
hashy_smoke_is( $status, 200, 'order-paid from asiaticbows is accepted' );
hashy_smoke_is( hashy_smoke_stock( 'ATLAS-SLATE-BS-500' ), 19, 'the 500 shaft went 20 to 19' );
hashy_smoke_is( hashy_smoke_stock( 'ATLAS-SLATE-FA-500' ), 19, 'the 500 arrow is derived to 19' );
hashy_smoke_is( hashy_smoke_stock( 'ATLAS-SLATE-BS-600' ), 20, 'the 600 shaft is untouched' );
$row = hashy_smoke_ledger_row( $aba['url'], 1001, 'r1-atlas-slate' );
hashy_smoke_ok( is_array( $row ), 'a ledger row was written' );
hashy_smoke_is( (string) ( $row['matched'] ?? '' ), 'match', 'it matched' );
hashy_smoke_is( (int) ( $row['applied'] ?? 0 ), 1, 'it is applied' );
hashy_smoke_is( (int) ( $row['qty'] ?? 0 ), 1, 'it consumed one unit' );
hashy_smoke_is( (string) ( $row['line_id'] ?? '' ), '77', 'it carries the agent line id' );
hashy_smoke_is( (string) ( $row['component_sku'] ?? '' ), 'ATLAS-SLATE-BS-500', 'it names the shaft' );
hashy_smoke_is( (string) ( $row['recipe_hash'] ?? '' ), $fixture['hash'], 'it carries the set hash' );
hashy_smoke_is( count( hashy_smoke_pushes_for( 'ATLAS-SLATE-BS-500' ) ), 3, 'the shaft was pushed to three agents' );
hashy_smoke_is( count( hashy_smoke_pushes_for( 'ATLAS-SLATE-FA-500' ) ), 3, 'the derived arrow was pushed to three agents' );
$p = hashy_smoke_pushes_for( 'ATLAS-SLATE-FA-500' );
hashy_smoke_is( $p[0]['qty'] ?? null, 19, 'the arrow push carries 19' );

/* ------------------------------------------------------------- old agent */
hashy_smoke_group( 'old agent' );
list( $status, $data ) = hashy_smoke_agent_call(
	$aba,
	'agent/order-paid',
	'rest_agent_order_paid',
	array(
		'agent_url' => $aba['url'],
		'order_id'  => 1002,
		'items'     => array(
			array(
				'sku' => 'ABA_ATLAS-SLATE-FA-600',
				'qty' => 2,
			),
		),
		'ts'        => time(),
	)
);
hashy_smoke_is( $status, 200, 'a 0.6.x payload (sku and qty only) is accepted' );
hashy_smoke_is( hashy_smoke_stock( 'ATLAS-SLATE-BS-600' ), 18, 'the 600 shaft went 20 to 18 from the variation attribute' );
hashy_smoke_is( hashy_smoke_stock( 'ATLAS-SLATE-FA-600' ), 18, 'the 600 arrow is derived to 18' );
$row = hashy_smoke_ledger_row( $aba['url'], 1002, 'r1-atlas-slate' );
hashy_smoke_is( (string) ( $row['line_id'] ?? '' ), 'i0', 'the line id falls back to the item index' );
hashy_smoke_is( (int) ( $row['qty'] ?? 0 ), 2, 'two units consumed' );

/* ------------------------------------------------------------ unresolved */
hashy_smoke_group( 'unresolved' );
list( $status, $data ) = hashy_smoke_agent_call(
	$aba,
	'agent/order-paid',
	'rest_agent_order_paid',
	array(
		'agent_url' => $aba['url'],
		'order_id'  => 1003,
		'items'     => array(
			array(
				'sku'        => 'ABA_17GB4-01',
				'qty'        => 1,
				'line_id'    => 5,
				'attributes' => array(
					'arrow-spine-wood' => 'match-my-arrows-to-my-new-bow',
					'arrowheads'       => 'longobarda',
				),
			),
		),
		'ts'        => time(),
	)
);
hashy_smoke_is( $status, 200, 'an order with an unresolvable spine is accepted' );
$row = hashy_smoke_ledger_row( $aba['url'], 1003, 'r2-wood-shafts' );
hashy_smoke_is( (string) ( $row['matched'] ?? '' ), 'unresolved', 'the shaft row is unresolved' );
hashy_smoke_is( (string) ( $row['reason'] ?? '' ), 'no_match', 'with reason no_match' );
hashy_smoke_ok( false !== strpos( (string) ( $row['wanted'] ?? '' ), 'match-my-arrows' ), 'and it names what it wanted' );
hashy_smoke_is( (int) ( $row['applied'] ?? 1 ), 0, 'nothing applied for it' );
$row = hashy_smoke_ledger_row( $aba['url'], 1003, 'r3-wood-tips' );
hashy_smoke_is( (string) ( $row['matched'] ?? '' ), 'value_map', 'the tip row matched through the value map' );
hashy_smoke_is( (string) ( $row['component_sku'] ?? '' ), 'PRM_16355-02', 'Longobarda 5/16 at the default 100 grains' );
hashy_smoke_is( hashy_smoke_stock( 'PRM_16355-02' ), 9, 'the tip pack went 10 to 9' );
hashy_smoke_is( hashy_smoke_stock( 'ICW95-2' ), 30, 'no shaft moved' );
hashy_smoke_is( Hashy_AU_Ledger::count_unresolved(), 1, 'one unresolved row is outstanding' );

/* ---------------------------------------------------------------- shadow */
hashy_smoke_group( 'shadow' );
list( $status, $data ) = hashy_smoke_agent_call(
	$aba,
	'agent/order-paid',
	'rest_agent_order_paid',
	array(
		'agent_url' => $aba['url'],
		'order_id'  => 1004,
		'items'     => array(
			array(
				'sku'        => 'ABA_17GB4-01',
				'qty'        => 1,
				'line_id'    => 6,
				'attributes' => array(
					'arrow-spine-wood' => '30-35lb',
					'arrowheads'       => '',
				),
			),
		),
		'ts'        => time(),
	)
);
hashy_smoke_is( $status, 200, 'a wood arrow sale is accepted' );
$row = hashy_smoke_ledger_row( $aba['url'], 1004, 'r2-wood-shafts' );
hashy_smoke_is( (string) ( $row['matched'] ?? '' ), 'match', 'the shadow shaft row matched' );
hashy_smoke_is( (string) ( $row['mode'] ?? '' ), 'shadow', 'in shadow' );
hashy_smoke_is( (int) ( $row['qty'] ?? 0 ), 12, 'it would have taken 12 shafts' );
hashy_smoke_is( (int) ( $row['applied'] ?? 1 ), 0, 'and moved nothing' );
hashy_smoke_is( hashy_smoke_stock( 'ICW95-2' ), 30, 'ICW95-2 is untouched by a shadow row' );
$row = hashy_smoke_ledger_row( $aba['url'], 1004, 'r3-wood-tips' );
hashy_smoke_is( (string) ( $row['matched'] ?? '' ), 'default', 'no tip chosen takes the default parent' );
hashy_smoke_is( hashy_smoke_stock( 'PRM_66265-02' ), 39, 'Brass 5/16 100 went 40 to 39' );

/* ---------------------------------------------------------------- dedupe */
hashy_smoke_group( 'dedupe' );
list( $status, $data ) = hashy_smoke_agent_call(
	$aba,
	'agent/order-paid',
	'rest_agent_order_paid',
	array(
		'agent_url' => $aba['url'],
		'order_id'  => 1001,
		'items'     => array(
			array(
				'sku'        => 'ABA_ATLAS-SLATE-FA-500',
				'qty'        => 1,
				'line_id'    => 77,
				'attributes' => array( 'pa_spine' => '500' ),
			),
		),
		'ts'        => time(),
	)
);
hashy_smoke_ok( 200 === $status && ! empty( $data['deduped'] ), 'a re-sent order-paid is deduped' );
hashy_smoke_is( hashy_smoke_stock( 'ATLAS-SLATE-BS-500' ), 19, 'the shaft did not move again' );

/* ---------------------------------------------------------- hub checkout */
hashy_smoke_group( 'hub checkout' );
hashy_smoke_clear_pushes();
// The host coalesces pushes per product per REQUEST, and this whole smoke is
// one request, so forget what the earlier groups pushed: a real checkout
// starts with an empty set.
$hashy_smoke_pushed = new ReflectionProperty( 'Hashy_AU_Host', 'pushed_ids' );
$hashy_smoke_pushed->setAccessible( true );
$hashy_smoke_pushed->setValue( null, array() );
$fa700 = wc_get_product( hashy_smoke_id( 'ATLAS-SLATE-FA-700' ) );
$order = wc_create_order();
$order->add_product( $fa700, 1 );
$order->set_address( array( 'first_name' => 'Smoke', 'last_name' => 'Test', 'email' => 'smoke@example.test', 'country' => 'AU' ), 'billing' );
$order->calculate_totals();
$order->save();
$order->payment_complete( 'smoke-txn-1' );
$hub_order_id = (int) $order->get_id();
$hub_item_id  = 0;
foreach ( $order->get_items() as $item ) {
	$hub_item_id = (int) $item->get_id();
}
hashy_smoke_is( hashy_smoke_stock( 'ATLAS-SLATE-BS-700' ), 19, 'a hub checkout took one 700 shaft' );
hashy_smoke_is( hashy_smoke_stock( 'ATLAS-SLATE-FA-700' ), 19, 'the 700 arrow reads 19' );
$row = hashy_smoke_ledger_row( $hub, $hub_order_id, 'r1-atlas-slate' );
hashy_smoke_ok( is_array( $row ), 'a ledger row was written for the hub order' );
hashy_smoke_is( (string) ( $row['line_id'] ?? '' ), (string) $hub_item_id, 'it carries the hub line id' );
hashy_smoke_is( (int) ( $row['applied'] ?? 0 ), 1, 'it is applied' );
$fresh_item = new WC_Order_Item_Product( $hub_item_id );
hashy_smoke_ok( '' !== (string) $fresh_item->get_meta( Hashy_AU_Recipes::LEDGER_ITEM_META, true ), 'the line carries the ledger guard meta' );
// Firing the reduce hook again must not apply twice.
Hashy_AU_Recipes::instance()->on_hub_order_reduced( $order );
hashy_smoke_is( hashy_smoke_stock( 'ATLAS-SLATE-BS-700' ), 19, 'a second reduce pass applies nothing' );
hashy_smoke_ok( count( hashy_smoke_pushes_for( 'ATLAS-SLATE-BS-700' ) ) >= 3, 'the shaft was pushed on the hub checkout' );
$p700 = hashy_smoke_pushes_for( 'ATLAS-SLATE-FA-700' );
hashy_smoke_ok( count( $p700 ) >= 3 && 19 === end( $p700 )['qty'], 'the arrow reached the agents at 19' );

/* --------------------------------------------------------- agent restore */
hashy_smoke_group( 'agent restore' );
hashy_smoke_clear_pushes();
list( $status, $data ) = hashy_smoke_agent_call(
	$aba,
	'agent/order-restored',
	'rest_agent_order_restored',
	array(
		'agent_url' => $aba['url'],
		'order_id'  => 1001,
		'event'     => 'cancelled',
		'refund_id' => 0,
		'items'     => array(
			array(
				'sku'     => 'ABA_ATLAS-SLATE-FA-500',
				'qty'     => 1,
				'line_id' => 77,
			),
		),
		'ts'        => time(),
	)
);
hashy_smoke_is( $status, 200, 'a cancel from asiaticbows is accepted' );
hashy_smoke_is( hashy_smoke_stock( 'ATLAS-SLATE-BS-500' ), 20, 'the 500 shaft is back to 20' );
hashy_smoke_is( hashy_smoke_stock( 'ATLAS-SLATE-FA-500' ), 20, 'the 500 arrow is derived back to 20, not double-restored' );
$row = hashy_smoke_ledger_row( $aba['url'], 1001, 'r1-atlas-slate' );
hashy_smoke_is( (int) ( $row['reversed_qty'] ?? 0 ), 1, 'the ledger row shows one unit reversed' );
hashy_smoke_ok( ! empty( $row['reversed_at'] ), 'with a reversed_at stamp' );
hashy_smoke_is( count( hashy_smoke_pushes_for( 'ATLAS-SLATE-FA-500' ) ), 3, 'the restored arrow was pushed' );
list( $status, $data ) = hashy_smoke_agent_call(
	$aba,
	'agent/order-restored',
	'rest_agent_order_restored',
	array(
		'agent_url' => $aba['url'],
		'order_id'  => 1001,
		'event'     => 'cancelled',
		'refund_id' => 0,
		'items'     => array(
			array(
				'sku'     => 'ABA_ATLAS-SLATE-FA-500',
				'qty'     => 1,
				'line_id' => 77,
			),
		),
		'ts'        => time(),
	)
);
hashy_smoke_ok( 200 === $status && ! empty( $data['deduped'] ), 'a re-sent cancel is deduped' );
hashy_smoke_is( hashy_smoke_stock( 'ATLAS-SLATE-BS-500' ), 20, 'and moves nothing' );
// A partial refund on the old-agent order (2 units) gives back one, then the other, then nothing.
foreach ( array( array( 'refund:9', 19 ), array( 'refund:10', 20 ), array( 'refund:11', 20 ) ) as $step ) {
	list( $key, $expect ) = $step;
	list( $status, $data ) = hashy_smoke_agent_call(
		$aba,
		'agent/order-restored',
		'rest_agent_order_restored',
		array(
			'agent_url' => $aba['url'],
			'order_id'  => 1002,
			'event'     => 'refunded',
			'refund_id' => (int) substr( $key, 7 ),
			'items'     => array(
				array(
					'sku'     => 'ABA_ATLAS-SLATE-FA-600',
					'qty'     => 1,
					'line_id' => 'i0',
				),
			),
			'ts'        => time(),
		)
	);
	hashy_smoke_is( hashy_smoke_stock( 'ATLAS-SLATE-BS-600' ), $expect, 'after ' . $key . ' the 600 shaft reads ' . $expect );
}
$bad_sig = new WP_REST_Request( 'POST', '/hashy-sync/v1/agent/order-restored' );
$bad_sig->set_header( 'X-Hashy-Timestamp', (string) time() );
$bad_sig->set_header( 'X-Hashy-Signature', 'nope' );
$bad_sig->set_body( wp_json_encode( array( 'agent_url' => $aba['url'], 'order_id' => 1001, 'event' => 'cancelled', 'items' => array( array( 'sku' => 'X', 'qty' => 1 ) ) ) ) );
hashy_smoke_is( Hashy_AU_Host::instance()->rest_agent_order_restored( $bad_sig )->get_status(), 403, 'a bad signature on order-restored is a 403' );

/* ------------------------------------------------------------ hub refund */
hashy_smoke_group( 'hub refund' );
$refund = wc_create_refund(
	array(
		'order_id'      => $hub_order_id,
		'amount'        => 1,
		'line_items'    => array(
			$hub_item_id => array(
				'qty'          => 1,
				'refund_total' => 1,
			),
		),
		'restock_items' => true,
	)
);
hashy_smoke_ok( ! is_wp_error( $refund ), 'a hub refund with restock was created' );
hashy_smoke_is( hashy_smoke_stock( 'ATLAS-SLATE-BS-700' ), 20, 'the 700 shaft is back to 20' );
hashy_smoke_is( hashy_smoke_stock( 'ATLAS-SLATE-FA-700' ), 20, 'the 700 arrow is derived back to 20' );
$row = hashy_smoke_ledger_row( $hub, $hub_order_id, 'r1-atlas-slate' );
hashy_smoke_is( (int) ( $row['reversed_qty'] ?? 0 ), 1, 'the hub ledger row shows one unit reversed' );

/* -------------------------------------------------------- connector path */
hashy_smoke_group( 'connector path' );
hashy_smoke_clear_pushes();
$bs800 = wc_get_product( hashy_smoke_id( 'ATLAS-SLATE-BS-800' ) );
wc_update_product_stock( $bs800, 5, 'set' ); // What the connector's adjust-stock does, pushes not suppressed.
hashy_smoke_is( hashy_smoke_stock( 'ATLAS-SLATE-FA-800' ), 5, 'a count write on the 800 shaft derives the arrow to 5' );
hashy_smoke_is( count( hashy_smoke_pushes_for( 'ATLAS-SLATE-BS-800' ) ), 3, 'the shaft was pushed by the host hook' );
$p800 = hashy_smoke_pushes_for( 'ATLAS-SLATE-FA-800' );
hashy_smoke_is( count( $p800 ), 3, 'the derived arrow was pushed exactly once per agent' );
hashy_smoke_is( $p800[0]['qty'] ?? null, 5, 'carrying 5' );
wc_update_product_stock( $bs800, 0, 'set' );
hashy_smoke_is( hashy_smoke_stock( 'ATLAS-SLATE-FA-800' ), 0, 'a shaft pool at zero takes the arrow to zero at once (D-36.2)' );
$fa800 = wc_get_product( hashy_smoke_id( 'ATLAS-SLATE-FA-800' ) );
hashy_smoke_is( $fa800->get_stock_status(), 'outofstock', 'and out of stock' );
wc_update_product_stock( $bs800, 20, 'set' );
hashy_smoke_is( hashy_smoke_stock( 'ATLAS-SLATE-FA-800' ), 20, 'and back to 20 when the shafts return' );

/* -------------------------------------------------------- stocktake path */
hashy_smoke_group( 'stocktake path' );
hashy_smoke_clear_pushes();
Hashy_AU_Host::suppress_pushes( true );
wc_update_product_stock( wc_get_product( hashy_smoke_id( 'ATLAS-SLATE-BS-400' ) ), 7, 'set' );
Hashy_AU_Host::suppress_pushes( false );
$touched = Hashy_AU_Recipes::instance()->drain_touched();
hashy_smoke_is( hashy_smoke_stock( 'ATLAS-SLATE-FA-400' ), 7, 'under suppression the 400 arrow still derives to 7' );
hashy_smoke_ok( in_array( hashy_smoke_id( 'ATLAS-SLATE-FA-400' ), $touched, true ), 'and its id waits in drain_touched() for the caller to push' );
hashy_smoke_is( count( hashy_smoke_pushes_for( 'ATLAS-SLATE-FA-400' ) ), 0, 'nothing was pushed while suppressed' );
hashy_smoke_is( Hashy_AU_Recipes::instance()->drain_touched(), array(), 'draining clears the list' );

/* ------------------------------------------------------------- api reads */
hashy_smoke_group( 'api reads' );
$page = Hashy_AU_Recipes_API::ledger_since( 0, 500 );
hashy_smoke_ok( count( $page['rows'] ) >= 7, 'the ledger holds the rows written above (' . count( $page['rows'] ) . ')' );
hashy_smoke_is( $page['has_more'], false, 'one page holds them all' );
hashy_smoke_ok( $page['next_id'] === (int) end( $page['rows'] )['id'], 'next_id is the last id' );
$first = Hashy_AU_Recipes_API::ledger_since( 0, 2 );
hashy_smoke_is( count( $first['rows'] ), 2, 'a limit of 2 answers 2 rows' );
hashy_smoke_is( $first['has_more'], true, 'and says there are more' );
$second = Hashy_AU_Recipes_API::ledger_since( $first['next_id'], 500 );
hashy_smoke_is( count( $second['rows'] ), count( $page['rows'] ) - 2, 'the next page starts after the cursor' );
hashy_smoke_ok( is_array( $page['rows'][0]['wanted'] ), 'wanted is decoded to a map' );
hashy_smoke_is( Hashy_AU_Recipes_API::recipes()['plugin_version'], '0.7.0', 'reads carry the plugin version' );

/* ----------------------------------------------------------- acknowledge */
hashy_smoke_group( 'acknowledge' );
$unresolved = Hashy_AU_Ledger::recent( 10, true );
hashy_smoke_is( count( $unresolved ), 1, 'one unresolved row lists' );
hashy_smoke_ok( Hashy_AU_Recipes_API::acknowledge( (int) $unresolved[0]['id'], 1 ), 'it can be acknowledged' );
hashy_smoke_is( Hashy_AU_Ledger::count_unresolved(), 0, 'and no longer counts as outstanding' );
hashy_smoke_is( Hashy_AU_Ledger::prune(), 0, 'pruning removes nothing this young' );

/* --------------------------------------------------------------- uninstall */
hashy_smoke_group( 'uninstall' );
$uninstall = (string) file_get_contents( WC_STOCK_SYNC_PLUGIN_DIR . 'uninstall.php' );
hashy_smoke_ok( false !== strpos( $uninstall, 'hashy_component_ledger' ), 'uninstall drops the ledger table' );
hashy_smoke_ok( false !== strpos( $uninstall, 'hashy_au_recipes' ), 'uninstall removes the recipe option' );

/* --------------------------------------------------------------------- log */
hashy_smoke_group( 'log' );
$logs  = Hashy_AU_Logger::instance()->get_logs( 500 );
$found = false;
foreach ( $logs as $l ) {
	if ( 'Recipe set stored' === (string) ( $l['message'] ?? '' ) ) {
		$found = true;
	}
}
hashy_smoke_ok( $found, 'the log records the recipe write' );
$debug = ABSPATH . 'wp-content/debug.log';
$fatal = file_exists( $debug ) && false !== strpos( (string) file_get_contents( $debug ), 'PHP Fatal' );
hashy_smoke_ok( ! $fatal, 'no PHP fatal in debug.log' );
$hashy_notices = file_exists( $debug ) ? preg_match_all( '/hashy-stock-sync\/includes\/class-hashy-au-(recipe|ledger)/', (string) file_get_contents( $debug ) ) : 0;
hashy_smoke_is( (int) $hashy_notices, 0, 'no PHP notice names a recipe file' );

hashy_smoke_finish();
