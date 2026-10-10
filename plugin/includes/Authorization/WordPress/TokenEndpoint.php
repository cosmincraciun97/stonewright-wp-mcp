<?php
/**
 * The token endpoint (RFC 6749 section 3.2).
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\WordPress;

use Stonewright\WpMcp\Authorization\Decisions\AuthorizationCodeDecision;
use Stonewright\WpMcp\Authorization\Exchange\CodeExchangeCoordinator;
use Stonewright\WpMcp\Authorization\Model\CodeDemand;
use Stonewright\WpMcp\Authorization\Model\CredentialFacts;
use Stonewright\WpMcp\Authorization\Model\FamilyState;
use Stonewright\WpMcp\Authorization\Model\IssuanceIntent;
use Stonewright\WpMcp\Authorization\Model\OAuthFault;
use Stonewright\WpMcp\Authorization\Model\RefreshOutcome;
use Stonewright\WpMcp\Authorization\Model\RotationDemand;
use Stonewright\WpMcp\Authorization\Model\TokenPair;
use Stonewright\WpMcp\Authorization\Protocol\ParameterBag;
use Stonewright\WpMcp\Authorization\Protocol\RequestDecoder;
use Stonewright\WpMcp\Authorization\Refresh\RefreshCoordinator;
use Stonewright\WpMcp\Authorization\Refresh\RotationDecision;
use Stonewright\WpMcp\Support\Logger;

/**
 * Authorization code and refresh token grants for public clients.
 *
 * The body is form-encoded (repeated single-value parameters are refused). client_id
 * is required for the code grant and optional for refresh (the credential names its
 * client); a metadata document URL maps to its stored client key. An unknown client is
 * invalid_client (HTTP 401). The client_id of the audit facts is the identifier the
 * client presented and is set only once the site knows the client, so an unknown
 * identifier never reaches the audit log. resource defaults to what the grant was
 * approved for; scope on refresh may narrow to the granted "mcp" and may name the other
 * advertised scopes, which no grant carries.
 *
 * Success: token_type, expires_in, access_token, refresh_token,
 * refresh_token_expires_in (seconds until this refresh credential expires) and scope.
 * Every response carries Cache-Control: no-store, Pragma: no-cache and
 * X-Stonewright-Refresh-Consumed: 1 for a successful refresh (rotation or a duplicate
 * delivery of the current credential), otherwise 0. Errors follow RFC 6749 section 5.2;
 * an invalid refresh credential adds reason "refresh_token_revoked" when its family is
 * revoked and "refresh_token_expired" when it or its family expired.
 */
final class TokenEndpoint {

	public const MAXIMUM_BYTES = 16384;
	public const CONSUMED_HEADER = 'X-Stonewright-Refresh-Consumed';

	private const DESCRIPTIONS = [
		'invalid_request'        => 'The request is missing a required parameter, repeats a parameter, or is otherwise malformed.',
		'invalid_client'         => 'The client is not registered with this site.',
		'invalid_grant'          => 'The authorization code is invalid, expired, already used, or was issued to another client.',
		'unsupported_grant_type' => 'The grant type is not supported.',
		'invalid_scope'          => 'The requested scope is not available.',
		'invalid_target'         => 'The requested resource is not served by this authorization server.',
		'server_error'           => 'The authorization server could not complete the request.',
	];

	public function __construct( private AuthorizationStorage $storage, private SiteProfile $site ) {}

	public function handle( OAuthRequest $request ): OAuthReply {
		$audit = [
			'client_id'        => '',
			'sensitive_values' => [],
		];
		try {
			if ( ! in_array( $request->media_type(), [ '', 'application/x-www-form-urlencoded' ], true ) ) {
				throw new OAuthFault( 'invalid_request' );
			}
			$parameters = ( new RequestDecoder() )->form( $request->body, self::MAXIMUM_BYTES );
			$audit['sensitive_values'] = array_merge( $parameters->values( 'code' ), $parameters->values( 'code_verifier' ), $parameters->values( 'refresh_token' ) );
			$audit['client_id'] = $this->known_client_id( $parameters->values( 'client_id' )[0] ?? '' );
			$grant = $parameters->one( 'grant_type' );
			if ( null === $grant ) {
				throw new OAuthFault( 'invalid_request' );
			}
			if ( 'authorization_code' === $grant ) {
				return $this->exchange( $parameters, $audit );
			}
			if ( 'refresh_token' === $grant ) {
				return $this->refresh( $parameters, $audit );
			}
			throw new OAuthFault( 'unsupported_grant_type' );
		} catch ( OAuthFault $fault ) {
			return $this->failure( $fault, $audit );
		} catch ( \Throwable $failure ) {
			Logger::warning( 'oauth_token_request_failed', [ 'error_class' => get_class( $failure ) ] );
			return $this->failure( new OAuthFault( 'server_error' ), $audit );
		}
	}

