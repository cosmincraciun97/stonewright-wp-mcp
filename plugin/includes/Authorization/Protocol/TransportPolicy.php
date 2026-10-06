<?php
/**
 * Endpoint transport policy.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\Protocol;

use Stonewright\WpMcp\Authorization\Model\OAuthFault;

/**
 * Local development is an explicit injected policy, never inferred from a request.
 *
 * Endpoints use HTTPS. Two injected exceptions exist: the development loopback rule
 * accepts plain HTTP at 127.0.0.1, [::1] and localhost, and a list of declared local
 * origins (the site's own origin when its operator marked the installation local)
 * accepts plain HTTP at exactly those origins. Neither exception widens callback
 * matching, which RedirectRules decides separately.
 */
final class TransportPolicy {

	/** @var list<string> Lowercase "http://host[:port]" origins. */
	private array $local_origins = [];

	/**
	 * @param list<string> $local_origins Plain HTTP origins of a site declared local.
	 * @throws \InvalidArgumentException When a declared origin is not a plain HTTP origin.
	 */
	public function __construct( private bool $allow_development_loopback = false, array $local_origins = [] ) {
		foreach ( $local_origins as $origin ) {
			$normalized = is_string( $origin ) ? self::origin( $origin ) : null;
			if ( null === $normalized || strtolower( $origin ) !== $normalized ) {
				throw new \InvalidArgumentException( 'A local origin is scheme, host and optional port of a plain HTTP URL.' );
			}
			$this->local_origins[] = $normalized;
		}
	}

	public function require_secure( mixed $url ): void {
		if ( ! is_string( $url ) ) {
			throw new OAuthFault( 'invalid_request' );
		}
		$parts = parse_url( $url );
		$http = is_array( $parts ) && 'http' === ( $parts['scheme'] ?? null );
		if ( $http && [] !== $this->local_origins && in_array( self::origin( $url ), $this->local_origins, true ) ) {
			// The HTTPS spelling of the same URL carries every syntax rule (no user information, fragment or control characters).
			$secure = 'https' . substr( $url, 4 );
			( new RedirectRules() )->approve( $secure, [ $secure ], false );
			return;
		}
		$local = $this->allow_development_loopback && $http && in_array( $parts['host'] ?? null, [ '127.0.0.1', '[::1]', 'localhost' ], true );
		( new RedirectRules() )->approve( $url, [ $url ], $local );
		if ( ! $local && 'https' !== ( $parts['scheme'] ?? null ) ) {
			throw new OAuthFault( 'invalid_request' );
		}
	}

	/** Lowercase "http://host[:port]" of a plain HTTP URL without user information, or null. */
	private static function origin( string $url ): ?string {
		$parts = parse_url( $url );
		if ( ! is_array( $parts ) || 'http' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || ! isset( $parts['host'] ) || '' === $parts['host'] || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return null;
		}
		return 'http://' . strtolower( $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
	}
}
