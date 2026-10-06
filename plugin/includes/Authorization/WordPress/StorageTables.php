<?php
/**
 * OAuth table creation and in-place upgrade.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\WordPress;

use Stonewright\WpMcp\Support\Logger;

/**
 * Creates and upgrades the OAuth tables through dbDelta.
 *
 * The six tables keep the columns and keys of schema version 4 in their original
 * order. Version 5 adds, without touching existing values:
 * - clients.client_metadata: the accepted registration profile as JSON;
 * - auth_codes.code_challenge, resources and family_key: the PKCE challenge, the
 *   approved resources and the family a used code created (replay revocation);
 * - access_tokens.family_key and refresh_identifier_hash, with keys: the family and
 *   the refresh credential an access credential was delivered with;
 * - refresh_tokens.credential_key: the logical key of rows written by this version,
 *   and a key on access_token_hash to find rows written by the earlier version;
 * - families: one row per grant family with its revision for compare-and-swap, a
 *   delivery counter that orders duplicate deliveries against other changes, phase,
 *   deadline, client, subject and consent (created lazily for earlier families);
 * - consents: one-use pending consent requests.
 */
final class StorageTables {

	public const SCHEMA_VERSION = 5;
	public const VERSION_OPTION = 'stonewright_oauth_schema_version';
	public const RATE_LIMIT_SCHEMA = 1;
	public const RATE_LIMIT_SCHEMA_OPTION = 'stonewright_oauth_rate_limit_schema';

	/** @var array<string, list<string>> Table suffix => columns every healthy install has. */
	public const REQUIRED_COLUMNS = [
		'clients'        => [ 'id', 'client_id', 'client_name', 'redirect_uris', 'is_confidential', 'client_secret_hash', 'created_at', 'last_used_at', 'registered_by_ip_hash', 'admin_created', 'registration_purpose', 'registration_expires_at', 'client_metadata' ],
		'auth_codes'     => [ 'identifier_hash', 'client_id', 'user_id', 'expires_at', 'scopes', 'redirect_uri', 'revoked', 'code_challenge', 'resources', 'family_key' ],
		'access_tokens'  => [ 'identifier_hash', 'client_id', 'user_id', 'expires_at', 'scopes', 'revoked', 'family_key', 'refresh_identifier_hash' ],
		'refresh_tokens' => [ 'identifier_hash', 'access_token_hash', 'grant_family_hash', 'client_id', 'user_id', 'parent_identifier_hash', 'family_expires_at', 'consumed_at', 'revoked_reason', 'expires_at', 'revoked', 'credential_key' ],
		'rate_limits'    => [ 'bucket_key', 'window_started', 'hits', 'updated_at' ],
		'rate_metrics'   => [ 'metric_bucket', 'window_started', 'fingerprint_key', 'limited_requests', 'cooldown_until', 'updated_at' ],
		'families'       => [ 'family_hash', 'family_key', 'client_id', 'user_id', 'scopes', 'resources', 'phase', 'revision', 'delivery_count', 'compacted_entries', 'family_expires_at', 'created_at', 'updated_at' ],
		'consents'       => [ 'consent_hash', 'user_id', 'client_id', 'request_json', 'created_at', 'expires_at' ],
	];

	/** @var \Closure(list<string>): mixed */
	private \Closure $delta;

	/** @param (\Closure(list<string>): mixed)|null $delta Applies CREATE TABLE statements; dbDelta() by default. */
	public function __construct( private Database $db, ?\Closure $delta = null ) {
		$this->delta = $delta ?? static function ( array $statements ): mixed {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			return dbDelta( $statements );
		};
	}

	/** Upgrade when the stored schema is older than this version; newer schemas are left alone. */
	public function maybe_upgrade(): bool {
		if ( (int) get_option( self::VERSION_OPTION, 0 ) >= self::SCHEMA_VERSION ) {
			return true;
		}
		return $this->install();
	}

	/** Create or extend every table, then record the version only when all columns exist. */
	public function install(): bool {
		( $this->delta )( $this->statements() );
		if ( ! $this->healthy() ) {
			Logger::error(
				'oauth_schema_install_failed',
				[
					'target_version' => self::SCHEMA_VERSION,
					'stored_version' => (int) get_option( self::VERSION_OPTION, 0 ),
				]
			);
			return false;
		}
		update_option( self::VERSION_OPTION, (string) self::SCHEMA_VERSION, false );
		if ( (int) get_option( self::RATE_LIMIT_SCHEMA_OPTION, 0 ) < self::RATE_LIMIT_SCHEMA ) {
			update_option( self::RATE_LIMIT_SCHEMA_OPTION, (string) self::RATE_LIMIT_SCHEMA, false );
		}
		return true;
	}

