<?php
/**
 * Wire formats of access credentials, refresh credentials and authorization codes.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\WordPress;

use Stonewright\WpMcp\Authorization\Model\CredentialFacts;
use Stonewright\WpMcp\Authorization\Model\IssuanceIntent;
use Stonewright\WpMcp\Authorization\Model\OAuthFault;
use Stonewright\WpMcp\Authorization\Model\TokenPair;
use Stonewright\WpMcp\Authorization\Ports\Clock;
use Stonewright\WpMcp\Authorization\Ports\CredentialCodec;
use Stonewright\WpMcp\Authorization\Protocol\ResourceRules;

/**
 * CredentialCodec for the stored keys.
 *
 * Access credentials are RS256 JWTs signed with the stored private key: header
 * {"typ":"JWT","alg":"RS256"}; claims aud (the resource), jti (the access key), iat,
 * nbf, exp, sub (user ID string), scopes, plus iss and client_id. Inspection accepts
 * credentials of the earlier version too (fractional times, no iss or client_id);
 * the access row (sha256 of jti) must exist and name the same subject and client.
 *
 * Codes and refresh credentials are payloads in the public format of the bundled OAuth
 * server library, sealed by PayloadCipher, with additional fields and a format marker:
 * - refresh: client_id, refresh_token_id (the credential key), access_token_id,
 *   scopes, user_id, expire_time, family_id, resources, binding, format;
 * - code: client_id, redirect_uri, auth_code_id (the code key), scopes, user_id,
 *   expire_time, code_challenge, code_challenge_method, resources, format.
 *
 * Re-delivery: a refresh credential is a self-contained encryption of its logical
 * key, so the current credential can be encoded again at any time. The binding field
 * is HMAC-SHA256 over the family and credential keys with a key derived from the
 * WordPress auth salt, which lives outside the database: database contents alone
 * (rows plus the encryption key option) cannot produce a refresh credential for a
 * stored row. Rotating the auth salts therefore ends refresh credentials issued by
 * this version, as it ends WordPress sessions.
 *
 * Refresh payloads of the earlier version (no format marker) are located through the
 * refresh row whose access_token_hash equals sha256 of the payload's access_token_id,
 * and are then addressed by that row's stored identifier. Their families are adopted
 * with the payload's client and subject. Codes of the earlier version are refused.
 */
final class TokenCodec implements CredentialCodec {

	/** Kind values of CredentialFacts, as the introspection decision expects them. */
	public const KIND_ACCESS = 'access_token';
	public const KIND_REFRESH = 'refresh_token';
	public const KIND_CODE = 'authorization_code';

	/** Seconds a not-before or issued-at time may lie ahead of this server's clock. */
	public const NOT_BEFORE_LEEWAY = 60;

	private const REFRESH_FORMAT = 'stonewright-refresh/2';
	private const CODE_FORMAT = 'stonewright-code/2';
	private const BINDING_CONTEXT = 'stonewright-oauth/refresh-binding/v1';
	private const KEY_PATTERN = '/^[A-Za-z0-9._~-]{16,96}$/D';

	private PayloadCipher $cipher;

	/** @var \Closure(): string */
	private \Closure $binding_secret;

	/** @var list<string> */
	private array $audiences;

	/**
	 * @param list<string>              $audiences      Resource identifiers this server answers for.
	 * @param (\Closure(): string)|null $binding_secret Secret kept outside the database.
	 */
	public function __construct( private CredentialKeys $keys, private FamilyStore $families, private AccessTokenStore $access, private Clock $clock, private string $issuer, array $audiences, ?\Closure $binding_secret = null ) {
		$this->cipher = new PayloadCipher( $keys );
		$this->binding_secret = $binding_secret ?? static fn (): string => (string) wp_salt( 'auth' );
		$this->audiences = ( new ResourceRules() )->require_authorized( $audiences, $audiences );
	}

