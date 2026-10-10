<?php
/**
 * OAuth table creation and in-place upgrade.
 * SPDX-License-Identifier: GPL-2.0-or-later
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
 *
 * An install that cannot finish (for example a database user without CREATE or ALTER
 * rights) starts a one-hour back-off: maybe_upgrade(), which runs on every request, then
 * waits instead of repeating the statements, the column checks and the error log entry.
 * install(), which activation calls, always tries; a success ends the back-off.
 *
 * maybe_upgrade() also reads the version option on every request, so the option is stored
 * autoloaded and arrives with the other start-up options; see autoload_version().
 */
final class StorageTables {

	public const SCHEMA_VERSION = 5;

	/** Autoloaded: maybe_upgrade() reads it on every request. */
	public const VERSION_OPTION = 'stonewright_oauth_schema_version';
	public const RATE_LIMIT_SCHEMA = 1;

	/** Not autoloaded: only install() reads it. */
	public const RATE_LIMIT_SCHEMA_OPTION = 'stonewright_oauth_rate_limit_schema';

	/** Transient that holds back the next upgrade attempt after a failed one. */
	public const BACKOFF_TRANSIENT = 'stonewright_oauth_schema_backoff';
	public const BACKOFF_SECONDS = HOUR_IN_SECONDS;

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

	/** @var array<string, string> Table suffix => one column to read; OAuth was used when any of these tables has a row. */
	private const STATE_TABLES = [
		'clients'        => 'client_id',
		'auth_codes'     => 'identifier_hash',
		'access_tokens'  => 'identifier_hash',
		'refresh_tokens' => 'identifier_hash',
		'families'       => 'family_key',
		'consents'       => 'consent_hash',
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

	/** Whether the stored schema is at least this version. */
	public function current(): bool {
		return (int) get_option( self::VERSION_OPTION, 0 ) >= self::SCHEMA_VERSION;
	}

	/**
	 * Upgrade when the stored schema is older than this version; newer schemas are left alone.
	 * After a failed attempt (the database user cannot create or alter the tables) the next
	 * attempt waits BACKOFF_SECONDS, so a blocked upgrade is not repeated on every request.
	 * A current schema never reads the wait; install() ignores it. A current schema also
	 * gets its version option into the autoloaded set once, if an earlier build stored it
	 * outside (see autoload_version()).
	 */
	public function maybe_upgrade(): bool {
		if ( $this->current() ) {
			$this->autoload_version();
			return true;
		}
		if ( false !== get_transient( self::BACKOFF_TRANSIENT ) ) {
			return false;
		}
		return $this->install();
	}

	/**
	 * Create or extend every table, then record the version only when all columns exist.
	 * A failure starts the back-off that maybe_upgrade() honours; a success ends it.
	 *
	 * @throws \Throwable Whatever the table statements or the column check raised, after the back-off started.
	 */
	public function install(): bool {
		try {
			( $this->delta )( $this->statements() );
			$installed = $this->healthy();
		} catch ( \Throwable $failure ) {
			$this->back_off();
			throw $failure;
		}
		if ( ! $installed ) {
			Logger::error(
				'oauth_schema_install_failed',
				[
					'target_version' => self::SCHEMA_VERSION,
					'stored_version' => (int) get_option( self::VERSION_OPTION, 0 ),
				]
			);
			$this->back_off();
			return false;
		}
		update_option( self::VERSION_OPTION, (string) self::SCHEMA_VERSION, true );
		if ( (int) get_option( self::RATE_LIMIT_SCHEMA_OPTION, 0 ) < self::RATE_LIMIT_SCHEMA ) {
			update_option( self::RATE_LIMIT_SCHEMA_OPTION, (string) self::RATE_LIMIT_SCHEMA, false );
		}
		delete_transient( self::BACKOFF_TRANSIENT );
		return true;
	}

	/**
	 * Whether OAuth was ever used here: a registered client, an authorization code, an
	 * issued credential, a grant family or a pending consent. Rate-limit counters do not
	 * count.
	 *
	 * @throws StorageFailure When a table cannot be read; that is not the same as empty.
	 */
	public function holds_state(): bool {
		foreach ( self::STATE_TABLES as $suffix => $column ) {
			// A SELECT through execute() answers with its row count and throws when the database refuses it.
			if ( $this->db->execute( 'SELECT ' . $column . ' FROM ' . $this->db->table( $suffix ) . ' LIMIT 1' ) > 0 ) {
				return true;
			}
		}
		return false;
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

	private function back_off(): void {
		set_transient( self::BACKOFF_TRANSIENT, time(), self::BACKOFF_SECONDS );
	}

	/**
	 * The version option is read on every request, so it belongs in the autoloaded set that
	 * WordPress loads in one query at start-up; install() writes it that way. An option an
	 * earlier build stored without autoload is switched here, once: it is already in the set
	 * afterwards. Needs WordPress 6.4; older versions leave it as it is.
	 */
	private function autoload_version(): void {
		if ( ! function_exists( 'wp_set_option_autoload' ) || array_key_exists( self::VERSION_OPTION, wp_load_alloptions() ) ) {
			return;
		}
		wp_set_option_autoload( self::VERSION_OPTION, true );
	}
}
