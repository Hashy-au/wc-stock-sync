<?php
/**
 * Command-line test of Hashy_AU_Recipe_Resolver over the shared fixture.
 *
 * Run from the plugin root:  php tests/test-recipe-resolver.php
 * Exits 0 when every check passes, 1 otherwise. No WordPress install is
 * needed: the resolver is pure and its file only declares the class.
 *
 * The fixture tests/fixtures/resolver-cases.json is shared with the
 * Solkarra Desktop App (packages/app_core/test/fixtures/resolver-cases.json);
 * both sides run every case and pin the same set hash (D-36.11). Print the
 * hash with `php tests/test-recipe-resolver.php --hash` when re-pinning.
 *
 * @package Hashy_AU
 */

define( 'ABSPATH', __DIR__ . '/' );

require_once dirname( __DIR__ ) . '/includes/class-hashy-au-recipe-resolver.php';

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

$wcss_fixture_path = __DIR__ . '/fixtures/resolver-cases.json';
$wcss_fixture      = json_decode( (string) file_get_contents( $wcss_fixture_path ), true );
if ( ! is_array( $wcss_fixture ) ) {
	echo 'FAIL cannot read ' . $wcss_fixture_path . PHP_EOL;
	exit( 1 );
}

$wcss_set = array(
	'aliases' => $wcss_fixture['aliases'],
	'recipes' => $wcss_fixture['recipes'],
);

if ( in_array( '--hash', $argv, true ) ) {
	echo Hashy_AU_Recipe_Resolver::hash( $wcss_set ) . PHP_EOL;
	echo Hashy_AU_Recipe_Resolver::canonical( $wcss_set ) . PHP_EOL;
	exit( 0 );
}

// 1. Value normalisation.
foreach ( $wcss_fixture['norm_values'] as $pair ) {
	$got = Hashy_AU_Recipe_Resolver::norm_value( (string) $pair[0] );
	wcss_check( $got === $pair[1], 'norm_value(' . json_encode( $pair[0] ) . ') = ' . json_encode( $pair[1] ) . ( $got === $pair[1] ? '' : ' (got ' . json_encode( $got ) . ')' ) );
}

// 2. Role lookup.
foreach ( $wcss_fixture['role_keys'] as $pair ) {
	$got = Hashy_AU_Recipe_Resolver::role_of_key( (string) $pair[0], $wcss_fixture['aliases'] );
	wcss_check( $got === $pair[1], 'role_of_key(' . json_encode( $pair[0] ) . ') = ' . json_encode( $pair[1] ) . ( $got === $pair[1] ? '' : ' (got ' . json_encode( $got ) . ')' ) );
}

// 3. The canonical hash: pinned, whitespace-blind, key-order-blind, case-folded on SKUs.
$wcss_hash = Hashy_AU_Recipe_Resolver::hash( $wcss_set );
wcss_check( 64 === strlen( $wcss_hash ) && ctype_xdigit( $wcss_hash ), 'the set hash is 64 hex characters' );
wcss_check( $wcss_hash === $wcss_fixture['hash'], 'the set hash is pinned to the fixture (' . $wcss_hash . ')' );

$wcss_shuffled = $wcss_set;
$wcss_shuffled['recipes'] = array_reverse( $wcss_shuffled['recipes'] );
foreach ( $wcss_shuffled['recipes'] as &$wcss_row ) {
	$wcss_row['assembled_sku'] = strtolower( $wcss_row['assembled_sku'] ) . ' ';
	$wcss_row['note']          = 'a note changes nothing';
	if ( is_array( $wcss_row['match'] ) ) {
		$wcss_row['match'] = array_reverse( $wcss_row['match'] );
	}
	$wcss_row = array_reverse( $wcss_row, true );
}
unset( $wcss_row );
$wcss_shuffled['aliases'] = array_reverse( $wcss_shuffled['aliases'], true );
wcss_check( Hashy_AU_Recipe_Resolver::hash( $wcss_shuffled ) === $wcss_hash, 'row order, key order, SKU case, trailing space and notes do not move the hash' );

$wcss_changed                          = $wcss_set;
$wcss_changed['recipes'][0]['qty_per'] = 2;
wcss_check( Hashy_AU_Recipe_Resolver::hash( $wcss_changed ) !== $wcss_hash, 'a changed qty_per moves the hash' );
$wcss_changed                       = $wcss_set;
$wcss_changed['recipes'][0]['mode'] = 'shadow';
wcss_check( Hashy_AU_Recipe_Resolver::hash( $wcss_changed ) !== $wcss_hash, 'a mode flip moves the hash' );

$wcss_canon = Hashy_AU_Recipe_Resolver::canonical( $wcss_set );
wcss_check( 1 !== preg_match( '/\s/', (string) preg_replace( '/"[^"]*"/', '""', $wcss_canon ) ), 'canonical JSON carries no whitespace outside values' );
wcss_check( false !== strpos( $wcss_canon, '"fixed":{}' ), 'an empty fixed map encodes as an object, not a list' );
wcss_check( false === strpos( $wcss_canon, '"note"' ), 'notes are not in the canonical form' );

