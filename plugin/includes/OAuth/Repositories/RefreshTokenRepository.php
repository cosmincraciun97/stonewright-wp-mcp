<?php
/**
 * SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Derived from includes/oauth/repositories/refresh-token-repository.php
 * Source SHA-256: a7d5db0cf30a2a682643c6514a90c5db1b659f256fb6e7c4b2627827e03b31b8
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\OAuth\Repositories;

// League interfaces use camelCase parameter names and pair entities with repositories.
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

use DateTimeImmutable;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\RefreshTokenTrait;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;

defined( 'ABSPATH' ) || exit;

final class RefreshTokenEntity implements RefreshTokenEntityInterface {
	use EntityTrait;
	use RefreshTokenTrait;
}

final class RefreshTokenRepository implements RefreshTokenRepositoryInterface {

	private string $active_grant_family_hash = '';

	public function getNewRefreshToken(): ?RefreshTokenEntityInterface {
		return new RefreshTokenEntity();
	}

	public function persistNewRefreshToken( RefreshTokenEntityInterface $refreshTokenEntity ): void {
		global $wpdb;
		$family_hash = $this->active_grant_family_hash;
		if ( '' === $family_hash ) {
			$family_hash = hash( 'sha256', random_bytes( 32 ) );
		}

		$wpdb->insert(
			$wpdb->prefix . 'stonewright_oauth_refresh_tokens',
			[
				'identifier_hash'  => hash( 'sha256', (string) $refreshTokenEntity->getIdentifier() ),
				'access_token_hash' => hash( 'sha256', (string) $refreshTokenEntity->getAccessToken()->getIdentifier() ),
				'grant_family_hash' => $family_hash,
				'expires_at'       => $refreshTokenEntity->getExpiryDateTime()->format( 'Y-m-d H:i:s' ),
				'revoked'          => 0,
			]
		);
	}

	/**
	 * @throws OAuthServerException When the refresh token has already been used.
	 */
	public function revokeRefreshToken( mixed $tokenId ): void {
		global $wpdb;

		$claimed = $wpdb->update(
			$wpdb->prefix . 'stonewright_oauth_refresh_tokens',
			[ 'revoked' => 1 ],
			[
				'identifier_hash' => hash( 'sha256', (string) $tokenId ),
				'revoked'         => 0,
			]
		);
		if ( 1 !== $claimed ) {
			throw OAuthServerException::invalidGrant( 'Refresh token has already been used' );
		}
	}

	public function isRefreshTokenRevoked( mixed $tokenId ): bool {
		global $wpdb;
		$identifier_hash = hash( 'sha256', (string) $tokenId );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT revoked, expires_at, grant_family_hash FROM {$wpdb->prefix}stonewright_oauth_refresh_tokens
				WHERE identifier_hash = %s",
				$identifier_hash
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return true;
		}

		$family_hash = (string) ( $row['grant_family_hash'] ?? '' );
		if ( '' === $family_hash ) {
			$family_hash = hash( 'sha256', random_bytes( 32 ) );
			$wpdb->update(
				$wpdb->prefix . 'stonewright_oauth_refresh_tokens',
				[ 'grant_family_hash' => $family_hash ],
				[ 'identifier_hash' => $identifier_hash ]
			);
		}

		if ( 1 === (int) ( $row['revoked'] ?? 0 ) ) {
			$this->revoke_grant_family( $family_hash );
			return true;
		}

		$this->active_grant_family_hash = $family_hash;

		return new DateTimeImmutable( (string) $row['expires_at'] . ' UTC' ) < new DateTimeImmutable( 'now' );
	}

	/**
	 * Revoke every refresh and access token descended from a replayed grant.
	 */
	private function revoke_grant_family( string $family_hash ): void {
		if ( '' === $family_hash ) {
			return;
		}

		global $wpdb;
		$refresh_table = $wpdb->prefix . 'stonewright_oauth_refresh_tokens';
		$access_table  = $wpdb->prefix . 'stonewright_oauth_access_tokens';
		$access_hashes = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT access_token_hash FROM {$refresh_table} WHERE grant_family_hash = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an internal constant-derived name; the value is prepared.
				$family_hash
			)
		);

		$wpdb->update( $refresh_table, [ 'revoked' => 1 ], [ 'grant_family_hash' => $family_hash ] );
		if ( ! is_array( $access_hashes ) ) {
			return;
		}
		foreach ( $access_hashes as $access_hash ) {
			if ( is_string( $access_hash ) && '' !== $access_hash ) {
				$wpdb->update( $access_table, [ 'revoked' => 1 ], [ 'identifier_hash' => $access_hash ] );
			}
		}
	}

	public function accessTokenHashFor( string $refreshJti ): string {
		global $wpdb;

		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT access_token_hash FROM {$wpdb->prefix}stonewright_oauth_refresh_tokens
				WHERE identifier_hash = %s",
				hash( 'sha256', $refreshJti )
			)
		);
		return is_string( $value ) ? $value : '';
	}
}
