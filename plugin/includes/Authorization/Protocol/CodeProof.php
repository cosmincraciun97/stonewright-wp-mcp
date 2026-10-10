<?php
/**
 * Authorization foundation.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\Protocol;

use Stonewright\WpMcp\Authorization\Model\OAuthFault;

/** S256-only proof validation from RFC 7636. */
final class CodeProof {

	public function challenge( string $verifier ): string {
		if ( ! preg_match( '/^[A-Za-z0-9._~-]{43,128}$/D', $verifier ) ) {
			throw new OAuthFault( 'invalid_grant' );
		}
		return rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
	}

	public function require_match( string $verifier, string $challenge ): void {
		$this->require_s256( 'S256', $challenge );
		if ( ! hash_equals( $challenge, $this->challenge( $verifier ) ) ) {
			throw new OAuthFault( 'invalid_grant' );
		}
	}

	public function require_s256( string $method, string $challenge ): void {
		if ( 'S256' !== $method || ! preg_match( '/^[A-Za-z0-9_-]{43}$/D', $challenge ) ) {
			throw new OAuthFault( 'invalid_request' );
		}
	}
}
