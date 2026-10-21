<?php
/**
 * Helpers shared by the routes and the self-check.
 *
 * @package ExactSocial\Relay
 */

declare( strict_types=1 );

namespace ExactSocial\Relay;

/**
 * URL-safe base64 without padding.
 *
 * @param string $bytes Raw bytes.
 */
function base64url( string $bytes ): string {
	return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
}

/**
 * A random URL-safe token.
 */
function token(): string {
	return base64url( random_bytes( 32 ) );
}

/**
 * Whether a verifier matches the challenge the site sent at the start
 * (PKCE's S256: base64url of its SHA-256).
 *
 * @param string $verifier  Verifier the site kept.
 * @param string $challenge Challenge from the start.
 */
function verifies( string $verifier, string $challenge ): bool {
	return '' !== $verifier && hash_equals( $challenge, base64url( hash( 'sha256', $verifier, true ) ) );
}

/**
 * Whether a challenge looks like one: 43 URL-safe characters.
 *
 * @param string $challenge Challenge.
 */
function is_challenge( string $challenge ): bool {
	return 1 === preg_match( '/^[A-Za-z0-9_-]{43}$/', $challenge );
}

/**
 * Whether the site's return address is usable. Plain http is allowed for
 * local sites: only a one-time code goes there, and it's useless without the
 * verifier the site kept.
 *
 * @param string $url Return address.
 */
function is_return_url( string $url ): bool {
	$parts = parse_url( $url );

	return strlen( $url ) <= 2000
		&& is_array( $parts )
		&& in_array( $parts['scheme'] ?? '', [ 'http', 'https' ], true )
		&& ! empty( $parts['host'] )
		&& ! isset( $parts['user'] )
		&& ! isset( $parts['fragment'] );
}

/**
 * Add query arguments to a URL that may already have some.
 *
 * @param string $url  URL.
 * @param array  $args Arguments.
 */
function with_query( string $url, array $args ): string {
	return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $args );
}

/**
 * Escape for HTML.
 *
 * @param string $text Text.
 */
function esc( string $text ): string {
	return htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
}
