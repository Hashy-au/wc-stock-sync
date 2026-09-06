<?php
/**
 * Test for the 0.5.1 secrets store (review finding M2, 2026-09-06).
 *
 * Covers, without WordPress: the one-time move of secrets out of
 * hashy_au_settings (including the pre-0.5.0 field names), the overlay in
 * Hashy_AU_Settings::get_all(), the sanitiser routing secret fields to
 * Hashy_AU_Secrets and stripping them from the saved option, the
 * "absent field keeps the stored value" rule the Settings API double-sanitise
 * relies on, sealing with libsodium when it is available, and autoload off.
 *
 * The update_option() stub calls the registered sanitiser for
 * hashy_au_settings, as WordPress does, so the re-entrant path the migration
 * takes (migrate -> update_option -> sanitize_settings -> get_all) is the
 * real one.
 *
 * Run from the plugin root:  php tests/test-secrets-store.php
 *
 * @package Hashy_AU
 */

define( 'ABSPATH', __DIR__ . '/' );

/* ------------------------------------------------------------ WP stubs */

$wcss_options  = array();
$wcss_autoload = array();
$wcss_sanitise = null; // callable registered for hashy_au_settings.

function get_option( string $name, $default_value = false ) {
	global $wcss_options;
	return array_key_exists( $name, $wcss_options ) ? $wcss_options[ $name ] : $default_value;
}

function update_option( string $name, $value, $autoload = null ): bool {
	global $wcss_options, $wcss_autoload, $wcss_sanitise;
	if ( 'hashy_au_settings' === $name && is_callable( $wcss_sanitise ) ) {
		$value = call_user_func( $wcss_sanitise, $value );
	}
	$wcss_options[ $name ]  = $value;
	$wcss_autoload[ $name ] = $autoload;
	return true;
}

function wp_salt( string $scheme = 'auth' ): string {
	return 'test-salt-' . $scheme . '-0123456789abcdef0123456789abcdef';
}

function sanitize_text_field( $str ): string {
	return trim( strip_tags( (string) $str ) );
}

