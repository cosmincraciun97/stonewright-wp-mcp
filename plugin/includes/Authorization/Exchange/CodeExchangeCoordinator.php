<?php
/**
 * Fresh-state authorization code exchange.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\Exchange;

use Stonewright\WpMcp\Authorization\Decisions\AuthorizationCodeDecision;
use Stonewright\WpMcp\Authorization\Model\CodeDemand;
use Stonewright\WpMcp\Authorization\Model\CodeGrantState;
use Stonewright\WpMcp\Authorization\Model\CodeExchangeOutcome;
use Stonewright\WpMcp\Authorization\Model\OAuthFault;
use Stonewright\WpMcp\Authorization\Ports\Clock;
use Stonewright\WpMcp\Authorization\Ports\CodeLedger;
use Stonewright\WpMcp\Authorization\Ports\IdentifierSource;
use Stonewright\WpMcp\Authorization\Ports\SubjectAuthority;

final class CodeExchangeCoordinator {

	public function __construct( private CodeLedger $ledger, private AuthorizationCodeDecision $decision, private Clock $clock, private IdentifierSource $identifiers, private SubjectAuthority $subjects ) {}

	/**
	 * The decision runs first, so a replay requests revocation regardless of current
	 * permissions. Issuance additionally requires the subject's current permission;
	 * losing it is a token-endpoint invalid_grant (RFC 6749 section 5.2) that leaves
	 * the code unused.
	 */
	public function exchange( CodeDemand $demand ): CodeExchangeOutcome {
		return $this->ledger->change(
			$demand->code_key,
			function ( CodeGrantState $current ) use ( $demand ): CodeExchangeOutcome {
				$outcome = $this->decision->redeem( $current, $demand, $this->clock->now(), $this->identifiers->next(), $this->identifiers->next(), $this->identifiers->next() );
				if ( null === $outcome->issuance ) {
					return $outcome;
				}
				$state = $current->to_array();
				try {
					$this->subjects->require_allowed( $state['subject_key'], 'code_exchange', [ 'client_key' => $state['client_key'], 'resources' => $state['resources'], 'scopes' => $state['scopes'] ] );
				} catch ( OAuthFault $fault ) {
					return new CodeExchangeOutcome( $current, null, null, new OAuthFault( 'invalid_grant' ) );
				}
				return $outcome;
			}
		);
	}
}
