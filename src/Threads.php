<?php
/**
 * Threads API. The token lasts 60 days; the site refreshes it at
 * graph.threads.net/refresh_access_token, which needs no app secret.
 *
 * @package ExactSocial\Relay
 */

declare( strict_types=1 );

namespace ExactSocial\Relay;

/**
 * Threads.
 */
final class Threads implements Network {

	/**
	 * Threads API version.
	 */
	public const VERSION = 'v1.0';

	/**
	 * Permissions per set: publishing, then the inbox on top.
	 */
	private const SCOPES = [
		'publish' => [ 'threads_basic', 'threads_content_publish', 'threads_manage_replies', 'threads_manage_insights' ],
		'inbox'   => [ 'threads_basic', 'threads_content_publish', 'threads_manage_replies', 'threads_manage_insights', 'threads_read_replies', 'threads_manage_mentions' ],
	];

	/**
	 * Threads.
	 *
	 * @param string $app_id       Threads app ID.
	 * @param string $app_secret   Threads app secret.
	 * @param string $redirect_uri This relay's callback, as registered with Meta.
	 */
	public function __construct(
		private string $app_id,
		private string $app_secret,
		private string $redirect_uri
	) {}

	/**
	 * Name.
	 */
	public function name(): string {
		return 'Threads';
	}

	/**
	 * What is connected.
	 */
	public function label(): string {
		return 'a Threads account';
	}

	/**
	 * Sets.
	 */
	public function sets(): array {
		return array_keys( self::SCOPES );
	}

	/**
	 * The login dialog.
	 *
	 * @param string $state State.
	 * @param string $set   Set.
	 */
	public function login_url( string $state, string $set ): string {
		return 'https://threads.net/oauth/authorize?' . http_build_query(
			[
				'client_id'     => $this->app_id,
				'redirect_uri'  => $this->redirect_uri,
				'scope'         => implode( ',', self::SCOPES[ $set ] ),
				'response_type' => 'code',
				'state'         => $state,
			]
		);
	}

	/**
	 * The account and its 60-day token.
	 *
	 * @param string $code Code.
	 * @param string $set  Set.
	 */
	public function connect( string $code, string $set ): array {
		$short = Http::post(
			'https://graph.threads.net/oauth/access_token',
			[
				'client_id'     => $this->app_id,
				'client_secret' => $this->app_secret,
				'grant_type'    => 'authorization_code',
				'redirect_uri'  => $this->redirect_uri,
				'code'          => $code,
			]
		);
		$long  = Http::get(
			'https://graph.threads.net/access_token',
			[
				'grant_type'    => 'th_exchange_token',
				'client_secret' => $this->app_secret,
				'access_token'  => $short['access_token'],
			]
		);
		$me    = Http::get(
			'https://graph.threads.net/' . self::VERSION . '/me',
			[
				'fields'       => 'id,username,name,threads_profile_picture_url',
				'access_token' => $long['access_token'],
			]
		);

		return [
			'network'    => 'threads',
			'set'        => $set,
			'user'       => [
				'id'       => (string) $me['id'],
				'username' => (string) ( $me['username'] ?? '' ),
				'name'     => (string) ( $me['name'] ?? '' ),
				'picture'  => $me['threads_profile_picture_url'] ?? null,
			],
			'user_token' => $long['access_token'],
			'expires_in' => isset( $long['expires_in'] ) ? (int) $long['expires_in'] : null,
		];
	}
}
