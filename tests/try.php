<?php
/**
 * Try a deployed relay end to end, playing the WordPress site: start a
 * connect, catch the browser on the way back, collect the tokens, and prove
 * one works with a read-only call. Tokens are masked in the output.
 *
 * Run: php tests/try.php <relay-url> <network> [set]
 * e.g. php tests/try.php https://connect.exactwp.com facebook instagram
 *
 * @package ExactSocial\Relay
 */

declare( strict_types=1 );

namespace ExactSocial\Relay;

use Throwable;

require dirname( __DIR__ ) . '/src/functions.php';
require dirname( __DIR__ ) . '/src/Http.php';
require dirname( __DIR__ ) . '/src/Network.php';
require dirname( __DIR__ ) . '/src/Facebook.php';
require dirname( __DIR__ ) . '/src/Threads.php';

const PORT = 8899;

[ , $relay, $network, $set ] = $argv + [ 1 => '', 2 => '', 3 => '' ];
if ( ! $relay || ! in_array( $network, [ 'facebook', 'instagram', 'threads' ], true ) ) {
	fwrite( STDERR, "Usage: php tests/try.php <relay-url> <facebook|instagram|threads> [set]\n" );
	exit( 2 );
}
$relay = rtrim( $relay, '/' );

// 1. Start, as the plugin will: keep the verifier, send its hash.
$verifier = token();
$return   = 'http://127.0.0.1:' . PORT . '/done';
$start    = "$relay/$network/start?" . http_build_query(
	array_filter(
		[
			'return'    => $return,
			'challenge' => base64url( hash( 'sha256', $verifier, true ) ),
			'set'       => $set,
		]
	)
);

$server = @stream_socket_server( 'tcp://127.0.0.1:' . PORT, $errno, $errstr );
if ( ! $server ) {
	fwrite( STDERR, "Can't listen on port " . PORT . ": $errstr\n" );
	exit( 1 );
}

echo "Open this, confirm, and log in (it opens by itself on a Mac; NO_OPEN=1 stops that):\n\n  $start\n\nWaiting for the browser to come back…\n";
if ( 'Darwin' === PHP_OS_FAMILY && ! getenv( 'NO_OPEN' ) ) {
	exec( 'open ' . escapeshellarg( $start ) );
}

// 2. Catch the browser coming back with relay_code or relay_error.
$back = wait_for_return( $server );
if ( isset( $back['relay_error'] ) ) {
	fwrite( STDERR, "\nThe relay sent back relay_error={$back['relay_error']}. For \"failed\", the relay's log has a line starting exact-social-relay: with the network's reason.\n" );
	exit( 1 );
}

// 3. Collect, server to server, then try the same code again.
[ $status, $payload ] = collect( $relay, $back['relay_code'], $verifier );
if ( 200 !== $status ) {
	fwrite( STDERR, "\n/collect answered $status: " . json_encode( $payload ) . "\n" );
	exit( 1 );
}
[ $again ] = collect( $relay, $back['relay_code'], $verifier );

echo "\nCollected ({$payload['network']}, set {$payload['set']}):\n";
echo '  user: ' . ( $payload['user']['name'] ?: $payload['user']['username'] ?? '' ) . " ({$payload['user']['id']})\n";
echo '  user token: ' . mask( $payload['user_token'] ) . ', expires in ' . ( null === $payload['expires_in'] ? 'never' : round( $payload['expires_in'] / 86400 ) . ' days' ) . "\n";
foreach ( $payload['pages'] ?? [] as $page ) {
	echo "  page: {$page['name']} ({$page['id']}), token " . mask( $page['access_token'] ) . ', tasks ' . implode( ' ', $page['tasks'] )
		. ', Instagram ' . ( $page['instagram'] ? '@' . $page['instagram']['username'] : 'none' ) . "\n";
}
if ( isset( $payload['pages'] ) && ! $payload['pages'] ) {
	echo "  no Pages: was a Page picked in the dialog?\n";
}

// 4. Prove a token works with a read-only call.
try {
	echo '  token works: ' . prove( $payload ) . "\n";
} catch ( Throwable $e ) {
	echo '  token check FAILED: ' . $e->getMessage() . "\n";
}
echo '  second /collect with the same code: ' . ( 404 === $again ? 'refused, as it should be' : "UNEXPECTED $again" ) . "\n";

/**
 * Accept connections until one carries relay_code or relay_error.
 *
 * @param resource $server Listening socket.
 */
function wait_for_return( $server ): array {
	while ( $client = stream_socket_accept( $server, 600 ) ) {
		$line = (string) fgets( $client );
		parse_str( (string) parse_url( explode( ' ', $line )[1] ?? '', PHP_URL_QUERY ), $query );
		fwrite( $client, "HTTP/1.1 200 OK\r\nContent-Type: text/html\r\nConnection: close\r\n\r\n<p>Back at the test script. You can close this tab.</p>" );
		fclose( $client );
		if ( isset( $query['relay_code'] ) || isset( $query['relay_error'] ) ) {
			return $query;
		}
	}
	fwrite( STDERR, "\nNo browser came back within 10 minutes.\n" );
	exit( 1 );
}

/**
 * POST /collect.
 *
 * @param string $relay    Relay URL.
 * @param string $code     One-time code.
 * @param string $verifier Verifier.
 *
 * @return array{0: int, 1: mixed} Status and decoded body.
 */
function collect( string $relay, string $code, string $verifier ): array {
	$curl = curl_init( "$relay/collect" );
	curl_setopt_array(
		$curl,
		[
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => http_build_query( compact( 'code', 'verifier' ) ),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 15,
		]
	);
	$body = (string) curl_exec( $curl );

	return [ (int) curl_getinfo( $curl, CURLINFO_RESPONSE_CODE ), json_decode( $body, true, 512, JSON_BIGINT_AS_STRING ) ];
}

/**
 * A read-only call with the collected token: the first Page's name, or the
 * account's own username.
 *
 * @param array $payload What /collect returned.
 */
function prove( array $payload ): string {
	switch ( $payload['network'] ) {
		case 'facebook':
			$page = $payload['pages'][0] ?? null;
			if ( ! $page ) {
				return 'skipped (no Page)';
			}
			$data = Http::get( 'https://graph.facebook.com/' . Facebook::VERSION . "/{$page['id']}", [ 'fields' => 'name,followers_count', 'access_token' => $page['access_token'] ] );
			return "read Page \"{$data['name']}\" with its Page token, " . ( $data['followers_count'] ?? '?' ) . ' followers';
		case 'instagram':
			$data = Http::get( 'https://graph.instagram.com/' . Facebook::VERSION . '/me', [ 'fields' => 'username', 'access_token' => $payload['user_token'] ] );
			return "read @{$data['username']}";
		default:
			$data = Http::get( 'https://graph.threads.net/' . Threads::VERSION . '/me', [ 'fields' => 'username', 'access_token' => $payload['user_token'] ] );
			return "read @{$data['username']}";
	}
}

/**
 * A token's first characters and length, never the whole thing.
 *
 * @param string $token Token.
 */
function mask( string $token ): string {
	return substr( $token, 0, 6 ) . '… (' . strlen( $token ) . ' characters)';
}
