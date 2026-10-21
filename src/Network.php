<?php
/**
 * A network the relay connects through one of our apps.
 *
 * @package ExactSocial\Relay
 */

declare( strict_types=1 );

namespace ExactSocial\Relay;

use RuntimeException;

/**
 * Network.
 */
interface Network {

	/**
	 * Name on the relay's pages and buttons, e.g. "Facebook".
	 */
	public function name(): string;

	/**
	 * What the person connects, e.g. "Facebook Pages".
	 */
	public function label(): string;

	/**
	 * Permission sets the site can ask for; the first is the default.
	 *
	 * @return string[]
	 */
	public function sets(): array;

	/**
	 * The network's login dialog.
	 *
	 * @param string $state Random state, checked on the way back.
	 * @param string $set   One of `sets()`.
	 */
	public function login_url( string $state, string $set ): string;

	/**
	 * Tokens and account details from the dialog's code: what `/collect` returns.
	 *
	 * @param string $code Code from the callback.
	 * @param string $set  The set the login asked for.
	 *
	 * @throws RuntimeException When the network refuses.
	 */
	public function connect( string $code, string $set ): array;
}
