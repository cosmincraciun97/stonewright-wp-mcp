<?php
/**
 * Token revocation (RFC 7009).
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\WordPress;

use Stonewright\WpMcp\Authorization\Model\OAuthFault;
use Stonewright\WpMcp\Authorization\Protocol\RequestDecoder;
use Stonewright\WpMcp\Support\Logger;

/**
 * Public clients revoke without authentication. Revoking an access or refresh
 * credential closes its whole family (CredentialRevocation); token_type_hint is not
 * needed because the credential's own format tells its kind. A client_id that does not
 * match the credential's client changes nothing. Unknown, missing or malformed tokens
 * are answered like a successful revocation: 200 with an empty body. Only a storage
 * failure is reported, as 503 temporarily_unavailable, because the revocation did not
 * take effect.
 */
final class RevocationEndpoint {

	public const MAXIMUM_BYTES = 16384;

	public function __construct( private CredentialRevocation $revocation ) {}

	public function handle( OAuthRequest $request ): OAuthReply {
		$audit = [
			'client_id'        => '',
			'sensitive_values' => [],
		];
		try {
			$parameters = ( new RequestDecoder() )->form( $request->body, self::MAXIMUM_BYTES );
			$token = $parameters->values( 'token' )[0] ?? '';
			$client_id = $parameters->values( 'client_id' )[0] ?? null;
			$audit['client_id'] = (string) $client_id;
			$audit['sensitive_values'] = $parameters->values( 'token' );
			if ( '' !== $token ) {
				$this->revocation->revoke( $token, null === $client_id ? null : ClientDocuments::client_key( $client_id ) );
			}
		} catch ( OAuthFault $unreadable ) {
			// RFC 7009 section 2.2: an unusable request still receives the success answer.
			unset( $unreadable );
		} catch ( \Throwable $failure ) {
			Logger::warning( 'oauth_revocation_failed', [ 'error_class' => get_class( $failure ) ] );
			return new OAuthReply(
				503,
				[
					'error'             => 'temporarily_unavailable',
					'error_description' => 'The revocation could not be stored. Try again.',
				],
				[
					'Cache-Control' => 'no-store',
					'Retry-After'   => '1',
				],
				$audit
			);
		}
		return new OAuthReply( 200, null, [ 'Cache-Control' => 'no-store' ], $audit );
	}
}
