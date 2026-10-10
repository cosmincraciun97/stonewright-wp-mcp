<?php
/**
 * Authorized introspection disclosure.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\Decisions;

use Stonewright\WpMcp\Authorization\Model\CredentialFacts;
use Stonewright\WpMcp\Authorization\Model\FamilyState;
use Stonewright\WpMcp\Authorization\Model\OAuthFault;
use Stonewright\WpMcp\Authorization\Protocol\ScopeRules;

/**
 * A refresh credential is active only as the unconsumed, unexpired current credential
 * of its active family before the family deadline, so the adapter supplies current
 * family state; without it the refresh credential is reported inactive. Access
 * credentials keep their own expiry and the adapter's revocation fact.
 */
final class IntrospectionDecision {

	public function inspect( ?CredentialFacts $facts, bool $caller_authorized, bool $may_disclose, bool $revoked, int $now, array $allowed_claims, ?FamilyState $family = null ): array {
		if ( ! $caller_authorized ) {
			throw new OAuthFault( 'invalid_client', 401 );
		}
		if ( ! array_is_list( $allowed_claims ) || array_diff( $allowed_claims, [ 'client_id', 'scope', 'sub', 'aud', 'exp', 'token_type' ] ) ) {
			throw new \InvalidArgumentException( 'Invalid introspection disclosure policy.' );
		}
		if ( null === $facts || ! $may_disclose || $revoked || $now < 0 || $now >= $facts->expires_at || ( 'refresh_token' === $facts->kind && ! $this->live_refresh( $facts, $family, $now ) ) ) {
			return [ 'active' => false ];
		}
		$claims = [ 'client_id' => $facts->client_key, 'scope' => implode( ' ', ( new ScopeRules() )->normalize( $facts->scopes, true ) ), 'sub' => $facts->subject_key, 'aud' => $facts->resources, 'exp' => $facts->expires_at ];
		if ( 'access_token' === $facts->kind ) {
			$claims['token_type'] = 'Bearer';
		}
		return [ 'active' => true ] + array_intersect_key( $claims, array_fill_keys( $allowed_claims, true ) );
	}

	private function live_refresh( CredentialFacts $facts, ?FamilyState $family, int $now ): bool {
		if ( null === $family ) {
			return false;
		}
		$state = $family->to_array();
		return $facts->family_key === $state['family_key'] && $facts->client_key === $state['client_key'] && $family->credential_active( $facts->credential_key, $now );
	}
}