	/** Whether every table exists with every required column. */
	public function healthy(): bool {
		foreach ( self::REQUIRED_COLUMNS as $suffix => $columns ) {
			$present = $this->db->column( 'SHOW COLUMNS FROM ' . $this->db->table( $suffix ) );
			if ( [] !== array_diff( $columns, $present ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * CREATE TABLE statements in dbDelta form: one definition per line, two spaces after
	 * PRIMARY KEY, lowercase types, no backticks.
	 *
	 * @return list<string>
	 */
	public function statements(): array {
		$charset = $this->db->charset_collate();
		$definitions = [
			'clients'        => [
				'id bigint unsigned NOT NULL AUTO_INCREMENT',
				'client_id varchar(64) NOT NULL',
				'client_name varchar(191) NOT NULL',
				'redirect_uris text NOT NULL',
				"is_confidential tinyint(1) NOT NULL DEFAULT '0'",
				'client_secret_hash varchar(255) DEFAULT NULL',
				'created_at datetime NOT NULL',
				'last_used_at datetime DEFAULT NULL',
				'registered_by_ip_hash char(64) NOT NULL',
				"admin_created tinyint(1) NOT NULL DEFAULT '0'",
				'registration_purpose varchar(64) DEFAULT NULL',
				'registration_expires_at datetime DEFAULT NULL',
				'client_metadata longtext',
				'PRIMARY KEY  (id)',
				'UNIQUE KEY client_id (client_id)',
				'KEY registration_expires_at (registration_expires_at)',
			],
			'auth_codes'     => [
				'identifier_hash char(64) NOT NULL',
				'client_id varchar(64) NOT NULL',
				'user_id bigint unsigned NOT NULL',
				'expires_at datetime NOT NULL',
				'scopes text NOT NULL',
				'redirect_uri text NOT NULL',
				"revoked tinyint(1) NOT NULL DEFAULT '0'",
				'code_challenge varchar(128) DEFAULT NULL',
				'resources text',
				'family_key varchar(96) DEFAULT NULL',
				'PRIMARY KEY  (identifier_hash)',
				'KEY expires_at (expires_at)',
			],
			'access_tokens'  => [
				'identifier_hash char(64) NOT NULL',
				'client_id varchar(64) NOT NULL',
				'user_id bigint unsigned NOT NULL',
				'expires_at datetime NOT NULL',
				'scopes text NOT NULL',
				"revoked tinyint(1) NOT NULL DEFAULT '0'",
				'family_key varchar(96) DEFAULT NULL',
				'refresh_identifier_hash char(64) DEFAULT NULL',
				'PRIMARY KEY  (identifier_hash)',
				'KEY expires_at (expires_at)',
				'KEY user_id (user_id)',
				'KEY family_key (family_key)',
				'KEY refresh_identifier_hash (refresh_identifier_hash)',
			],
			'refresh_tokens' => [
				'identifier_hash char(64) NOT NULL',
				'access_token_hash char(64) NOT NULL',
				'grant_family_hash char(64) NOT NULL',
				'client_id varchar(64) DEFAULT NULL',
				'user_id bigint unsigned DEFAULT NULL',
				'parent_identifier_hash char(64) DEFAULT NULL',
				'family_expires_at datetime DEFAULT NULL',
				'consumed_at datetime DEFAULT NULL',
				'revoked_reason varchar(64) DEFAULT NULL',
				'expires_at datetime NOT NULL',
				"revoked tinyint(1) NOT NULL DEFAULT '0'",
				'credential_key varchar(96) DEFAULT NULL',
				'PRIMARY KEY  (identifier_hash)',
				'KEY expires_at (expires_at)',
				'KEY grant_family_hash (grant_family_hash)',
				'KEY family_expires_at (family_expires_at)',
				'KEY consumed_at (consumed_at)',
				'KEY access_token_hash (access_token_hash)',
			],
			'rate_limits'    => [
				'bucket_key char(64) NOT NULL',
				'window_started bigint(20) unsigned NOT NULL',
				"hits bigint(20) unsigned NOT NULL DEFAULT '0'",
				'updated_at datetime NOT NULL',
				'PRIMARY KEY  (bucket_key)',
				'KEY updated_idx (updated_at)',
			],
			'rate_metrics'   => [
				'metric_bucket char(64) NOT NULL',
				'window_started bigint(20) unsigned NOT NULL',
				'fingerprint_key char(64) NOT NULL',
				"limited_requests bigint(20) unsigned NOT NULL DEFAULT '0'",
				"cooldown_until bigint(20) unsigned NOT NULL DEFAULT '0'",
				'updated_at datetime NOT NULL',
				'PRIMARY KEY  (metric_bucket,window_started,fingerprint_key)',
				'KEY window_idx (window_started)',
				'KEY updated_idx (updated_at)',
			],
			'families'       => [
				'family_hash char(64) NOT NULL',
				'family_key varchar(96) NOT NULL',
				'client_id varchar(64) NOT NULL',
				'user_id bigint unsigned NOT NULL',
				'scopes text NOT NULL',
				'resources text NOT NULL',
				"phase varchar(16) NOT NULL DEFAULT 'active'",
				"revision bigint unsigned NOT NULL DEFAULT '0'",
				"delivery_count bigint unsigned NOT NULL DEFAULT '0'",
				"compacted_entries int unsigned NOT NULL DEFAULT '0'",
				'family_expires_at datetime NOT NULL',
				'created_at datetime NOT NULL',
				'updated_at datetime NOT NULL',
				'PRIMARY KEY  (family_hash)',
				'UNIQUE KEY family_key (family_key)',
				'KEY client_id (client_id)',
				'KEY family_expires_at (family_expires_at)',
			],
			'consents'       => [
				'consent_hash char(64) NOT NULL',
				'user_id bigint unsigned NOT NULL',
				'client_id varchar(64) NOT NULL',
				'request_json longtext NOT NULL',
				'created_at datetime NOT NULL',
				'expires_at datetime NOT NULL',
				'PRIMARY KEY  (consent_hash)',
				'KEY expires_at (expires_at)',
			],
		];
		$statements = [];
		foreach ( $definitions as $suffix => $lines ) {
			$statements[] = rtrim( 'CREATE TABLE ' . $this->db->table( $suffix ) . " (\n" . implode( ",\n", $lines ) . "\n) " . $charset );
		}
		return $statements;
	}
}
