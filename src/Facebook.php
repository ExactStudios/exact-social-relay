<?php
/**
 * Facebook Login for Business: the login dialog, and turning its code into a
 * long-lived user token plus the Pages the person picked.
 *
 * @package ExactSocial\Relay
 */

declare( strict_types=1 );

namespace ExactSocial\Relay;

use RuntimeException;

/**
 * Facebook.
 */
final class Facebook {

	/**
	 * Graph API version; the plugin publishes with the same one.
	 */
	public const VERSION = 'v26.0';

	/**
	 * Facebook.
	 *
	 * @param string $app_id       App ID.
	 * @param string $app_secret   App secret: never leaves this server.
	 * @param string $config_id    Facebook Login for Business configuration ID.
	 * @param string $redirect_uri This relay's callback, as registered with Meta.
	 */
	public function __construct(
		private string $app_id,
		private string $app_secret,
		private string $config_id,
		private string $redirect_uri
	) {}

	/**
	 * The login dialog's address.
	 *
	 * @param string $state Random state, checked on the way back.
	 */
	public function login_url( string $state ): string {
		return 'https://www.facebook.com/' . self::VERSION . '/dialog/oauth?' . http_build_query(
			[
				'client_id'                      => $this->app_id,
				'redirect_uri'                   => $this->redirect_uri,
				'state'                          => $state,
				'config_id'                      => $this->config_id,
				'response_type'                  => 'code',
				'override_default_response_type' => 'true',
			]
		);
	}

	/**
	 * The person and their Pages, from the dialog's code.
	 *
	 * @param string $code Code from the callback.
	 *
	 * @return array{network: string, user: array{id: string, name: string}, user_token: string, expires_in: ?int, pages: array}
	 *
	 * @throws RuntimeException When Facebook refuses.
	 */
	public function connect( string $code ): array {
		$short = $this->get(
			'/oauth/access_token',
			[
				'client_id'     => $this->app_id,
				'client_secret' => $this->app_secret,
				'redirect_uri'  => $this->redirect_uri,
				'code'          => $code,
			]
		);
		$long  = $this->get(
			'/oauth/access_token',
			[
				'grant_type'        => 'fb_exchange_token',
				'client_id'         => $this->app_id,
				'client_secret'     => $this->app_secret,
				'fb_exchange_token' => $short['access_token'],
			]
		);
		$token = $long['access_token'];

		$me = $this->get( '/me', $this->signed( $token, [ 'fields' => 'id,name' ] ) );
		// Page tokens from a long-lived user token don't expire.
		// ponytail: the first 100 Pages; follow `paging.next` if someone has more.
		$pages = $this->get( '/me/accounts', $this->signed( $token, [ 'fields' => 'id,name,access_token,picture{url},tasks', 'limit' => 100 ] ) );

		return [
			'network'    => 'facebook',
			'user'       => [
				'id'   => (string) $me['id'],
				'name' => (string) $me['name'],
			],
			'user_token' => $token,
			'expires_in' => isset( $long['expires_in'] ) ? (int) $long['expires_in'] : null,
			'pages'      => array_map(
				static fn( array $page ): array => [
					'id'           => (string) $page['id'],
					'name'         => (string) $page['name'],
					'access_token' => (string) $page['access_token'],
					'picture'      => $page['picture']['data']['url'] ?? null,
					'tasks'        => $page['tasks'] ?? [],
				],
				$pages['data'] ?? []
			),
		];
	}

	/**
	 * Add the token and its proof that the call comes from this app.
	 *
	 * @param string $token Access token.
	 * @param array  $query Other parameters.
	 */
	private function signed( string $token, array $query ): array {
		return $query + [
			'access_token'    => $token,
			'appsecret_proof' => hash_hmac( 'sha256', $token, $this->app_secret ),
		];
	}

	/**
	 * GET a Graph API path.
	 *
	 * @param string $path  Path after the version.
	 * @param array  $query Query parameters.
	 *
	 * @throws RuntimeException On a network error or an error response.
	 */
	private function get( string $path, array $query ): array {
		$curl = curl_init( 'https://graph.facebook.com/' . self::VERSION . $path . '?' . http_build_query( $query ) );
		curl_setopt_array(
			$curl,
			[
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_CONNECTTIMEOUT => 5,
				CURLOPT_TIMEOUT        => 15,
			]
		);
		$body   = curl_exec( $curl );
		$status = (int) curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );
		$error  = curl_error( $curl );

		$data = is_string( $body ) ? json_decode( $body, true ) : null;
		if ( ! is_array( $data ) || $status >= 400 || isset( $data['error'] ) ) {
			throw new RuntimeException( sprintf( 'Graph %s: %s', $path, $data['error']['message'] ?? ( $error ?: "HTTP $status" ) ) );
		}

		return $data;
	}
}
