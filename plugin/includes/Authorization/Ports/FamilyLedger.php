<?php
/**
 * Authorization foundation.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );
namespace Stonewright\WpMcp\Authorization\Ports;

use Stonewright\WpMcp\Authorization\Model\FamilyState;
use Stonewright\WpMcp\Authorization\Model\RefreshOutcome;

/** Atomic authoritative transition; error outcomes may contain durable revocation. */
interface FamilyLedger {
	/**
	 * Evaluate against fresh state at the effect boundary. Commit only while the stored
	 * revision is still the one the decision observed (compare-and-swap or an equivalent
	 * lock); otherwise decide again on the new state and never reuse an earlier acceptance.
	 * Every state change advances the revision by exactly one. Persist the outcome state,
	 * including a revocation or expiry carried by a fault, and return only after it is
	 * durable. An unchanged outcome (a fault without a transition, or a duplicate
	 * re-delivery) writes nothing but is still confirmed against the current revision.
	 * Never encode or deliver credentials inside this operation.
	 * Unknown family: OAuthFault invalid_grant (HTTP 400).
	 *
	 * @param callable(FamilyState):RefreshOutcome $decide Pure state decision.
	 */
	public function change( string $family_key, callable $decide ): RefreshOutcome;
}