	/**
	 * @param array<string, mixed> $audit
	 * @throws OAuthFault For a refused request.
	 */
	private function exchange( ParameterBag $parameters, array $audit ): OAuthReply {
		$code = $parameters->one( 'code' );
		$redirect = $parameters->one( 'redirect_uri' );
		$client_id = $parameters->one( 'client_id' );
		$verifier = $parameters->one( 'code_verifier' );
		if ( null === $code || null === $redirect || null === $client_id || null === $verifier ) {
			throw new OAuthFault( 'invalid_request' );
		}
		$client_key = $this->registered_client( $client_id );
		$facts = $this->inspect( $code, TokenCodec::KIND_CODE );
		$resources = $parameters->values( 'resource' );
		$coordinator = new CodeExchangeCoordinator( $this->storage->codes(), new AuthorizationCodeDecision( $this->storage->policy() ), $this->storage->clock(), $this->storage->identifiers(), $this->storage->subjects() );
		$outcome = $coordinator->exchange( new CodeDemand( $facts->credential_key, $client_key, $redirect, $verifier, [] === $resources ? $facts->resources : $resources ) );
		if ( null !== $outcome->fault || null === $outcome->issuance ) {
			$fault = $outcome->fault ?? new OAuthFault( 'server_error' );
			if ( null !== $outcome->revoke_family_key ) {
				$audit['event'] = HttpSurface::EVENT_CODE_REPLAY;
				return $this->failure( $fault, $audit, null, [ 'hint' => 'Authorization code replay: the grant family the code created was revoked.' ] );
			}
			return $this->failure( $fault, $audit );
		}
		return $this->success( $this->storage->codec()->encode( $outcome->issuance ), $outcome->issuance, false, $audit );
	}

	/**
	 * @param array<string, mixed> $audit
	 * @throws OAuthFault For a refused request.
	 */
	private function refresh( ParameterBag $parameters, array $audit ): OAuthReply {
		$token = $parameters->one( 'refresh_token' );
		$client_id = $parameters->one( 'client_id' );
		if ( null === $token ) {
			throw new OAuthFault( 'invalid_request' );
		}
		$client_key = null === $client_id ? null : $this->registered_client( $client_id );
		$scopes = self::refresh_scopes( $parameters->one( 'scope' ) );
		$facts = $this->inspect( $token, TokenCodec::KIND_REFRESH );
		if ( null === $client_key ) {
			// Without client_id the credential names its client, which must still be known.
			$client_key = $facts->client_key;
			if ( null === $this->storage->clients()->find( $client_key ) ) {
				throw new OAuthFault( 'invalid_client', 401 );
			}
			$audit['client_id'] = $client_key;
		}
		$resources = $parameters->values( 'resource' );
		if ( [] === $resources ) {
			$resources = in_array( $this->site->resource(), $facts->resources, true ) ? [ $this->site->resource() ] : $facts->resources;
		}
		$family_key = (string) $facts->family_key;
		$before = $this->storage->families()->load( $family_key );
		$coordinator = new RefreshCoordinator( $this->storage->families(), new RotationDecision( $this->storage->policy() ), $this->storage->clock(), $this->storage->identifiers(), $this->storage->subjects() );
		$outcome = $coordinator->rotate( new RotationDemand( $family_key, $facts->credential_key, $client_key, array_values( $resources ), $scopes ) );
		if ( null === $outcome->fault && null !== $outcome->issuance ) {
			$pair = $this->storage->codec()->encode( $outcome->issuance );
			if ( ! $outcome->issuance->redelivery ) {
				return $this->success( $pair, $outcome->issuance, true, $audit );
			}
			$audit['event'] = HttpSurface::EVENT_REDELIVERY;
			return $this->success( $pair, $outcome->issuance, true, $audit, [ 'hint' => 'A duplicate refresh inside the duplicate window received the current refresh credential again.' ] );
		}
		return $this->refresh_failure( $outcome, $before, $facts, $audit );
	}

	/** @param array<string, mixed> $audit */
	private function refresh_failure( RefreshOutcome $outcome, ?FamilyState $before, CredentialFacts $facts, array $audit ): OAuthReply {
		$fault = $outcome->fault ?? new OAuthFault( 'server_error' );
		if ( 'invalid_grant' !== $fault->error() ) {
			return $this->failure( $fault, $audit );
		}
		$phase = $outcome->state->to_array()['phase'];
		if ( 'revoked' === $phase ) {
			// The family was active before this request, so this presentation revoked it.
			if ( null !== $before && 'active' === $before->to_array()['phase'] ) {
				$audit['event'] = HttpSurface::EVENT_REFRESH_REPLAY;
				return $this->failure( $fault, $audit, 'refresh_token_revoked', [ 'hint' => 'Refresh token replay: the grant family was revoked.' ], 'The refresh token is no longer valid.' );
			}
			return $this->failure( $fault, $audit, 'refresh_token_revoked', [], 'The refresh token is no longer valid.' );
		}
		if ( 'expired' === $phase || $this->storage->clock()->now() >= $facts->expires_at ) {
			return $this->failure( $fault, $audit, 'refresh_token_expired', [], 'The refresh token has expired.' );
		}
		return $this->failure( $fault, $audit, null, [], 'The refresh token is not valid for this client.' );
	}