	/**
	 * @throws OAuthFault For any credential that is not authentic and known (invalid_grant),
	 *                    or server_error when the keys are missing.
	 */
	public function inspect( string $opaque_credential ): CredentialFacts {
		if ( JsonWebSignature::looks_like( $opaque_credential ) ) {
			return $this->access_facts( $opaque_credential );
		}
		$payload = $this->cipher->open( $opaque_credential );
		if ( null === $payload ) {
			throw new OAuthFault( 'invalid_grant' );
		}
		$format = $payload['format'] ?? null;
		if ( self::REFRESH_FORMAT === $format ) {
			return $this->refresh_facts( $payload );
		}
		if ( self::CODE_FORMAT === $format ) {
			return $this->code_facts( $payload );
		}
		if ( null === $format && ! array_key_exists( 'auth_code_id', $payload ) && array_key_exists( 'refresh_token_id', $payload ) ) {
			return $this->earlier_refresh_facts( $payload );
		}
		throw new OAuthFault( 'invalid_grant' );
	}

	public function encode( IssuanceIntent $intent ): TokenPair {
		$binding = $this->families->binding( $intent->family_key );
		$private_key = $this->keys->private_key();
		$now = $this->clock->now();
		if ( null === $binding || null === $private_key || RowKeys::is_earlier( $intent->refresh_key ) || ! preg_match( self::KEY_PATTERN, $intent->refresh_key ) || $intent->access_deadline <= $now || [] === $intent->resources ) {
			throw new OAuthFault( 'server_error', 500 );
		}
		$resources = array_values( $intent->resources );
		$scopes = array_values( $intent->access_scopes );
		$access = JsonWebSignature::sign(
			[
				'aud'       => 1 === count( $resources ) ? $resources[0] : $resources,
				'jti'       => $intent->access_key,
				'iat'       => $now,
				'nbf'       => $now,
				'exp'       => $intent->access_deadline,
				'sub'       => $binding['subject_key'],
				'scopes'    => $scopes,
				'iss'       => $this->issuer,
				'client_id' => $binding['client_key'],
			],
			$private_key
		);
		$refresh = $this->cipher->seal(
			[
				'client_id'        => $binding['client_key'],
				'refresh_token_id' => $intent->refresh_key,
				'access_token_id'  => $intent->access_key,
				'scopes'           => $scopes,
				'user_id'          => $binding['subject_key'],
				'expire_time'      => $intent->refresh_deadline,
				'family_id'        => $intent->family_key,
				'resources'        => $resources,
				'binding'          => $this->binding( $intent->family_key, $intent->refresh_key ),
				'format'           => self::REFRESH_FORMAT,
			]
		);
		return new TokenPair( $access, $refresh, $intent->access_deadline - $now );
	}

	/**
	 * Encode an approved authorization code (the code array of ConsentDecision).
	 *
	 * A missing encryption key is an OAuthFault (server_error) from the cipher.
	 *
	 * @param array<string, mixed> $code
	 * @throws \InvalidArgumentException When the code is incomplete.
	 */
	public function encode_code( array $code ): string {
		foreach ( [ 'code_key', 'client_key', 'subject_key', 'redirect_uri', 'code_challenge' ] as $name ) {
			if ( ! isset( $code[ $name ] ) || ! is_string( $code[ $name ] ) || '' === $code[ $name ] ) {
				throw new \InvalidArgumentException( 'Incomplete authorization code.' );
			}
		}
		if ( ! isset( $code['expires_at'], $code['scopes'], $code['resources'] ) || ! is_int( $code['expires_at'] ) || ! is_array( $code['scopes'] ) || ! is_array( $code['resources'] ) ) {
			throw new \InvalidArgumentException( 'Incomplete authorization code.' );
		}
		return $this->cipher->seal(
			[
				'client_id'             => $code['client_key'],
				'redirect_uri'          => $code['redirect_uri'],
				'auth_code_id'          => $code['code_key'],
				'scopes'                => array_values( $code['scopes'] ),
				'user_id'               => $code['subject_key'],
				'expire_time'           => $code['expires_at'],
				'code_challenge'        => $code['code_challenge'],
				'code_challenge_method' => 'S256',
				'resources'             => array_values( $code['resources'] ),
				'format'                => self::CODE_FORMAT,
			]
		);
	}

