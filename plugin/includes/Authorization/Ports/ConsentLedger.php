<?php
/**
 * Atomic consent transaction boundary.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\Ports;

interface ConsentLedger {
	/**
	 * Re-evaluate the decision against current, server-bound pending facts.
	 * Atomically consume the pending transaction and create the code on approval.
	 * Persist denial consumption too; no redirect or code may be delivered before commit.
	 * An unknown pending_key throws OAuthFault invalid_request (HTTP 400), the same fault as
	 * a used or expired transaction, changes nothing, and produces no redirect. It never
	 * surfaces as another exception type; only failed persistence may throw otherwise.
	 */
	public function change( string $pending_key, callable $decide ): array;
}
