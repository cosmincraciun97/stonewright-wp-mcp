<?php
/**
 * Public well-known metadata URL construction.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\Protocol;

use Stonewright\WpMcp\Authorization\Model\OAuthFault;

final class DiscoveryLocations {

	public function __construct( private ?TransportPolicy $transport = null ) {
		$this->transport ??= new TransportPolicy();
	}

	public function authorization_server( string $issuer ): string {
		$this->transport->require_secure( $issuer );
		if ( isset( parse_url( $issuer )['query'] ) ) {
			throw new OAuthFault( 'invalid_request' );
		}
		return $this->insert( $issuer, 'oauth-authorization-server' );
	}

	public function protected_resource( string $resource ): string {
		$this->transport->require_secure( $resource );
		return $this->insert( $resource, 'oauth-protected-resource' );
	}

	/** Any terminating "/" leaves the path before the well-known segment is inserted (RFC 8414 and RFC 9728, section 3.1). */
	private function insert( string $url, string $suffix ): string {
		$parts = parse_url( $url );
		$origin = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		return $origin . '/.well-known/' . $suffix . rtrim( $parts['path'] ?? '', '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
	}
}
