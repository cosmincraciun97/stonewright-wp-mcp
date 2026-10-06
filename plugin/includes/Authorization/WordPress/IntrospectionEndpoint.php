<?php
/**
 * Token introspection (RFC 7662).
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\WordPress;

use Stonewright\WpMcp\Authorization\Decisions\IntrospectionDecision;
use Stonewright\WpMcp\Authorization\Model\CredentialFacts;
use Stonewright\WpMcp\Authorization\Model\OAuthFault;
use Stonewright\WpMcp\Authorization\Protocol\RequestDecoder;
use Stonewright\WpMcp\Authorization\Refresh\RefreshPolicy;
use Stonewright\WpMcp\Support\Logger;

/**
 * For an authorized caller (the REST route requires a WordPress administrator):
 *
 * - an access credential is active while it verifies, has not expired, its row is not
 *   revoked and its subject may still use MCP; the answer holds active, sub, scope, jti,
 *   exp, iat and client_id;
 * - a refresh credential is active only as the unconsumed, unexpired current
 *   credential of an active family before the family deadline; the answer holds active,
 *   sub, scope, exp and client_id;
 * - anything else, including authorization codes, is {"active": false}.
 *
 * client_id is the identifier the client uses: its registration id, or the URL of its
 * metadata document.
 */
final class IntrospectionEndpoint {

	public const MAXIMUM_BYTES = 16384;

	public function __construct( private AuthorizationStorage $storage ) {}

	public function handle( OAuthRequest $request, bool $caller_authorized ): OAuthReply {
		$headers = [
			'Cache-Control' => 'no-store',
			'Pragma'        => 'no-cache',
		];
		$audit = [
			'client_id'        => '',
			'sensitive_values' => [],
		];
		if ( ! $caller_authorized ) {
			return new OAuthReply( 401, self::error( 'invalid_client', 'Introspection requires a site administrator.' ), $headers, $audit );
		}
		try {
			$parameters = ( new RequestDecoder() )->form( $request->body, self::MAXIMUM_BYTES );
			$audit['client_id'] = $parameters->values( 'client_id' )[0] ?? '';
			$audit['sensitive_values'] = $parameters->values( 'token' );
			$token = $parameters->one( 'token' );
		} catch ( OAuthFault $malformed ) {
			$token = null;
		}
		if ( null === $token ) {
			return new OAuthReply( 400, self::error( 'invalid_request', 'The token parameter is required once.' ), $headers, $audit );
		}
		try {
			$body = $this->describe( $token );
		} catch ( \Throwable $failure ) {
			Logger::warning( 'oauth_introspection_failed', [ 'error_class' => get_class( $failure ) ] );
			$body = [ 'active' => false ];
		}
		return new OAuthReply( 200, $body, $headers, $audit );
	}

	/** @return array<string, mixed> */
	private function describe( string $token ): array {
		try {
			$facts = $this->storage->codec()->inspect( $token );
		} catch ( OAuthFault $unknown ) {
			return [ 'active' => false ];
		}
		if ( TokenCodec::KIND_ACCESS !== $facts->kind && TokenCodec::KIND_REFRESH !== $facts->kind ) {
			return [ 'active' => false ];
		}
		$revoked = false;
		$family = null;
		if ( TokenCodec::KIND_ACCESS === $facts->kind ) {
			$row = $this->storage->access_tokens()->find( $facts->credential_key );
			$revoked = null === $row || $row['revoked'] || ! $this->subject_allowed( $facts );
		} else {
			$family = null === $facts->family_key ? null : $this->storage->families()->load( $facts->family_key );
		}
		$result = ( new IntrospectionDecision() )->inspect( $facts, true, true, $revoked, $this->storage->clock()->now(), [ 'client_id', 'scope', 'sub', 'exp' ], $family );
		if ( true !== ( $result['active'] ?? false ) ) {
			return [ 'active' => false ];
		}
		$body = [
			'active' => true,
			'sub'    => (string) $result['sub'],
			'scope'  => (string) $result['scope'],
		];
		if ( TokenCodec::KIND_ACCESS === $facts->kind ) {
			$body['jti'] = $facts->credential_key;
		}
		$body['exp'] = (int) $result['exp'];
		if ( TokenCodec::KIND_ACCESS === $facts->kind ) {
			$body['iat'] = self::issued_at( $token ) ?? $facts->expires_at - RefreshPolicy::ACCESS_LIFETIME;
		}
		$body['client_id'] = $this->public_client_id( $facts->client_key );
		return $body;
	}

	private function subject_allowed( CredentialFacts $facts ): bool {
		try {
			$this->storage->subjects()->require_allowed( $facts->subject_key, 'introspection', [ 'client_key' => $facts->client_key ] );
		} catch ( OAuthFault $denied ) {
			return false;
		}
		return true;
	}

	/** The identifier the client presents: the metadata document URL for document clients. */
	private function public_client_id( string $client_key ): string {
		$client = $this->storage->clients()->find( $client_key );
		$url = $client['client_id_metadata_document'] ?? null;
		return is_string( $url ) && '' !== $url ? $url : $client_key;
	}

	/** The iat claim of an access credential whose signature inspect() already verified. */
	private static function issued_at( string $token ): ?int {
		$parts = explode( '.', $token );
		$claims = 3 === count( $parts ) ? json_decode( (string) base64_decode( strtr( $parts[1], '-_', '+/' ), true ), true ) : null;
		$issued = is_array( $claims ) ? ( $claims['iat'] ?? null ) : null;
		return is_int( $issued ) || is_float( $issued ) ? (int) floor( (float) $issued ) : null;
	}

	/** @return array{error: string, error_description: string} */
	private static function error( string $error, string $description ): array {
		return [
			'error'             => $error,
			'error_description' => $description,
		];
	}
}
