<?php
/**
 * Unpredictable credential identifiers.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\WordPress;

use Stonewright\WpMcp\Authorization\Ports\IdentifierSource;

/**
 * 40 random bytes as 80 lowercase hex characters: the shape of the access credential
 * identifier (jti). Collisions are checked by the unique keys the identifiers are
 * stored under; 320 random bits make one practically impossible.
 */
final class RandomIdentifiers implements IdentifierSource {

	public function next(): string {
		return bin2hex( random_bytes( 40 ) );
	}
}
