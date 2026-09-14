<?php
/**
 * Fixture store for a Playground run of tests/playground/smoke-recipes.php.
 *
 * One install in HOST mode standing in for the hub, with three agent rows
 * (asiaticbows.test, traditional-archery.test, traditionalarrows.test) whose
 * secrets are known to the smoke script, and the Atlas-shaped catalogue the
 * shared resolver fixture describes: a fletched arrow parent and a bare
 * shaft parent with five spine variations each, a wood arrow parent whose
 * spine is an "Any" attribute, wood shafts by spine, two tip parents varied
 * by diameter and grains, and two simple products.
 *
 * Not loaded by the plugin. Only ever called from blueprint-recipes.json.
 * Safe to run twice: products are found by SKU and updated.
 *
 * @package Hashy_AU
 */

defined( 'ABSPATH' ) || exit;

/**
 * The agent rows and their secrets, shared with the smoke script.
 *
 * @return array<string, array{id: string, name: string, url: string, secret: string}>
 */
function hashy_recipes_fixture_agents(): array {
	return array(
		'aba' => array(
			'id'     => 'agent_aba',
			'name'   => 'Asiatic Bows',
			'url'    => 'https://asiaticbows.test',
			'secret' => 'smoke-secret-aba-0123456789abcdef0123456789abcdef',
		),
		'tra' => array(
			'id'     => 'agent_tra',
			'name'   => 'Traditional Archery',
			'url'    => 'https://traditional-archery.test',
			'secret' => 'smoke-secret-tra-0123456789abcdef0123456789abcdef',
		),
		'taa' => array(
			'id'     => 'agent_taa',
			'name'   => 'Traditional Arrows',
			'url'    => 'https://traditionalarrows.test',
			'secret' => 'smoke-secret-taa-0123456789abcdef0123456789abcdef',
		),
	);
}

/**
 * Create or update a variable product with one or two variation attributes.
 *
 * @param string $sku        Parent SKU.
 * @param string $name       Name.
 * @param array  $attributes Attribute name => options (variation attributes; an empty option list means "Any").
 * @param array  $variations [sku, attrs (name => value), stock|null, price].
 * @return int Parent id.
 */
function hashy_recipes_fixture_variable( string $sku, string $name, array $attributes, array $variations ): int {
	$parent_id = wc_get_product_id_by_sku( $sku );
	$parent    = $parent_id ? wc_get_product( $parent_id ) : null;
	if ( ! $parent instanceof WC_Product_Variable ) {
		$parent = new WC_Product_Variable();
	}
	$attr_objects = array();
	foreach ( $attributes as $attr_name => $options ) {
		$a = new WC_Product_Attribute();
		$a->set_name( $attr_name );
		$a->set_options( $options );
		$a->set_visible( true );
		$a->set_variation( true );
		$attr_objects[] = $a;
	}
	$parent->set_name( $name );
	$parent->set_sku( $sku );
	$parent->set_status( 'publish' );
	$parent->set_catalog_visibility( 'visible' );
	$parent->set_attributes( $attr_objects );
	$parent->set_manage_stock( false );
	$parent->save();

	foreach ( $variations as $spec ) {
		list( $vsku, $attrs, $stock, $price ) = $spec;
		$existing                             = wc_get_product_id_by_sku( $vsku );
		$variation                            = $existing ? wc_get_product( $existing ) : null;
		if ( ! $variation instanceof WC_Product_Variation ) {
			$variation = new WC_Product_Variation();
		}
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_sku( $vsku );
		$variation->set_regular_price( $price );
		$variation->set_status( 'publish' );
		$keyed = array();
		foreach ( $attrs as $attr_name => $value ) {
			$keyed[ sanitize_title( $attr_name ) ] = $value;
		}
		$variation->set_attributes( $keyed );
		if ( null === $stock ) {
			$variation->set_manage_stock( false );
			$variation->set_stock_status( 'instock' );
		} else {
			$variation->set_manage_stock( true );
			$variation->set_stock_quantity( (int) $stock );
			$variation->set_stock_status( $stock > 0 ? 'instock' : 'outofstock' );
		}
		$variation->save();
		printf( "variation %d %s stock %s\n", (int) $variation->get_id(), esc_html( $vsku ), esc_html( (string) $stock ) );
	}
	printf( "variable %d %s\n", (int) $parent->get_id(), esc_html( $sku ) );
	return (int) $parent->get_id();
}

/**
 * Build the fixture store.
 *
 * @return void
 */