function sanitize_key( $key ): string {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function esc_url_raw( $url ): string {
	return (string) $url;
}

function untrailingslashit( $value ): string {
	return rtrim( (string) $value, '/\\' );
}

function add_action(): void {}

require_once dirname( __DIR__ ) . '/includes/class-hashy-au-secrets.php';
require_once dirname( __DIR__ ) . '/includes/class-hashy-au-settings.php';

/* ------------------------------------------------------------- harness */

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
 * True when any secret-bearing key is present anywhere in a settings array.
 *
 * @param array $settings Settings array.
 */
function wcss_has_secret_keys( array $settings ): bool {
	if ( array_key_exists( 'shared_secret', $settings ) ) {
		return true;
	}
	if ( isset( $settings['agent'] ) && ( array_key_exists( 'shared_secret', $settings['agent'] ) || array_key_exists( 'host_shared_secret', $settings['agent'] ) ) ) {
		return true;
	}
	if ( isset( $settings['updates'] ) && array_key_exists( 'github_token', $settings['updates'] ) ) {
		return true;
	}
	foreach ( (array) ( $settings['host']['agents'] ?? array() ) as $row ) {
		if ( is_array( $row ) && array_key_exists( 'shared_secret', $row ) ) {
			return true;
		}
	}
	return false;
}

$settings      = Hashy_AU_Settings::instance();
$wcss_sanitise = array( $settings, 'sanitize_settings' ); // as register_setting() would.
$b_id          = md5( 'https://b.test' );

/* ---------------------------------------------------- 1. legacy migration */

$wcss_options['hashy_au_settings'] = array(
	'mode'          => 'host',
	'shared_secret' => 'TOP-LEVEL-OLD',
	'host'          => array(
		'agents' => array(
			array(
				'id'            => 'a1',
				'name'          => 'A',
				'url'           => 'https://a.test',
				'shared_secret' => 'SECRET-A',
				'price_pct'     => 0.0,
				'sync_prices'   => 'no',
			),
			array(
				'name'          => 'B',
				'url'           => 'https://b.test/',
				'shared_secret' => 'SECRET-B',
			),
			array(
				'name'          => 'C (no secret yet)',
				'url'           => 'https://c.test',
				'shared_secret' => '',
			),
		),
	),
	'agent'         => array(
		'host_url'           => 'https://hub.test',
		'agent_code'         => 'ABC',
		'shared_secret'      => 'AGENT-SECRET',
		'host_shared_secret' => 'OLDER-NAME',
	),
	'updates'       => array(
		'github_token' => 'ghp_example_token',
	),
);
$wcss_autoload['hashy_au_settings'] = null;

$all = $settings->get_all();

wcss_check( 'AGENT-SECRET' === $all['agent']['shared_secret'], 'agent secret overlaid from the store (agent.shared_secret wins over the older names)' );
wcss_check( 'ghp_example_token' === $all['updates']['github_token'], 'github token overlaid from the store' );
wcss_check( 'SECRET-A' === $all['host']['agents'][0]['shared_secret'], 'host agent a1 secret overlaid by id' );
wcss_check( $b_id === ( $all['host']['agents'][1]['id'] ?? '' ), 'a legacy row without an id got md5(url) as its id' );
wcss_check( 'SECRET-B' === $all['host']['agents'][1]['shared_secret'], 'host agent B secret overlaid by that id' );
wcss_check( '' === $all['host']['agents'][2]['shared_secret'], 'a row with no secret reads back empty' );
wcss_check( 'host' === $all['mode'] && 'https://hub.test' === $all['agent']['host_url'] && 'ABC' === $all['agent']['agent_code'], 'non-secret settings untouched by the migration' );

$saved = $wcss_options['hashy_au_settings'];
wcss_check( ! wcss_has_secret_keys( $saved ), 'hashy_au_settings no longer carries any secret key (current or pre-0.5.0 names)' );
wcss_check( 'https://a.test' === ( $saved['host']['agents'][0]['url'] ?? '' ) && 'B' === ( $saved['host']['agents'][1]['name'] ?? '' ), 'agent rows survive the migration re-save' );

$store = $wcss_options['hashy_au_secrets'] ?? null;
wcss_check( is_array( $store ) && false === $wcss_autoload['hashy_au_secrets'], 'hashy_au_secrets written with autoload false' );
wcss_check( is_array( $store ) && isset( $store['agents']['a1'], $store['agents'][ $b_id ] ) && ! isset( $store['agents'][ md5( 'https://c.test' ) ] ), 'store holds a1 and B, not the empty C secret' );

if ( Hashy_AU_Secrets::can_seal() ) {
	wcss_check( 0 === strpos( (string) $store['agent_secret'], 'wcss:sb1:' ) && false === strpos( (string) $store['agent_secret'], 'AGENT-SECRET' ), 'stored agent secret is sealed (marker present, plain text absent)' );
	wcss_check( 0 === strpos( (string) $store['agents']['a1'], 'wcss:sb1:' ), 'stored per-agent secret is sealed' );
	wcss_check( false === strpos( serialize( $store ), 'ghp_example_token' ), 'github token not stored in plain text' );
} else {
	echo 'skip libsodium not available; sealing checks skipped' . PHP_EOL;
}

/* ---------------------------------------- 2. sealed values read back plain */

$fresh = new ReflectionProperty( 'Hashy_AU_Secrets', 'cache' );
$fresh->setAccessible( true );
$fresh->setValue( null, null ); // next read goes to the option, as a new request would.
wcss_check( 'AGENT-SECRET' === Hashy_AU_Secrets::get_agent_secret(), 'a fresh read opens the sealed agent secret' );
wcss_check( 'SECRET-B' === Hashy_AU_Secrets::get_host_agent_secret( $b_id ), 'a fresh read opens a sealed per-agent secret' );
wcss_check( 'ghp_example_token' === Hashy_AU_Secrets::get_github_token(), 'a fresh read opens the sealed github token' );

/* ------------------------------------ 3. legacy plain value still readable */

$wcss_options['hashy_au_secrets']['agents']['legacy'] = 'PLAIN-LEGACY';
$fresh->setValue( null, null );
wcss_check( 'PLAIN-LEGACY' === Hashy_AU_Secrets::get_host_agent_secret( 'legacy' ), 'an unsealed (legacy plain) value reads back as is' );

/* ------------------------------------------------ 4. settings form save */

$form = array(
	'mode'           => 'host',
	'normalize_skus' => 'yes',
	'agent'          => array(
		'host_url'      => 'https://hub.test',
		'agent_code'    => 'abc',
		'shared_secret' => 'NEW-AGENT-SECRET',
	),
	'updates'        => array( 'github_token' => '' ), // cleared by the admin
	'host'           => array(
		'agents' => array(
			array(
				'id'            => 'a1',
				'name'          => 'A',
				'url'           => 'https://a.test',
				'shared_secret' => '', // cleared
				'price_pct'     => '10',
				'sync_prices'   => 'yes',
			),
			array(
				'id'            => $b_id,
				'name'          => 'B',
				'url'           => 'https://b.test',
				'shared_secret' => 'SECRET-B2',
			),
			array(
				'id'            => 'd1',
				'name'          => 'D',
				'url'           => 'https://d.test',
				'shared_secret' => '<b>SECRET-D</b>', // sanitised like before
			),
		),
	),
);

$out = $settings->sanitize_settings( $form );
wcss_check( ! wcss_has_secret_keys( $out ), 'sanitiser output carries no secret key' );
wcss_check( 'ABC' === $out['agent']['agent_code'] && 10.0 === $out['host']['agents'][0]['price_pct'] && 'yes' === $out['host']['agents'][0]['sync_prices'], 'sanitiser still cleans the non-secret fields as before' );

$now = Hashy_AU_Secrets::all();
wcss_check( 'NEW-AGENT-SECRET' === $now['agent_secret'], 'submitted agent secret saved' );
wcss_check( '' === $now['github_token'], 'an empty submitted token clears the stored token' );
wcss_check( ! isset( $now['agents']['a1'] ), 'an emptied per-agent secret is dropped' );
wcss_check( 'SECRET-B2' === ( $now['agents'][ $b_id ] ?? '' ), 'a changed per-agent secret is saved' );
wcss_check( 'SECRET-D' === ( $now['agents']['d1'] ?? '' ), 'a new per-agent secret is sanitised and saved' );
wcss_check( ! isset( $now['agents']['legacy'] ), 'a row removed from the form loses its secret' );

/* ----------------------- 5. double sanitise: absent fields keep the store */

$before = Hashy_AU_Secrets::all();
$again  = $settings->sanitize_settings( $out ); // WordPress sanitises the already-sanitised value again on a first save.
wcss_check( Hashy_AU_Secrets::all() === $before, 'sanitising an array without secret fields leaves the store untouched' );
wcss_check( ! wcss_has_secret_keys( $again ), 'and still returns no secret key' );

/* ------------------------------------------- 6. overlay after the save */

$all2 = $settings->get_all();
wcss_check( 'NEW-AGENT-SECRET' === $all2['agent']['shared_secret'] && 'SECRET-B2' === $all2['host']['agents'][1]['shared_secret'] && '' === $all2['host']['agents'][0]['shared_secret'], 'get_all() overlays the saved secrets onto the rows by id' );
wcss_check( ! wcss_has_secret_keys( $wcss_options['hashy_au_settings'] ), 'the stored settings option still has no secret key after the save' );

/* ------------------------------------------ 7. migration is a one-off */

$writes_before                 = $wcss_options['hashy_au_secrets'];
$wcss_options['hashy_au_secrets_writes'] = 0;
$fresh->setValue( null, null );
Hashy_AU_Secrets::all();
wcss_check( $writes_before === $wcss_options['hashy_au_secrets'], 'a read with nothing left to migrate does not rewrite the store' );

echo PHP_EOL . ( 0 === $wcss_failures ? 'PASS' : 'FAIL' ) . ': ' . ( $wcss_checks - $wcss_failures ) . '/' . $wcss_checks . ' checks passed.' . PHP_EOL;
exit( 0 === $wcss_failures ? 0 : 1 );
