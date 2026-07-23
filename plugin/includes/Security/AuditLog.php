<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

use Stonewright\WpMcp\Support\Json;
use Stonewright\WpMcp\Support\Logger;

/**
 * Append-only audit log for Stonewright-owned mutations and abilities.
 */
final class AuditLog {

	public const TABLE = 'stonewright_audit_log';

	/** @var list<string> */
	public const STATUSES = [ 'ok', 'error', 'blocked' ];

	/**
	 * When true, AbilityKernel (or another recorder) already wrote a row for
	 * this request — REST mutation middleware must not create a duplicate.
	 *
	 * @var bool
	 */
	private static bool $request_already_audited = false;

	/**
	 * Request correlation UUID for the current MCP/REST mutation.
	 *
	 * @var string|null
	 */
	private static ?string $request_correlation_id = null;

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	public static function reset_request_state(): void {
		self::$request_already_audited = false;
		self::$request_correlation_id  = null;
	}

	public static function begin_request( ?string $correlation_id = null ): string {
		self::$request_already_audited = false;
		self::$request_correlation_id  = $correlation_id ?? wp_generate_uuid4();
		return self::$request_correlation_id;
	}

	public static function mark_audited(): void {
		self::$request_already_audited = true;
	}

	public static function was_audited(): bool {
		return self::$request_already_audited;
	}

	public static function request_id(): string {
		if ( null === self::$request_correlation_id || '' === self::$request_correlation_id ) {
			self::$request_correlation_id = wp_generate_uuid4();
		}
		return self::$request_correlation_id;
	}

