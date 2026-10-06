<?php
/**
 * Authorization foundation.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\Model;

/**
 * Next family state plus at most one result. A refresh decision always carries an
 * issuance intent or a fault; security transitions (revocation, expiry) commit even
 * when the protocol response is a fault. Maintenance transitions such as revocation
 * by the client or an administrator, and history compaction, carry neither.
 */
final class RefreshOutcome {

	public function __construct( public readonly FamilyState $state, public readonly ?IssuanceIntent $issuance, public readonly ?OAuthFault $fault ) {
		if ( null !== $issuance && null !== $fault ) {
			throw new \InvalidArgumentException( 'An outcome carries at most one result.' );
		}
	}
}
