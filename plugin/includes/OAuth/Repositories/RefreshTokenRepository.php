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

	public function getNewRefreshToken(): ?RefreshTokenEntityInterface {
		return new RefreshTokenEntity();
	}

	public function persistNewRefreshToken( RefreshTokenEntityInterface $refreshTokenEntity ): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'stonewright_oauth_refresh_tokens',
			[
				'identifier_hash'  => hash( 'sha256', (string) $refreshTokenEntity->getIdentifier() ),
				'access_token_hash' => hash( 'sha256', (string) $refreshTokenEntity->getAccessToken()->getIdentifier() ),
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

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT revoked, expires_at FROM {$wpdb->prefix}stonewright_oauth_refresh_tokens
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
