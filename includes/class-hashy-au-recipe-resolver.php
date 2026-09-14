<?php
/**
 * The component recipe resolver: which component variation an arrow line
 * consumes, and how much (design/26 in the Solkarra Desktop App, D-36.4).
 *
 * Pure. No WordPress calls, no database, no clock, so
 * tests/test-recipe-resolver.php runs it from the command line and the
 * Solkarra Desktop App's Dart resolver runs the same
 * tests/fixtures/resolver-cases.json (D-36.11). Anything that needs a
 * WC_Product lives in Hashy_AU_Recipes, which turns products into the plain
 * arrays this class reads.
 *
 * A recipe set is `{aliases: {role: [name...]}, recipes: [row...]}` and a
 * row is:
 *
 *   id, assembled_sku, component_sku|null, qty_per, match: [role...],
 *   fixed: {role: value}, value_map: null|{role, map: {value: parent_sku},
 *   default_parent}, default_component_variant: sku|null,
 *   default_attrs: {role: value}, derives: bool, mode: shadow|live, note
 *
 * Attribute NAMES are matched through roles and their aliases, because
 * every store names the same attribute differently ("Arrow Spine (Wood)",
 * "Spine", "pa_spine"). Attribute VALUES are compared normalised, because
 * "30-35lb-2" and "30-35" are one spine. Both were the reason the first
 * Atlas recipe on the box matched nothing on 2026-09-14.
 *
 * @package Hashy_AU
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Hashy_AU_Recipe_Resolver {

	public const MODES = array( 'shadow', 'live' );

	/**
	 * The aliases a set carries by default. A pushed set replaces them.
	 *
	 * @var array<string, string[]>
	 */
	public const DEFAULT_ALIASES = array(
		'spine'  => array( 'Arrow Spine (Wood)', 'Arrow Spine (Carbon)', 'Spine', 'spine', 'Arrow Spine', 'pa_spine' ),
		'tip'    => array( 'Arrowheads', 'Arrowhead', 'Wood Arrowtips' ),
		'grains' => array( 'Tip Grains' ),
	);

	/* ------------------------------------------------------ normalisation */

	/**
	 * One spelling for one attribute value.
	 *
	 * Lower-cased and trimmed; Woo's slug disambiguator ("30-35lb-2", the
	 * "-2" a second term with the same name gets) is dropped when it follows
	 * a letter or "#" and never when it follows a digit ("8mm-5-16" is a
	 * diameter, not a suffixed value); the unit words lb, lbs, #, gn, grain,
	 * grains are dropped where they follow a number; the word "spine" is
	 * dropped; whitespace goes. Hyphens between digits stay, so "30-35" is
	 * still a band.
	 *
	 * @param string $value Raw attribute value, slug or label.
	 * @return string
	 */
	public static function norm_value( string $value ): string {
		$v = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$v = strtolower( trim( $v ) );
		if ( '' === $v ) {
			return '';
		}
		$v = (string) preg_replace( '/(?<=[a-z#])-\d{1,2}$/', '', $v );
		$v = (string) preg_replace( '/(?<=\d)\s*(?:lbs|lb|grains|grain|gn|#)(?![a-z])/', '', $v );
		$v = (string) preg_replace( '/(?:^|(?<=[\s\-]))spine(?=$|[\s\-])/', '', $v );
		$v = (string) preg_replace( '/\s+/', '', $v );
		$v = trim( $v, '-' );
		return $v;
	}

	/**
	 * Lower-cased, trimmed attribute key with Woo's `attribute_` prefix
	 * dropped (order item meta carries it stripped already; cart keys do not).
	 *
	 * @param string $key Attribute key, label or slug.
	 * @return string
	 */
	public static function norm_key( string $key ): string {
		$k = strtolower( trim( $key ) );
		if ( 0 === strpos( $k, 'attribute_' ) ) {
			$k = substr( $k, 10 );
		}
		return $k;
	}

	/**
	 * A WordPress-free sanitize_title: lower-case, non-alphanumerics to one
	 * hyphen, trimmed. "Arrow Spine (Wood)" becomes "arrow-spine-wood",
	 * which is exactly the key Woo gives that custom attribute on a
	 * variation and on an order line.
	 *
	 * @param string $label Attribute label.
	 * @return string
	 */
	public static function slugify( string $label ): string {
		$s = strtolower( trim( $label ) );
		$s = (string) preg_replace( '/[^a-z0-9]+/', '-', $s );
		return trim( $s, '-' );
	}

	/**
	 * The role an attribute key belongs to, or null.
	 *
	 * A key matches a role when, lower-cased, it equals an alias; or it
	 * equals the alias slugified; or, with a leading `pa_` dropped, it
	 * equals the alias slugified (a global attribute's taxonomy key).
	 *
	 * @param string                  $key     Attribute key, label or slug.
	 * @param array<string, string[]> $aliases Role => aliases.
	 * @return string|null
	 */
	public static function role_of_key( string $key, array $aliases ): ?string {
		$k = self::norm_key( $key );
		if ( '' === $k ) {
			return null;
		}
		$k_bare = ( 0 === strpos( $k, 'pa_' ) ) ? substr( $k, 3 ) : $k;
		foreach ( $aliases as $role => $names ) {
			// The role name itself is always an alias, so a role with no
			// alias list (diameter, length) still finds an attribute of its
			// own name.
			foreach ( array_merge( array( (string) $role ), (array) $names ) as $name ) {
				$name = (string) $name;
				$al   = strtolower( trim( $name ) );
				if ( '' === $al ) {
					continue;
				}
				if ( $k === $al ) {
					return (string) $role;
				}
				$slug = self::slugify( $name );
				if ( '' !== $slug && ( $k === $slug || $k_bare === $slug ) ) {
					return (string) $role;
				}
				$al_bare = ( 0 === strpos( $al, 'pa_' ) ) ? substr( $al, 3 ) : $al;
				if ( '' !== $al_bare && $k_bare === $al_bare ) {
					return (string) $role;
				}
			}
		}
		return null;
	}

	/**
	 * The raw value an attribute map holds for a role, or null when no key
	 * of that role is present. An empty string is a present, empty value
	 * (an "Any" attribute nobody chose).
	 *
	 * @param array<string, mixed>    $attrs   Attribute key => value.
	 * @param string                  $role    Role.
	 * @param array<string, string[]> $aliases Role => aliases.
	 * @return string|null
	 */
	public static function attr_for_role( array $attrs, string $role, array $aliases ): ?string {
		foreach ( $attrs as $key => $value ) {
			if ( self::role_of_key( (string) $key, $aliases ) === $role ) {
				return is_scalar( $value ) ? (string) $value : '';
			}
		}
		return null;
	}

	/* --------------------------------------------------------- the set */

	/**
	 * The set with every field in its canonical shape: SKUs upper-cased and
	 * trimmed, roles lower-cased, values normalised, lists sorted, rows
	 * sorted by (assembled_sku, component_sku, value_map role). Notes are
	 * kept but do not enter the hash.
	 *
	 * @param array $set Raw set.
	 * @return array{aliases: array<string, string[]>, recipes: array<int, array>}
	 */
	public static function normalise_set( array $set ): array {
		$aliases_in = isset( $set['aliases'] ) && is_array( $set['aliases'] ) ? $set['aliases'] : array();
		$aliases    = array();
		foreach ( $aliases_in as $role => $names ) {
			$role = strtolower( trim( (string) $role ) );
			if ( '' === $role ) {
				continue;
			}
			$clean = array();
			foreach ( (array) $names as $name ) {
				$name = trim( (string) $name );
				if ( '' !== $name ) {
					$clean[ strtolower( $name ) ] = strtolower( $name );
				}
			}
			$clean = array_values( $clean );
			sort( $clean, SORT_STRING );
			$aliases[ $role ] = $clean;
		}
		ksort( $aliases, SORT_STRING );

		$rows_in = isset( $set['recipes'] ) && is_array( $set['recipes'] ) ? $set['recipes'] : array();
		$rows    = array();
		foreach ( $rows_in as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$rows[] = self::normalise_row( $row );
		}
		usort(
			$rows,
			function ( array $a, array $b ): int {
				$c = strcmp( $a['assembled_sku'], $b['assembled_sku'] );
				if ( 0 !== $c ) {
					return $c;
				}
				$c = strcmp( (string) $a['component_sku'], (string) $b['component_sku'] );
				if ( 0 !== $c ) {
					return $c;
				}
				$ra = is_array( $a['value_map'] ) ? (string) $a['value_map']['role'] : '';
				$rb = is_array( $b['value_map'] ) ? (string) $b['value_map']['role'] : '';
				$c  = strcmp( $ra, $rb );
				return 0 !== $c ? $c : strcmp( $a['id'], $b['id'] );
			}
		);

		return array(
			'aliases' => $aliases,
			'recipes' => $rows,
		);
	}

	/**
	 * One row in canonical shape.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	public static function normalise_row( array $row ): array {
		$sku = function ( $v ): ?string {
			$s = strtoupper( trim( (string) $v ) );
			return '' === $s ? null : $s;
		};

		$match = array();
		foreach ( (array) ( $row['match'] ?? array() ) as $role ) {
			$role = strtolower( trim( (string) $role ) );
			if ( '' !== $role ) {
				$match[ $role ] = $role;
			}
		}
		$match = array_values( $match );
		sort( $match, SORT_STRING );

		$fixed = array();
		foreach ( (array) ( $row['fixed'] ?? array() ) as $role => $value ) {
			$role = strtolower( trim( (string) $role ) );
			if ( '' !== $role ) {
				$fixed[ $role ] = self::norm_value( (string) $value );
			}
		}
		ksort( $fixed, SORT_STRING );

		$default_attrs = array();
		foreach ( (array) ( $row['default_attrs'] ?? array() ) as $role => $value ) {
			$role = strtolower( trim( (string) $role ) );
			if ( '' !== $role ) {
				$default_attrs[ $role ] = self::norm_value( (string) $value );
			}
		}
		ksort( $default_attrs, SORT_STRING );

		$value_map = null;
		if ( isset( $row['value_map'] ) && is_array( $row['value_map'] ) ) {
			$map = array();
			foreach ( (array) ( $row['value_map']['map'] ?? array() ) as $value => $parent ) {
				$key    = self::norm_value( (string) $value );
				$parent = $sku( $parent );
				if ( '' !== $key && null !== $parent ) {
					$map[ $key ] = $parent;
				}
			}
			ksort( $map, SORT_STRING );
			$value_map = array(
				'role'           => strtolower( trim( (string) ( $row['value_map']['role'] ?? '' ) ) ),
				'map'            => $map,
				'default_parent' => $sku( $row['value_map']['default_parent'] ?? null ),
			);
		}

		$mode = strtolower( trim( (string) ( $row['mode'] ?? 'shadow' ) ) );

		return array(
			'id'                        => trim( (string) ( $row['id'] ?? '' ) ),
			'assembled_sku'             => (string) $sku( $row['assembled_sku'] ?? '' ),
			'component_sku'             => $sku( $row['component_sku'] ?? null ),
			'qty_per'                   => (int) ( $row['qty_per'] ?? 0 ),
			'match'                     => $match,
			'fixed'                     => $fixed,
			'value_map'                 => $value_map,
			'default_component_variant' => $sku( $row['default_component_variant'] ?? null ),
			'default_attrs'             => $default_attrs,
			'derives'                   => ! empty( $row['derives'] ),
			'mode'                      => $mode,
			'note'                      => trim( (string) ( $row['note'] ?? '' ) ),
		);
	}

	/**
	 * Canonical JSON of a set: normalised, keys sorted at every level, no
	 * whitespace, slashes and unicode unescaped, notes dropped, every map
	 * (aliases, fixed, default_attrs, value_map.map) an object even when
	 * empty. The Dart side produces the same bytes.
	 *
	 * @param array $set Raw set.
	 * @return string
	 */
	public static function canonical( array $set ): string {
		$n    = self::normalise_set( $set );
		$rows = array();
		foreach ( $n['recipes'] as $row ) {
			$value_map = null;
			if ( is_array( $row['value_map'] ) ) {
				$value_map = array(
					'default_parent' => $row['value_map']['default_parent'],
					'map'            => (object) $row['value_map']['map'],
					'role'           => $row['value_map']['role'],
				);
			}
			// Keys in sorted order by construction.
			$rows[] = array(
				'assembled_sku'             => $row['assembled_sku'],
				'component_sku'             => $row['component_sku'],
				'default_attrs'             => (object) $row['default_attrs'],
				'default_component_variant' => $row['default_component_variant'],
				'derives'                   => $row['derives'],
				'fixed'                     => (object) $row['fixed'],
				'id'                        => $row['id'],
				'match'                     => $row['match'],
				'mode'                      => $row['mode'],
				'qty_per'                   => $row['qty_per'],
				'value_map'                 => $value_map,
			);
		}
		$doc = array(
			'aliases' => (object) $n['aliases'],
			'recipes' => $rows,
		);
		// json_encode, not wp_json_encode: this class is pure so the CLI test
		// and the Dart side can run the same bytes with no WordPress present.
		return (string) json_encode( $doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	/**
	 * sha256 over the canonical JSON.
	 *
	 * @param array $set Raw set.
	 * @return string
	 */
	public static function hash( array $set ): string {
		return hash( 'sha256', self::canonical( $set ) );
	}

	/**
	 * Validate a set. Returns an empty list when it is good, else one error
	 * per problem as `{code, id, message}`. Codes: self_reference, cycle,
	 * qty_per, mode, component, derives, role, assembled, too_many.
	 *
	 * @param array $set Raw set.
	 * @return array<int, array{code: string, id: string, message: string}>
	 */
	public static function validate( array $set ): array {
		$errors = array();
		$n      = self::normalise_set( $set );
		$rows   = $n['recipes'];

		if ( count( $rows ) > 64 ) {
			$errors[] = array(
				'code'    => 'too_many',
				'id'      => '',
				'message' => 'A set holds at most 64 recipe rows.',
			);
		}

		foreach ( $rows as $row ) {
			$id = $row['id'];
			if ( '' === $row['assembled_sku'] ) {
				$errors[] = array(
					'code'    => 'assembled',
					'id'      => $id,
					'message' => 'A row needs an assembled SKU.',
				);
			}
			if ( $row['qty_per'] < 1 || $row['qty_per'] > 100 ) {
				$errors[] = array(
					'code'    => 'qty_per',
					'id'      => $id,
					'message' => 'qty_per must be between 1 and 100.',
				);
			}
			if ( ! in_array( $row['mode'], self::MODES, true ) ) {
				$errors[] = array(
					'code'    => 'mode',
					'id'      => $id,
					'message' => 'mode must be shadow or live.',
				);
			}
			$has_component = null !== $row['component_sku'];
			$has_map       = is_array( $row['value_map'] );
			if ( ! $has_component && ! $has_map ) {
				$errors[] = array(
					'code'    => 'component',
					'id'      => $id,
					'message' => 'A row names a component SKU or a value map.',
				);
			}
			if ( $has_map ) {
				if ( '' === $row['value_map']['role'] || ! preg_match( '/^[a-z_]{1,32}$/', $row['value_map']['role'] ) ) {
					$errors[] = array(
						'code'    => 'role',
						'id'      => $id,
						'message' => 'A value map names a role.',
					);
				}
				if ( $row['derives'] ) {
					$errors[] = array(
						'code'    => 'derives',
						'id'      => $id,
						'message' => 'A value-mapped component never derives the assembled figure (a shared pool).',
					);
				}
				if ( empty( $row['value_map']['map'] ) && null === $row['value_map']['default_parent'] ) {
					$errors[] = array(
						'code'    => 'component',
						'id'      => $id,
						'message' => 'A value map needs at least one entry or a default parent.',
					);
				}
			}
			foreach ( $row['match'] as $role ) {
				if ( ! preg_match( '/^[a-z_]{1,32}$/', $role ) ) {
					$errors[] = array(
						'code'    => 'role',
						'id'      => $id,
						'message' => 'Roles are lower-case words: ' . $role,
					);
				}
			}
			if ( $has_component && $row['component_sku'] === $row['assembled_sku'] ) {
				$errors[] = array(
					'code'    => 'self_reference',
					'id'      => $id,
					'message' => 'A component may not be its own assembly.',
				);
			}
		}

		if ( self::has_cycle( $rows ) ) {
			$errors[] = array(
				'code'    => 'cycle',
				'id'      => '',
				'message' => 'The recipes form a cycle.',
			);
		}

		return $errors;
	}

	/**
	 * Whether the rows form a cycle (A consumes B consumes A), including
	 * through value-map parents. A cycle would make a derived figure recurse
	 * forever, and it is an easy mis-click.
	 *
	 * @param array<int, array> $rows Normalised rows.
	 * @return bool
	 */
	public static function has_cycle( array $rows ): bool {
		$edges = array();
		foreach ( $rows as $row ) {
			$from = (string) ( $row['assembled_sku'] ?? '' );
			if ( '' === $from ) {
				continue;
			}
			$targets = array();
			if ( ! empty( $row['component_sku'] ) ) {
				$targets[] = (string) $row['component_sku'];
			}
			if ( isset( $row['value_map'] ) && is_array( $row['value_map'] ) ) {
				foreach ( (array) ( $row['value_map']['map'] ?? array() ) as $parent ) {
					$targets[] = (string) $parent;
				}
				if ( ! empty( $row['value_map']['default_parent'] ) ) {
					$targets[] = (string) $row['value_map']['default_parent'];
				}
			}
			foreach ( $targets as $to ) {
				$edges[ $from ][ $to ] = true;
			}
		}
		foreach ( array_keys( $edges ) as $start ) {
			$seen  = array();
			$stack = array_keys( $edges[ $start ] );
			while ( ! empty( $stack ) ) {
				$node = (string) array_pop( $stack );
				if ( $node === $start ) {
					return true;
				}
				if ( isset( $seen[ $node ] ) ) {
					continue;
				}
				$seen[ $node ] = true;
				foreach ( array_keys( $edges[ $node ] ?? array() ) as $next ) {
					$stack[] = $next;
				}
			}
		}
		return false;
	}

	/* --------------------------------------------------------- resolving */

	/**
	 * The rows under a parent a customer can buy: its variations where it
	 * has any, else the product itself. A variable parent holds no stock and
	 * is never consumed.
	 *
	 * @param array<int, array> $group Catalogue rows of one parent (the parent and its variations).
	 * @return array<int, array>
	 */
	public static function sellable( array $group ): array {
		$variations = array();
		$others     = array();
		foreach ( $group as $row ) {
			$kind = (string) ( $row['kind'] ?? 'simple' );
			if ( 'variation' === $kind ) {
				$variations[] = $row;
			} elseif ( 'variable' !== $kind ) {
				$others[] = $row;
			}
		}
		return ! empty( $variations ) ? $variations : $others;
	}

	/**
	 * Index a flat catalogue by parent SKU (upper-cased). A variation lands
	 * under its parent; a simple product is its own parent.
	 *
	 * @param array<int, array> $catalogue Rows `{sku, kind, parent_sku, attrs}`.
	 * @return array<string, array<int, array>>
	 */
	public static function index_catalogue( array $catalogue ): array {
		$by_parent = array();
		foreach ( $catalogue as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$sku    = strtoupper( trim( (string) ( $row['sku'] ?? '' ) ) );
			$parent = strtoupper( trim( (string) ( $row['parent_sku'] ?? '' ) ) );
			$kind   = (string) ( $row['kind'] ?? 'simple' );
			$key    = ( 'variation' === $kind && '' !== $parent ) ? $parent : $sku;
			if ( '' === $key ) {
				continue;
			}
			$row['sku']               = $sku;
			$row['parent_sku']        = '' === $parent ? null : $parent;
			$by_parent[ $key ][]      = $row;
		}
		return $by_parent;
	}

	/**
	 * Resolve every recipe row of one assembled product for one sold line.
	 *
	 * @param array                          $set                 Raw or normalised set.
	 * @param array                          $assembled_variation The sold row `{sku, kind, parent_sku, attrs}`.
	 * @param array<string, mixed>           $line_attrs          The order line's attributes (key => value), may be empty.
	 * @param int                            $line_qty            Units sold.
	 * @param array<string, array<int,array>> $by_parent           From index_catalogue().
	 * @return array<int, array> One result per recipe row of that parent (may be empty).
	 */
	public static function resolve_line( array $set, array $assembled_variation, array $line_attrs, int $line_qty, array $by_parent ): array {
		$n        = self::normalise_set( $set );
		$aliases  = ! empty( $n['aliases'] ) ? $n['aliases'] : self::DEFAULT_ALIASES;
		$sold_sku = strtoupper( trim( (string) ( $assembled_variation['sku'] ?? '' ) ) );
		$kind     = (string) ( $assembled_variation['kind'] ?? 'simple' );
		$parent   = strtoupper( trim( (string) ( $assembled_variation['parent_sku'] ?? '' ) ) );
		$parent   = ( 'variation' === $kind && '' !== $parent ) ? $parent : $sold_sku;

		$out = array();
		foreach ( $n['recipes'] as $row ) {
			if ( $row['assembled_sku'] !== $parent ) {
				continue;
			}
			$out[] = self::resolve_row( $row, $assembled_variation, $line_attrs, $line_qty, $by_parent, $aliases );
		}
		return $out;
	}

	/**
	 * Resolve one normalised recipe row for one sold line.
	 *
	 * Result: `{recipe_id, matched, reason, component_sku, component_parent,
	 * qty_per, qty, wanted, mode, derives}` where matched is one of match,
	 * default, value_map, unresolved, ambiguous; reason is set on the last
	 * two (no_match, missing_value, no_map, unknown_component, ambiguous).
	 *
	 * @param array                            $row                 Normalised row.
	 * @param array                            $assembled_variation The sold row.
	 * @param array<string, mixed>             $line_attrs          Line attributes.
	 * @param int                              $line_qty            Units sold.
	 * @param array<string, array<int, array>> $by_parent           Catalogue index.
	 * @param array<string, string[]>          $aliases             Role aliases.
	 * @return array
	 */
	public static function resolve_row( array $row, array $assembled_variation, array $line_attrs, int $line_qty, array $by_parent, array $aliases ): array {
		$variation_attrs = isset( $assembled_variation['attrs'] ) && is_array( $assembled_variation['attrs'] ) ? $assembled_variation['attrs'] : array();

		$base = array(
			'recipe_id'        => $row['id'],
			'matched'          => 'unresolved',
			'reason'           => null,
			'component_sku'    => null,
			'component_parent' => null,
			'qty_per'          => $row['qty_per'],
			'qty'              => 0,
			'wanted'           => array(),
			'mode'             => $row['mode'],
			'derives'          => $row['derives'],
		);

		// The value a role has for this line: the line's own attribute wins,
		// then the variation's, then the row's default for the role.
		$value_for = function ( string $role ) use ( $line_attrs, $variation_attrs, $row, $aliases ): ?string {
			$v = self::attr_for_role( $line_attrs, $role, $aliases );
			if ( null === $v || '' === self::norm_value( $v ) ) {
				$w = self::attr_for_role( $variation_attrs, $role, $aliases );
				if ( null !== $w && '' !== self::norm_value( $w ) ) {
					$v = $w;
				}
			}
			$normalised = null === $v ? '' : self::norm_value( $v );
			if ( '' === $normalised && isset( $row['default_attrs'][ $role ] ) ) {
				$normalised = $row['default_attrs'][ $role ];
			}
			return $normalised;
		};

		// 1. Which parent product is consumed.
		$matched_via = 'match';
		if ( is_array( $row['value_map'] ) ) {
			$role   = $row['value_map']['role'];
			$chosen = $value_for( $role );
			if ( '' === $chosen ) {
				$parent = $row['value_map']['default_parent'];
				if ( null === $parent ) {
					$base['reason'] = 'no_map';
					$base['wanted'] = array( $role => '' );
					return $base;
				}
				$matched_via = 'default';
			} elseif ( isset( $row['value_map']['map'][ $chosen ] ) ) {
				$parent      = $row['value_map']['map'][ $chosen ];
				$matched_via = 'value_map';
			} else {
				$base['reason'] = 'no_map';
				$base['wanted'] = array( $role => $chosen );
				return $base;
			}
		} else {
			$parent = (string) $row['component_sku'];
		}
		$base['component_parent'] = $parent;

		$group = $by_parent[ $parent ] ?? array();
		if ( empty( $group ) ) {
			$base['reason'] = 'unknown_component';
			return $base;
		}
		$candidates = self::sellable( $group );
		if ( empty( $candidates ) ) {
			$base['reason'] = 'unknown_component';
			return $base;
		}

		// 2. The values the component variation must agree on.
		$wanted  = array();
		$missing = array();
		foreach ( $row['match'] as $role ) {
			$v = $value_for( $role );
			if ( '' === $v ) {
				$missing[] = $role;
			} else {
				$wanted[ $role ] = $v;
			}
		}
		$base['wanted'] = $wanted;

		if ( ! empty( $missing ) ) {
			// The sold variation states none of the matched attributes: a
			// standard arrow takes the default tip (D-7.8). Without a
			// default there is nothing to consume, and that is named.
			if ( null !== $row['default_component_variant'] ) {
				$hit = null;
				foreach ( $candidates as $c ) {
					if ( (string) $c['sku'] === $row['default_component_variant'] ) {
						$hit = $c;
						break;
					}
				}
				if ( null !== $hit ) {
					$base['matched']       = 'default';
					$base['component_sku'] = (string) $hit['sku'];
					$base['qty']           = $line_qty * $row['qty_per'];
					return $base;
				}
			}
			$base['reason'] = 'missing_value';
			return $base;
		}

		// 3. The candidates agreeing on every matched and fixed role.
		$hits = array();
		foreach ( $candidates as $c ) {
			$attrs = isset( $c['attrs'] ) && is_array( $c['attrs'] ) ? $c['attrs'] : array();
			$ok    = true;
			foreach ( $wanted as $role => $value ) {
				$have = self::attr_for_role( $attrs, $role, $aliases );
				if ( null === $have || self::norm_value( $have ) !== $value ) {
					$ok = false;
					break;
				}
			}
			if ( $ok ) {
				foreach ( $row['fixed'] as $role => $value ) {
					$have = self::attr_for_role( $attrs, $role, $aliases );
					if ( null === $have ) {
						// A fixed key may also be the attribute's own name.
						$have = isset( $attrs[ $role ] ) && is_scalar( $attrs[ $role ] ) ? (string) $attrs[ $role ] : null;
					}
					if ( null === $have || self::norm_value( $have ) !== $value ) {
						$ok = false;
						break;
					}
				}
			}
			if ( $ok ) {
				$hits[] = $c;
			}
		}

		if ( 1 === count( $hits ) ) {
			$base['matched']       = $matched_via;
			$base['component_sku'] = (string) $hits[0]['sku'];
			$base['qty']           = $line_qty * $row['qty_per'];
			return $base;
		}
		if ( empty( $hits ) ) {
			$base['reason'] = 'no_match';
			return $base;
		}
		// Several: deterministic code must not pick a winner.
		$base['matched'] = 'ambiguous';
		$base['reason']  = 'ambiguous';
		return $base;
	}

	/**
	 * The figure a derived assembled variation should show: the minimum over
	 * its live, deriving rows of floor(component stock / qty_per), or null
	 * when no live deriving row resolves for it (nothing derives, so the
	 * figure is left alone).
	 *
	 * @param array                            $set                 Raw or normalised set.
	 * @param array                            $assembled_variation The sellable row.
	 * @param array<string, array<int, array>> $by_parent           Catalogue index.
	 * @param array<string, int>               $stocks              Component SKU => quantity on hand.
	 * @return int|null
	 */
	public static function derived_count( array $set, array $assembled_variation, array $by_parent, array $stocks ): ?int {
		$results = self::resolve_line( $set, $assembled_variation, array(), 1, $by_parent );
		$floor   = null;
		foreach ( $results as $r ) {
			if ( 'live' !== $r['mode'] || ! $r['derives'] ) {
				continue;
			}
			if ( ! in_array( $r['matched'], array( 'match', 'default', 'value_map' ), true ) ) {
				// A live deriving row that cannot resolve: leave the figure
				// alone rather than publish a guess.
				return null;
			}
			$stock   = (int) ( $stocks[ $r['component_sku'] ] ?? 0 );
			$per     = max( 1, (int) $r['qty_per'] );
			$can     = (int) floor( $stock / $per );
			$floor   = null === $floor ? $can : min( $floor, $can );
		}
		return $floor;
	}
}
