<?php
/**
 * Effects of token revocation (RFC 7009).
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\WordPress;

use Stonewright\WpMcp\Authorization\Model\OAuthFault;

/**
 * Revoking an access or refresh credential closes its whole family: every live
 * refresh credential and access credential of the grant. A client_id that does not
 * match the credential's client changes nothing, and unknown credentials are
 * ignored, so the endpoint can always answer 200. Authorization codes are not
 * revocation targets.
 */
final class CredentialRevocation {

	public function __construct( private TokenCodec $codec, private FamilyStore $families, private AccessTokenStore $access ) {}

	/** True when the credential was recognized and its revocation is in effect. */
	public function revoke( string $token, ?string $client_key ): bool {
		if ( '' === $token ) {
			return false;
		}
		try {
			$facts = $this->codec->inspect( $token );
		} catch ( OAuthFault $unknown ) {
			return false;
		}
		if ( TokenCodec::KIND_CODE === $facts->kind ) {
			return false;
		}
		if ( null !== $client_key && '' !== $client_key && ! hash_equals( $facts->client_key, $client_key ) ) {
			return false;
		}
		if ( null !== $facts->family_key ) {
			$this->families->revoke( $facts->family_key );
		}
		if ( TokenCodec::KIND_ACCESS === $facts->kind ) {
			$this->access->revoke( $facts->credential_key );
		}
		return true;
	}
}
