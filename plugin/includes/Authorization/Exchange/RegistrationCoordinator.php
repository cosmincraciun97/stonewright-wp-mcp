<?php
/**
 * Public-client dynamic registration.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\Exchange;

use Stonewright\WpMcp\Authorization\Model\OAuthFault;
use Stonewright\WpMcp\Authorization\Ports\ClientDirectory;
use Stonewright\WpMcp\Authorization\Protocol\ClientMetadataRules;
use Stonewright\WpMcp\Authorization\Protocol\RequestDecoder;
use Stonewright\WpMcp\Authorization\Protocol\ScopeRules;
use Stonewright\WpMcp\Authorization\Protocol\TransportPolicy;

/** Registration admission and transport enforcement belong before this bounded transaction. */
final class RegistrationCoordinator {

	public function __construct( private ClientDirectory $clients, private array $supported_policy, private int $maximum_body_bytes, private ?TransportPolicy $transport = null ) {
		if ( $maximum_body_bytes < 1 ) {
			throw new \InvalidArgumentException( 'Invalid registration body limit.' );
		}
	}

	public function register( string $body ): array {
		$requested = ( new RequestDecoder() )->registration( $body, $this->maximum_body_bytes );
		$accepted = ( new ClientMetadataRules( $this->transport ) )->accept( $requested, $this->supported_policy );
		if ( 'none' !== $accepted['token_endpoint_auth_method'] ) {
			throw new OAuthFault( 'invalid_client_metadata' );
		}
		if ( isset( $accepted['scope'] ) ) {
			try {
				$scopes = ( new ScopeRules() )->normalize( explode( ' ', $accepted['scope'] ) );
				if ( isset( $this->supported_policy['scopes_supported'] ) ) {
					$scopes = ( new ScopeRules() )->require_subset( $scopes, $this->supported_policy['scopes_supported'] );
				}
				$accepted['scope'] = implode( ' ', $scopes );
			} catch ( OAuthFault $fault ) {
				throw new OAuthFault( 'invalid_client_metadata' );
			}
		}
		$assigned = $this->clients->create( $accepted );
		if ( ! isset( $assigned['client_id'] ) || ! is_string( $assigned['client_id'] ) || ! preg_match( '/^[\x20-\x7e]{1,1024}$/D', $assigned['client_id'] ) ) {
			throw new OAuthFault( 'server_error' );
		}
		return [ 'status' => 201, 'headers' => [ 'Content-Type' => 'application/json', 'Cache-Control' => 'no-store', 'Pragma' => 'no-cache' ], 'body' => [ 'client_id' => $assigned['client_id'] ] + $accepted ];
	}
}
