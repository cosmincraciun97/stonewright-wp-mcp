<?php
/**
 * Authorization foundation.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\Refresh;

use Stonewright\WpMcp\Authorization\Model\FamilyState;
use Stonewright\WpMcp\Authorization\Model\RotationDemand;
use Stonewright\WpMcp\Authorization\Model\RefreshOutcome;
use Stonewright\WpMcp\Authorization\Model\OAuthFault;
use Stonewright\WpMcp\Authorization\Ports\FamilyLedger;
use Stonewright\WpMcp\Authorization\Ports\Clock;
use Stonewright\WpMcp\Authorization\Ports\IdentifierSource;
use Stonewright\WpMcp\Authorization\Ports\SubjectAuthority;

/** The ledger owns serialization; every retry makes a new authoritative decision. */
final class RefreshCoordinator {

	public function __construct( private FamilyLedger $ledger, private RotationDecision $decision, private Clock $clock, private IdentifierSource $identifiers, private SubjectAuthority $authority ) {}

	/**
	 * The decision classifies first, so replay revocation and expiry never depend on
	 * current permissions. Rotation and re-delivery additionally require the subject's
	 * current permission; losing it is a token-endpoint invalid_grant (RFC 6749 section
	 * 5.2) that changes nothing.
	 */
	public function rotate( RotationDemand $demand ): RefreshOutcome {
		return $this->ledger->change(
			$demand->family_key,
			function ( FamilyState $current ) use ( $demand ): RefreshOutcome {
				$outcome = $this->decision->decide( $current, $demand, $this->clock->now(), $this->identifiers->next(), $this->identifiers->next() );
				if ( null === $outcome->issuance ) {
					return $outcome;
				}
				$state = $current->to_array();
				try {
					$this->authority->require_allowed( $state['subject_key'], 'refresh', [ 'client_key' => $state['client_key'], 'resources' => $state['consented_resources'] ] );
				} catch ( OAuthFault $fault ) {
					return new RefreshOutcome( $current, null, new OAuthFault( 'invalid_grant' ) );
				}
				return $outcome;
			}
		);
	}

	/** Close the family for client revocation (RFC 7009) or an administrative disconnect. */
	public function revoke( string $family_key ): FamilyState {
		return $this->ledger->change( $family_key, static fn ( FamilyState $current ): RefreshOutcome => new RefreshOutcome( $current->revoke(), null, null ) )->state;
	}

	/** Drop consumed ancestors that can no longer be duplicates; the current credential and its predecessor stay. */
	public function compact( string $family_key ): FamilyState {
		return $this->ledger->change( $family_key, fn ( FamilyState $current ): RefreshOutcome => new RefreshOutcome( $current->compact( $this->clock->now(), $this->decision->policy->duplicate_window() ), null, null ) )->state;
	}
}
