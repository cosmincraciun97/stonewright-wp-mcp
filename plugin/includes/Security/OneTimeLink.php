<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

/**
 * One-time admin access links.
 *
 * Allows AI agents to generate short-lived, single-use admin login URLs for
 * browser automation tools without exposing user passwords.
 * Tokens are stored in WP transients and are consumed on first use.
 *
 * @stonewright-status stable
 */
final class OneTimeLink {

	private const TRANSIENT_PREFIX = 'stonewright_otl_';

	/**
	 * Create a one-time admin access URL valid for $ttl_seconds.
	 *
	 * @param int $user_id     WP user ID to log in as.
	 * @param int $ttl_seconds Validity window in seconds (default 300 = 5 min).
	 * @return string Full admin URL with the OTL token as a query parameter.
	 */
	public static function create( int $user_id, int $ttl_seconds = 300 ): string {
		$token   = wp_generate_password( 32, false );
		$key     = self::TRANSIENT_PREFIX . $token;
		$expires = time() + $ttl_seconds;
		set_transient(
			$key,
			[
				'user_id' => $user_id,
				'expires' => $expires,
			],
			$ttl_seconds
		);
		return add_query_arg( [ 'stonewright_otl' => $token ], admin_url() );
	}

	/**
	 * Consume a one-time token. Returns the associated user ID on success,
	 * or false when the token is invalid, expired, or already consumed.
	 *
	 * @return int|false User ID, or false.
	 */
	public static function consume( string $token ): int|false {
		$key  = self::TRANSIENT_PREFIX . $token;
		$data = get_transient( $key );
		if ( false === $data || time() > ( $data['expires'] ?? 0 ) ) {
			return false;
		}
		delete_transient( $key );
		return (int) $data['user_id'];
	}

	public static function authenticate( string $token ): int|false {
		$user_id = self::consume( $token );
		if ( false === $user_id ) {
			return false;
		}

		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true, is_ssl() );
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
}
