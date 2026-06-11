<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

use Stonewright\WpMcp\Support\Json;

/**
 * Issues and verifies short-lived HMAC-signed tokens for destructive abilities.
 *
 * Token format: swc_<base64url(payload_json)>.<base64url(hmac_sha256)>
 * The payload is canonical JSON of {ability, args_hash, user_id, nonce, expires_at}.
 * The HMAC secret is derived from wp_salt('auth') + a per-install secret stored
 * in the stonewright_confirmation_secret option.
 *
 * Replay protection: on successful verify the nonce is stored in a transient for
 * min(TTL_remaining, 3600) seconds. A second verify of the same token is rejected
 * with stonewright_confirmation_replayed.
 */
final class ConfirmationToken {

	private const MIN_TTL        = 60;
	private const MAX_TTL        = 3600;
	private const NONCE_OPTION   = 'stonewright_confirmation_secret';
	private const NONCE_PREFIX   = 'stonewright_confirm_used_';

	// -------------------------------------------------------------------------
	// Public API.
	// -------------------------------------------------------------------------

	/**
	 * Issues a new confirmation token for the given ability + args.
	 *
	 * @param string               $ability     Fully-qualified ability name.
	 * @param array<string, mixed> $args        Ability args to sign over (confirmation_token is stripped).
	 * @param int                  $ttl_seconds Desired TTL, clamped to [60, 3600].
	 */
	public static function issue( string $ability, array $args, int $ttl_seconds = 300 ): string {
		$ttl         = max( self::MIN_TTL, min( self::MAX_TTL, $ttl_seconds ) );
		$normalized  = self::normalize_args( $args );
		$args_hash   = Json::hash( $normalized );
		$nonce       = bin2hex( random_bytes( 16 ) );
		$expires_at  = time() + $ttl;

		$payload_data = [
			'ability'    => $ability,
			'args_hash'  => $args_hash,
			'user_id'    => get_current_user_id(),
			'nonce'      => $nonce,
			'expires_at' => $expires_at,
		];

		$payload_json = self::canonical_json( $payload_data );
		$sig          = self::sign( $payload_json );

		return 'swc_' . self::b64url_encode( $payload_json ) . '.' . self::b64url_encode( $sig );
	}

	/**
	 * Verifies a token and returns true or a structured WP_Error.
	 *
	 * @param string               $token   Token string as returned by issue().
	 * @param string               $ability Expected ability name.
	 * @param array<string, mixed> $args    Args the ability was called with.
	 * @return bool|\WP_Error
	 */
	public static function verify_or_error( string $token, string $ability, array $args ): bool|\WP_Error {
		// 1. Parse the token structure.
		$parsed = self::parse_token( $token );
		if ( null === $parsed ) {
			AuditLog::record(
				'security.confirmation_token',
				[
					'ability'    => $ability,
					'result'     => 'invalid',
					'nonce_sha8' => '',
				]
			);
			return new \WP_Error(
				'stonewright_confirmation_invalid',
				__( 'Confirmation token is malformed.', 'stonewright' ),
				[ 'status' => 403 ]
			);
		}

		[ $payload_json, $provided_sig, $payload_data ] = $parsed;
		$nonce     = (string) ( $payload_data['nonce'] ?? '' );
		$nonce_sha = substr( hash( 'sha256', $nonce ), 0, 8 );

		// 2. Verify HMAC — constant-time comparison.
		$expected_sig = self::sign( $payload_json );
		if ( ! hash_equals( $expected_sig, $provided_sig ) ) {
			AuditLog::record(
				'security.confirmation_token',
				[
					'ability'    => $ability,
					'result'     => 'invalid',
					'nonce_sha8' => $nonce_sha,
				]
			);
			return new \WP_Error(
				'stonewright_confirmation_invalid',
				__( 'Confirmation token signature is invalid.', 'stonewright' ),
				[ 'status' => 403 ]
			);
		}

		// 3. Check expiry.
		$expires_at = (int) ( $payload_data['expires_at'] ?? 0 );
		if ( time() > $expires_at ) {
			AuditLog::record(
				'security.confirmation_token',
				[
					'ability'    => $ability,
					'result'     => 'expired',
					'nonce_sha8' => $nonce_sha,
				]
			);
			return new \WP_Error(
				'stonewright_confirmation_expired',
				__( 'Confirmation token has expired.', 'stonewright' ),
				[ 'status' => 403 ]
			);
		}

		// 4. Replay check — nonce must not have been consumed yet.
		$transient_key = self::NONCE_PREFIX . $nonce;
		if ( false !== get_transient( $transient_key ) ) {
			AuditLog::record(
				'security.confirmation_token',
				[
					'ability'    => $ability,
					'result'     => 'replayed',
					'nonce_sha8' => $nonce_sha,
				]
			);
			return new \WP_Error(
				'stonewright_confirmation_replayed',
				__( 'Confirmation token has already been used.', 'stonewright' ),
				[ 'status' => 403 ]
			);
		}

		// 5. Args hash check.
		$normalized = self::normalize_args( $args );
		$args_hash  = Json::hash( $normalized );
		if ( $args_hash !== (string) ( $payload_data['args_hash'] ?? '' ) ) {
			AuditLog::record(
				'security.confirmation_token',
				[
					'ability'    => $ability,
					'result'     => 'args_mismatch',
					'nonce_sha8' => $nonce_sha,
				]
			);
			return new \WP_Error(
				'stonewright_confirmation_args_mismatch',
				__( 'Confirmation token was issued for different arguments.', 'stonewright' ),
				[ 'status' => 403 ]
			);
		}

		// 6. Ability check.
		if ( (string) ( $payload_data['ability'] ?? '' ) !== $ability ) {
			AuditLog::record(
				'security.confirmation_token',
				[
					'ability'    => $ability,
					'result'     => 'ability_mismatch',
					'nonce_sha8' => $nonce_sha,
				]
			);
			return new \WP_Error(
				'stonewright_confirmation_ability_mismatch',
				__( 'Confirmation token was issued for a different ability.', 'stonewright' ),
				[ 'status' => 403 ]
			);
		}

		// 7. User ID check.
		if ( (int) ( $payload_data['user_id'] ?? -1 ) !== get_current_user_id() ) {
			AuditLog::record(
				'security.confirmation_token',
				[
					'ability'    => $ability,
					'result'     => 'user_mismatch',
					'nonce_sha8' => $nonce_sha,
				]
			);
			return new \WP_Error(
				'stonewright_confirmation_user_mismatch',
				__( 'Confirmation token was issued for a different user.', 'stonewright' ),
				[ 'status' => 403 ]
			);
		}

		// 8. All checks passed — consume the nonce.
		$ttl_remaining = max( 1, $expires_at - time() );
		set_transient( $transient_key, 1, min( $ttl_remaining, self::MAX_TTL ) );

		AuditLog::record(
			'security.confirmation_token',
			[
				'ability'    => $ability,
				'result'     => 'valid',
				'nonce_sha8' => $nonce_sha,
			]
		);

		return true;
	}

