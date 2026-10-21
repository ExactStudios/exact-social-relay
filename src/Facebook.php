<?php
/**
 * Facebook Login for Business: Pages, and the Instagram accounts linked to
 * them. Each permission set is one Login for Business configuration.
 *
 * @package ExactSocial\Relay
 */

declare( strict_types=1 );

namespace ExactSocial\Relay;

/**
 * Facebook.
 */
final class Facebook implements Network {

	/**
	 * Graph API version; the plugin publishes with the same one.
	 */
	public const VERSION = 'v26.0';

	/**
	 * Facebook.
	 *
	 * @param string $app_id       App ID.
	 * @param string $app_secret   App secret: never leaves this server.
	 * @param array  $configs      Set => configuration ID: `pages`, then `instagram` and `inbox` when configured.
	 * @param string $redirect_uri This relay's callback, as registered with Meta.
	 */
	public function __construct(
		private string $app_id,
		private string $app_secret,
		private array $configs,
		private string $redirect_uri
	) {}

	/**
	 * Name.
	 */
	public function name(): string {
		return 'Facebook';
	}

	/**
	 * What is connected.
	 */
	public function label(): string {
		return 'Facebook Pages';
	}

	/**
	 * Sets: one per configuration.
	 */
	public function sets(): array {
		return array_keys( $this->configs );
	}

	/**
	 * The login dialog.
	 *
	 * @param string $state State.
	 * @param string $set   Set.
	 */
	public function login_url( string $state, string $set ): string {
		return 'https://www.facebook.com/' . self::VERSION . '/dialog/oauth?' . http_build_query(
			[
				'client_id'                      => $this->app_id,
				'redirect_uri'                   => $this->redirect_uri,
				'state'                          => $state,
				'config_id'                      => $this->configs[ $set ],
				'response_type'                  => 'code',
				'override_default_response_type' => 'true',
			]
		);
	}

	/**
	 * The person and their Pages, each with its Page token (which doesn't
	 * expire) and, beyond `pages`, its linked Instagram account.
	 *
	 * @param string $code Code.
	 * @param string $set  Set.
	 */
	public function connect( string $code, string $set ): array {
		$short = Http::get(
			$this->graph( '/oauth/access_token' ),
			[
				'client_id'     => $this->app_id,
				'client_secret' => $this->app_secret,
				'redirect_uri'  => $this->redirect_uri,
				'code'          => $code,
			]
		);
		$long  = Http::get(
			$this->graph( '/oauth/access_token' ),
			[
				'grant_type'        => 'fb_exchange_token',
				'client_id'         => $this->app_id,
				'client_secret'     => $this->app_secret,
				'fb_exchange_token' => $short['access_token'],
			]
		);
		$token = $long['access_token'];

		$fields = 'id,name,access_token,picture{url},tasks';
		if ( 'pages' !== $set ) {
			$fields .= ',instagram_business_account{id,username,name,profile_picture_url}';
		}

		$me = Http::get( $this->graph( '/me' ), $this->signed( $token, [ 'fields' => 'id,name' ] ) );
		// ponytail: the first 100 Pages; follow `paging.next` if someone has more.
		$pages = Http::get( $this->graph( '/me/accounts' ), $this->signed( $token, [ 'fields' => $fields, 'limit' => 100 ] ) );

		return [
			'network'    => 'facebook',
			'set'        => $set,
			'user'       => [
				'id'   => (string) $me['id'],
				'name' => (string) $me['name'],
			],
			'user_token' => $token,
			'expires_in' => isset( $long['expires_in'] ) ? (int) $long['expires_in'] : null,
			'pages'      => array_map( [ $this, 'page' ], $pages['data'] ?? [] ),
		];
	}

	/**
	 * One Page as `/collect` returns it.
	 *
	 * @param array $page Graph Page.
	 */
	private function page( array $page ): array {
		$instagram = $page['instagram_business_account'] ?? null;

		return [
			'id'           => (string) $page['id'],
			'name'         => (string) $page['name'],
			'access_token' => (string) $page['access_token'],
			'picture'      => $page['picture']['data']['url'] ?? null,
			'tasks'        => $page['tasks'] ?? [],
			'instagram'    => $instagram ? [
				'id'       => (string) $instagram['id'],
				'username' => (string) ( $instagram['username'] ?? '' ),
				'name'     => (string) ( $instagram['name'] ?? '' ),
				'picture'  => $instagram['profile_picture_url'] ?? null,
			] : null,
		];
	}

	/**
	 * A Graph API address.
	 *
	 * @param string $path Path after the version.
	 */
	private function graph( string $path ): string {
		return 'https://graph.facebook.com/' . self::VERSION . $path;
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
}
