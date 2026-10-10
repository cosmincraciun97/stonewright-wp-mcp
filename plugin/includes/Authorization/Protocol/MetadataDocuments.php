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

/**
 * Composes declared metadata; the WordPress adapter serves it. Optional capabilities
 * (issuer identification in authorization responses, client ID metadata documents and
 * introspection authentication methods) appear only when the caller declares them.
 */
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
		if ( array_key_exists( 'introspection_endpoint_auth_methods_supported', $capabilities ) ) {
			$methods = $this->methods( $capabilities['introspection_endpoint_auth_methods_supported'] );
			if ( isset( $document['introspection_endpoint'] ) ) {
				$document['introspection_endpoint_auth_methods_supported'] = $methods;
			}
		}
		// Declared only when the caller implements them (RFC 9207 section 3; client ID metadata documents).
		foreach ( [ 'authorization_response_iss_parameter_supported', 'client_id_metadata_document_supported' ] as $flag ) {
			if ( ! array_key_exists( $flag, $capabilities ) ) {
				continue;
			}
			if ( ! is_bool( $capabilities[ $flag ] ) ) {
				throw new OAuthFault( 'invalid_request' );
			}
			if ( $capabilities[ $flag ] ) {
				$document[ $flag ] = true;
			}
		}
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
	 * @throws OAuthFault When the list is empty or holds anything but method names.
	 */
	private function methods( mixed $methods ): array {
		if ( ! is_array( $methods ) || [] === $methods || ! array_is_list( $methods ) ) {
			throw new OAuthFault( 'invalid_request' );
		}
		foreach ( $methods as $method ) {
			if ( ! is_string( $method ) || ! preg_match( '/^[a-z0-9_]{1,64}$/D', $method ) ) {
				throw new OAuthFault( 'invalid_request' );
			}
		}
		return array_values( array_unique( $methods ) );
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
