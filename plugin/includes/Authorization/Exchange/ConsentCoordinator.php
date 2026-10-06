<?php
/**
 * Fresh-state consent orchestration.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\Exchange;

use Stonewright\WpMcp\Authorization\Decisions\ConsentDecision;
use Stonewright\WpMcp\Authorization\Ports\Clock;
use Stonewright\WpMcp\Authorization\Ports\ConsentLedger;
use Stonewright\WpMcp\Authorization\Ports\IdentifierSource;
use Stonewright\WpMcp\Authorization\Ports\SubjectAuthority;

/** Subject identity and form integrity must be verified by the web adapter, not copied from input. */
final class ConsentCoordinator {

	public function __construct( private ConsentLedger $ledger, private Clock $clock, private IdentifierSource $identifiers, private SubjectAuthority $subjects, private ?ConsentDecision $decision = null ) {
		$this->decision ??= new ConsentDecision();
	}

	public function decide( string $pending_key, string $subject_key, bool $form_integrity, bool $approved ): array {
		return $this->ledger->change(
			$pending_key,
			function ( array $pending ) use ( $subject_key, $form_integrity, $approved ): array {
				$this->subjects->require_allowed( $subject_key, 'consent', [ 'client_key' => $pending['client_key'], 'scopes' => $pending['scopes'], 'resources' => $pending['resources'] ] );
				return $this->decision->decide( $pending, $subject_key, $form_integrity, true, $approved, $this->clock->now(), $approved ? $this->identifiers->next() : '' );
			}
		);
	}
}
