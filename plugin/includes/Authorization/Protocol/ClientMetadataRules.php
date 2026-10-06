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

/** RFC 7591 metadata decisions; registration persistence is not implemented here. */
final class ClientMetadataRules {

	private const LINK_FIELDS = [ 'client_uri', 'logo_uri', 'tos_uri', 'policy_uri' ];

	public function __construct( private ?TransportPolicy $transport = null ) {
		$this->transport ??= new TransportPolicy();
	}

	/**
	 * @return array<string, mixed>
	 * @throws OAuthFault When the protocol input is invalid.
	 */
	public function accept( array $requested, array $supported_policy ): array {
		$accepted = [];
		foreach ( [ 'redirect_uris', 'grant_types', 'response_types' ] as $name ) {
			$value = $requested[ $name ] ?? ( 'redirect_uris' === $name ? [] : [ 'grant_types' === $name ? 'authorization_code' : 'code' ] );
			if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
				throw new OAuthFault( 'invalid_client_metadata' );
			}
			foreach ( $value as $item ) {
				if ( ! is_string( $item ) ) {
					throw new OAuthFault( 'invalid_client_metadata' );
				}
			}
			$accepted[ $name ] = array_values( array_unique( $value ) );
		}
		if ( [] === $accepted['redirect_uris'] || count( $accepted['redirect_uris'] ) > ( $supported_policy['maximum_redirects'] ?? 10 ) ) {
			throw new OAuthFault( 'invalid_redirect_uri' );
		}
		foreach ( $accepted['redirect_uris'] as $uri ) {
			( new RedirectRules() )->approve( $uri, [ $uri ], (bool) ( $supported_policy['native_clients'] ?? false ) );
		}
		foreach ( [ 'grant_types', 'response_types' ] as $name ) {
			if ( [] === $accepted[ $name ] || array_diff( $accepted[ $name ], $supported_policy[ $name ] ?? [] ) ) {
				throw new OAuthFault( 'invalid_client_metadata' );
			}
		}
		if ( in_array( 'authorization_code', $accepted['grant_types'], true ) !== in_array( 'code', $accepted['response_types'], true ) ) {
			throw new OAuthFault( 'invalid_client_metadata' );
		}
		// An omitted method is the RFC 7591 default unless the policy names an explicit
		// substitute (RFC 7591 section 3.2.1); the response then reports the substitute.
		$method = $requested['token_endpoint_auth_method'] ?? ( $supported_policy['omitted_authentication_method'] ?? 'client_secret_basic' );
		if ( ! is_string( $method ) || ! in_array( $method, $supported_policy['authentication_methods'] ?? [], true ) ) {
			throw new OAuthFault( 'invalid_client_metadata' );
		}
		$accepted['token_endpoint_auth_method'] = $method;
		foreach ( [ 'client_name', 'client_uri', 'logo_uri', 'tos_uri', 'policy_uri', 'scope' ] as $name ) {
			if ( array_key_exists( $name, $requested ) ) {
				if ( ! is_string( $requested[ $name ] ) || strlen( $requested[ $name ] ) > ( $supported_policy['maximum_text_bytes'] ?? 1024 ) ) {
					throw new OAuthFault( 'invalid_client_metadata' );
				}
				if ( in_array( $name, self::LINK_FIELDS, true ) ) {
					$this->require_safe_link( $requested[ $name ] );
				}
				$accepted[ $name ] = $requested[ $name ];
			}
		}
		return $accepted;
	}

	/**
	 * Links shown during consent must be absolute HTTPS URLs written only with URI
	 * characters (brackets only around an IP-literal host); loopback HTTP is accepted
	 * only under the injected development transport policy. Any other scheme, user
	 * information, or malformed escape fails.
	 *
	 * @throws OAuthFault When the link is not a safe absolute URL.
	 */
	private function require_safe_link( string $uri ): void {
		if ( ! preg_match( '/^[A-Za-z0-9._~:\/?\[\]@!$&\'()*+,;=%-]+(?:#[A-Za-z0-9._~:\/?@!$&\'()*+,;=%-]*)?$/D', $uri ) || preg_match( '/%(?![0-9a-fA-F]{2})/', $uri ) ) {
			throw new OAuthFault( 'invalid_client_metadata' );
		}
		$fragment = strpos( $uri, '#' );
		$base = false === $fragment ? $uri : substr( $uri, 0, $fragment );
		$parts = parse_url( $base );
		if ( ! is_array( $parts ) || preg_match( '/[\[\]]/', ( $parts['path'] ?? '' ) . ( $parts['query'] ?? '' ) ) ) {
			throw new OAuthFault( 'invalid_client_metadata' );
		}
		try {
			$this->transport->require_secure( $base );
		} catch ( OAuthFault $fault ) {
			throw new OAuthFault( 'invalid_client_metadata' );
		}
	}
}
