<?php
/**
 * OAuth scope validation.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\Protocol;

use Stonewright\WpMcp\Authorization\Model\OAuthFault;

final class ScopeRules {

	public function normalize( array $scopes, bool $allow_empty = false ): array {
		if ( ! array_is_list( $scopes ) || ( ! $allow_empty && [] === $scopes ) ) {
			throw new OAuthFault( 'invalid_scope' );
		}
		foreach ( $scopes as $scope ) {
			if ( ! is_string( $scope ) || ! preg_match( '/^[\x21\x23-\x5b\x5d-\x7e]+$/D', $scope ) ) {
				throw new OAuthFault( 'invalid_scope' );
			}
		}
		return array_values( array_unique( $scopes ) );
	}

	public function require_subset( array $requested, array $consented ): array {
		$selected = $this->normalize( $requested, true );
		if ( array_diff( $selected, $this->normalize( $consented ) ) ) {
			throw new OAuthFault( 'invalid_scope' );
		}
		return $selected;
	}
}
