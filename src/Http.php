<?php
/**
 * JSON over HTTPS for the networks' token and profile endpoints.
 *
 * @package ExactSocial\Relay
 */

declare( strict_types=1 );

namespace ExactSocial\Relay;

use RuntimeException;

/**
 * Http.
 */
final class Http {

	/**
	 * GET with query parameters.
	 *
	 * @param string $url   Address without a query.
	 * @param array  $query Parameters.
	 *
	 * @throws RuntimeException On a network error or an error response.
	 */
	public static function get( string $url, array $query ): array {
		return self::request( $url, $url . '?' . http_build_query( $query ), null );
	}

	/**
	 * POST a form.
	 *
	 * @param string $url  Address.
	 * @param array  $form Fields.
	 *
	 * @throws RuntimeException On a network error or an error response.
	 */
	public static function post( string $url, array $form ): array {
		return self::request( $url, $url, $form );
	}

	/**
	 * Send, decode, and turn every error shape the networks use into one
	 * exception. Messages name the address without its query, which holds
	 * secrets and tokens.
	 *
	 * @param string     $label Address for messages.
	 * @param string     $url   Address to call.
	 * @param array|null $form  POST fields, or null for GET.
	 *
	 * @throws RuntimeException On a network error or an error response.
	 */
	private static function request( string $label, string $url, ?array $form ): array {
		$curl    = curl_init( $url );
		$options = [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_TIMEOUT        => 15,
		];
		if ( null !== $form ) {
			$options[ CURLOPT_POST ]       = true;
			$options[ CURLOPT_POSTFIELDS ] = http_build_query( $form );
		}
		curl_setopt_array( $curl, $options );

		$body   = curl_exec( $curl );
		$status = (int) curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );
		$error  = curl_error( $curl );

		// Big IDs stay strings (Instagram's user IDs overflow a float).
		$data = is_string( $body ) ? json_decode( $body, true, 512, JSON_BIGINT_AS_STRING ) : null;
		if ( is_array( $data ) && $status < 400 && ! isset( $data['error'] ) && ! isset( $data['error_message'] ) ) {
			return $data;
		}

		$message = $data['error']['message']
			?? $data['error_message']
			?? ( is_string( $data['error'] ?? null ) ? $data['error'] : null )
			?? ( $error ?: "HTTP $status" );
		throw new RuntimeException( "$label: $message" );
	}
}
