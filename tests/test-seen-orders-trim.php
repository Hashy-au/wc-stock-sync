<?php
/**
 * Regression test for Hashy_AU_Host::trim_seen_orders().
 *
 * Background (review finding M1, 2026-09-06): mark_order_seen() trimmed the
 * dedup map with array_slice($seen, -4000, true), which PHP reads as length 1.
 * After 5,000 agent orders the map collapsed to one entry (the 1,002nd oldest),
 * so a re-sent order-paid for any other order, the newest included, was no
 * longer recognised and its stock was decremented again.
 *
 * Run from the plugin root:  php tests/test-seen-orders-trim.php
 * Exits 0 when every check passes, 1 otherwise. No WordPress install is
 * needed: the Host class file only declares the class when it is loaded.
 *
 * @package Hashy_AU
 */

define( 'ABSPATH', __DIR__ . '/' );

require_once dirname( __DIR__ ) . '/includes/class-hashy-au-host.php';

$wcss_checks   = 0;
$wcss_failures = 0;

/**
 * Record one assertion.
 *
 * @param bool   $ok   Whether it passed.
 * @param string $what What was being checked.
 */
function wcss_check( bool $ok, string $what ): void {
	global $wcss_checks, $wcss_failures;
	++$wcss_checks;
	if ( ! $ok ) {
		++$wcss_failures;
	}
	echo ( $ok ? 'ok   ' : 'FAIL ' ) . $what . PHP_EOL;
}

$wcss_agent = 'https://agent.example.test';

// 1. Feed 5,001 orders through the same steps mark_order_seen() takes:
//    append, then trim. Timestamps rise with the order id, as time() would.
$wcss_seen = array();
for ( $i = 1; $i <= 5001; $i++ ) {
	$wcss_seen[ $wcss_agent . ':' . $i ] = 1000000 + $i;
	$wcss_seen                          = Hashy_AU_Host::trim_seen_orders( $wcss_seen );
}

wcss_check( 4000 === count( $wcss_seen ), 'after 5,001 orders the map holds 4,000 entries (got ' . count( $wcss_seen ) . ')' );
wcss_check( isset( $wcss_seen[ $wcss_agent . ':5001' ] ), 'the newest order is kept' );
wcss_check( isset( $wcss_seen[ $wcss_agent . ':1002' ] ), 'the oldest survivor is order 1002' );
wcss_check( ! isset( $wcss_seen[ $wcss_agent . ':1001' ] ), 'order 1001 was trimmed' );
wcss_check( ! isset( $wcss_seen[ $wcss_agent . ':1' ] ), 'order 1 was trimmed' );

$wcss_string_keys = array_filter( array_keys( $wcss_seen ), 'is_string' );
wcss_check( count( $wcss_string_keys ) === count( $wcss_seen ), 'every surviving key is still the agent_url:order_id string' );

// This is the exact lookup rest_agent_order_paid() makes for a replay.
wcss_check( ! empty( $wcss_seen[ $wcss_agent . ':4321' ] ), 'a replayed order-paid for a recent order is recognised' );

// 2. Under the limit nothing changes, including key order.
$wcss_small = array(
	$wcss_agent . ':1' => 50,
	$wcss_agent . ':2' => 30,
);
wcss_check( Hashy_AU_Host::trim_seen_orders( $wcss_small ) === $wcss_small, 'a map under the limit is returned untouched' );

// 3. Exactly $max entries is untouched; $max + 1 trims to $keep.
$wcss_edge = array();
for ( $i = 1; $i <= 5; $i++ ) {
	$wcss_edge[ 'k' . $i ] = $i;
}
wcss_check( Hashy_AU_Host::trim_seen_orders( $wcss_edge, 5, 3 ) === $wcss_edge, 'exactly $max entries is untouched' );
$wcss_edge['k6'] = 6;
$wcss_trimmed    = Hashy_AU_Host::trim_seen_orders( $wcss_edge, 5, 3 );
wcss_check( array( 'k4', 'k5', 'k6' ) === array_keys( $wcss_trimmed ), '$max + 1 entries trims to the $keep newest' );

// 4. "Newest" means newest by timestamp, not by position in the array.
$wcss_mixed = array();
for ( $i = 1; $i <= 6; $i++ ) {
	$wcss_mixed[ 'k' . $i ] = 100 - $i; // k1 is the newest, k6 the oldest.
}
$wcss_trimmed = Hashy_AU_Host::trim_seen_orders( $wcss_mixed, 5, 3 );
wcss_check( array( 'k3', 'k2', 'k1' ) === array_keys( $wcss_trimmed ), 'the trim keeps the newest timestamps regardless of insertion order' );

// 5. For the record: the 0.5.0 expression collapses the same map to one entry,
//    and it is not the newest order, so even a replay of order 5001 got through.
$wcss_old = array_slice( $wcss_seen, -4000, true ); // phpcs:ignore -- deliberately the buggy call.
wcss_check( 1 === count( $wcss_old ) && ! isset( $wcss_old[ $wcss_agent . ':5001' ] ), 'the 0.5.0 expression collapses the map to one entry that is not the newest order (documents the bug)' );

echo PHP_EOL . ( 0 === $wcss_failures ? 'PASS' : 'FAIL' ) . ': ' . ( $wcss_checks - $wcss_failures ) . '/' . $wcss_checks . ' checks passed.' . PHP_EOL;
exit( 0 === $wcss_failures ? 0 : 1 );
