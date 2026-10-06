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

/**
 * Exact callback matching with the native loopback port exception: a native client's
 * HTTP callback at 127.0.0.1, [::1] or localhost may use another port than the one
 * registered, while scheme, host, path and query must stay identical. The host is never
 * substituted (localhost does not match 127.0.0.1), and HTTPS callbacks match exactly.
 */
final class RedirectRules {

	private const LOOPBACK_HOSTS = [ '127.0.0.1', '[::1]', 'localhost' ];

	/**
	 * @param list<string> $registered Registered callback URIs.
	 * @throws OAuthFault When the protocol input is invalid.
	 */
	public function approve( string $requested, array $registered, bool $native_client ): string {
		$parts = $this->parts( $requested, $native_client );
		foreach ( $registered as $candidate ) {
			$candidate_parts = $this->parts( $candidate, $native_client );
			if ( hash_equals( $candidate, $requested ) ) {
				return $requested;
			}
			if ( $native_client && 'http' === $parts['scheme'] && in_array( $parts['host'], self::LOOPBACK_HOSTS, true ) ) {
				unset( $parts['port'], $candidate_parts['port'] );
				if ( $parts === $candidate_parts ) {
					return $requested;
				}
			}
		}
		throw new OAuthFault( 'invalid_redirect_uri' );
	}

	public function require_exchange_match( string $supplied, string $approved ): void {
		if ( ! hash_equals( $approved, $supplied ) ) {
			throw new OAuthFault( 'invalid_grant' );
		}
	}

	/**
	 * @return array<string, int|string>
	 * @throws OAuthFault When the protocol input is invalid.
	 */
	private function parts( string $uri, bool $native_client ): array {
		if ( preg_match( '/[\x00-\x20\x7f\\\\]/', $uri ) || preg_match( '/%(?![0-9a-fA-F]{2})/', $uri ) ) {
			throw new OAuthFault( 'invalid_redirect_uri' );
		}
		$parts = parse_url( $uri );
		if ( false === $parts || ! isset( $parts['scheme'], $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ) {
			throw new OAuthFault( 'invalid_redirect_uri' );
		}
		$host = $parts['host'];
		if ( str_contains( $host, ':' ) || str_contains( $host, '[' ) || str_contains( $host, ']' ) ) {
			if ( ! preg_match( '/^\[[0-9a-fA-F:]+\]$/D', $host ) || false === filter_var( substr( $host, 1, -1 ), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
				throw new OAuthFault( 'invalid_redirect_uri' );
			}
		} elseif ( ! preg_match( '/^[A-Za-z0-9.-]+$/D', $host ) ) {
			throw new OAuthFault( 'invalid_redirect_uri' );
		}
		if ( 'https' !== $parts['scheme'] && ! ( $native_client && 'http' === $parts['scheme'] && in_array( $host, self::LOOPBACK_HOSTS, true ) ) ) {
			throw new OAuthFault( 'invalid_redirect_uri' );
		}
		return $parts;
	}
}