	/**
	 * Backward-compat bool wrapper around verify_or_error().
	 *
	 * @param string               $token
	 * @param string               $ability
	 * @param array<string, mixed> $args
	 */
	public static function verify( string $token, string $ability, array $args ): bool {
		return true === self::verify_or_error( $token, $ability, $args );
	}

	// -------------------------------------------------------------------------
	// Private helpers.
	// -------------------------------------------------------------------------

	/**
	 * Normalize args for deterministic hashing:
	 * - Strips top-level 'confirmation_token' key.
	 * - Recursively sorts associative arrays by key.
	 * - Leaves numerically-indexed arrays in their original order.
	 *
	 * @param array<mixed, mixed> $args
	 * @return array<mixed, mixed>
	 */
	private static function normalize_args( array $args ): array {
		// Strip top-level confirmation_token.
		unset( $args['confirmation_token'] );

		return self::sort_assoc( $args );
	}

	/**
	 * Recursively sorts associative arrays by key; leaves numeric arrays alone.
	 *
	 * @param array<mixed, mixed> $arr
	 * @return array<mixed, mixed>
	 */
	private static function sort_assoc( array $arr ): array {
		// Detect associative: any string key makes it associative.
		$is_assoc = false;
		foreach ( array_keys( $arr ) as $k ) {
			if ( is_string( $k ) ) {
				$is_assoc = true;
				break;
			}
		}

		if ( $is_assoc ) {
			ksort( $arr );
		}

		foreach ( $arr as $key => $value ) {
			if ( is_array( $value ) ) {
				$arr[ $key ] = self::sort_assoc( $value );
			}
		}

		return $arr;
	}

	/**
	 * Produces canonical JSON with stable flags.
	 *
	 * @param array<string, mixed> $data
	 */
	private static function canonical_json( array $data ): string {
		// Sort keys so verify and issue produce identical JSON regardless of array literal order.
		ksort( $data );
		$encoded = json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $encoded ? '{}' : $encoded;
	}

	/**
	 * Returns the per-install HMAC key: wp_salt('auth') concatenated with a
	 * per-install random secret stored in the stonewright_confirmation_secret option.
	 */
	private static function secret(): string {
		$per_install = (string) get_option( self::NONCE_OPTION, '' );
		if ( '' === $per_install ) {
			$per_install = bin2hex( random_bytes( 32 ) );
			add_option( self::NONCE_OPTION, $per_install, '', false );
		}
		return wp_salt( 'auth' ) . $per_install;
	}

	/**
	 * Signs the payload with a single HMAC-SHA256 over the install secret.
	 * Returns raw binary (32 bytes).
	 */
	private static function sign( string $payload_json ): string {
		return hash_hmac( 'sha256', $payload_json, self::secret(), true );
	}

	/**
	 * Encodes bytes as base64url (no padding, URL-safe alphabet).
	 */
	private static function b64url_encode( string $bytes ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}

	/**
	 * Decodes a base64url string to bytes. Returns false on malformed input.
	 *
	 * @return string|false
	 */
	private static function b64url_decode( string $b64 ): string|false {
		$padded = $b64 . str_repeat( '=', ( 4 - strlen( $b64 ) % 4 ) % 4 );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		return base64_decode( strtr( $padded, '-_', '+/' ), true );
	}

	/**
	 * Parses a token string. Returns [payload_json, hmac, payload_data] or null.
	 *
	 * @return array{string, string, array<string, mixed>}|null
	 */
	private static function parse_token( string $token ): ?array {
		if ( ! str_starts_with( $token, 'swc_' ) ) {
			return null;
		}

		$without_prefix = substr( $token, 4 );
		$dot            = strpos( $without_prefix, '.' );
		if ( false === $dot ) {
			return null;
		}

		$payload_b64 = substr( $without_prefix, 0, $dot );
		$sig_b64     = substr( $without_prefix, $dot + 1 );

		$payload_json = self::b64url_decode( $payload_b64 );
		$sig          = self::b64url_decode( $sig_b64 );

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
