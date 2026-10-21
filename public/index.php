<?php
/**
 * Exact Social connect relay. Point the web root at this folder.
 *
 * GET  /{network}/start?return=…&challenge=…&set=…  confirm, then the network's dialog
 * GET  /{network}/callback                          the network comes back here
 * POST /collect  code, verifier                     the site fetches its tokens, once
 *
 * Networks: facebook (sets pages, instagram, inbox), instagram and threads
 * (sets publish, inbox), each when its settings are present.
 *
 * @package ExactSocial\Relay
 */

declare( strict_types=1 );

namespace ExactSocial\Relay;

use Throwable;

require dirname( __DIR__ ) . '/src/functions.php';
require dirname( __DIR__ ) . '/src/Store.php';
require dirname( __DIR__ ) . '/src/Http.php';
require dirname( __DIR__ ) . '/src/Network.php';
require dirname( __DIR__ ) . '/src/Facebook.php';
require dirname( __DIR__ ) . '/src/Instagram.php';
require dirname( __DIR__ ) . '/src/Threads.php';

const COOKIE = 'exs_relay';

$config   = config();
$networks = networks( $config );
$store    = new Store( $config['RELAY_STORE'], $config['RELAY_KEY'] );
$store->sweep();

header( 'Cache-Control: no-store' );
header( 'Referrer-Policy: no-referrer' );
header( 'X-Content-Type-Options: nosniff' );

$method = $_SERVER['REQUEST_METHOD'];
$path   = (string) parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH );

if ( 'POST' === $method && '/collect' === $path ) {
	collect( $store );
} elseif ( 'GET' === $method && '/' === $path ) {
	print 'ok';
} elseif ( 'GET' === $method && preg_match( '#^/([a-z]+)/(start|callback)$#', $path, $route ) && isset( $networks[ $route[1] ] ) ) {
	'start' === $route[2]
		? start( $store, $networks[ $route[1] ], $route[1] )
		: callback( $store, $networks[ $route[1] ], $route[1] );
} else {
	page( 404, 'Not found', 'There is nothing here.' );
}

/**
 * Show where the account will go, then send the person to the network.
 *
 * @param Store   $store   Store.
 * @param Network $network Network.
 * @param string  $slug    Its route name.
 */
function start( Store $store, Network $network, string $slug ): void {
	$return    = (string) ( $_GET['return'] ?? '' );
	$challenge = (string) ( $_GET['challenge'] ?? '' );
	$set       = (string) ( $_GET['set'] ?? $network->sets()[0] );
	if ( ! is_return_url( $return ) || ! is_challenge( $challenge ) || ! in_array( $set, $network->sets(), true ) ) {
		page( 400, 'Can’t connect', 'Start again from your WordPress site.' );
		return;
	}

	// The callback must come back to the browser that saw this page, so a
	// login link made by someone else can't send the account to their site.
	$browser = token();
	$state   = token();
	$store->put(
		$state,
		[
			'kind'      => 'state',
			'network'   => $slug,
			'set'       => $set,
			'return'    => $return,
			'challenge' => $challenge,
			'browser'   => hash( 'sha256', $browser ),
		]
	);
	setcookie(
		COOKIE,
		$browser,
		[
			'expires'  => time() + 600,
			'path'     => '/',
			'secure'   => true,
			'httponly' => true,
			'samesite' => 'Lax',
		]
	);

	$host = (string) parse_url( $return, PHP_URL_HOST );
	page(
		200,
		'Connect ' . $network->label(),
		'You’re connecting ' . esc( $network->label() ) . ' to <strong>' . esc( $host ) . '</strong>. Continue only if you started this from that WordPress site.',
		[
			'Continue to ' . $network->name() => $network->login_url( $state, $set ),
			'Cancel'                          => with_query( $return, [ 'relay_error' => 'cancelled' ] ),
		]
	);
}

/**
 * Turn the network's code into tokens, keep them for the site, and send the
 * person back with a one-time code.
 *
 * @param Store   $store   Store.
 * @param Network $network Network.
 * @param string  $slug    Its route name.
 */
