<?php
/**
 * Short-lived records on disk: encrypted, and each readable once.
 *
 * @package ExactSocial\Relay
 */

declare( strict_types=1 );

namespace ExactSocial\Relay;

/**
 * Store.
 */
final class Store {

	/**
	 * Store.
	 *
	 * @param string $dir Writable directory outside the web root.
	 * @param string $key 32-byte secretbox key.
	 * @param int    $ttl Seconds a record lives.
	 */
	public function __construct(
		private string $dir,
		private string $key,
		private int $ttl = 600
	) {}

	/**
	 * Save a record.
	 *
	 * @param string $id   Random id.
	 * @param array  $data Record.
	 */
	public function put( string $id, array $data ): void {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$box   = sodium_crypto_secretbox( json_encode( $data, JSON_THROW_ON_ERROR ), $nonce, $this->key );
		$path  = $this->path( $id );

		file_put_contents( $path, $nonce . $box, LOCK_EX );
		chmod( $path, 0600 );
	}

	/**
	 * The record, removed first so no one reads it twice.
	 *
	 * @param string $id Random id.
	 *
	 * @return array|null Null when missing, expired or unreadable.
	 */
	public function take( string $id ): ?array {
		$path  = $this->path( $id );
		$claim = $path . '.' . bin2hex( random_bytes( 8 ) );

		// Rename is atomic: of two parallel requests, only one gets the file.
		// Missing is a normal answer, so the warning is silenced.
		if ( ! @rename( $path, $claim ) ) {
			return null;
		}

		$raw   = file_get_contents( $claim );
		$saved = filemtime( $claim );
		unlink( $claim );

		if ( false === $raw || $saved < time() - $this->ttl ) {
			return null;
		}

		$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $nonce, $this->key );

		return false === $plain ? null : json_decode( $plain, true );
	}

	/**
	 * Delete expired records, including half-taken ones.
	 */
	public function sweep(): void {
		foreach ( glob( $this->dir . '/*' ) ?: [] as $file ) {
			if ( filemtime( $file ) < time() - $this->ttl ) {
				@unlink( $file ); // A parallel sweep may have removed it.
			}
		}
	}

	/**
	 * A record's file: the id hashed, so ids never appear on disk.
	 *
	 * @param string $id Random id.
	 */
	private function path( string $id ): string {
		return $this->dir . '/' . hash( 'sha256', $id );
	}
}
