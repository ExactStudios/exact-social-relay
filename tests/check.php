<?php
/**
 * Self-check: `php tests/check.php`. Exits non-zero on the first failure.
 *
 * @package ExactSocial\Relay
 */

declare( strict_types=1 );

namespace ExactSocial\Relay;

require dirname( __DIR__ ) . '/src/functions.php';
require dirname( __DIR__ ) . '/src/Store.php';
require dirname( __DIR__ ) . '/src/Http.php';
require dirname( __DIR__ ) . '/src/Network.php';
require dirname( __DIR__ ) . '/src/Facebook.php';
require dirname( __DIR__ ) . '/src/Instagram.php';
require dirname( __DIR__ ) . '/src/Threads.php';

/**
 * Fail loudly.
 *
 * @param bool   $ok   Condition.
 * @param string $what What was checked.
 */
function check( bool $ok, string $what ): void {
	if ( ! $ok ) {
		fwrite( STDERR, "FAIL: $what\n" );
		exit( 1 );
	}
	echo "ok   $what\n";
}

// PKCE S256, with RFC 7636's own example.
check( verifies( 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk', 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM' ), 'verifier matches its challenge' );
check( ! verifies( 'wrong', 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM' ), 'wrong verifier refused' );
check( ! verifies( '', 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM' ), 'empty verifier refused' );
check( is_challenge( 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM' ), 'challenge shape accepted' );
check( ! is_challenge( 'short' ), 'short challenge refused' );

// Return addresses.
check( is_return_url( 'https://example.com/wp-admin/admin.php?page=exact-social' ), 'https return accepted' );
check( is_return_url( 'http://exact-social.vm/wp-admin/' ), 'local http return accepted' );
check( ! is_return_url( 'javascript:alert(1)' ), 'javascript: refused' );
check( ! is_return_url( 'https://user:pass@example.com/' ), 'credentials refused' );
check( ! is_return_url( 'https://example.com/#x' ), 'fragment refused' );
check( ! is_return_url( '/relative' ), 'relative refused' );
check( 'https://a.test/?page=x&relay_code=c' === with_query( 'https://a.test/?page=x', [ 'relay_code' => 'c' ] ), 'query appended with &' );
check( 'https://a.test/?relay_code=c' === with_query( 'https://a.test/', [ 'relay_code' => 'c' ] ), 'query appended with ?' );

// Store: encrypted, one read, expiry.
$dir = sys_get_temp_dir() . '/exs-relay-check-' . bin2hex( random_bytes( 4 ) );
mkdir( $dir, 0700 );
$key   = random_bytes( SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
$store = new Store( $dir, $key, 600 );

$store->put( 'abc', [ 'secret' => 'page-token-123' ] );
$files = glob( "$dir/*" );
check( 1 === count( $files ) && ! str_contains( (string) file_get_contents( $files[0] ), 'page-token-123' ), 'record encrypted on disk' );
check( ! str_contains( $files[0], 'abc' ), 'id not in the file name' );
check( [ 'secret' => 'page-token-123' ] === $store->take( 'abc' ), 'record read back' );
check( null === $store->take( 'abc' ), 'record read only once' );

$store->put( 'old', [ 'x' => 1 ] );
touch( glob( "$dir/*" )[0], time() - 601 );
check( null === $store->take( 'old' ), 'expired record refused' );

$store->put( 'other', [ 'x' => 1 ] );
check( null === ( new Store( $dir, random_bytes( SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) ) )->take( 'other' ), 'wrong key reads nothing' );

$store->put( 'stale', [ 'x' => 1 ] );
touch( glob( "$dir/*" )[0], time() - 601 );
$store->sweep();
check( [] === glob( "$dir/*" ), 'sweep removes expired records' );

rmdir( $dir );

/**
 * A login URL's query.
 *
 * @param string $url URL.
 */
function query_of( string $url ): array {
	parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
	return $query;
}

// What each network's login asks for.
$facebook = new Facebook( 'app', 'secret', [ 'pages' => 'C1', 'instagram' => 'C2', 'inbox' => 'C3' ], 'https://r.test/facebook/callback' );
check( [ 'pages', 'instagram', 'inbox' ] === $facebook->sets(), 'Facebook sets, pages first' );
check( 'C1' === query_of( $facebook->login_url( 's', 'pages' ) )['config_id'], 'Facebook pages uses its configuration' );
check( 'C3' === query_of( $facebook->login_url( 's', 'inbox' ) )['config_id'], 'Facebook inbox uses its configuration' );
check( 's' === query_of( $facebook->login_url( 's', 'pages' ) )['state'], 'Facebook login carries the state' );
check( [ 'pages' ] === ( new Facebook( 'app', 'secret', array_filter( [ 'pages' => 'C1', 'instagram' => '', 'inbox' => '' ] ), 'x' ) )->sets(), 'unset configurations are no sets' );

$instagram = query_of( ( new Instagram( 'ig', 'secret', 'https://r.test/instagram/callback' ) )->login_url( 's', 'publish' ) );
check( 'https://r.test/instagram/callback' === $instagram['redirect_uri'], 'Instagram redirect URI' );
check( str_contains( $instagram['scope'], 'instagram_business_content_publish' ) && ! str_contains( $instagram['scope'], 'manage_messages' ), 'Instagram publish asks for no messages' );
check( str_contains( query_of( ( new Instagram( 'ig', 'secret', 'x' ) )->login_url( 's', 'inbox' ) )['scope'], 'instagram_business_manage_messages' ), 'Instagram inbox asks for messages' );

$threads = query_of( ( new Threads( 'th', 'secret', 'https://r.test/threads/callback' ) )->login_url( 's', 'publish' ) );
check( str_contains( $threads['scope'], 'threads_content_publish' ) && ! str_contains( $threads['scope'], 'threads_read_replies' ), 'Threads publish asks for no replies' );
check( str_contains( query_of( ( new Threads( 'th', 'secret', 'x' ) )->login_url( 's', 'inbox' ) )['scope'], 'threads_manage_mentions' ), 'Threads inbox asks for mentions' );
check( ! str_contains( $facebook->login_url( 's', 'pages' ), 'secret' ), 'no secret in a login URL' );

echo "all checks passed\n";