// 4. Validation.
wcss_check( array() === Hashy_AU_Recipe_Resolver::validate( $wcss_set ), 'the fixture set validates clean' );
foreach ( $wcss_fixture['invalid_sets'] as $bad ) {
	$errors = Hashy_AU_Recipe_Resolver::validate( array( 'aliases' => array(), 'recipes' => $bad['recipes'] ) );
	$codes  = array_map(
		function ( $e ) {
			return $e['code'];
		},
		$errors
	);
	wcss_check( in_array( $bad['error'], $codes, true ), 'invalid set "' . $bad['name'] . '" reports ' . $bad['error'] . ( in_array( $bad['error'], $codes, true ) ? '' : ' (got ' . json_encode( $codes ) . ')' ) );
}

// 5. Resolution cases.
$wcss_index = Hashy_AU_Recipe_Resolver::index_catalogue( $wcss_fixture['catalogue'] );
$wcss_rows  = array();
foreach ( $wcss_fixture['catalogue'] as $row ) {
	$wcss_rows[ strtoupper( $row['sku'] ) ] = $row;
}

foreach ( $wcss_fixture['cases'] as $case ) {
	$sold    = $wcss_rows[ strtoupper( $case['assembled_sku'] ) ] ?? array(
		'sku'        => $case['assembled_sku'],
		'kind'       => 'simple',
		'parent_sku' => null,
		'attrs'      => array(),
	);
	$results = Hashy_AU_Recipe_Resolver::resolve_line( $wcss_set, $sold, (array) $case['line_attrs'], (int) $case['line_qty'], $wcss_index );
	$by_id   = array();
	foreach ( $results as $r ) {
		$by_id[ $r['recipe_id'] ] = $r;
	}
	wcss_check( count( $results ) === count( $case['expect'] ), 'case "' . $case['name'] . '": ' . count( $case['expect'] ) . ' result(s)' . ( count( $results ) === count( $case['expect'] ) ? '' : ' (got ' . count( $results ) . ')' ) );
	foreach ( $case['expect'] as $exp ) {
		$got = $by_id[ $exp['recipe_id'] ] ?? null;
		if ( null === $got ) {
			wcss_check( false, 'case "' . $case['name'] . '": row ' . $exp['recipe_id'] . ' resolved' );
			continue;
		}
		$ok = $got['matched'] === $exp['matched'];
		if ( isset( $exp['component_sku'] ) ) {
			$ok = $ok && $got['component_sku'] === $exp['component_sku'];
		}
		if ( isset( $exp['qty'] ) ) {
			$ok = $ok && (int) $got['qty'] === (int) $exp['qty'];
		}
		if ( isset( $exp['reason'] ) ) {
			$ok = $ok && $got['reason'] === $exp['reason'];
		}
		if ( isset( $exp['wanted'] ) ) {
			$ok = $ok && $got['wanted'] == $exp['wanted']; // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqualEqual -- {} against [] is one empty map.
		}
		wcss_check( $ok, 'case "' . $case['name'] . '": row ' . $exp['recipe_id'] . ' ' . json_encode( $exp ) . ( $ok ? '' : ' (got ' . json_encode( $got ) . ')' ) );
	}
}

// 6. Derived counts.
foreach ( $wcss_fixture['derived'] as $d ) {
	$sold = $wcss_rows[ strtoupper( $d['assembled_sku'] ) ];
	$got  = Hashy_AU_Recipe_Resolver::derived_count( $wcss_set, $sold, $wcss_index, $wcss_fixture['stocks'] );
	wcss_check( $got === $d['expect'], 'derived(' . $d['assembled_sku'] . ') = ' . json_encode( $d['expect'] ) . ( $got === $d['expect'] ? '' : ' (got ' . json_encode( $got ) . ')' ) );
}

// 7. Sellable rows: variations when there are any, else the product itself, never a variable parent.
wcss_check( 5 === count( Hashy_AU_Recipe_Resolver::sellable( $wcss_index['ATLAS-SLATE-BS'] ) ), 'a variable parent sells its five variations' );
wcss_check( 1 === count( Hashy_AU_Recipe_Resolver::sellable( $wcss_index['NOCK-BP'] ) ), 'a simple product sells itself' );
wcss_check( array() === Hashy_AU_Recipe_Resolver::sellable( array( array( 'sku' => 'X', 'kind' => 'variable' ) ) ), 'a variable parent alone sells nothing' );

echo PHP_EOL . ( 0 === $wcss_failures ? 'PASS' : 'FAIL' ) . ': ' . ( $wcss_checks - $wcss_failures ) . '/' . $wcss_checks . ' checks passed.' . PHP_EOL;
exit( 0 === $wcss_failures ? 0 : 1 );
