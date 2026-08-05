<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * Atomic OAuth throttling keyed by client identity and a network bucket.
 */
final class OAuthRateLimiter {

	public const TABLE = 'stonewright_oauth_rate_limits';
	public const METRICS_TABLE = 'stonewright_oauth_rate_metrics';
	public const DCR_LIMIT = 10;
	public const ENDPOINT_LIMIT = 30;
	public const REFRESH_LIMIT = 12;
	public const AUTH_FAILURE_LIMIT = 10;
	public const METRICS_RETENTION = DAY_IN_SECONDS;

	/** @var array<string, array{window_started:int,limited_requests:int,fingerprints:array<string,true>,cooldown_until:int}> */
	private static array $fallback_metrics = [];

	public static function enabled(): bool {
		$enabled = (bool) get_option( 'stonewright_oauth_rate_limiter_enabled', true );
		return (bool) apply_filters( 'stonewright_oauth_rate_limiter_enabled', $enabled );
	}

	public static function maybe_install(): void {
		if ( '1' === (string) get_option( 'stonewright_oauth_rate_limit_schema', '0' ) ) {
			return;
		}
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( "CREATE TABLE {$table} (
			bucket_key CHAR(64) NOT NULL,
			window_started BIGINT(20) UNSIGNED NOT NULL,
			hits BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (bucket_key),
			KEY updated_idx (updated_at)
		) {$wpdb->get_charset_collate()};" );
		$metrics_table = $wpdb->prefix . self::METRICS_TABLE;
		dbDelta( "CREATE TABLE {$metrics_table} (
			metric_bucket CHAR(64) NOT NULL,
			window_started BIGINT(20) UNSIGNED NOT NULL,
			fingerprint_key CHAR(64) NOT NULL,
			limited_requests BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			cooldown_until BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (metric_bucket, window_started, fingerprint_key),
			KEY window_idx (window_started),
			KEY updated_idx (updated_at)
		) {$wpdb->get_charset_collate()};" );
		update_option( 'stonewright_oauth_rate_limit_schema', '1', false );
	}

