<?php
/**
 * Exact Social connect relay. Point the web root at this folder.
 *
 * GET  /facebook/start?return=…&challenge=…  confirm, then Facebook's dialog
 * GET  /facebook/callback                     Facebook comes back here
 * POST /collect  code, verifier               the site fetches its tokens, once
 *
 * @package ExactSocial\Relay
 */

declare( strict_types=1 );

namespace ExactSocial\Relay;

use Throwable;

require dirname( __DIR__ ) . '/src/functions.php';
require dirname( __DIR__ ) . '/src/Store.php';
require dirname( __DIR__ ) . '/src/Facebook.php';

const COOKIE = 'exs_relay';

$config = config();
$store  = new Store( $config['RELAY_STORE'], $config['RELAY_KEY'] );
$store->sweep();

$facebook = new Facebook(
	$config['FB_APP_ID'],
	$config['FB_APP_SECRET'],
	$config['FB_CONFIG_ID'],
	rtrim( $config['RELAY_URL'], '/' ) . '/facebook/callback'
);

header( 'Cache-Control: no-store' );
header( 'Referrer-Policy: no-referrer' );
header( 'X-Content-Type-Options: nosniff' );

$route = $_SERVER['REQUEST_METHOD'] . ' ' . parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH );

match ( $route ) {
	'GET /facebook/start'    => start( $store, $facebook ),
	'GET /facebook/callback' => callback( $store, $facebook ),
	'POST /collect'          => collect( $store ),
	'GET /'                  => print 'ok',
	default                  => page( 404, 'Not found', 'There is nothing here.' ),
};

/**
 * Show where the Pages will go, then send the person to Facebook.
 *
 * @param Store    $store    Store.
 * @param Facebook $facebook Facebook.
 */
function start( Store $store, Facebook $facebook ): void {
	$return    = (string) ( $_GET['return'] ?? '' );
	$challenge = (string) ( $_GET['challenge'] ?? '' );
	if ( ! is_return_url( $return ) || ! is_challenge( $challenge ) ) {
		page( 400, 'Can’t connect', 'Start again from your WordPress site.' );
		return;
	}

	// The callback must come back to the browser that saw this page, so a
	// Facebook link made by someone else can't send Pages to their site.
	$browser = token();
	$state   = token();
	$store->put(
		$state,
		[
			'kind'      => 'state',
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
		'Connect Facebook Pages',
		'You’re connecting Facebook Pages to <strong>' . esc( $host ) . '</strong>. Continue only if you started this from that WordPress site.',
		[
			'Continue to Facebook' => $facebook->login_url( $state ),
			'Cancel'               => with_query( $return, [ 'relay_error' => 'cancelled' ] ),
		]
	);
}

/**
 * Turn Facebook's code into tokens, keep them for the site, and send the
 * person back with a one-time code.
 *
 * @param Store    $store    Store.
 * @param Facebook $facebook Facebook.
 */
function callback( Store $store, Facebook $facebook ): void {
	$record  = $store->take( (string) ( $_GET['state'] ?? '' ) );
	$browser = (string) ( $_COOKIE[ COOKIE ] ?? '' );
	setcookie( COOKIE, '', [ 'expires' => 1, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax' ] );

	if ( 'state' !== ( $record['kind'] ?? '' ) || ! hash_equals( $record['browser'], hash( 'sha256', $browser ) ) ) {
		page( 400, 'This link has expired', 'Start again from your WordPress site.' );
		return;
	}

	if ( isset( $_GET['error'] ) ) {
		$error = 'access_denied' === $_GET['error'] ? 'cancelled' : 'denied';
		redirect( with_query( $record['return'], [ 'relay_error' => $error ] ) );
		return;
	}

	try {
		$payload = $facebook->connect( (string) ( $_GET['code'] ?? '' ) );
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
 * Settings from the environment, or `config.php` beside `public/`.
 */
function config(): array {
	$file   = dirname( __DIR__ ) . '/config.php';
	$values = is_readable( $file ) ? require $file : [];

	$config = [];
	foreach ( [ 'FB_APP_ID', 'FB_APP_SECRET', 'FB_CONFIG_ID', 'RELAY_URL', 'RELAY_KEY', 'RELAY_STORE' ] as $name ) {
		$config[ $name ] = (string) ( $values[ $name ] ?? getenv( $name ) ?: '' );
	}
	$config['RELAY_STORE'] = $config['RELAY_STORE'] ?: dirname( __DIR__ ) . '/store';
	$config['RELAY_KEY']   = (string) base64_decode( $config['RELAY_KEY'], true );

	if ( in_array( '', $config, true ) || SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== strlen( $config['RELAY_KEY'] ) || ! is_writable( $config['RELAY_STORE'] ) ) {
		error_log( 'exact-social-relay: missing settings, a RELAY_KEY that isn’t 32 base64 bytes, or a store that isn’t writable.' );
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