	/** @throws OAuthFault For an unknown or altered access credential (invalid_grant), or server_error without keys. */
	private function access_facts( string $jwt ): CredentialFacts {
		$public_key = $this->keys->public_key();
		if ( null === $public_key ) {
			throw new OAuthFault( 'server_error', 500 );
		}
		$claims = JsonWebSignature::verify( $jwt, $public_key );
		if ( null === $claims ) {
			throw new OAuthFault( 'invalid_grant' );
		}
		$jti = $claims['jti'] ?? null;
		$subject = $claims['sub'] ?? null;
		$expiry = $claims['exp'] ?? null;
		$scopes = self::strings( $claims['scopes'] ?? [] );
		if ( ! is_string( $jti ) || ! preg_match( self::KEY_PATTERN, $jti ) || ! is_string( $subject ) || null === PermissionSubjectAuthority::user_id( $subject ) || ! self::number( $expiry ) || null === $scopes ) {
			throw new OAuthFault( 'invalid_grant' );
		}
		$horizon = $this->clock->now() + self::NOT_BEFORE_LEEWAY;
		foreach ( [ 'nbf', 'iat' ] as $name ) {
			if ( array_key_exists( $name, $claims ) && ( ! self::number( $claims[ $name ] ) || $claims[ $name ] > $horizon ) ) {
				throw new OAuthFault( 'invalid_grant' );
			}
		}
		if ( array_key_exists( 'iss', $claims ) && $claims['iss'] !== $this->issuer ) {
			throw new OAuthFault( 'invalid_grant' );
		}
		$audiences = $this->matching_audiences( $claims['aud'] ?? null );
		$row = [] === $audiences ? null : $this->access->find( $jti );
		if ( null === $row || $row['subject_key'] !== $subject || ( array_key_exists( 'client_id', $claims ) && $claims['client_id'] !== $row['client_key'] ) ) {
			throw new OAuthFault( 'invalid_grant' );
		}
		$expires = (int) floor( (float) $expiry );
		if ( null !== $row['expires_at'] ) {
			$expires = min( $expires, $row['expires_at'] );
		}
		$family = $row['family_key'] ?? $this->access->earlier_family( $jti );
		return new CredentialFacts( self::KIND_ACCESS, $jti, $family, $row['client_key'], $subject, $scopes, $audiences, $expires );
	}

	/**
	 * @param array<string, mixed> $payload
	 * @throws OAuthFault For a payload that is not bound to this server (invalid_grant).
	 */
	private function refresh_facts( array $payload ): CredentialFacts {
		$family = $payload['family_id'] ?? null;
		$credential = $payload['refresh_token_id'] ?? null;
		$binding = $payload['binding'] ?? null;
		if ( ! is_string( $family ) || ! preg_match( self::KEY_PATTERN, $family ) || ! is_string( $credential ) || ! preg_match( self::KEY_PATTERN, $credential ) || ! is_string( $binding ) || ! hash_equals( $this->binding( $family, $credential ), $binding ) ) {
			throw new OAuthFault( 'invalid_grant' );
		}
		[ $client, $subject, $scopes, $resources, $expires ] = self::owner_fields( $payload );
		return new CredentialFacts( self::KIND_REFRESH, $credential, $family, $client, $subject, $scopes, $resources, $expires );
	}

	/**
	 * @param array<string, mixed> $payload
	 * @throws OAuthFault For an incomplete code payload (invalid_grant).
	 */
	private function code_facts( array $payload ): CredentialFacts {
		$code = $payload['auth_code_id'] ?? null;
		if ( ! is_string( $code ) || ! preg_match( self::KEY_PATTERN, $code ) || ! is_string( $payload['redirect_uri'] ?? null ) || ! is_string( $payload['code_challenge'] ?? null ) ) {
			throw new OAuthFault( 'invalid_grant' );
		}
		[ $client, $subject, $scopes, $resources, $expires ] = self::owner_fields( $payload );
		return new CredentialFacts( self::KIND_CODE, $code, null, $client, $subject, $scopes, $resources, $expires );
	}

