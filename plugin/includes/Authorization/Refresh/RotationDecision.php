<?php
/**
 * Authorization foundation.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\Refresh;

use Stonewright\WpMcp\Authorization\Model\FamilyState;
use Stonewright\WpMcp\Authorization\Model\IssuanceIntent;
use Stonewright\WpMcp\Authorization\Model\OAuthFault;
use Stonewright\WpMcp\Authorization\Model\RefreshOutcome;
use Stonewright\WpMcp\Authorization\Model\RotationDemand;
use Stonewright\WpMcp\Authorization\Protocol\ResourceRules;
use Stonewright\WpMcp\Authorization\Protocol\ScopeRules;

/**
 * One-lineage refresh decision. The presented credential is classified before any
 * scope or resource narrowing:
 * - the current credential rotates to exactly one successor;
 * - its immediate predecessor, presented inside the duplicate window, re-delivers the
 *   existing current credential with a new access credential and no state change;
 * - any other credential of the family is a replay that revokes the family;
 * - a key the family never recorded fails without any change, unless history was
 *   compacted, in which case an authenticated credential of the family is a replay.
 * Narrowing applies only to the two live outcomes.
 */
final class RotationDecision {

	private const HEAD = 'head';
	private const DUPLICATE = 'duplicate';
	private const REPLAY = 'replay';
	private const UNKNOWN = 'unknown';

	public function __construct( public readonly RefreshPolicy $policy ) {}

	public function decide( FamilyState $current, RotationDemand $demand, int $now, string $access_key, string $next_refresh_key ): RefreshOutcome {
		$state = $current->to_array();
		if ( $demand->family_key !== $state['family_key'] || $demand->client_key !== $state['client_key'] || 'active' !== $state['phase'] ) {
			return $this->failure( $current, 'invalid_grant' );
		}
		$class = $this->classify( $state, $demand->refresh_key, $now );
		if ( self::UNKNOWN === $class ) {
			return $this->failure( $current, 'invalid_grant' );
		}
		if ( $now >= $state['family_deadline'] ) {
			return $this->failure( $current->advance( [ 'phase' => 'expired', 'revision' => $state['revision'] + 1 ] ), 'invalid_grant' );
		}
		if ( self::REPLAY === $class ) {
			return $this->failure( $current->revoke(), 'invalid_grant' );
		}
		$head_key = $state['current_refresh_key'];
		$head = $state['credential_history'][ $head_key ];
		if ( $now >= $head['expires_at'] ) {
			return $this->failure( $current, 'invalid_grant' );
		}
		try {
			$resources = ( new ResourceRules() )->require_authorized( $demand->resources, $state['consented_resources'] );
			$requested = null === $demand->scopes || [] === $demand->scopes ? $state['consented_scopes'] : $demand->scopes;
			$scopes = ( new ScopeRules() )->require_subset( $requested, $state['consented_scopes'] );
		} catch ( OAuthFault $fault ) {
			return new RefreshOutcome( $current, null, $fault );
		}
		$history = $state['credential_history'];
		if ( '' === $access_key || isset( $history[ $access_key ] ) || ! $this->policy->accepts_time( $now ) ) {
			return $this->failure( $current, 'server_error' );
		}
		if ( self::DUPLICATE === $class ) {
			return new RefreshOutcome( $current, new IssuanceIntent( $state['family_key'], $access_key, $head_key, $this->policy->access_deadline( $now ), $head['expires_at'], $scopes, $resources, $state['revision'], true ), null );
		}
		if ( '' === $next_refresh_key || $access_key === $next_refresh_key || isset( $history[ $next_refresh_key ] ) ) {
			return $this->failure( $current, 'server_error' );
		}
		$refresh_deadline = $this->policy->refresh_deadline( $now, $state['family_deadline'] );
		$history[ $head_key ] = array_replace( $head, [ 'consumed' => true, 'consumed_at' => $now, 'successor_key' => $next_refresh_key ] );
		$history[ $next_refresh_key ] = [ 'expires_at' => $refresh_deadline, 'consumed' => false, 'consumed_at' => null, 'successor_key' => null ];
		$revision = $state['revision'] + 1;
		$next = $current->advance( [ 'credential_history' => $history, 'current_refresh_key' => $next_refresh_key, 'revision' => $revision ] );
		return new RefreshOutcome( $next, new IssuanceIntent( $state['family_key'], $access_key, $next_refresh_key, $this->policy->access_deadline( $now ), $refresh_deadline, $scopes, $resources, $revision ), null );
	}

	private function classify( array $state, string $key, int $now ): string {
		$history = $state['credential_history'];
		if ( ! isset( $history[ $key ] ) ) {
			return $state['compacted_entries'] > 0 ? self::REPLAY : self::UNKNOWN;
		}
		if ( $key === $state['current_refresh_key'] ) {
			return self::HEAD;
		}
		$entry = $history[ $key ];
		if ( $entry['successor_key'] === $state['current_refresh_key'] && null !== $entry['consumed_at'] && $this->policy->within_duplicate_window( $entry['consumed_at'], $now ) ) {
			return self::DUPLICATE;
		}
		return self::REPLAY;
	}

	private function failure( FamilyState $state, string $error ): RefreshOutcome {
		return new RefreshOutcome( $state, null, new OAuthFault( $error ) );
	}
}
