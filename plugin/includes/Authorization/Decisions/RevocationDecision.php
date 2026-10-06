<?php
/**
 * Revocation intent without storage assumptions.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\Decisions;

use Stonewright\WpMcp\Authorization\Model\CredentialFacts;
use Stonewright\WpMcp\Authorization\Model\OAuthFault;

/** A successful HTTP description may be delivered only after these effects are durable. */
final class RevocationDecision {

	public function revoke( ?CredentialFacts $facts, string $caller_client, bool $caller_authorized, string $cascade ): array {
		if ( ! $caller_authorized || '' === $caller_client || ( null !== $facts && $facts->client_key !== $caller_client ) ) {
			throw new OAuthFault( 'invalid_client', 401 );
		}
		if ( ! in_array( $cascade, [ 'token_only', 'refresh_family', 'family_all_tokens' ], true ) ) {
			throw new \InvalidArgumentException( 'Invalid revocation cascade policy.' );
		}
		$effects = [];
		if ( null !== $facts ) {
			$family = 'family_all_tokens' === $cascade || ( 'refresh_family' === $cascade && 'refresh_token' === $facts->kind );
			$effects[] = $family && null !== $facts->family_key ? [ 'kind' => 'family', 'key' => $facts->family_key ] : [ 'kind' => 'credential', 'key' => $facts->credential_key ];
		}
		return [ 'response' => [ 'status' => 200, 'headers' => [ 'Cache-Control' => 'no-store' ], 'body' => [] ], 'effects' => $effects ];
	}
}