function hashy_recipes_install_fixtures() {
	if ( ! function_exists( 'wc_get_product' ) ) {
		echo "WooCommerce is not active.\n";
		return;
	}

	// Host mode with three agents. The Settings API sanitiser routes the
	// secrets into the sealed store, exactly as a settings save would.
	$agents_in = array();
	foreach ( hashy_recipes_fixture_agents() as $a ) {
		$agents_in[] = array(
			'id'            => $a['id'],
			'name'          => $a['name'],
			'url'           => $a['url'],
			'shared_secret' => $a['secret'],
			'price_pct'     => '',
			'sync_prices'   => 'no',
		);
	}
	$clean = Hashy_AU_Settings::instance()->sanitize_settings(
		array(
			'mode'           => 'host',
			'normalize_skus' => 'yes',
			'host'           => array( 'agents' => $agents_in ),
		)
	);
	update_option( 'hashy_au_settings', $clean, false );
	echo "settings: host mode with " . count( $agents_in ) . " agents\n";

	$spines = array( '400', '500', '600', '700', '800' );

	// Atlas Slate: fletched arrows (derived) and bare shafts (the stock).
	$fa = array();
	$bs = array();
	foreach ( $spines as $s ) {
		$fa[] = array( 'ATLAS-SLATE-FA-' . $s, array( 'Arrow Spine (Carbon)' => $s ), 3, '175' ); // Wrong on purpose: the first recipe write derives it to 20.
		$bs[] = array( 'ATLAS-SLATE-BS-' . $s, array( 'Arrow Spine (Carbon)' => $s ), 20, '85' );
	}
	hashy_recipes_fixture_variable( 'ATLAS-SLATE-FA', 'Solkarra Atlas Slate Fletched Carbon Arrows (x12)', array( 'Arrow Spine (Carbon)' => $spines ), $fa );
	hashy_recipes_fixture_variable( 'ATLAS-SLATE-BS', 'Solkarra Atlas Slate Carbon Bare Shafts (x12)', array( 'Arrow Spine (Carbon)' => $spines ), $bs );

	// Wood arrows: spine and arrowheads chosen at checkout ("Any").
	hashy_recipes_fixture_variable(
		'17GB4',
		'Traditional Wooden Arrows: 12PK',
		array(
			'Arrow Spine (Wood)' => array( '30-35lb', '35-40lb', '40-45lb', '45-50lb' ),
			'Arrowheads'         => array( 'Longobarda', 'Brass Bullets' ),
		),
		array(
			array( '17GB4-01', array( 'Arrow Spine (Wood)' => '', 'Arrowheads' => '' ), 50, '199' ),
		)
	);
	$wood = array();
	foreach ( array( '1' => '30lb', '2' => '30-35lb-2', '3' => '35-40lb-2', '4' => '40-45lb-2', '5' => '45-50lb-2' ) as $n => $spine ) {
		$wood[] = array( 'ICW95-' . $n, array( 'Spine' => $spine ), 30, '5' );
	}
	hashy_recipes_fixture_variable( 'ICW95', 'Birch Shafts', array( 'Spine' => array( '30lb', '30-35lb-2', '35-40lb-2', '40-45lb-2', '45-50lb-2' ) ), $wood );

	// Tips: two parents varied by diameter and grains.
	hashy_recipes_fixture_variable(
		'PRM_16355',
		'Steel Screw Arrow Point "Longobarda" (x12)',
		array(
			'Diameter'   => array( '8mm-5-16', '9mm-11-32' ),
			'Tip Grains' => array( '85gn', '100gn' ),
		),
		array(
			array( 'PRM_16355-01', array( 'Diameter' => '8mm-5-16', 'Tip Grains' => '85gn' ), 10, '30' ),
			array( 'PRM_16355-02', array( 'Diameter' => '8mm-5-16', 'Tip Grains' => '100gn' ), 10, '30' ),
			array( 'PRM_16355-03', array( 'Diameter' => '9mm-11-32', 'Tip Grains' => '100gn' ), 10, '30' ),
		)
	);
	hashy_recipes_fixture_variable(
		'PRM_66265',
		'Brass Screw Points (x12)',
		array(
			'Diameter'   => array( '8mm-5-16', '9mm-11-32' ),
			'Tip Grains' => array( '80', '100', '125' ),
		),
		array(
			array( 'PRM_66265-01', array( 'Diameter' => '8mm-5-16', 'Tip Grains' => '80' ), 40, '20' ),
			array( 'PRM_66265-02', array( 'Diameter' => '8mm-5-16', 'Tip Grains' => '100' ), 40, '20' ),
			array( 'PRM_66265-03', array( 'Diameter' => '9mm-11-32', 'Tip Grains' => '100' ), 40, '20' ),
			array( 'PRM_66265-04', array( 'Diameter' => '9mm-11-32', 'Tip Grains' => '125' ), 40, '20' ),
		)
	);

	// Two simple products: an assembled one and a fixed component.
	foreach ( array( array( 'FLUFLU', 'Flu-Flu Arrows (x6)', 40, '60' ), array( 'NOCK-BP', 'Bearpaw Nocks', 100, '1' ), array( 'PLAIN', 'A plain product with no recipe', 9, '10' ) ) as $spec ) {
		list( $sku, $name, $stock, $price ) = $spec;
		$existing                           = wc_get_product_id_by_sku( $sku );
		$product                            = $existing ? wc_get_product( $existing ) : null;
		if ( ! $product instanceof WC_Product_Simple ) {
			$product = new WC_Product_Simple();
		}
		$product->set_name( $name );
		$product->set_sku( $sku );
		$product->set_regular_price( $price );
		$product->set_status( 'publish' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( $stock );
		$product->set_stock_status( 'instock' );
		$product->save();
		printf( "simple %d %s stock %d\n", (int) $product->get_id(), esc_html( $sku ), (int) $stock );
	}

	echo "fixtures installed\n";
}
