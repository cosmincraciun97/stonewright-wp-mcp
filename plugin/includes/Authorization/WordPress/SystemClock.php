<?php
/**
 * Runtime clock.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\WordPress;

use Stonewright\WpMcp\Authorization\Ports\Clock;

/** UTC epoch seconds from the server clock. */
final class SystemClock implements Clock {

	public function now(): int {
		return time();
	}
}
