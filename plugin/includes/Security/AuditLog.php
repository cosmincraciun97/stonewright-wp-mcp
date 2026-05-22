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
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function recent( int $per_page = 20, int $page = 1 ): array {
		global $wpdb;
		$table  = self::table_name();
		$offset = max( 0, ( $page - 1 ) * $per_page );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, ability_name, user_id, result_status, created_at FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$per_page,
				$offset
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : [];
	}

	private static function hash_value( string $value ): string {
		if ( '' === $value ) {
			return '';
		}
		$salt = defined( 'AUTH_SALT' ) ? constant( 'AUTH_SALT' ) : 'stonewright';
		return hash( 'sha256', $salt . '|' . $value );
	}
}
