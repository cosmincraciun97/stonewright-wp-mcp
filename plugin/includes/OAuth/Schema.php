<?php
/**
 * SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Derived from includes/oauth/schema.php
 * Source SHA-256: 6269e68bcc64e68c41f4bf5973df8fd6f14c3ae24e2b028636fb77c1f582c8d8
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * OAuth table lifecycle and garbage collection.
 */
final class Schema {

	public const SCHEMA_VERSION_OPTION = 'stonewright_oauth_schema_version';

	public const CURRENT_SCHEMA_VERSION = '4';

	public const GC_HOOK = 'stonewright_oauth_gc';

	/**
	 * Install or upgrade the OAuth tables.
	 */
	public static function maybe_install(): void {
		OAuthRateLimiter::maybe_install();
		if ( self::CURRENT_SCHEMA_VERSION === get_option( self::SCHEMA_VERSION_OPTION ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		global $wpdb;
		$collation = $wpdb->get_charset_collate();
		$prefix    = $wpdb->prefix . 'stonewright_oauth_';

		dbDelta(
			"CREATE TABLE {$prefix}clients (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				client_id VARCHAR(64) NOT NULL,
				client_name VARCHAR(191) NOT NULL,
				redirect_uris TEXT NOT NULL,
				is_confidential TINYINT(1) NOT NULL DEFAULT 0,
				client_secret_hash VARCHAR(255) DEFAULT NULL,
				created_at DATETIME NOT NULL,
				last_used_at DATETIME DEFAULT NULL,
				registered_by_ip_hash CHAR(64) NOT NULL,
				admin_created TINYINT(1) NOT NULL DEFAULT 0,
				registration_purpose VARCHAR(64) DEFAULT NULL,
				registration_expires_at DATETIME DEFAULT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY client_id (client_id),
				KEY registration_expires_at (registration_expires_at)
			) {$collation};"
		);

		dbDelta(
			"CREATE TABLE {$prefix}auth_codes (
				identifier_hash CHAR(64) NOT NULL,
				client_id VARCHAR(64) NOT NULL,
				user_id BIGINT UNSIGNED NOT NULL,
				expires_at DATETIME NOT NULL,
				scopes TEXT NOT NULL,
				redirect_uri TEXT NOT NULL,
				revoked TINYINT(1) NOT NULL DEFAULT 0,
				PRIMARY KEY (identifier_hash),
				KEY expires_at (expires_at)
			) {$collation};"
		);

		dbDelta(
			"CREATE TABLE {$prefix}access_tokens (
				identifier_hash CHAR(64) NOT NULL,
				client_id VARCHAR(64) NOT NULL,
				user_id BIGINT UNSIGNED NOT NULL,
				expires_at DATETIME NOT NULL,
				scopes TEXT NOT NULL,
				revoked TINYINT(1) NOT NULL DEFAULT 0,
				PRIMARY KEY (identifier_hash),
				KEY expires_at (expires_at),
				KEY user_id (user_id)
			) {$collation};"
		);

		dbDelta(
			"CREATE TABLE {$prefix}refresh_tokens (
				identifier_hash CHAR(64) NOT NULL,
				access_token_hash CHAR(64) NOT NULL,
				grant_family_hash CHAR(64) NOT NULL,
				client_id VARCHAR(64) DEFAULT NULL,
				user_id BIGINT UNSIGNED DEFAULT NULL,
				parent_identifier_hash CHAR(64) DEFAULT NULL,
				family_expires_at DATETIME DEFAULT NULL,
				consumed_at DATETIME DEFAULT NULL,
				revoked_reason VARCHAR(64) DEFAULT NULL,
				expires_at DATETIME NOT NULL,
				revoked TINYINT(1) NOT NULL DEFAULT 0,
				PRIMARY KEY (identifier_hash),
				KEY expires_at (expires_at),
				KEY grant_family_hash (grant_family_hash),
				KEY family_expires_at (family_expires_at),
				KEY consumed_at (consumed_at)
			) {$collation};"
		);

		self::backfill_family_expiry();

		update_option( self::SCHEMA_VERSION_OPTION, self::CURRENT_SCHEMA_VERSION, false );
	}

	/**
	 * Additive backfill for family_expires_at. Never extends an already-expired grant.
	 */
	private static function backfill_family_expiry(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'stonewright_oauth_refresh_tokens';
		$fallback_expiry = gmdate( 'Y-m-d H:i:s', time() + ( 14 * DAY_IN_SECONDS ) );
		$now             = gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is prefix-derived.
		$families = $wpdb->get_results(
			"SELECT grant_family_hash, MIN(expires_at) AS earliest_expiry, MAX(expires_at) AS latest_expiry
			FROM `{$table}`
			WHERE grant_family_hash IS NOT NULL AND grant_family_hash != ''
			AND (family_expires_at IS NULL OR family_expires_at = '0000-00-00 00:00:00')
			GROUP BY grant_family_hash",
			ARRAY_A
		);

		if ( ! is_array( $families ) ) {
			return;
		}

		foreach ( $families as $family ) {
			if ( ! is_array( $family ) ) {
				continue;
			}
			$family_hash = (string) ( $family['grant_family_hash'] ?? '' );
			if ( '' === $family_hash ) {
				continue;
			}
			$earliest = (string) ( $family['earliest_expiry'] ?? '' );
			$latest   = (string) ( $family['latest_expiry'] ?? '' );
			// Prefer earliest stored expiry; fallback only when the family cannot be reconstructed.
			$family_expires_at = '' !== $earliest ? $earliest : $fallback_expiry;
			if ( '' !== $latest && $latest < $now ) {
				// Never extend an already-expired grant.
				$family_expires_at = '' !== $earliest ? $earliest : $latest;
			}

			$wpdb->query(
				$wpdb->prepare(
					"UPDATE `{$table}` SET family_expires_at = %s WHERE grant_family_hash = %s AND (family_expires_at IS NULL OR family_expires_at = '0000-00-00 00:00:00')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$family_expires_at,
					$family_hash
				)
			);
		}
	}

	/**
	 * Delete OAuth rows that expired more than thirty days ago.
	 */
	public static function gc(): void {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( 30 * DAY_IN_SECONDS ) );
		$prefix = $wpdb->prefix . 'stonewright_oauth_';
		foreach ( [ 'auth_codes', 'access_tokens', 'refresh_tokens' ] as $suffix ) {
			$table = $prefix . $suffix;
			$sql   = $wpdb->prepare( "DELETE FROM `{$table}` WHERE expires_at < %s", $cutoff ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		// Ephemeral diagnostic clients (Task 9) with registration_expires_at in the past.
		$clients = $prefix . 'clients';
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `{$clients}` WHERE registration_expires_at IS NOT NULL AND registration_expires_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cutoff
			)
		);
	}

	/**
	 * Schedule daily garbage collection.
	 */
	public static function schedule_gc(): void {
		if ( ! wp_next_scheduled( self::GC_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::GC_HOOK );
		}
	}

	/**
	 * Remove the OAuth garbage collection schedule.
	 */
	public static function unschedule_gc(): void {
		wp_clear_scheduled_hook( self::GC_HOOK );
	}
}