	/** @return array{allowed:bool,retry_after:int,remaining:int} */
	public static function hit( string $bucket, string $key, int $limit, int $window ): array {
		if ( ! self::enabled() || '' === $key ) {
			return [ 'allowed' => true, 'retry_after' => 0, 'remaining' => $limit ];
		}
		$now       = time();
		$window    = max( 1, $window );
		$limit     = max( 1, $limit );
		// The bucket identifier is operational state, not a public digest. HMAC
		// keeps client/network identities opaque even if the rate-limit table is
		// inspected outside the normal admin boundary.
		$bucket_key = self::bucket_key( $bucket, $key );
		global $wpdb;
		if ( self::db_available() ) {
			$table = $wpdb->prefix . self::TABLE;
			$started = $now - ( $now % $window );
			$sql = $wpdb->prepare(
				self::atomic_upsert_sql( $table ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- internal SQL template contains placeholders; every value is prepared below.
				$bucket_key,
				$started,
				gmdate( 'Y-m-d H:i:s', $now ),
				$started,
				$started
			);
			$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- all values prepared; table is internal.
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT window_started, hits FROM {$table} WHERE bucket_key = %s", $bucket_key ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- table name is an internal constant.
			if ( is_array( $row ) ) {
				$started = (int) ( $row['window_started'] ?? $started );
				$hits    = (int) ( $row['hits'] ?? 0 );
				$retry   = max( 0, ( $started + $window ) - $now );
				$result = [ 'allowed' => $hits <= $limit, 'retry_after' => $retry, 'remaining' => max( 0, $limit - $hits ) ];
				self::record_metric( $bucket, $key, $started, $window, $result['allowed'] ? 0 : 1, $result['allowed'] ? 0 : $started + $window );
				return $result;
			}
		}

		// Unit doubles and installations before the schema is created use the
		// same key contract. Production reaches the atomic branch above.
		$transient = 'stonewright_oauth_rl_' . $bucket_key;
		$current   = (int) get_transient( $transient );
		if ( $current >= $limit ) {
			$result = [ 'allowed' => false, 'retry_after' => $window, 'remaining' => 0 ];
			self::record_metric( $bucket, $key, $now - ( $now % $window ), $window, 1, $now + $window );
			return $result;
		}
		set_transient( $transient, $current + 1, $window );
		$result = [ 'allowed' => true, 'retry_after' => $window, 'remaining' => max( 0, $limit - $current - 1 ) ];
		self::record_metric( $bucket, $key, $now - ( $now % $window ), $window, 0, 0 );
		return $result;
	}

	/**
	 * Release a successful preflight reservation without forgiving failures.
	 * Database storage performs one conditional decrement in the active window.
	 */
	public static function release( string $bucket, string $key, int $window ): void {
		if ( ! self::enabled() || '' === $key ) {
			return;
		}
		$now        = time();
		$window     = max( 1, $window );
		$started    = $now - ( $now % $window );
		$bucket_key = self::bucket_key( $bucket, $key );
		global $wpdb;
		if ( self::db_available() ) {
			$table = $wpdb->prefix . self::TABLE;
			$sql   = $wpdb->prepare(
				"UPDATE {$table} SET hits = GREATEST(hits - 1, 0), updated_at = %s WHERE bucket_key = %s AND window_started = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an internal constant.
				gmdate( 'Y-m-d H:i:s', $now ),
				$bucket_key,
				$started
			);
			if ( false !== $wpdb->query( $sql ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- all values prepared; table is internal.
				return;
			}
		}

		$transient = 'stonewright_oauth_rl_' . $bucket_key;
		$current   = (int) get_transient( $transient );
		if ( $current <= 1 ) {
			delete_transient( $transient );
			return;
		}
		set_transient( $transient, $current - 1, max( 1, ( $started + $window ) - $now ) );
	}

	/**
	 * Count-only operational metrics. Identity material is never returned; the
	 * storage layer keeps only HMAC fingerprints so operators can see a storm
	 * without receiving client IDs or addresses.
	 *
	 * @return array{limited_requests:int,distinct_client_fingerprints:int,cooldown_active:bool}
	 */
	public static function metrics_snapshot( string $bucket = '' ): array {
		$now = time();
		global $wpdb;
		if ( self::db_available() ) {
			$table  = $wpdb->prefix . self::METRICS_TABLE;
			$where  = [ 'window_started >= %d' ];
			$params = [ $now - self::METRICS_RETENTION ];
			if ( '' !== $bucket ) {
				$where[]  = 'metric_bucket = %s';
				$params[] = self::metric_bucket( $bucket );
			}
			$sql = 'SELECT COALESCE(SUM(limited_requests), 0) AS limited_requests, COUNT(DISTINCT fingerprint_key) AS distinct_client_fingerprints, MAX(cooldown_until) AS cooldown_until FROM ' . $table . ' WHERE ' . implode( ' AND ', $where ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an internal constant.
			$row = $wpdb->get_row( $wpdb->prepare( $sql, ...$params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- all values are prepared and the table is internal.
			if ( is_array( $row ) ) {
				return [
					'limited_requests'            => (int) ( $row['limited_requests'] ?? 0 ),
					'distinct_client_fingerprints' => (int) ( $row['distinct_client_fingerprints'] ?? 0 ),
					'cooldown_active'             => (int) ( $row['cooldown_until'] ?? 0 ) > $now,
				];
			}
		}

		$rows = self::$fallback_metrics;
		if ( '' !== $bucket ) {
			$rows = isset( $rows[ self::metric_bucket( $bucket ) ] ) ? [ self::metric_bucket( $bucket ) => $rows[ self::metric_bucket( $bucket ) ] ] : [];
		}
		$limited = 0;
		$fingerprints = [];
		$cooldown = false;
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || (int) ( $row['window_started'] ?? 0 ) < $now - self::METRICS_RETENTION ) {
				continue;
			}
			$limited += (int) ( $row['limited_requests'] ?? 0 );
			foreach ( array_keys( (array) ( $row['fingerprints'] ?? [] ) ) as $fingerprint ) {
				$fingerprints[ (string) $fingerprint ] = true;
			}
			$cooldown = $cooldown || (int) ( $row['cooldown_until'] ?? 0 ) > $now;
		}
		return [ 'limited_requests' => $limited, 'distinct_client_fingerprints' => count( $fingerprints ), 'cooldown_active' => $cooldown ];
	}

	/** Reset only the in-memory fallback used by unit doubles. */
	public static function reset_metrics_for_tests(): void {
		self::$fallback_metrics = [];
	}

	public static function client_ip(): string {
		$remote = ClientValidation::normalize_ip_literal( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		$trusted = apply_filters( 'stonewright_oauth_trusted_proxy_ips', [] );
		$trusted = is_array( $trusted ) ? array_map( [ ClientValidation::class, 'normalize_ip_literal' ], $trusted ) : [];
		if ( '' === $remote || ! in_array( $remote, $trusted, true ) ) {
			return $remote;
		}
		$chain = [];
		foreach ( array_map( 'trim', explode( ',', (string) ( $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '' ) ) ) as $candidate ) {
			$ip = ClientValidation::normalize_ip_literal( $candidate );
			if ( '' !== $ip ) {
				$chain[] = $ip;
			}
		}
		$chain[] = $remote;
		for ( $index = count( $chain ) - 1; $index >= 0; --$index ) {
			if ( ! in_array( $chain[ $index ], $trusted, true ) ) {
				return $chain[ $index ];
			}
		}
		return $remote;
	}

	public static function network_bucket( string $ip ): string {
		$ip = ClientValidation::normalize_ip_literal( $ip );
		if ( '' === $ip ) {
			return '';
		}
		if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$parts = explode( '.', $ip );
			return implode( '.', array_slice( $parts, 0, 3 ) ) . '.0/24';
		}
		$packed = inet_pton( $ip );
		return is_string( $packed ) ? inet_ntop( substr( $packed, 0, 8 ) . str_repeat( "\0", 8 ) ) . '/64' : $ip;
	}

	public static function identity_key( string $ip, string $client_id = '' ): string {
		return strtolower( trim( $client_id ) ) . '|' . self::network_bucket( $ip );
	}

	/** @return array{allowed:bool,retry_after:int,remaining:int} */
	public static function endpoint( string $endpoint, string $ip, string $client_id = '' ): array {
		return self::hit( 'endpoint:' . $endpoint, self::identity_key( $ip, $client_id ), self::ENDPOINT_LIMIT, MINUTE_IN_SECONDS );
	}

	private static function db_available(): bool {
		global $wpdb;
		// Generic wpdb-shaped test doubles do not emulate atomic SQL or metric
		// reads. Keep those on the deterministic fallback; production uses the
		// real WordPress database class.
		return $wpdb instanceof \wpdb;
	}

	private static function atomic_upsert_sql( string $table ): string {
		return "INSERT INTO {$table} (bucket_key, window_started, hits, updated_at)" . // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an internal constant.
			"\n
			VALUES (%s, %d, 1, %s)
			ON DUPLICATE KEY UPDATE
			hits = IF(window_started < %d, 1, hits + 1),
			window_started = IF(window_started < %d, VALUES(window_started), window_started),
			updated_at = VALUES(updated_at)";
	}

	private static function bucket_key( string $bucket, string $key ): string {
		$salt = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : 'stonewright-oauth';
		return hash_hmac( 'sha256', 'stonewright-oauth|' . sanitize_key( $bucket ) . '|' . $key, $salt );
	}

	private static function metric_bucket( string $bucket ): string {
		$salt = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : 'stonewright-oauth';
		return hash_hmac( 'sha256', 'stonewright-oauth-metric|' . sanitize_key( $bucket ), $salt );
	}

	private static function metric_fingerprint( string $key ): string {
		$salt = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : 'stonewright-oauth';
		return hash_hmac( 'sha256', 'stonewright-oauth-fingerprint|' . $key, $salt );
	}

	private static function record_metric( string $bucket, string $key, int $window_started, int $window, int $limited, int $cooldown_until ): void {
		$metric_bucket = self::metric_bucket( $bucket );
		$fingerprint   = self::metric_fingerprint( $key );
		global $wpdb;
		if ( self::db_available() ) {
			$table = $wpdb->prefix . self::METRICS_TABLE;
			$sql   = $wpdb->prepare(
				"INSERT INTO {$table} (metric_bucket, window_started, fingerprint_key, limited_requests, cooldown_until, updated_at) VALUES (%s, %d, %s, %d, %d, %s)\n" . // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an internal constant.
				"ON DUPLICATE KEY UPDATE limited_requests = limited_requests + VALUES(limited_requests), cooldown_until = GREATEST(cooldown_until, VALUES(cooldown_until)), updated_at = VALUES(updated_at)",
				$metric_bucket,
				$window_started,
				$fingerprint,
				$limited,
				$cooldown_until,
				gmdate( 'Y-m-d H:i:s' )
			);
			$written = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- all values prepared; table is internal.
			if ( false !== $written ) {
				return;
			}
		}

		$current = self::$fallback_metrics[ $metric_bucket ] ?? [
			'window_started'   => $window_started,
			'limited_requests' => 0,
			'fingerprints'     => [],
			'cooldown_until'   => 0,
		];
		if ( (int) $current['window_started'] !== $window_started ) {
			$current = [ 'window_started' => $window_started, 'limited_requests' => 0, 'fingerprints' => [], 'cooldown_until' => 0 ];
		}
		$current['limited_requests'] += $limited;
		if ( $limited > 0 ) {
			$current['fingerprints'][ $fingerprint ] = true;
		}
		$current['cooldown_until'] = max( (int) $current['cooldown_until'], $cooldown_until, $window_started + max( 1, $window ) * ( $limited > 0 ? 1 : 0 ) );
		self::$fallback_metrics[ $metric_bucket ] = $current;
	}
}
