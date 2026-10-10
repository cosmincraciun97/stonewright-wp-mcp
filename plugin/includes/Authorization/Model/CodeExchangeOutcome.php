<?php
/**
 * Atomic code exchange outcome.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\Model;

final class CodeExchangeOutcome {
	public function __construct( public readonly CodeGrantState $state, public readonly ?FamilyState $family, public readonly ?IssuanceIntent $issuance, public readonly ?OAuthFault $fault, public readonly ?string $revoke_family_key = null ) {
		if ( null === $fault ? null === $family || null === $issuance || null !== $revoke_family_key : null !== $family || null !== $issuance || '' === $revoke_family_key ) {
			throw new \InvalidArgumentException( 'Invalid code outcome.' );
		}
	}
}
