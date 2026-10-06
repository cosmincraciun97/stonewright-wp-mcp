<?php
/**
 * One-use authorization code exchange.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\Decisions;

use Stonewright\WpMcp\Authorization\Model\CodeDemand;
use Stonewright\WpMcp\Authorization\Model\CodeGrantState;
use Stonewright\WpMcp\Authorization\Model\CodeExchangeOutcome;
use Stonewright\WpMcp\Authorization\Model\FamilyState;
use Stonewright\WpMcp\Authorization\Model\IssuanceIntent;
use Stonewright\WpMcp\Authorization\Model\OAuthFault;
use Stonewright\WpMcp\Authorization\Protocol\CodeProof;
use Stonewright\WpMcp\Authorization\Protocol\RedirectRules;
use Stonewright\WpMcp\Authorization\Protocol\ResourceRules;
use Stonewright\WpMcp\Authorization\Refresh\RefreshPolicy;

/** Run against current code state inside the exchange ledger's durable boundary. */
final class AuthorizationCodeDecision {

	public function __construct( private RefreshPolicy $policy ) {}

	public function redeem( CodeGrantState $code, CodeDemand $demand, int $now, string $family_key, string $access_key, string $refresh_key ): CodeExchangeOutcome {
		$state = $code->to_array();
		if ( $state['code_key'] !== $demand->code_key || $state['client_key'] !== $demand->client_key ) {
			return $this->failure( $code, new OAuthFault( 'invalid_grant' ) );
		}
		try {
			( new RedirectRules() )->require_exchange_match( $demand->redirect_uri, $state['redirect_uri'] );
			( new CodeProof() )->require_match( $demand->verifier, $state['code_challenge'] );
			$resources = ( new ResourceRules() )->require_authorized( $demand->resources, $state['resources'] );
		} catch ( OAuthFault $fault ) {
			return $this->failure( $code, $fault );
		}
		if ( $state['used'] ) {
			return $this->failure( $code, new OAuthFault( 'invalid_grant' ), $state['issued_family_key'] );
		}
		if ( $now < 0 || $now >= $state['expires_at'] ) {
			return $this->failure( $code, new OAuthFault( 'invalid_grant' ) );
		}
		if ( ! $this->policy->accepts_time( $now ) || in_array( '', [ $family_key, $access_key, $refresh_key ], true ) || count( array_unique( [ $family_key, $access_key, $refresh_key ] ) ) !== 3 ) {
			return $this->failure( $code, new OAuthFault( 'server_error' ) );
		}
		$deadline = $this->policy->family_deadline( $now );
		$refresh_deadline = $this->policy->refresh_deadline( $now, $deadline );
		$family = FamilyState::from_array( [ 'family_key' => $family_key, 'client_key' => $state['client_key'], 'subject_key' => $state['subject_key'], 'current_refresh_key' => $refresh_key, 'consented_scopes' => $state['scopes'], 'consented_resources' => $resources, 'family_deadline' => $deadline, 'phase' => 'active', 'revision' => 0, 'credential_history' => [ $refresh_key => [ 'expires_at' => $refresh_deadline, 'consumed' => false, 'consumed_at' => null, 'successor_key' => null ] ] ] );
		$issuance = new IssuanceIntent( $family_key, $access_key, $refresh_key, $this->policy->access_deadline( $now ), $refresh_deadline, $state['scopes'], $resources, 0 );
		return new CodeExchangeOutcome( $code->consume( $family_key ), $family, $issuance, null );
	}

	private function failure( CodeGrantState $code, OAuthFault $fault, ?string $revoke_family_key = null ): CodeExchangeOutcome {
		return new CodeExchangeOutcome( $code, null, null, $fault, $revoke_family_key );
	}
}
