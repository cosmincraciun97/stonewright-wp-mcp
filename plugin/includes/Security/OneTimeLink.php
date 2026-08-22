<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

/**
 * One-time admin access links.
 *
 * HMAC-signed, IP/UA-bound, single-use tokens for short-lived wp-admin login.
 * Tokens are never persistent remember-me cookies.
 *
 * @stonewright-status stable
 */
final class OneTimeLink {

	private const TOKEN_PREFIX     = 'swotl_';
	private const USED_PREFIX      = 'stonewright_otl_used_';
	private const RATE_OPTION_PREFIX = 'stonewright_otl_rate_';
	private const RATE_MAX         = 10;
	private const RATE_WINDOW      = 3600;
	private const SECRET_OPTION    = 'stonewright_otl_secret';

	/**
	 * Create a one-time admin access URL valid for $ttl_seconds.
	 *
	 * @param array{ip?:string,user_agent?:string} $context Optional bind override.
	 * @return string|\WP_Error Full admin URL, or a rate-limit error.
	 */
	public static function create( int $user_id, int $ttl_seconds = 300, array $context = [] ): string|\WP_Error {
		$ttl = max( 30, min( 3600, $ttl_seconds ) );
		$rate = self::consume_issue_slot( $user_id );
		if ( $rate instanceof \WP_Error ) {
			return $rate;
		}

		$ip = self::client_ip( $context );
		$ua = self::client_user_agent( $context );
		$nonce = bin2hex( random_bytes( 16 ) );
		$payload_data = [
			'user_id'    => $user_id,
			'nonce'      => $nonce,
			'expires_at' => self::now() + $ttl,
			'ip_hash'    => self::hash_bind( $ip ),
			'ua_hash'    => self::hash_bind( $ua ),
		];
		$payload_json = self::canonical_json( $payload_data );
		$token        = self::TOKEN_PREFIX . self::b64url_encode( $payload_json ) . '.' . self::b64url_encode( self::sign( $payload_json ) );

		return add_query_arg( [ 'stonewright_otl' => $token ], admin_url() );
	}

	/**
	 * Consume a one-time token. Returns the associated user ID on success,
	 * or false when the token is invalid, expired, bound-mismatched, or already used.
	 *
	 * @param array{ip?:string,user_agent?:string} $context Optional bind override.
	 * @return int|false User ID, or false.
	 */
	public static function consume( string $token, array $context = [] ): int|false {
		$parsed = self::parse_token( $token );
		if ( null === $parsed ) {
			return false;
		}

		[ $payload_json, $provided_sig, $payload_data ] = $parsed;
		if ( ! hash_equals( self::sign( $payload_json ), $provided_sig ) ) {
			return false;
		}

		$expires_at = (int) ( $payload_data['expires_at'] ?? 0 );
		if ( self::now() > $expires_at ) {
			return false;
		}

		$ip = self::client_ip( $context );
		$ua = self::client_user_agent( $context );
		if ( ! hash_equals( (string) ( $payload_data['ip_hash'] ?? '' ), self::hash_bind( $ip ) ) ) {
			return false;
		}
		if ( ! hash_equals( (string) ( $payload_data['ua_hash'] ?? '' ), self::hash_bind( $ua ) ) ) {
			return false;
		}

		$nonce = (string) ( $payload_data['nonce'] ?? '' );
		if ( '' === $nonce ) {
			return false;
		}
		$used_key = self::USED_PREFIX . $nonce;
		if ( false !== get_transient( $used_key ) ) {
			return false;
		}

		$ttl_remaining = max( 1, $expires_at - self::now() );
		set_transient( $used_key, 1, min( $ttl_remaining, self::RATE_WINDOW ) );

		return (int) ( $payload_data['user_id'] ?? 0 );
	}