	public static function maybe_install_table(): void {
		global $wpdb;
		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			ability_name VARCHAR(190) NOT NULL,
			user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			args_hash CHAR(64) NOT NULL DEFAULT '',
			sanitized_args LONGTEXT NOT NULL,
			result_status VARCHAR(32) NOT NULL DEFAULT 'ok',
			ip_hash CHAR(64) NOT NULL DEFAULT '',
			ua_hash CHAR(64) NOT NULL DEFAULT '',
			request_id CHAR(36) NOT NULL DEFAULT '',
			parent_request_id CHAR(36) NOT NULL DEFAULT '',
			event_type VARCHAR(32) NOT NULL DEFAULT 'mutation',
			operation_class VARCHAR(96) NOT NULL DEFAULT '',
			resource_type VARCHAR(96) NOT NULL DEFAULT '',
			resource_ref VARCHAR(255) NOT NULL DEFAULT '',
			change_set_id VARCHAR(96) NOT NULL DEFAULT '',
			execution_status VARCHAR(32) NOT NULL DEFAULT '',
			verification_status VARCHAR(32) NOT NULL DEFAULT '',
			rollback_status VARCHAR(32) NOT NULL DEFAULT 'not_needed',
			before_sha256 CHAR(64) NOT NULL DEFAULT '',
			after_sha256 CHAR(64) NOT NULL DEFAULT '',
			changed_bytes BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			validator_summary LONGTEXT NULL,
			smoke_summary LONGTEXT NULL,
			error_code VARCHAR(190) NOT NULL DEFAULT '',
			cause_key VARCHAR(255) NOT NULL DEFAULT '',
			duration_ms BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			backend VARCHAR(32) NOT NULL DEFAULT 'plugin',
			site_fingerprint CHAR(64) NOT NULL DEFAULT '',
			mode VARCHAR(32) NOT NULL DEFAULT '',
			severity VARCHAR(16) NOT NULL DEFAULT 'info',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY ability_idx (ability_name),
			KEY user_idx (user_id),
			KEY event_idx (event_type),
			KEY operation_idx (operation_class),
			KEY verification_idx (verification_status),
			KEY rollback_idx (rollback_status),
			KEY change_set_idx (change_set_id),
			KEY created_idx (created_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * @param array<string, mixed> $sanitized_args Already-redacted payload summary.
	 * @return bool True when the row was persisted.
	 */
	public static function record( string $ability, array $sanitized_args, string $status = 'ok' ): bool {
		global $wpdb;
		$table = self::table_name();

		$status = in_array( $status, self::STATUSES, true ) ? $status : 'error';
		$sanitized_args = self::redact_sensitive( $sanitized_args );
		$meta = is_array( $sanitized_args['_meta'] ?? null ) ? $sanitized_args['_meta'] : [];
		$verification = self::meta_string( $meta, 'verification_status' );
		$rollback     = self::meta_string( $meta, 'rollback_status', 'not_needed' );
		$event_type   = 'mutation';
		if ( 'failed' === $verification || 'failed' === $rollback || 'succeeded' === $rollback ) {
			$event_type = 'incident';
		} elseif ( 'blocked' === $status ) {
			$event_type = 'safety_block';
		}
		$severity = 'info';
		if ( 'failed' === $rollback ) {
			$severity = 'p0';
		} elseif ( 'error' === $status || 'failed' === $verification ) {
			$severity = 'high';
		} elseif ( 'blocked' === $status ) {
			$severity = 'warning';
		}

		$result = $wpdb->insert(
			$table,
			[
				'ability_name'   => $ability,
				'user_id'        => get_current_user_id(),
				'args_hash'      => Json::hash( $sanitized_args ),
				'sanitized_args' => Json::encode( $sanitized_args ),
				'result_status'  => $status,
				'ip_hash'        => self::hash_value( $_SERVER['REMOTE_ADDR'] ?? '' ),
				'ua_hash'        => self::hash_value( $_SERVER['HTTP_USER_AGENT'] ?? '' ),
				'request_id'     => self::request_id(),
				'parent_request_id' => self::meta_string( $meta, 'parent_request_id' ),
				'event_type'        => $event_type,
				'operation_class'   => self::meta_string( $meta, 'operation_class' ),
				'resource_type'     => self::meta_string( $meta, 'resource_type' ),
				'resource_ref'      => self::logical_resource_ref( $meta, $sanitized_args ),
				'change_set_id'     => self::meta_string( $meta, 'change_set_id' ),
				'execution_status'  => self::meta_string( $meta, 'execution_status', $status ),
				'verification_status'=> $verification,
				'rollback_status'   => $rollback,
				'before_sha256'     => self::hash_field( $meta, 'before_sha256' ),
				'after_sha256'      => self::hash_field( $meta, 'after_sha256' ),
				'changed_bytes'     => max( 0, (int) ( $meta['changed_bytes'] ?? 0 ) ),
				'validator_summary' => self::encoded_meta( $meta['validator_summary'] ?? null ),
				'smoke_summary'     => self::encoded_meta( $meta['smoke_summary'] ?? null ),
				'error_code'        => sanitize_key( self::meta_string( $meta, 'error_code' ) ),
				'cause_key'         => mb_substr( sanitize_text_field( self::meta_string( $meta, 'cause_key' ) ), 0, 255 ),
				'duration_ms'       => max( 0, (int) ( $meta['duration_ms'] ?? 0 ) ),
				'backend'           => 'plugin',
				'site_fingerprint'  => hash( 'sha256', home_url( '/' ) . '|' . (string) ( function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 1 ) ),
				'mode'              => sanitize_key( (string) get_option( 'stonewright_mode', 'development' ) ),
				'severity'          => $severity,
				'created_at'     => current_time( 'mysql', true ),
			],
			[ '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ]
		);

		if ( false === $result ) {
			// Do not recursively audit the audit failure.
			Logger::error(
				'audit_log_insert_failed',
				[
					'ability'    => $ability,
					'status'     => $status,
					'wpdb_error' => (string) ( $wpdb->last_error ?? '' ),
				]
			);
			self::mark_audited();
			update_option(
				'stonewright_audit_degraded',
				[
					'at'      => current_time( 'mysql', true ),
					'ability' => $ability,
				],
				false
			);
			return false;
		}

		self::mark_audited();
		delete_option( 'stonewright_audit_degraded' );

		// Learn from recurring errors without blocking the audit write path.
		try {
			ErrorPatterns::observe( $ability, $status, $sanitized_args );
		} catch ( \Throwable $t ) {
			Logger::error(
				'error_patterns_observe_threw',
				[
					'ability' => $ability,
					'error'   => $t->getMessage(),
				]
			);
		}

		return true;
	}

	/**
	 * Record a Stonewright REST mutation unless an ability already audited this request.
	 *
	 * @param array<string, mixed> $sanitized_args
	 */
	public static function record_rest_mutation( string $route, string $method, array $sanitized_args, string $status = 'ok' ): bool {
		if ( self::was_audited() ) {
			return true;
		}
		$label = 'rest:' . strtoupper( $method ) . ' ' . $route;
		return self::record( $label, $sanitized_args, $status );
	}

	/**
	 * @param array<string, mixed> $filters Optional ability/status/user/date filters.
	 * @return array<int, array<string, mixed>>
	 */
	public static function recent( int $per_page = 20, int $page = 1, array $filters = [] ): array {
		global $wpdb;
		$table  = self::table_name();
		$offset = max( 0, ( $page - 1 ) * $per_page );

		[ $where_sql, $params ] = self::build_filter_clause( $filters );

		$sql = "SELECT id, ability_name, user_id, result_status, sanitized_args,
				event_type, operation_class, resource_type, resource_ref, change_set_id,
				execution_status, verification_status, rollback_status, before_sha256,
				after_sha256, changed_bytes, error_code, cause_key, duration_ms, backend,
				site_fingerprint, mode, severity, created_at
			FROM {$table}
			{$where_sql}
			ORDER BY id DESC
			LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name internal.
		$params[] = $per_page;
		$params[] = $offset;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Dynamic WHERE assembled above; values prepared.
		$rows = $wpdb->get_results(
			$wpdb->prepare( $sql, ...$params ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Exact matching row count for deterministic pagination.
	 *
	 * @param array<string, mixed> $filters
	 */
	public static function count( array $filters = [] ): int {
		global $wpdb;
		$table = self::table_name();
		[ $where_sql, $params ] = self::build_filter_clause( $filters );

		$sql = "SELECT COUNT(*) FROM {$table} {$where_sql}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		if ( [] === $params ) {
			$total = $wpdb->get_var( $sql );
		} else {
			$total = $wpdb->get_var( $wpdb->prepare( $sql, ...$params ) );
		}
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		return (int) $total;
	}

	/**
	 * Daily call counts for the last N days (UTC), oldest → newest.
	 *
	 * @return array<string, int> Map of Y-m-d => count (includes zero days).
	 */
	public static function daily_counts( int $days = 14 ): array {
		global $wpdb;
		$days  = max( 1, min( 90, $days ) );
		$table = self::table_name();
		$from  = gmdate( 'Y-m-d 00:00:00', time() - ( ( $days - 1 ) * DAY_IN_SECONDS ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Table name internal.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(created_at) AS day, COUNT(*) AS total
				FROM {$table}
				WHERE created_at >= %s
				GROUP BY DATE(created_at)
				ORDER BY day ASC",
				$from
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared

		$counts = [];
		for ( $i = $days - 1; $i >= 0; --$i ) {
			$day            = gmdate( 'Y-m-d', time() - ( $i * DAY_IN_SECONDS ) );
			$counts[ $day ] = 0;
		}

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$day = (string) ( $row['day'] ?? '' );
				if ( isset( $counts[ $day ] ) ) {
					$counts[ $day ] = (int) ( $row['total'] ?? 0 );
				}
			}
		}

		return $counts;
	}

	/**
	 * @param array<string, mixed> $filters Optional ability/status/user/date filters.
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	private static function build_filter_clause( array $filters ): array {
		$clauses = [];
		$params  = [];

		$ability = isset( $filters['ability'] ) ? sanitize_text_field( (string) $filters['ability'] ) : '';
		if ( '' !== $ability ) {
			$clauses[] = 'ability_name LIKE %s';
			$params[]  = '%' . self::esc_like( $ability ) . '%';
		}

		$status = isset( $filters['status'] ) ? sanitize_key( (string) $filters['status'] ) : '';
		if ( in_array( $status, self::STATUSES, true ) ) {
			$clauses[] = 'result_status = %s';
			$params[]  = $status;
		}

		$user = isset( $filters['user'] ) ? (int) $filters['user'] : 0;
		if ( $user > 0 ) {
			$clauses[] = 'user_id = %d';
			$params[]  = $user;
		}

		$from = isset( $filters['from'] ) ? sanitize_text_field( (string) $filters['from'] ) : '';
		if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) {
			$clauses[] = 'created_at >= %s';
			$params[]  = $from . ' 00:00:00';
		}

		$to = isset( $filters['to'] ) ? sanitize_text_field( (string) $filters['to'] ) : '';
		if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
			$clauses[] = 'created_at <= %s';
			$params[]  = $to . ' 23:59:59';
		}

		foreach (
			[
				'backend'            => 'backend',
				'operation_class'    => 'operation_class',
				'verification_status'=> 'verification_status',
				'rollback_status'    => 'rollback_status',
				'severity'           => 'severity',
				'change_set_id'      => 'change_set_id',
				'event_type'         => 'event_type',
			] as $filter_key => $column
		) {
			$value = isset( $filters[ $filter_key ] ) ? sanitize_key( (string) $filters[ $filter_key ] ) : '';
			if ( '' !== $value ) {
				$clauses[] = $column . ' = %s';
				$params[]  = $value;
			}
		}

		if ( [] === $clauses ) {
			return [ '', [] ];
		}

		return [ 'WHERE ' . implode( ' AND ', $clauses ), $params ];
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	public static function redact_sensitive( array $payload ): array {
		$keys = [
			'password',
			'user_pass',
			'token',
			'confirmation_token',
			'api_key',
			'secret',
			'app_password',
			'application_password',
			'authorization',
			'cookie',
			'cookies',
		];
		$out = [];
		foreach ( $payload as $key => $value ) {
			$lk = strtolower( (string) $key );
			// Preserve AbilityKernel digests already shaped as [redacted,...].
			if ( is_string( $value ) && str_starts_with( $value, '[redacted' ) ) {
				$out[ $key ] = $value;
				continue;
			}
			if ( in_array( $lk, $keys, true ) || str_contains( $lk, 'password' ) || str_contains( $lk, 'token' ) || str_contains( $lk, 'secret' ) ) {
				$out[ $key ] = is_string( $value ) && str_starts_with( $value, '[redacted' )
					? $value
					: '[redacted]';
				continue;
			}
			if ( is_array( $value ) ) {
				$out[ $key ] = self::redact_sensitive( $value );
				continue;
			}
			$out[ $key ] = $value;
		}
		return $out;
	}

	private static function esc_like( string $value ): string {
		global $wpdb;
		if ( is_object( $wpdb ) && method_exists( $wpdb, 'esc_like' ) ) {
			return $wpdb->esc_like( $value );
		}
		return addcslashes( $value, '_%\\' );
	}

	private static function hash_value( string $value ): string {
		if ( '' === $value ) {
			return '';
		}
		$salt = defined( 'AUTH_SALT' ) ? constant( 'AUTH_SALT' ) : 'stonewright';
		return hash( 'sha256', $salt . '|' . $value );
	}

	/**
	 * @param array<string, mixed> $meta
	 */
	private static function meta_string( array $meta, string $key, string $default = '' ): string {
		return isset( $meta[ $key ] ) && is_scalar( $meta[ $key ] )
			? mb_substr( (string) $meta[ $key ], 0, 255 )
			: $default;
	}

	/**
	 * @param array<string, mixed> $meta
	 */
	private static function hash_field( array $meta, string $key ): string {
		$value = strtolower( self::meta_string( $meta, $key ) );
		return 1 === preg_match( '/^[a-f0-9]{64}$/', $value ) ? $value : '';
	}

	private static function encoded_meta( mixed $value ): string {
		if ( null === $value || '' === $value ) {
			return '';
		}
		return is_scalar( $value ) ? mb_substr( (string) $value, 0, 2000 ) : mb_substr( Json::encode( $value ), 0, 2000 );
	}

	/**
	 * @param array<string, mixed> $meta
	 * @param array<string, mixed> $args
	 */
	private static function logical_resource_ref( array $meta, array $args ): string {
		foreach ( [ $meta['resource_ref'] ?? null, $args['resource'] ?? null, $args['path'] ?? null ] as $candidate ) {
			if ( is_scalar( $candidate ) && '' !== trim( (string) $candidate ) ) {
				$value = str_replace( '\\', '/', (string) $candidate );
				if ( str_starts_with( $value, '/' ) || preg_match( '/^[A-Za-z]:\//', $value ) ) {
					$value = basename( $value );
				}
				return mb_substr( sanitize_text_field( $value ), 0, 255 );
			}
		}
		return '';
	}
}
