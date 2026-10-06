<?php
/**
 * Encrypted credential payloads.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\WordPress;

use Defuse\Crypto\Crypto;
use Defuse\Crypto\Exception\WrongKeyOrModifiedCiphertextException;
use Stonewright\WpMcp\Authorization\Model\OAuthFault;

/**
 * JSON payloads sealed in the authenticated-encryption format of the bundled
 * encryption library: lowercase hex beginning with the def50200 header, password-based
 * with the stored encryption key, the form the bundled OAuth server library uses for a
 * string key. Opening tries the stored string first, then its base64-decoded bytes, so
 * credentials sealed with either variant open.
 */
final class PayloadCipher {

	private const SHAPE = '/^def50200[0-9a-f]{160,16384}$/D';

	public function __construct( private CredentialKeys $keys ) {}

	/**
	 * @param array<string, mixed> $payload
	 * @throws OAuthFault When the key is missing or encryption fails (server_error).
	 */
	public function seal( array $payload ): string {
		$password = $this->keys->encryption_key();
		$json = json_encode( $payload );
		if ( null === $password || false === $json ) {
			throw new OAuthFault( 'server_error', 500 );
		}
		try {
			return Crypto::encryptWithPassword( $json, $password );
		} catch ( \Throwable $failure ) {
			throw new OAuthFault( 'server_error', 500 );
		}
	}

	/**
	 * The decrypted JSON object, or null for anything that is not a payload sealed with
	 * the stored key.
	 *
	 * @return array<string, mixed>|null
	 */
	public function open( string $credential ): ?array {
		if ( 0 !== strlen( $credential ) % 2 || ! preg_match( self::SHAPE, $credential ) ) {
			return null;
		}
		$password = $this->keys->encryption_key();
		if ( null === $password ) {
			return null;
		}
		foreach ( self::variants( $password ) as $variant ) {
			try {
				$plain = Crypto::decryptWithPassword( $credential, $variant );
			} catch ( WrongKeyOrModifiedCiphertextException $wrong ) {
				continue;
			} catch ( \Throwable $broken ) {
				return null;
			}
			$payload = json_decode( $plain, true, 16 );
			return is_array( $payload ) && [] !== $payload && ! array_is_list( $payload ) ? $payload : null;
		}
		return null;
	}

	/** @return list<string> */
	private static function variants( string $password ): array {
		$variants = [ $password ];
		$decoded = base64_decode( $password, true );
		if ( is_string( $decoded ) && '' !== $decoded && $decoded !== $password ) {
			$variants[] = $decoded;
		}
		return $variants;
	}
}
