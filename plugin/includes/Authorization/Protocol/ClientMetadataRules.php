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
 * RFC 7591 metadata decisions; registration persistence is not implemented here.
 *
 * Policy keys: authentication_methods, omitted_authentication_method, grant_types, response_types,
 * native_clients, maximum_redirects, maximum_text_bytes and substitute_unsupported_types. With the
 * last one set, grant and response types the server does not support are left out of the accepted
 * metadata instead of refusing it (RFC 7591 section 3.2.1); what remains must still describe the
 * authorization code flow. Fields the rules do not know are never copied.
 */
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
				throw new OAuthFault( 'invalid_client_metadata', null, $name );
			}
			foreach ( $value as $item ) {
				if ( ! is_string( $item ) ) {
					throw new OAuthFault( 'invalid_client_metadata', null, $name );
				}
			}
			$accepted[ $name ] = array_values( array_unique( $value ) );
		}
		if ( [] === $accepted['redirect_uris'] || count( $accepted['redirect_uris'] ) > ( $supported_policy['maximum_redirects'] ?? 10 ) ) {
			throw new OAuthFault( 'invalid_redirect_uri', null, 'redirect_uris' );
		}
		foreach ( $accepted['redirect_uris'] as $uri ) {
			try {
				( new RedirectRules() )->approve( $uri, [ $uri ], (bool) ( $supported_policy['native_clients'] ?? false ) );
			} catch ( OAuthFault $fault ) {
				throw new OAuthFault( $fault->error(), $fault->status(), 'redirect_uris' );
			}
		}
		foreach ( [ 'grant_types', 'response_types' ] as $name ) {
			if ( ! empty( $supported_policy['substitute_unsupported_types'] ) ) {
				$accepted[ $name ] = array_values( array_intersect( $accepted[ $name ], $supported_policy[ $name ] ?? [] ) );
			}
			if ( [] === $accepted[ $name ] || array_diff( $accepted[ $name ], $supported_policy[ $name ] ?? [] ) ) {
				throw new OAuthFault( 'invalid_client_metadata', null, $name );
			}
		}
		// The only flow served is the authorization code flow, so it must be among what is left.
		if ( ! in_array( 'authorization_code', $accepted['grant_types'], true ) || ! in_array( 'code', $accepted['response_types'], true ) ) {
			throw new OAuthFault( 'invalid_client_metadata', null, 'grant_types' );
		}
		// An omitted method is the RFC 7591 default unless the policy names an explicit
		// substitute (RFC 7591 section 3.2.1); the response then reports the substitute.
		$method = $requested['token_endpoint_auth_method'] ?? ( $supported_policy['omitted_authentication_method'] ?? 'client_secret_basic' );
		if ( ! is_string( $method ) || ! in_array( $method, $supported_policy['authentication_methods'] ?? [], true ) ) {
			throw new OAuthFault( 'invalid_client_metadata', null, 'token_endpoint_auth_method' );
		}
		$accepted['token_endpoint_auth_method'] = $method;
		foreach ( [ 'client_name', 'client_uri', 'logo_uri', 'tos_uri', 'policy_uri', 'scope' ] as $name ) {
			if ( array_key_exists( $name, $requested ) ) {
				if ( ! is_string( $requested[ $name ] ) || strlen( $requested[ $name ] ) > ( $supported_policy['maximum_text_bytes'] ?? 1024 ) ) {
					throw new OAuthFault( 'invalid_client_metadata', null, $name );
				}
				if ( in_array( $name, self::LINK_FIELDS, true ) ) {
					$this->require_safe_link( $requested[ $name ], $name );
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
	private function require_safe_link( string $uri, string $field ): void {
		if ( ! preg_match( '/^[A-Za-z0-9._~:\/?\[\]@!$&\'()*+,;=%-]+(?:#[A-Za-z0-9._~:\/?@!$&\'()*+,;=%-]*)?$/D', $uri ) || preg_match( '/%(?![0-9a-fA-F]{2})/', $uri ) ) {
			throw new OAuthFault( 'invalid_client_metadata', null, $field );
		}
		$fragment = strpos( $uri, '#' );
		$base = false === $fragment ? $uri : substr( $uri, 0, $fragment );
		$parts = parse_url( $base );
		if ( ! is_array( $parts ) || preg_match( '/[\[\]]/', ( $parts['path'] ?? '' ) . ( $parts['query'] ?? '' ) ) ) {
			throw new OAuthFault( 'invalid_client_metadata', null, $field );
		}
		try {
			$this->transport->require_secure( $base );
		} catch ( OAuthFault $fault ) {
			throw new OAuthFault( 'invalid_client_metadata', null, $field );
		}
	}
}
