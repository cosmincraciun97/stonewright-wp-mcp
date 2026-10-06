<?php
/**
 * Atomic authorization code persistence boundary.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\Ports;

use Stonewright\WpMcp\Authorization\Model\CodeExchangeOutcome;

interface CodeLedger {
	/**
	 * Decide against current CodeGrantState inside the durable boundary.
	 * Re-evaluate after conflicts; commit code consumption and family creation together.
	 * Apply any replay revocation even on an error outcome before returning it.
	 * An unknown code_key throws OAuthFault invalid_grant (HTTP 400) and changes nothing;
	 * it never surfaces as another exception type.
	 * Keep every code until CodeGrantState::retained_until(): a used code stays
	 * recognizable until its expiry plus CodeGrantState::USED_CODE_RETENTION, so a
	 * replay still revokes the family it created. Forgetting it earlier is not allowed.
	 * Throw on failed persistence; never encode or deliver tokens inside this boundary.
	 */
	public function change( string $code_key, callable $decide ): CodeExchangeOutcome;
}
