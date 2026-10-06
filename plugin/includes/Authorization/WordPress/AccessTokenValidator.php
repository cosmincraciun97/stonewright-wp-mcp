<?php
/**
 * Bearer credential checks at the protected resource.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\WordPress;

use Stonewright\WpMcp\Authorization\Model\OAuthFault;
use Stonewright\WpMcp\Authorization\Ports\Clock;
use Stonewright\WpMcp\Authorization\Ports\SubjectAuthority;

/**
 * Accepts a bearer access credential only when its RS256 signature verifies with the
 * public key derived from the stored private key, its time claims hold, its audience is
 * this resource, its row exists and is not revoked, and the subject still exists and
 * still holds the MCP capability. Every refusal is invalid_token (HTTP 401). A bearer
 * that is not shaped like a compact JWT is refused before the codec is called, so it
 * is never decrypted.
 */
final class AccessTokenValidator {

	public function __construct( private TokenCodec $codec, private AccessTokenStore $access, private SubjectAuthority $subjects, private Clock $clock ) {}

	/**
	 * @return array{subject_key: string, client_key: string, scopes: list<string>, credential_key: string, family_key: ?string, resources: list<string>, expires_at: int}
	 * @throws OAuthFault For a refused credential (invalid_token, HTTP 401), or server_error when the keys are missing.
	 */
	public function validate( string $bearer ): array {
		if ( ! JsonWebSignature::looks_like( $bearer ) ) {
			throw new OAuthFault( 'invalid_token', 401 );
		}
		try {
			$facts = $this->codec->inspect( $bearer );
		} catch ( OAuthFault $fault ) {
			if ( 'server_error' === $fault->error() ) {
				throw $fault;
			}
			throw new OAuthFault( 'invalid_token', 401 );
		}
		if ( TokenCodec::KIND_ACCESS !== $facts->kind || $this->clock->now() >= $facts->expires_at ) {
			throw new OAuthFault( 'invalid_token', 401 );
		}
		$row = $this->access->find( $facts->credential_key );
		if ( null === $row || $row['revoked'] ) {
			throw new OAuthFault( 'invalid_token', 401 );
		}
		try {
			$this->subjects->require_allowed( $facts->subject_key, 'resource', [ 'client_key' => $facts->client_key, 'scopes' => $facts->scopes, 'resources' => $facts->resources ] );
		} catch ( OAuthFault $denied ) {
			throw new OAuthFault( 'invalid_token', 401 );
		}
		return [
			'subject_key'    => $facts->subject_key,
			'client_key'     => $facts->client_key,
			'scopes'         => array_values( $facts->scopes ),
			'credential_key' => $facts->credential_key,
			'family_key'     => $facts->family_key,
			'resources'      => array_values( $facts->resources ),
			'expires_at'     => $facts->expires_at,
		];
	}
}