	/**
	 * @param array<string, mixed> $payload
	 * @throws OAuthFault When no stored row matches the payload (invalid_grant).
	 */
	private function earlier_refresh_facts( array $payload ): CredentialFacts {
		$client = $payload['client_id'] ?? null;
		$access_key = $payload['access_token_id'] ?? null;
		$user = $payload['user_id'] ?? null;
		$subject = is_int( $user ) || is_string( $user ) ? (string) $user : '';
		$scopes = self::strings( $payload['scopes'] ?? null );
		if ( ! is_string( $client ) || '' === $client || strlen( $client ) > 64 || ! is_string( $access_key ) || '' === $access_key || null === PermissionSubjectAuthority::user_id( $subject ) || null === $scopes ) {
			throw new OAuthFault( 'invalid_grant' );
		}
		$row = $this->families->earlier_credential( $access_key );
		$expires = null === $row ? null : Database::epoch( $row['expires_at'] ?? null );
		if ( null === $row || null === $expires || ( null !== $row['client_id'] && (string) $row['client_id'] !== $client ) || ( null !== $row['user_id'] && (string) $row['user_id'] !== $subject ) ) {
			throw new OAuthFault( 'invalid_grant' );
		}
		$owner = $this->access->owner( RowKeys::access( $access_key ) );
		if ( null !== $owner && ( $owner['client_key'] !== $client || $owner['subject_key'] !== $subject ) ) {
			throw new OAuthFault( 'invalid_grant' );
		}
		$family = (string) $row['grant_family_hash'];
		$this->families->adopt( $family, $client, $subject, $scopes );
		return new CredentialFacts( self::KIND_REFRESH, (string) $row['identifier_hash'], $family, $client, $subject, $scopes, $this->audiences, $expires );
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array{0: string, 1: string, 2: list<string>, 3: list<string>, 4: int}
	 * @throws OAuthFault For missing owner fields (invalid_grant).
	 */
	private static function owner_fields( array $payload ): array {
		$client = $payload['client_id'] ?? null;
		$subject = $payload['user_id'] ?? null;
		$scopes = self::strings( $payload['scopes'] ?? null );
		$resources = self::strings( $payload['resources'] ?? null );
		$expires = $payload['expire_time'] ?? null;
		if ( ! is_string( $client ) || '' === $client || ! is_string( $subject ) || null === PermissionSubjectAuthority::user_id( $subject ) || null === $scopes || null === $resources || ! is_int( $expires ) ) {
			throw new OAuthFault( 'invalid_grant' );
		}
		return [ $client, $subject, $scopes, $resources, $expires ];
	}

	/** @return list<string> */
	private function matching_audiences( mixed $audience ): array {
		$values = is_string( $audience ) ? [ $audience ] : ( is_array( $audience ) && array_is_list( $audience ) ? $audience : [] );
		$matched = [];
		foreach ( $values as $value ) {
			if ( ! is_string( $value ) ) {
				continue;
			}
			try {
				$canonical = ( new ResourceRules() )->require_authorized( [ $value ], [ $value ] )[0];
			} catch ( OAuthFault $malformed ) {
				continue;
			}
			if ( in_array( $canonical, $this->audiences, true ) ) {
				$matched[] = $canonical;
			}
		}
		return array_values( array_unique( $matched ) );
	}

	private function binding( string $family_key, string $credential_key ): string {
		$key = hash_hmac( 'sha256', self::BINDING_CONTEXT, ( $this->binding_secret )(), true );
		return hash_hmac( 'sha256', $family_key . "\n" . $credential_key, $key );
	}

	/** @return list<string>|null */
	private static function strings( mixed $value ): ?array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			return null;
		}
		foreach ( $value as $item ) {
			if ( ! is_string( $item ) ) {
				return null;
			}
		}
		return $value;
	}

	private static function number( mixed $value ): bool {
		return is_int( $value ) || is_float( $value );
	}
}