	/**
	 * Authentic facts of a credential of the expected kind.
	 *
	 * @throws OAuthFault When the credential is anything else (invalid_grant), or the keys are missing (server_error).
	 */
	private function inspect( string $credential, string $kind ): CredentialFacts {
		$facts = $this->facts( $credential );
		if ( null === $facts || $kind !== $facts->kind || ( TokenCodec::KIND_REFRESH === $kind && ( null === $facts->family_key || '' === $facts->family_key ) ) ) {
			throw new OAuthFault( 'invalid_grant' );
		}
		return $facts;
	}

	/**
	 * Facts of an authentic credential, or null.
	 *
	 * @throws OAuthFault When the keys are missing (server_error).
	 */
	private function facts( string $credential ): ?CredentialFacts {
		try {
			return $this->storage->codec()->inspect( $credential );
		} catch ( OAuthFault $fault ) {
			if ( 'server_error' === $fault->error() ) {
				throw $fault;
			}
			return null;
		}
	}

	/**
	 * Stored key of a client the site knows, from the identifier the client presents. A
	 * metadata document client presents its URL; its stored key is not a client_id.
	 *
	 * @throws OAuthFault When the client is unknown (invalid_client, HTTP 401).
	 */
	private function registered_client( string $client_id ): string {
		$key = ClientDocuments::client_key( $client_id );
		$client = $this->storage->clients()->find( $key );
		if ( null === $client || ( $key === $client_id && ClientStore::DOCUMENT_PURPOSE === $client['registration_purpose'] ) ) {
			throw new OAuthFault( 'invalid_client', 401 );
		}
		return $key;
	}

	/** The identifier a request presented when the site knows that client, else an empty string. */
	private function known_client_id( string $client_id ): string {
		if ( '' === $client_id ) {
			return '';
		}
		try {
			$this->registered_client( $client_id );
		} catch ( OAuthFault $unknown ) {
			return '';
		}
		return $client_id;
	}

	/**
	 * Scopes a refresh asks for, narrowed to the granted scope; null keeps the grant's.
	 *
	 * @return list<string>|null
	 * @throws OAuthFault When a scope is not advertised by this server (invalid_scope).
	 */
	private static function refresh_scopes( ?string $scope ): ?array {
		if ( null === $scope ) {
			return null;
		}
		$requested = array_values( array_filter( explode( ' ', $scope ), static fn ( string $item ): bool => '' !== $item ) );
		if ( array_diff( $requested, SiteProfile::SUPPORTED_SCOPES ) ) {
			throw new OAuthFault( 'invalid_scope' );
		}
		$granted = array_values( array_intersect( $requested, SiteProfile::GRANTED_SCOPES ) );
		return [] === $granted ? null : $granted;
	}

	/**
	 * @param array<string, mixed> $audit
	 * @param array<string, string> $audit_body
	 */
	private function success( TokenPair $pair, IssuanceIntent $issuance, bool $refresh, array $audit, array $audit_body = [] ): OAuthReply {
		$body = [
			'token_type'               => 'Bearer',
			'expires_in'               => $pair->expires_in,
			'access_token'             => $pair->access_token,
			'refresh_token'            => $pair->refresh_token,
			'refresh_token_expires_in' => max( 0, $issuance->refresh_deadline - $this->storage->clock()->now() ),
			'scope'                    => implode( ' ', $issuance->access_scopes ),
		];
		if ( [] !== $audit_body ) {
			$audit['body'] = $audit_body;
		}
		return new OAuthReply( 200, $body, self::headers( $refresh ), $audit );
	}

	/**
	 * @param array<string, mixed>  $audit
	 * @param array<string, string> $audit_hint
	 */
	private function failure( OAuthFault $fault, array $audit, ?string $reason = null, array $audit_hint = [], ?string $description = null ): OAuthReply {
		$body = [
			'error'             => $fault->error(),
			'error_description' => $description ?? ( self::DESCRIPTIONS[ $fault->error() ] ?? 'The request could not be completed.' ),
		];
		if ( null !== $reason ) {
			$body['reason'] = $reason;
		}
		if ( [] !== $audit_hint ) {
			$audit['body'] = $body + $audit_hint;
		}
		return new OAuthReply( $fault->status(), $body, self::headers( false ), $audit );
	}

	/** @return array<string, string> */
	private static function headers( bool $refresh_consumed ): array {
		return [
			'Cache-Control'       => 'no-store',
			'Pragma'              => 'no-cache',
			self::CONSUMED_HEADER => $refresh_consumed ? '1' : '0',
		];
	}
}
