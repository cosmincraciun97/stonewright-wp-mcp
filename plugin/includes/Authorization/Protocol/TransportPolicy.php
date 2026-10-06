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

/** Local development is an explicit injected policy, never inferred from a request. */
final class TransportPolicy {

	public function __construct( private bool $allow_development_loopback = false ) {}

	public function require_secure( mixed $url ): void {
		if ( ! is_string( $url ) ) {
			throw new OAuthFault( 'invalid_request' );
		}
		$parts = parse_url( $url );
		$local = $this->allow_development_loopback && is_array( $parts ) && 'http' === ( $parts['scheme'] ?? null ) && in_array( $parts['host'] ?? null, [ '127.0.0.1', '[::1]', 'localhost' ], true );
		( new RedirectRules() )->approve( $url, [ $url ], $local );
		if ( ! $local && 'https' !== ( $parts['scheme'] ?? null ) ) {
			throw new OAuthFault( 'invalid_request' );
		}
	}
}
