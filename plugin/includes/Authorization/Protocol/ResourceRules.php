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

/** Resource-set selection never widens the consented audience. */
final class ResourceRules {

	/**
	 * @return list<string>
	 * @throws OAuthFault When the protocol input is invalid.
	 */
	public function require_authorized( array $requested, array $consented ): array {
		if ( [] === $requested || ! array_is_list( $requested ) || ! array_is_list( $consented ) ) {
			throw new OAuthFault( 'invalid_target' );
		}
		$allowed = array_map( [ $this, 'canonical' ], $consented );
		$selected = array_values( array_unique( array_map( [ $this, 'canonical' ], $requested ) ) );
		if ( array_diff( $selected, $allowed ) ) {
			throw new OAuthFault( 'invalid_target' );
		}
		return $selected;
	}

	private function canonical( mixed $uri ): string {
		if ( ! is_string( $uri ) || preg_match( '/[^A-Za-z0-9._~:\/\?\[\]@!$&\x27()*+,;=%-]/', $uri ) || preg_match( '/%(?![0-9a-fA-F]{2})/', $uri ) || ! preg_match( '/^[A-Za-z][A-Za-z0-9+.-]*:.+$/D', $uri ) ) {
			throw new OAuthFault( 'invalid_target' );
		}
		$parts = parse_url( $uri );
		// A value such as "localhost:8080" reads as a host and a port, so it has no scheme.
		if ( false === $parts || ! isset( $parts['scheme'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || preg_match( '/[\[\]]/', ( $parts['path'] ?? '' ) . ( $parts['query'] ?? '' ) ) || ( isset( $parts['host'] ) && ! $this->valid_host( $parts['host'] ) ) || ( isset( $parts['port'] ) && $parts['port'] < 1 ) ) {
			throw new OAuthFault( 'invalid_target' );
		}
		$scheme = strtolower( $parts['scheme'] );
		if ( in_array( $scheme, [ 'http', 'https' ], true ) ) {
			if ( ! isset( $parts['host'] ) || '' === $parts['host'] ) {
				throw new OAuthFault( 'invalid_target' );
			}
			return $scheme . '://' . strtolower( $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . ( $parts['path'] ?? '' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
		}
		return $scheme . substr( $uri, strlen( $parts['scheme'] ) );
	}

	private function valid_host( string $host ): bool {
		if ( str_starts_with( $host, '[' ) ) {
			return str_ends_with( $host, ']' ) && false !== filter_var( substr( $host, 1, -1 ), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 );
		}
		return 1 === preg_match( '/^(?:[A-Za-z0-9._~!$&\x27()*+,;=-]|%[0-9A-Fa-f]{2})+$/D', $host );
	}
}
