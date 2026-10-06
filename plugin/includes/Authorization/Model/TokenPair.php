<?php
/**
 * Authorization foundation.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\Model;

/** Ephemeral wire credentials; never write this object into audit/fixture state. */
final class TokenPair {

	public function __construct( public readonly string $access_token, public readonly string $refresh_token, public readonly int $expires_in ) {
		if ( '' === $access_token || '' === $refresh_token || $expires_in < 1 ) {
			throw new \InvalidArgumentException( 'Invalid credential response.' );
		}
	}
}
