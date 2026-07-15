<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

use Stonewright\WpMcp\Support\Json;

/**
 * Append-only audit log for write abilities.
 */
final class AuditLog {

	public const TABLE = 'stonewright_audit_log';

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
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

	public static function record( string $ability, array $sanitized_args, string $status = 'ok' ): void {
		global $wpdb;
		$table = self::table_name();

		$wpdb->insert(
			$table,
			[
				'ability_name'   => $ability,
				'user_id'        => get_current_user_id(),
				'args_hash'      => Json::hash( $sanitized_args ),
				'sanitized_args' => Json::encode( $sanitized_args ),
				'result_status'  => $status,
				'ip_hash'        => self::hash_value( $_SERVER['REMOTE_ADDR'] ?? '' ),
				'ua_hash'        => self::hash_value( $_SERVER['HTTP_USER_AGENT'] ?? '' ),
				'request_id'     => wp_generate_uuid4(),
				'created_at'     => current_time( 'mysql', true ),
			],
			[ '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		// Learn from recurring errors without blocking the audit write path.
		try {
			ErrorPatterns::observe( $ability, $status, $sanitized_args );
		} catch ( \Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Pattern learning is best-effort.
		}
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
		if ( in_array( $status, [ 'ok', 'error' ], true ) ) {
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
