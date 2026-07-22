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
	 */
	private static bool $request_already_audited = false;

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
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY ability_idx (ability_name),
			KEY user_idx (user_id),
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
				'created_at'     => current_time( 'mysql', true ),
			],
			[ '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
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
			return false;
		}

		self::mark_audited();

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

		$sql = "SELECT id, ability_name, user_id, result_status, sanitized_args, created_at
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
}
