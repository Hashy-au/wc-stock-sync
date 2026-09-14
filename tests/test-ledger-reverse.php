<?php
/**
 * Command-line test of Hashy_AU_Ledger::reverse_plan(), the arithmetic a
 * cancel or refund runs over a line's ledger rows.
 *
 * Run from the plugin root:  php tests/test-ledger-reverse.php
 * Exits 0 when every check passes, 1 otherwise. No WordPress install is
 * needed: the method is static and free of WordPress calls.
 *
 * @package Hashy_AU
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'WC_STOCK_SYNC_VERSION', 'test' );

require_once dirname( __DIR__ ) . '/includes/class-hashy-au-ledger.php';

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

/**
 * A ledger row for a dozen arrows (line_qty 2) consuming shafts at 12 each.
 *
 * @param array $over Overrides.
 * @return array
 */
function wcss_row( array $over = array() ): array {
	return array_merge(
		array(
			'id'                   => 1,
			'line_qty'             => 2,
			'qty_per'              => 12,
			'qty'                  => 24,
			'matched'              => 'match',
			'mode'                 => 'live',
			'applied'              => 1,
			'reversed_qty'         => 0,
			'component_product_id' => 501,
			'component_sku'        => 'ICW95-2',
		),
		$over
	);
}

// 1. A full cancel of two dozen gives back all 24 shafts.
$plan = Hashy_AU_Ledger::reverse_plan( array( wcss_row() ), 2 );
wcss_check( 1 === count( $plan ) && 24 === $plan[0]['units'] && true === $plan[0]['moves'], 'cancelling both dozens reverses 24 shafts and moves stock' );

// 2. A partial refund of one dozen gives back 12; a second partial gives back the other 12 and no more.
$plan = Hashy_AU_Ledger::reverse_plan( array( wcss_row() ), 1 );
wcss_check( 12 === $plan[0]['units'], 'refunding one dozen reverses 12' );
$plan = Hashy_AU_Ledger::reverse_plan( array( wcss_row( array( 'reversed_qty' => 12 ) ) ), 1 );
wcss_check( 12 === $plan[0]['units'], 'the second partial refund reverses the remaining 12' );
$plan = Hashy_AU_Ledger::reverse_plan( array( wcss_row( array( 'reversed_qty' => 24 ) ) ), 1 );
wcss_check( array() === $plan, 'a third refund reverses nothing: the row is fully reversed' );

// 3. Restoring more than was sold is capped at the row.
$plan = Hashy_AU_Ledger::reverse_plan( array( wcss_row() ), 5 );
wcss_check( 24 === $plan[0]['units'], 'restoring five dozen against a two-dozen line is capped at 24' );

// 4. A shadow row is marked reversed but moves nothing.
$plan = Hashy_AU_Ledger::reverse_plan( array( wcss_row( array( 'mode' => 'shadow', 'applied' => 0 ) ) ), 2 );
wcss_check( 24 === $plan[0]['units'] && false === $plan[0]['moves'], 'a shadow row nets its would-have-moved figure without moving stock' );

// 5. A live row that never applied (a fault between insert and decrement) moves nothing back.
$plan = Hashy_AU_Ledger::reverse_plan( array( wcss_row( array( 'applied' => 0 ) ) ), 2 );
wcss_check( false === $plan[0]['moves'], 'an unapplied live row does not restore stock it never took' );

// 6. An unresolved row has qty 0 and is skipped.
$plan = Hashy_AU_Ledger::reverse_plan( array( wcss_row( array( 'matched' => 'unresolved', 'qty' => 0, 'component_product_id' => 0 ) ) ), 2 );
wcss_check( array() === $plan, 'an unresolved row has nothing to reverse' );

// 7. Two rows on one line (shafts and tips) are each planned.
$plan = Hashy_AU_Ledger::reverse_plan(
	array(
		wcss_row(),
		wcss_row(
			array(
				'id'                   => 2,
				'qty_per'              => 1,
				'qty'                  => 2,
				'matched'              => 'value_map',
				'component_product_id' => 777,
				'component_sku'        => 'PRM_16355-02',
			)
		),
	),
	1
);
wcss_check( 2 === count( $plan ) && 12 === $plan[0]['units'] && 1 === $plan[1]['units'], 'shafts and tips on one line are each reversed by their own qty_per' );

// 8. Zero or negative restores plan nothing.
wcss_check( array() === Hashy_AU_Ledger::reverse_plan( array( wcss_row() ), 0 ), 'restoring zero plans nothing' );

echo PHP_EOL . ( 0 === $wcss_failures ? 'PASS' : 'FAIL' ) . ': ' . ( $wcss_checks - $wcss_failures ) . '/' . $wcss_checks . ' checks passed.' . PHP_EOL;
exit( 0 === $wcss_failures ? 0 : 1 );
