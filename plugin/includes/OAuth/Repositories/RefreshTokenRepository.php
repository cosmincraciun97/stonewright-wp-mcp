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
use Stonewright\WpMcp\OAuth\ServerFactory;

defined( 'ABSPATH' ) || exit;

final class RefreshTokenEntity implements RefreshTokenEntityInterface {
	use EntityTrait;
	use RefreshTokenTrait;
}

final class RefreshTokenRepository implements RefreshTokenRepositoryInterface {

	private string $active_grant_family_hash = '';

	private ?string $active_family_expires_at = null;

	private ?string $active_parent_identifier_hash = null;

	private ?string $active_client_id = null;

	private ?int $active_user_id = null;

	private ?string $last_revoked_reason = null;

	private static ?string $last_persisted_family_expires_at = null;

	public function getNewRefreshToken(): ?RefreshTokenEntityInterface {
		return new RefreshTokenEntity();
	}

	public function persistNewRefreshToken( RefreshTokenEntityInterface $refreshTokenEntity ): void {
		global $wpdb;

		$family_hash = $this->active_grant_family_hash;
		if ( '' === $family_hash ) {
			$family_hash = hash( 'sha256', random_bytes( 32 ) );
			$this->active_grant_family_hash = $family_hash;
		}

		$family_expires_at = $this->active_family_expires_at;
		if ( null === $family_expires_at || '' === $family_expires_at ) {
			$family_expires_at = ( new DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) )
				->add( new \DateInterval( ServerFactory::REFRESH_FAMILY_TTL ) )
				->format( 'Y-m-d H:i:s' );
			$this->active_family_expires_at = $family_expires_at;
		}