	/**
	 * @param array{ip?:string,user_agent?:string} $context
	 */
	public static function authenticate( string $token, array $context = [] ): int|false {
		$user_id = self::consume( $token, $context );
		if ( false === $user_id || $user_id <= 0 ) {
			self::audit_redemption( false, 0 );
			return false;
		}

		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, false, is_ssl() );
		self::audit_redemption( true, $user_id );
		return $user_id;
	}

	public static function maybe_handle_request(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = isset( $_GET['stonewright_otl'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['stonewright_otl'] ) ) : '';
		if ( '' === $token ) {
			return;
		}

		$user_id = self::authenticate( $token );
		if ( false === $user_id ) {
			return;
		}

		$redirect = remove_query_arg( 'stonewright_otl' );
		wp_safe_redirect( $redirect );
		exit;
	}

	/** @param array{ip?:string,user_agent?:string} $context */
	private static function client_ip( array $context ): string {
		if ( isset( $context['ip'] ) && is_string( $context['ip'] ) ) {
			return self::canonicalize_ip( $context['ip'] );
		}
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		return self::canonicalize_ip( $ip );
	}

	/**
	 * Localhost IPv4/IPv6 must hash the same. MCP often sees 127.0.0.1 while
	 * a browser on the same machine redeems via ::1.
	 */
	private static function canonicalize_ip( string $ip ): string {
		$ip = strtolower( trim( $ip ) );
		if ( in_array( $ip, [ '127.0.0.1', '::1', '::ffff:127.0.0.1', '0:0:0:0:0:0:0:1' ], true ) ) {
			return '127.0.0.1';
		}
		return $ip;
	}

	/** @param array{ip?:string,user_agent?:string} $context */
	private static function client_user_agent( array $context ): string {
		if ( isset( $context['user_agent'] ) && is_string( $context['user_agent'] ) ) {
			return $context['user_agent'];
		}
		return isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) ) : '';
	}

	private static function consume_issue_slot( int $user_id ): bool|\WP_Error {
		$key   = self::RATE_OPTION_PREFIX . $user_id;
		$state = get_option( $key, [] );
		$state = is_array( $state ) ? $state : [];
		$start = (int) ( $state['window_start'] ?? 0 );
		$count = (int) ( $state['count'] ?? 0 );
		$now   = self::now();
		if ( $start <= 0 || ( $now - $start ) >= self::RATE_WINDOW ) {
			$start = $now;
			$count = 0;
		}
		if ( $count >= self::RATE_MAX ) {
			return new \WP_Error(
				'stonewright_otl_rate_limited',
				__( 'Too many one-time login links were issued for this user. Try again later.', 'stonewright' ),
				[ 'status' => 429 ]
			);
		}
		update_option(
			$key,
			[
				'window_start' => $start,
				'count'        => $count + 1,
			],
			false
		);
		return true;
	}

	private static function audit_redemption( bool $ok, int $user_id ): void {
		AuditLog::record(
			'stonewright/security-one-time-link',
			[
				'_meta' => [
					'operation_class' => 'one_time_link_redeem',
					'resource_type'   => 'user',
					'resource_ref'    => (string) $user_id,
				],
			],
			$ok ? 'ok' : 'blocked'
		);
	}

	private static function now(): int {
		$now = apply_filters( 'stonewright_otl_now', time() );
		return is_numeric( $now ) ? (int) $now : time();
	}

	private static function hash_bind( string $value ): string {
		return hash_hmac( 'sha256', $value, self::secret() );
	}

	private static function secret(): string {
		$per_install = (string) get_option( self::SECRET_OPTION, '' );
		if ( '' === $per_install ) {
			$per_install = bin2hex( random_bytes( 32 ) );
			add_option( self::SECRET_OPTION, $per_install, '', false );
		}
		return wp_salt( 'auth' ) . $per_install;
	}

	private static function sign( string $payload_json ): string {
		return hash_hmac( 'sha256', $payload_json, self::secret(), true );
	}

	/** @param array<string, mixed> $data */
	private static function canonical_json( array $data ): string {
		ksort( $data );
		$encoded = json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $encoded ? '{}' : $encoded;
	}

	private static function b64url_encode( string $bytes ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}

	private static function b64url_decode( string $b64 ): string|false {
		$padded = $b64 . str_repeat( '=', ( 4 - strlen( $b64 ) % 4 ) % 4 );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		return base64_decode( strtr( $padded, '-_', '+/' ), true );
	}

	/**
	 * @return array{string, string, array<string, mixed>}|null
	 */
	private static function parse_token( string $token ): ?array {
		if ( ! str_starts_with( $token, self::TOKEN_PREFIX ) ) {
			return null;
		}

		$without_prefix = substr( $token, strlen( self::TOKEN_PREFIX ) );
		$dot            = strpos( $without_prefix, '.' );
		if ( false === $dot ) {
			return null;
		}

		$payload_json = self::b64url_decode( substr( $without_prefix, 0, $dot ) );
		$sig          = self::b64url_decode( substr( $without_prefix, $dot + 1 ) );
		if ( false === $payload_json || false === $sig || '' === $payload_json || '' === $sig ) {
			return null;
		}

		try {
			$data = json_decode( $payload_json, true, 512, JSON_THROW_ON_ERROR );
		} catch ( \JsonException ) {
			return null;
		}

		if ( ! is_array( $data ) ) {
			return null;
		}

		return [ $payload_json, $sig, $data ];
	}
}
