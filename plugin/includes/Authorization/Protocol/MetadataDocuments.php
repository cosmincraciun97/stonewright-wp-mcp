<?php
/**
 * Authorization foundation.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\Protocol;

use Stonewright\WpMcp\Authorization\Model\OAuthFault;

/** Composes declared metadata; WordPress routing belongs to a later adapter. */
final class MetadataDocuments {

	public function __construct( private ?TransportPolicy $transport = null ) {
		$this->transport ??= new TransportPolicy();
	}

	/**
	 * @return array<string, mixed>
	 * @throws OAuthFault When the protocol input is invalid.
	 */
	public function authorization_server( array $endpoints, array $capabilities ): array {
		foreach ( [ 'issuer', 'authorization_endpoint', 'token_endpoint' ] as $required ) {
			if ( ! isset( $endpoints[ $required ] ) || ! is_string( $endpoints[ $required ] ) ) {
				throw new OAuthFault( 'invalid_request' );
			}
		}
		$document = [];
		foreach ( [ 'issuer', 'authorization_endpoint', 'token_endpoint', 'registration_endpoint', 'revocation_endpoint', 'introspection_endpoint' ] as $name ) {
			if ( isset( $endpoints[ $name ] ) ) {
				$this->transport->require_secure( $endpoints[ $name ] );
				$document[ $name ] = $endpoints[ $name ];
			}
		}
		$issuer = parse_url( $document['issuer'] );
		if ( isset( $issuer['query'] ) ) {
			throw new OAuthFault( 'invalid_request' );
		}
		$document['response_types_supported'] = [ 'code' ];
		$document['grant_types_supported'] = [ 'authorization_code', 'refresh_token' ];
		$document['token_endpoint_auth_methods_supported'] = [ 'none' ];
		if ( isset( $document['revocation_endpoint'] ) ) {
			// Omission would imply client_secret_basic (RFC 8414 section 2).
			$document['revocation_endpoint_auth_methods_supported'] = $document['token_endpoint_auth_methods_supported'];
		}
		$document['code_challenge_methods_supported'] = [ 'S256' ];
		$document['scopes_supported'] = $this->scopes( $capabilities['scopes_supported'] ?? [ 'mcp' ] );
		return $document;
	}

	/**
	 * @return array<string, mixed>
	 * @throws OAuthFault When the protocol input is invalid.
	 */
	public function protected_resource( string $resource, array $issuers, array $minimal_scopes ): array {
		$this->transport->require_secure( $resource );
		if ( [] === $issuers || ! array_is_list( $issuers ) ) {
			throw new OAuthFault( 'invalid_request' );
		}
		foreach ( $issuers as $issuer ) {
			$this->transport->require_secure( $issuer );
		}
		return [ 'resource' => $resource, 'authorization_servers' => $issuers, 'scopes_supported' => $this->scopes( $minimal_scopes ), 'bearer_methods_supported' => [ 'header' ] ];
	}

	/**
	 * @return list<string>
	 * @throws OAuthFault When the protocol input is invalid.
	 */
	private function scopes( mixed $scopes ): array {
		if ( ! is_array( $scopes ) || [] === $scopes || ! array_is_list( $scopes ) ) {
			throw new OAuthFault( 'invalid_request' );
		}
		foreach ( $scopes as $scope ) {
			if ( ! is_string( $scope ) || ! preg_match( '/^[\x21\x23-\x5b\x5d-\x7e]+$/D', $scope ) ) {
				throw new OAuthFault( 'invalid_request' );
			}
		}
		return array_values( array_unique( $scopes ) );
	}
}