function callback( Store $store, Network $network, string $slug ): void {
	$record  = $store->take( (string) ( $_GET['state'] ?? '' ) );
	$browser = (string) ( $_COOKIE[ COOKIE ] ?? '' );
	setcookie( COOKIE, '', [ 'expires' => 1, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax' ] );

	if ( 'state' !== ( $record['kind'] ?? '' ) || $slug !== $record['network'] || ! hash_equals( $record['browser'], hash( 'sha256', $browser ) ) ) {
		page( 400, 'This link has expired', 'Start again from your WordPress site.' );
		return;
	}

	if ( isset( $_GET['error'] ) ) {
		$error = 'access_denied' === $_GET['error'] ? 'cancelled' : 'denied';
		redirect( with_query( $record['return'], [ 'relay_error' => $error ] ) );
		return;
	}

	try {
		$payload = $network->connect( (string) ( $_GET['code'] ?? '' ), $record['set'] );
	} catch ( Throwable $e ) {
		error_log( 'exact-social-relay: ' . $e->getMessage() );
		redirect( with_query( $record['return'], [ 'relay_error' => 'failed' ] ) );
		return;
	}

	$code = token();
	$store->put(
		$code,
		[
			'kind'      => 'result',
			'challenge' => $record['challenge'],
			'payload'   => $payload,
		]
	);
	redirect( with_query( $record['return'], [ 'relay_code' => $code ] ) );
}

/**
 * Hand the tokens to the site that holds the verifier, once. The record is
 * taken before the check, so a stolen code can't be tried twice.
 *
 * @param Store $store Store.
 */
function collect( Store $store ): void {
	header( 'Content-Type: application/json' );

	$record = $store->take( (string) ( $_POST['code'] ?? '' ) );
	if ( 'result' !== ( $record['kind'] ?? '' ) || ! verifies( (string) ( $_POST['verifier'] ?? '' ), $record['challenge'] ) ) {
		http_response_code( 404 );
		echo json_encode( [ 'error' => 'invalid_code' ] );
		return;
	}

	echo json_encode( $record['payload'] );
}

/**
 * The networks whose settings are present. Facebook's Pages set is required;
 * its other sets, Instagram and Threads switch on with their settings.
 *
 * @param array $config Settings.
 *
 * @return array<string, Network> By route name.
 */
function networks( array $config ): array {
	$base     = rtrim( $config['RELAY_URL'], '/' );
	$networks = [
		'facebook' => new Facebook(
			$config['FB_APP_ID'],
			$config['FB_APP_SECRET'],
			array_filter(
				[
					'pages'     => $config['FB_CONFIG_ID'],
					'instagram' => $config['FB_CONFIG_ID_INSTAGRAM'],
					'inbox'     => $config['FB_CONFIG_ID_INBOX'],
				]
			),
			"$base/facebook/callback"
		),
	];
	if ( $config['INSTAGRAM_APP_ID'] && $config['INSTAGRAM_APP_SECRET'] ) {
		$networks['instagram'] = new Instagram( $config['INSTAGRAM_APP_ID'], $config['INSTAGRAM_APP_SECRET'], "$base/instagram/callback" );
	}
	if ( $config['THREADS_APP_ID'] && $config['THREADS_APP_SECRET'] ) {
		$networks['threads'] = new Threads( $config['THREADS_APP_ID'], $config['THREADS_APP_SECRET'], "$base/threads/callback" );
	}

	return $networks;
}

/**
 * Settings from the environment, or `config.php` beside `public/`.
 */
function config(): array {
	$required = [ 'FB_APP_ID', 'FB_APP_SECRET', 'FB_CONFIG_ID', 'RELAY_URL', 'RELAY_KEY' ];
	$optional = [ 'FB_CONFIG_ID_INSTAGRAM', 'FB_CONFIG_ID_INBOX', 'INSTAGRAM_APP_ID', 'INSTAGRAM_APP_SECRET', 'THREADS_APP_ID', 'THREADS_APP_SECRET', 'RELAY_STORE' ];
	$file     = dirname( __DIR__ ) . '/config.php';
	$values   = is_readable( $file ) ? require $file : [];

	$config = [];
	foreach ( array_merge( $required, $optional ) as $name ) {
		$config[ $name ] = (string) ( $values[ $name ] ?? getenv( $name ) ?: '' );
	}
	$config['RELAY_STORE'] = $config['RELAY_STORE'] ?: dirname( __DIR__ ) . '/store';
	$config['RELAY_KEY']   = (string) base64_decode( $config['RELAY_KEY'], true );

	$missing = array_filter( $required, static fn( string $name ): bool => '' === $config[ $name ] );
	if ( $missing || SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== strlen( $config['RELAY_KEY'] ) || ! is_writable( $config['RELAY_STORE'] ) ) {
		error_log( 'exact-social-relay: missing ' . ( implode( ', ', $missing ) ?: 'nothing' ) . '; RELAY_KEY must be 32 base64 bytes and the store writable.' );
		page( 500, 'Not available', 'The connect service isn’t set up.' );
		exit;
	}

	return $config;
}

/**
 * Send the browser elsewhere.
 *
 * @param string $url Address.
 */
function redirect( string $url ): void {
	header( 'Location: ' . $url, true, 302 );
}

/**
 * A small HTML page.
 *
 * @param int    $status  HTTP status.
 * @param string $title   Title.
 * @param string $html    Message, already escaped.
 * @param array  $buttons Label => URL.
 */
function page( int $status, string $title, string $html, array $buttons = [] ): void {
	http_response_code( $status );
	header( 'Content-Type: text/html; charset=utf-8' );
	header( 'X-Frame-Options: DENY' );
	header( "Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'none'; form-action 'none'" );

	$links = '';
	foreach ( $buttons as $label => $url ) {
		$links .= '<a href="' . esc( $url ) . '">' . esc( $label ) . '</a>';
	}

	echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
		. '<title>' . esc( $title ) . ' · Exact Social</title><style>'
		. 'body{margin:0;font:16px/1.5 system-ui,sans-serif;color:#18181b;background:#fafafa}'
		. 'main{max-width:28rem;margin:12vh auto;padding:2rem;background:#fff;border:1px solid #e4e4e7;border-radius:12px}'
		. 'h1{font-size:1.25rem;margin:0 0 .5rem}a{display:inline-block;margin:1rem .5rem 0 0;padding:.5rem 1rem;border-radius:8px;border:1px solid #d4d4d8;color:inherit;text-decoration:none}'
		. 'a:first-of-type{background:#1877f2;border-color:#1877f2;color:#fff}'
		. '</style></head><body><main><h1>' . esc( $title ) . '</h1><p>' . $html . '</p>' . $links . '</main></body></html>';
}
