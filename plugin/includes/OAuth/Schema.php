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

	public const CURRENT_SCHEMA_VERSION = '2';

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
				PRIMARY KEY (id),
				UNIQUE KEY client_id (client_id)
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
				expires_at DATETIME NOT NULL,
				revoked TINYINT(1) NOT NULL DEFAULT 0,
				PRIMARY KEY (identifier_hash),
				KEY expires_at (expires_at)
			) {$collation};"
		);

		update_option( self::SCHEMA_VERSION_OPTION, self::CURRENT_SCHEMA_VERSION, false );
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
