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
use Stonewright\WpMcp\Authorization\Model\TokenPair;

/** Pure response descriptions, without sending headers or echoing credentials. */
final class OAuthResponses {

	public function __construct( private ?TransportPolicy $transport = null ) {
		$this->transport ??= new TransportPolicy();
	}

	/** @return array<string, mixed> */
	public function token( TokenPair $pair, array $granted_scopes ): array {
		return [ 'status' => 200, 'headers' => $this->private_headers(), 'body' => [ 'access_token' => $pair->access_token, 'refresh_token' => $pair->refresh_token, 'expires_in' => $pair->expires_in, 'token_type' => 'Bearer', 'scope' => $this->scope_text( $granted_scopes ) ] ];
	}

	/** @return array<string, mixed> */
	public function failure( OAuthFault $fault ): array {
		return [ 'status' => $fault->status(), 'headers' => $this->private_headers(), 'body' => [ 'error' => $fault->error(), 'error_description' => $fault->safe_description() ] ];
	}

	/**
	 * @return array<string, mixed>
	 * @throws OAuthFault When the protocol input is invalid.
	 */
	public function challenge( string $metadata_url, array $scopes, ?string $error ): array {
		$this->transport->require_secure( $metadata_url );
		if ( preg_match( '/["\\\\]/', $metadata_url ) || ! in_array( $error, [ null, 'invalid_token', 'insufficient_scope' ], true ) ) {
			throw new OAuthFault( 'invalid_request' );
		}
		$challenge = 'Bearer resource_metadata="' . $metadata_url . '", scope="' . $this->scope_text( $scopes ) . '"';
		if ( null !== $error ) {
			$challenge .= ', error="' . $error . '"';
		}
		return [ 'status' => 'insufficient_scope' === $error ? 403 : 401, 'headers' => [ 'WWW-Authenticate' => $challenge, 'Cache-Control' => 'no-store' ], 'body' => [] ];
	}

	/** @return array<string, string> */
	private function private_headers(): array {
		return [ 'Content-Type' => 'application/json', 'Cache-Control' => 'no-store', 'Pragma' => 'no-cache' ];
	}

	private function scope_text( array $scopes ): string {
		if ( ! array_is_list( $scopes ) ) {
			throw new OAuthFault( 'invalid_scope' );
		}
		foreach ( $scopes as $scope ) {
			if ( ! is_string( $scope ) || ! preg_match( '/^[\x21\x23-\x5b\x5d-\x7e]+$/D', $scope ) ) {
				throw new OAuthFault( 'invalid_scope' );
			}
		}
		return implode( ' ', array_unique( $scopes ) );
	}
}