		$family_expiry = new DateTimeImmutable( $family_expires_at . ' UTC' );
		if ( $family_expiry < new DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) ) {
			throw OAuthServerException::invalidGrant( 'Refresh token family has expired' );
		}

		// Clamp entity expiry to the fixed family expiry (no sliding window).
		$refreshTokenEntity->setExpiryDateTime( $family_expiry );

		$identifier_hash = hash( 'sha256', (string) $refreshTokenEntity->getIdentifier() );
		$access_hash     = hash( 'sha256', (string) $refreshTokenEntity->getAccessToken()->getIdentifier() );

		// Conditional insert: reject if family was revoked concurrently.
		$revoked_family = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}stonewright_oauth_refresh_tokens
				WHERE grant_family_hash = %s AND revoked = 1 AND revoked_reason IN ('replayed','revoked','expired')",
				$family_hash
			)
		);
		if ( $revoked_family > 0 ) {
			throw OAuthServerException::invalidGrant( 'Refresh token family has been revoked' );
		}

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'stonewright_oauth_refresh_tokens',
			[
				'identifier_hash'        => $identifier_hash,
				'access_token_hash'      => $access_hash,
				'grant_family_hash'      => $family_hash,
				'client_id'              => $this->active_client_id,
				'user_id'                => $this->active_user_id,
				'parent_identifier_hash' => $this->active_parent_identifier_hash,
				'family_expires_at'      => $family_expires_at,
				'expires_at'             => $family_expires_at,
				'revoked'                => 0,
			]
		);

		if ( false === $inserted ) {
			throw OAuthServerException::serverError( 'Unable to persist refresh token' );
		}

		self::$last_persisted_family_expires_at = $family_expires_at;

		// Recheck family state after insert — concurrent replay must revoke the child.
		$still_active = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}stonewright_oauth_refresh_tokens
				WHERE grant_family_hash = %s AND revoked = 1 AND revoked_reason = 'replayed'",
				$family_hash
			)
		);
		if ( $still_active > 0 ) {
			$wpdb->update(
				$wpdb->prefix . 'stonewright_oauth_refresh_tokens',
				[
					'revoked'        => 1,
					'revoked_reason' => 'replayed',
				],
				[ 'identifier_hash' => $identifier_hash ]
			);
			throw OAuthServerException::invalidGrant( 'Refresh token has already been used' );
		}
	}

	/**
	 * @throws OAuthServerException When the refresh token has already been used.
	 */
	public function revokeRefreshToken( mixed $tokenId ): void {
		global $wpdb;

		$identifier_hash = hash( 'sha256', (string) $tokenId );

		$claimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}stonewright_oauth_refresh_tokens
				SET consumed_at = %s, revoked = 1, revoked_reason = 'rotated'
				WHERE identifier_hash = %s AND consumed_at IS NULL AND revoked = 0",
				gmdate( 'Y-m-d H:i:s' ),
				$identifier_hash
			)
		);

		if ( 1 === (int) $claimed ) {
			$this->last_revoked_reason = 'rotated';
			return;
		}

		// Second claim = replay.
		$this->last_revoked_reason = 'replayed';
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT grant_family_hash FROM {$wpdb->prefix}stonewright_oauth_refresh_tokens WHERE identifier_hash = %s",
				$identifier_hash
			),
			ARRAY_A
		);
		$family = is_array( $row ) ? (string) ( $row['grant_family_hash'] ?? '' ) : '';
		$this->revoke_grant_family( $family, 'replayed' );
		throw OAuthServerException::invalidGrant( 'Refresh token has already been used' );
	}

	public function isRefreshTokenRevoked( mixed $tokenId ): bool {
		global $wpdb;
		$identifier_hash = hash( 'sha256', (string) $tokenId );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT revoked, expires_at, grant_family_hash, family_expires_at, consumed_at, revoked_reason, client_id, user_id
				FROM {$wpdb->prefix}stonewright_oauth_refresh_tokens
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

		$family_expires_at = (string) ( $row['family_expires_at'] ?? '' );
		if ( '' === $family_expires_at || '0000-00-00 00:00:00' === $family_expires_at ) {
			$family_expires_at = (string) ( $row['expires_at'] ?? '' );
		}

		if ( 1 === (int) ( $row['revoked'] ?? 0 ) || null !== ( $row['consumed_at'] ?? null ) ) {
			$this->last_revoked_reason = (string) ( $row['revoked_reason'] ?? 'revoked' );
			$this->revoke_grant_family( $family_hash, 'replayed' );
			return true;
		}

		$now = new DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		if ( '' !== $family_expires_at && new DateTimeImmutable( $family_expires_at . ' UTC' ) < $now ) {
			$this->last_revoked_reason = 'expired';
			$this->revoke_grant_family( $family_hash, 'expired' );
			return true;
		}

		if ( new DateTimeImmutable( (string) $row['expires_at'] . ' UTC' ) < $now ) {
			$this->last_revoked_reason = 'expired';
			return true;
		}

		$this->active_grant_family_hash     = $family_hash;
		$this->active_family_expires_at     = $family_expires_at;
		$this->active_parent_identifier_hash = $identifier_hash;
		$this->active_client_id             = isset( $row['client_id'] ) && is_string( $row['client_id'] ) ? $row['client_id'] : null;
		$this->active_user_id               = isset( $row['user_id'] ) ? (int) $row['user_id'] : null;

		return false;
	}

	public function last_revoked_reason(): ?string {
		return $this->last_revoked_reason;
	}

	/**
	 * Revoke every refresh and access token descended from a replayed/expired grant.
	 */
	private function revoke_grant_family( string $family_hash, string $reason = 'replayed' ): void {
		if ( '' === $family_hash ) {
			return;
		}

		global $wpdb;
		$refresh_table = $wpdb->prefix . 'stonewright_oauth_refresh_tokens';
		$access_table  = $wpdb->prefix . 'stonewright_oauth_access_tokens';
		$access_hashes = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT access_token_hash FROM {$refresh_table} WHERE grant_family_hash = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$family_hash
			)
		);

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$refresh_table} SET revoked = 1, revoked_reason = %s WHERE grant_family_hash = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$reason,
				$family_hash
			)
		);
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

	public static function last_persisted_family_expires_at(): ?string {
		return self::$last_persisted_family_expires_at;
	}

	public static function reset_last_persisted_family_expires_at(): void {
		self::$last_persisted_family_expires_at = null;
	}
}
