<?php
/**
 * Instagram API with Instagram Login: professional accounts that aren't
 * linked to a Facebook Page (linked ones come through Facebook's
 * `instagram` set). The token lasts 60 days; the site refreshes it at
 * graph.instagram.com/refresh_access_token, which needs no app secret.
 *
 * @package ExactSocial\Relay
 */

declare( strict_types=1 );

namespace ExactSocial\Relay;

/**
 * Instagram.
 */
final class Instagram implements Network {

	/**
	 * Permissions per set: publishing, then the inbox on top.
	 */
	private const SCOPES = [
		'publish' => [ 'instagram_business_basic', 'instagram_business_content_publish', 'instagram_business_manage_comments', 'instagram_business_manage_insights' ],
		'inbox'   => [ 'instagram_business_basic', 'instagram_business_content_publish', 'instagram_business_manage_comments', 'instagram_business_manage_insights', 'instagram_business_manage_messages' ],
	];

	/**
	 * Instagram.
	 *
	 * @param string $app_id       Instagram app ID.
	 * @param string $app_secret   Instagram app secret.
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
		return 'Instagram';
	}

	/**
	 * What is connected.
	 */
	public function label(): string {
		return 'an Instagram account';
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
		return 'https://www.instagram.com/oauth/authorize?' . http_build_query(
			[
				'client_id'     => $this->app_id,
				'redirect_uri'  => $this->redirect_uri,
				'response_type' => 'code',
				'scope'         => implode( ',', self::SCOPES[ $set ] ),
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
			'https://api.instagram.com/oauth/access_token',
			[
				'client_id'     => $this->app_id,
				'client_secret' => $this->app_secret,
				'grant_type'    => 'authorization_code',
				'redirect_uri'  => $this->redirect_uri,
				'code'          => $code,
			]
		);
		// Instagram has answered both flat and inside `data`.
		$short = $short['data'][0] ?? $short;

		$long = Http::get(
			'https://graph.instagram.com/access_token',
			[
				'grant_type'    => 'ig_exchange_token',
				'client_secret' => $this->app_secret,
				'access_token'  => $short['access_token'],
			]
		);
		$me   = Http::get(
			'https://graph.instagram.com/' . Facebook::VERSION . '/me',
			[
				'fields'       => 'user_id,username,name,profile_picture_url',
				'access_token' => $long['access_token'],
			]
		);

		return [
			'network'    => 'instagram',
			'set'        => $set,
			'user'       => [
				'id'       => (string) ( $me['user_id'] ?? $me['id'] ),
				'username' => (string) ( $me['username'] ?? '' ),
				'name'     => (string) ( $me['name'] ?? '' ),
				'picture'  => $me['profile_picture_url'] ?? null,
			],
			'user_token' => $long['access_token'],
			'expires_in' => isset( $long['expires_in'] ) ? (int) $long['expires_in'] : null,
		];
	}
}
