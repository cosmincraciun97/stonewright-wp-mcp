<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Memory;

use Stonewright\WpMcp\Support\Json;
use Stonewright\WpMcp\Support\Logger;

/**
 * Stonewright site memory: scoped key/value store backed by a custom table.
 */
final class Memory {

	public const TABLE = 'stonewright_memory';

	private const SCHEMA_VERSION = 3;

	/**
	 * Required columns for the current schema version.
	 *
	 * @return array<int, string>
	 */
	private static function required_columns(): array {
		return [
			'id',
			'scope',
			'type',
			'name',
			'memory_key',
			'value_json',
			'confidence',
			'topic',
			'version_fingerprint',
			'expires_at',
			'status',
			'precedence',
			'created_by',
			'created_at',
			'updated_at',
		];
	}

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * True when the memory table exists with all v3 columns.
	 */
	public static function table_schema_ok(): bool {
		global $wpdb;

		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_col' ) ) {
			return false;
		}

		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal (prefix + const).
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
		if ( ! is_array( $columns ) || [] === $columns ) {
			return false;
		}

		$columns = array_map( 'strval', $columns );
		return [] === array_diff( self::required_columns(), $columns );
	}

	/**
	 * Returns the valid memory type values.
	 *
	 * @return array<int, string>
	 */
	public static function valid_types(): array {
		return [ 'user', 'feedback', 'project', 'reference', 'generic' ];
	}

	/**
	 * Sanitize a type value to one of the 5 valid types, defaulting to 'generic'.
	 */
	private static function sanitize_type( string $type ): string {
		return in_array( $type, self::valid_types(), true ) ? $type : 'generic';
	}

	/**
	 * Sanitize a name value via sanitize_text_field, truncated to 190 chars.
	 */
	private static function sanitize_name( string $name ): string {
		$clean = sanitize_text_field( $name );
		return mb_substr( $clean, 0, 190 );
	}

	public static function maybe_install_table(): void {
		global $wpdb;

		$current_version = (int) get_option( 'stonewright_memory_schema_version', 0 );
		if ( $current_version >= self::SCHEMA_VERSION ) {
			return;
		}

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			scope VARCHAR(64) NOT NULL,
			type VARCHAR(32) NOT NULL DEFAULT 'generic',
			name VARCHAR(190) NOT NULL DEFAULT '',
			memory_key VARCHAR(190) NOT NULL,
			value_json LONGTEXT NOT NULL,
			confidence DECIMAL(5,4) NOT NULL DEFAULT 1.0000,
			topic VARCHAR(190) NOT NULL DEFAULT '',
			version_fingerprint VARCHAR(190) NOT NULL DEFAULT '',
			expires_at DATETIME NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			precedence SMALLINT NOT NULL DEFAULT 0,
			created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY scope_key (scope, memory_key),
			KEY type_idx (type),
			KEY topic_status (topic, status),
			KEY expires_at (expires_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		if ( self::table_schema_ok() ) {
			update_option( 'stonewright_memory_schema_version', self::SCHEMA_VERSION );
		} else {
			Logger::error(
				'memory_schema_install_failed',
				[
					'table'          => self::table_name(),
					'target_version' => self::SCHEMA_VERSION,
				]
			);
		}
	}

	public static function put( string $scope, string $key, mixed $value, float $confidence = 1.0 ): void {
		global $wpdb;
		$table = self::table_name();

		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE scope = %s AND memory_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$scope,
				$key
			)
		);

		$data = [
			'scope'      => $scope,
			'memory_key' => $key,
			'value_json' => Json::encode( $value ),
			'confidence' => $confidence,
			'created_by' => get_current_user_id(),
		];

		if ( $existing ) {
			$wpdb->update( $table, $data, [ 'id' => (int) $existing ], [ '%s', '%s', '%s', '%f', '%d' ], [ '%d' ] );
		} else {
			$wpdb->insert( $table, $data, [ '%s', '%s', '%s', '%f', '%d' ] );
		}
	}

	public static function get( string $scope, string $key, mixed $default = null ): mixed {
		global $wpdb;
		$table = self::table_name();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT value_json FROM {$table} WHERE scope = %s AND memory_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$scope,
				$key
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return $default;
		}

		return self::decode_value( (string) $row['value_json'] );
	}

	public static function delete( string $scope, string $key ): void {
		global $wpdb;
		$wpdb->delete( self::table_name(), [ 'scope' => $scope, 'memory_key' => $key ], [ '%s', '%s' ] );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function get_scope( string $scope ): array {
		global $wpdb;
		$table = self::table_name();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT memory_key, value_json FROM {$table} WHERE scope = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$scope
			),
			ARRAY_A
		);

		$out = [];
		foreach ( (array) $rows as $row ) {
			$out[ $row['memory_key'] ] = self::decode_value( (string) $row['value_json'] );
		}
		return $out;
	}

	// -------------------------------------------------------------------------
	// Typed CRUD — Wave 1a additions
	// -------------------------------------------------------------------------

	/**
	 * Decode a raw DB row into the public shape expected by callers.
	 *
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private static function decode_row( array $row ): array {
		return [
			'id'         => (int) $row['id'],
			'type'       => (string) $row['type'],
			'scope'      => (string) $row['scope'],
			'memory_key' => (string) $row['memory_key'],
			'name'       => (string) $row['name'],
			'value'      => self::decode_value( (string) $row['value_json'] ),
			'confidence' => (float) $row['confidence'],
			'topic'      => (string) ( $row['topic'] ?? '' ),
			'version_fingerprint' => (string) ( $row['version_fingerprint'] ?? '' ),
			'expires_at' => isset( $row['expires_at'] ) ? (string) $row['expires_at'] : '',
			'status'     => (string) ( $row['status'] ?? 'active' ),
			'precedence' => (int) ( $row['precedence'] ?? 0 ),
			'created_at' => (string) $row['created_at'],
			'updated_at' => (string) $row['updated_at'],
		];
	}

	private static function decode_value( string $json ): mixed {
		try {
			return json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		} catch ( \JsonException ) {
			return [];
		}
	}

	/**
	 * Insert or update a typed memory entry.
	 *
	 * @param string $type       One of valid_types(). Invalid values coerced to 'generic'.
	 * @param string $scope      Scope identifier.
	 * @param string $key        Memory key (unique within scope).
	 * @param string $name       Human-readable label; sanitized + truncated to 190.
	 * @param mixed  $value      Serializable value.
	 * @param float  $confidence Confidence score, 0–1.
	 * @param array<string, mixed> $metadata Lifecycle topic, fingerprint, expiry, status, and precedence.
	 * @return int Row id (0 on failure).
	 */
	public static function put_typed( string $type, string $scope, string $key, string $name, mixed $value, float $confidence = 1.0, array $metadata = [] ): int {
		global $wpdb;
		$table = self::table_name();

		$type = self::sanitize_type( $type );
		$name = self::sanitize_name( $name );

		$existing_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE scope = %s AND memory_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$scope,
				$key
			)
		);

		$data = [
			'type'       => $type,
			'scope'      => $scope,
			'memory_key' => $key,
			'name'       => $name,
			'value_json' => Json::encode( $value ),
			'confidence' => $confidence,
			'topic'      => sanitize_text_field( (string) ( $metadata['topic'] ?? $name ) ),
			'version_fingerprint' => sanitize_text_field( (string) ( $metadata['version_fingerprint'] ?? '' ) ),
			'expires_at' => self::sanitize_expiry( $metadata['expires_at'] ?? null ),
			'status'     => in_array( $metadata['status'] ?? 'active', [ 'active', 'stale', 'rejected' ], true ) ? (string) ( $metadata['status'] ?? 'active' ) : 'active',
			'precedence' => max( -1000, min( 1000, (int) ( $metadata['precedence'] ?? 0 ) ) ),
			'created_by' => get_current_user_id(),
		];

		$formats = [ '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%d', '%d' ];

		if ( $existing_id > 0 ) {
			$result = $wpdb->update( $table, $data, [ 'id' => $existing_id ], $formats, [ '%d' ] );
			if ( false !== $result ) {
				return $existing_id;
			}
			Logger::error(
				'memory_put_failed',
				[
					'scope'      => $scope,
					'memory_key' => $key,
					'type'       => $type,
					'wpdb_error' => (string) ( $wpdb->last_error ?? '' ),
					'schema_ok'  => self::table_schema_ok(),
				]
			);
			return 0;
		}

		$result = $wpdb->insert( $table, $data, $formats );
		if ( false !== $result ) {
			return (int) $wpdb->insert_id;
		}
		Logger::error(
			'memory_put_failed',
			[
				'scope'      => $scope,
				'memory_key' => $key,
				'type'       => $type,
				'wpdb_error' => (string) ( $wpdb->last_error ?? '' ),
				'schema_ok'  => self::table_schema_ok(),
			]
		);
		return 0;
	}

	/**
	 * List memory entries filtered by type.
	 *
	 * @param string $type   One of valid_types(). Invalid values coerced to 'generic'.
	 * @param int    $limit  Max rows.
	 * @param int    $offset Pagination offset.
	 * @return array<int, array<string, mixed>>
	 */
	public static function list_by_type( string $type, int $limit = 100, int $offset = 0 ): array {
		global $wpdb;
		$table = self::table_name();
		$type  = self::sanitize_type( $type );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, type, scope, memory_key, name, value_json, confidence, topic, version_fingerprint, expires_at, status, precedence, created_at, updated_at FROM {$table} WHERE type = %s ORDER BY id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$type,
				$limit,
				$offset
			),
			ARRAY_A
		);

		$out = [];
		foreach ( (array) $rows as $row ) {
			$out[] = self::decode_row( $row );
		}
		return $out;
	}

	/**
	 * List all memory entries regardless of type.
	 *
	 * @param int $limit  Max rows.
	 * @param int $offset Pagination offset.
	 * @return array<int, array<string, mixed>>
	 */
	public static function list_all( int $limit = 100, int $offset = 0 ): array {
		global $wpdb;
		$table = self::table_name();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, type, scope, memory_key, name, value_json, confidence, topic, version_fingerprint, expires_at, status, precedence, created_at, updated_at FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$limit,
				$offset
			),
			ARRAY_A
		);

		$out = [];
		foreach ( (array) $rows as $row ) {
			$out[] = self::decode_row( $row );
		}
		return $out;
	}

	/**
	 * Build a compact memory index for MCP discovery instructions.
	 */
	public static function instructions_block(): string {
		if ( ! get_option( 'stonewright_memory_enabled', true ) ) {
			return '';
		}

		return implode(
			"\n",
			[
				'',
				'## Site Memory',
				'',
				'Context bootstrap returns only the highest-priority relevant memory references. Stale or expired memory is excluded.',
				'Load a selected body on demand with `stonewright/memory-get`; user instructions outrank feedback, project, reference, and generic memory.',
			]
		);
	}

	/**
	 * Retrieve a single memory entry by its primary-key id.
	 *
	 * @param int $id Row id.
	 * @return array<string, mixed>|null Null if not found.
	 */
	public static function get_by_id( int $id ): ?array {
		global $wpdb;
		$table = self::table_name();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, type, scope, memory_key, name, value_json, confidence, topic, version_fingerprint, expires_at, status, precedence, created_at, updated_at FROM {$table} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		return self::decode_row( $row );
	}

	/**
	 * Delete a memory entry by id.
	 *
	 * @param int $id Row id.
	 * @return bool True if a row was deleted.
	 */
	public static function delete_by_id( int $id ): bool {
		global $wpdb;
		$result = $wpdb->delete( self::table_name(), [ 'id' => $id ], [ '%d' ] );
		return ( false !== $result && $result > 0 );
	}

	/**
	 * Partially update a memory entry by id.
	 *
	 * Accepted keys include content plus lifecycle index metadata.
	 * Unknown keys are silently ignored.
	 *
	 * @param int                  $id      Row id.
	 * @param array<string, mixed> $changes Field => new value map.
	 * @return bool True on success, false if id not found or update failed.
	 */
	public static function update_by_id( int $id, array $changes ): bool {
		global $wpdb;
		$table = self::table_name();

		$allowed = [ 'type', 'scope', 'memory_key', 'name', 'value', 'confidence', 'topic', 'version_fingerprint', 'expires_at', 'status', 'precedence' ];
		$data    = [];
		$formats = [];

		foreach ( $allowed as $field ) {
			if ( ! array_key_exists( $field, $changes ) ) {
				continue;
			}

			if ( 'type' === $field ) {
				$data['type'] = self::sanitize_type( (string) $changes['type'] );
				$formats[]    = '%s';
			} elseif ( 'name' === $field ) {
				$data['name'] = self::sanitize_name( (string) $changes['name'] );
				$formats[]    = '%s';
			} elseif ( 'scope' === $field ) {
				$data['scope'] = (string) $changes['scope'];
				$formats[]     = '%s';
			} elseif ( 'memory_key' === $field ) {
				$data['memory_key'] = (string) $changes['memory_key'];
				$formats[]          = '%s';
			} elseif ( 'value' === $field ) {
				$data['value_json'] = Json::encode( $changes['value'] );
				$formats[]          = '%s';
			} elseif ( 'confidence' === $field ) {
				$data['confidence'] = (float) $changes['confidence'];
				$formats[]          = '%f';
			} elseif ( 'topic' === $field ) {
				$data['topic'] = sanitize_text_field( (string) $changes['topic'] );
				$formats[]     = '%s';
			} elseif ( 'version_fingerprint' === $field ) {
				$data['version_fingerprint'] = sanitize_text_field( (string) $changes['version_fingerprint'] );
				$formats[]                   = '%s';
			} elseif ( 'expires_at' === $field ) {
				$data['expires_at'] = self::sanitize_expiry( $changes['expires_at'] );
				$formats[]          = '%s';
			} elseif ( 'status' === $field ) {
				$data['status'] = in_array( $changes['status'], [ 'active', 'stale', 'rejected' ], true ) ? (string) $changes['status'] : 'stale';
				$formats[]      = '%s';
			} elseif ( 'precedence' === $field ) {
				$data['precedence'] = max( -1000, min( 1000, (int) $changes['precedence'] ) );
				$formats[]          = '%d';
			}
		}

		if ( empty( $data ) ) {
			return false;
		}

		$result = $wpdb->update( $table, $data, [ 'id' => $id ], $formats, [ '%d' ] );
		return ( false !== $result );
	}

	/** @param array<string, mixed> $entry */
	public static function is_active( array $entry ): bool {
		if ( 'active' !== (string) ( $entry['status'] ?? 'active' ) ) {
			return false;
		}
		$expires_at = trim( (string) ( $entry['expires_at'] ?? '' ) );
		return '' === $expires_at || ( strtotime( $expires_at . ' UTC' ) ?: 0 ) > time();
	}

	private static function sanitize_expiry( mixed $value ): ?string {
		if ( null === $value || '' === trim( (string) $value ) ) {
			return null;
		}
		$timestamp = strtotime( (string) $value );
		return false === $timestamp ? null : gmdate( 'Y-m-d H:i:s', $timestamp );
	}
}
