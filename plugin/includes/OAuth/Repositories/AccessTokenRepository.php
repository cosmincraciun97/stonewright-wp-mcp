<?php
/**
 * SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Derived from includes/oauth/repositories/access-token-repository.php
 * Source SHA-256: 6deada7078fb258c7c06c8ba7d39ffc64df2cd258579dace46f764af6046bbf9
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\OAuth\Repositories;

// League interfaces use camelCase parameter names and pair entities with repositories.
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

use DateTimeImmutable;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Entities\Traits\AccessTokenTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\TokenEntityTrait;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use Stonewright\WpMcp\OAuth\Bootstrap;

defined( 'ABSPATH' ) || exit;

final class AccessTokenEntity implements AccessTokenEntityInterface {
	use AccessTokenTrait;
	use EntityTrait;
	use TokenEntityTrait;

	/**
	 * Convert the token to a JWT bound to the canonical MCP resource.
	 */
	public function __toString(): string {
		$this->initJwtConfiguration();

		$audience = Bootstrap::resource_identifier();
		$token_id = (string) $this->getIdentifier();
		$subject  = (string) $this->getUserIdentifier();
		$now      = new DateTimeImmutable();
		$builder  = $this->jwtConfiguration
			->builder()
			->issuedAt( $now )
			->canOnlyBeUsedAfter( $now )
			->expiresAt( $this->getExpiryDateTime() )
			->withClaim( 'scopes', $this->getScopes() );

		if ( '' !== $audience ) {
			$builder = $builder->permittedFor( $audience );
		}
		if ( '' !== $token_id ) {
			$builder = $builder->identifiedBy( $token_id );
		}
		if ( '' !== $subject ) {
			$builder = $builder->relatedTo( $subject );
		}

		return $builder->getToken(
			$this->jwtConfiguration->signer(),
			$this->jwtConfiguration->signingKey()
		)->toString();
	}
}

final class AccessTokenRepository implements AccessTokenRepositoryInterface {

	/**
	 * @param array<array-key, ScopeEntityInterface> $scopes Granted scopes.
	 */
	public function getNewToken(
		ClientEntityInterface $clientEntity,
		array $scopes,
		mixed $userIdentifier = null
	): AccessTokenEntityInterface {
		$token = new AccessTokenEntity();
		$token->setClient( $clientEntity );
		foreach ( $scopes as $scope ) {
			$token->addScope( $scope );
		}
		if ( null !== $userIdentifier ) {
			$token->setUserIdentifier( $userIdentifier );
		}
		return $token;
	}

	public function persistNewAccessToken( AccessTokenEntityInterface $accessTokenEntity ): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'stonewright_oauth_access_tokens',
			[
				'identifier_hash' => hash( 'sha256', (string) $accessTokenEntity->getIdentifier() ),
				'client_id'       => (string) $accessTokenEntity->getClient()->getIdentifier(),
				'user_id'         => (int) $accessTokenEntity->getUserIdentifier(),
				'expires_at'      => $accessTokenEntity->getExpiryDateTime()->format( 'Y-m-d H:i:s' ),
				'scopes'          => wp_json_encode(
					array_map(
						static fn( ScopeEntityInterface $scope ): string => $scope->getIdentifier(),
						$accessTokenEntity->getScopes()
					)
				),
				'revoked'         => 0,
			]
		);
	}

	public function revokeAccessToken( mixed $tokenId ): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'stonewright_oauth_access_tokens',
			[ 'revoked' => 1 ],
			[ 'identifier_hash' => hash( 'sha256', (string) $tokenId ) ]
		);
	}

	public function revokeGrantByAccessHash( string $accessTokenHash, string $requestClientId ): void {
		global $wpdb;

		$owner = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT client_id FROM {$wpdb->prefix}stonewright_oauth_access_tokens
				WHERE identifier_hash = %s",
				$accessTokenHash
			)
		);
		if ( '' !== $requestClientId && is_string( $owner ) && ! hash_equals( $owner, $requestClientId ) ) {
			return;
		}

		$wpdb->update(
			$wpdb->prefix . 'stonewright_oauth_access_tokens',
			[ 'revoked' => 1 ],
			[ 'identifier_hash' => $accessTokenHash ]
		);
		$wpdb->update(
			$wpdb->prefix . 'stonewright_oauth_refresh_tokens',
			[ 'revoked' => 1 ],
			[ 'access_token_hash' => $accessTokenHash ]
		);
	}

	public function isAccessTokenRevoked( mixed $tokenId ): bool {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT revoked, expires_at FROM {$wpdb->prefix}stonewright_oauth_access_tokens
				WHERE identifier_hash = %s",
				hash( 'sha256', (string) $tokenId )
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) || 1 === (int) ( $row['revoked'] ?? 0 ) ) {
			return true;
		}

		return new DateTimeImmutable( (string) $row['expires_at'] . ' UTC' ) < new DateTimeImmutable( 'now' );
	}

	public function get_client_id_by_token_identifier( string $token_id ): ?string {
		global $wpdb;

		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT client_id FROM {$wpdb->prefix}stonewright_oauth_access_tokens
				WHERE identifier_hash = %s",
				hash( 'sha256', $token_id )
			)
		);
		return is_string( $value ) ? $value : null;
	}
}
