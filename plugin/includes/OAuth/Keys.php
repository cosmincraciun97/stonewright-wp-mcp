<?php
/**
 * SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Derived from includes/oauth/keys.php
 * Source SHA-256: 40e5b817d895463f19cff98380d773dc7bb5f4ddfd275f2dded62b5d3890dcf7
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\OAuth;

use League\OAuth2\Server\CryptKey;

defined( 'ABSPATH' ) || exit;

/**
 * OAuth signing and encryption key storage.
 */
final class Keys {

	public const PRIVATE_KEY_OPTION = 'stonewright_oauth_private_key';

	public const ENCRYPTION_KEY_OPTION = 'stonewright_oauth_encryption_key';

	/**
	 * Return persisted OAuth keys, generating them atomically on first use.
	 *
	 * @return array{private:CryptKey,public:CryptKey,encryption:string}
	 * @throws \RuntimeException When OpenSSL fails or key persistence is unavailable.
	 */
	public static function get(): array {
		$private = (string) get_option( self::PRIVATE_KEY_OPTION, '' );
		if ( '' === $private ) {
			add_option( self::PRIVATE_KEY_OPTION, self::generate_private_key(), '', false );
			$private = (string) get_option( self::PRIVATE_KEY_OPTION, '' );
		}
		if ( '' === $private ) {
			throw new \RuntimeException( 'Failed to persist OAuth private key.' );
		}

		$encryption = (string) get_option( self::ENCRYPTION_KEY_OPTION, '' );
		if ( '' === $encryption ) {
			add_option( self::ENCRYPTION_KEY_OPTION, base64_encode( random_bytes( 32 ) ), '', false );
			$encryption = (string) get_option( self::ENCRYPTION_KEY_OPTION, '' );
		}
		if ( '' === $encryption ) {
			throw new \RuntimeException( 'Failed to persist OAuth encryption key.' );
		}

		return [
			'private'    => new CryptKey( $private, null, false ),
			'public'     => new CryptKey( self::derive_public_key( $private ), null, false ),
			'encryption' => $encryption,
		];
	}

	private static function generate_private_key(): string {
		$resource = openssl_pkey_new(
			[
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			]
		);
		if ( false === $resource ) {
			throw new \RuntimeException( 'openssl_pkey_new failed.' );
		}

		$private = '';
		if ( ! openssl_pkey_export( $resource, $private ) ) {
			throw new \RuntimeException( 'openssl_pkey_export failed.' );
		}

		return $private;
	}

	private static function derive_public_key( string $private_pem ): string {
		$key = openssl_pkey_get_private( $private_pem );
		if ( false === $key ) {
			throw new \RuntimeException( 'openssl_pkey_get_private failed.' );
		}

		$details = openssl_pkey_get_details( $key );
		if ( false === $details || ! isset( $details['key'] ) ) {
			throw new \RuntimeException( 'openssl_pkey_get_details failed.' );
		}

		return (string) $details['key'];
	}
}
