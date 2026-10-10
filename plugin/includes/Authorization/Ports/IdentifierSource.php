<?php
/**
 * Authorization foundation.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );
namespace Stonewright\WpMcp\Authorization\Ports;

/** Production implementations must supply unpredictable, collision-checked IDs. */
interface IdentifierSource {
	public function next(): string;
}
