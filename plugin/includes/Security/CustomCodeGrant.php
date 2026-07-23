<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

/**
 * Short-lived, single-use operator grant for custom PHP/CSS/JS/HTML writes.
 *
 * Grants are minted only from authenticated wp-admin (or a manage_options REST
 * action). MCP clients may request the approval requirement and URL, but must
 * not mint grants themselves.
 */
final class CustomCodeGrant {

	public const TRANSIENT_PREFIX = 'sw_cc_grant_';
	public const DEFAULT_TTL      = 900; // 15 minutes
	public const MAX_TTL          = 3600;
	public const MIN_TTL          = 60;

	/**
	 * Issue a grant. Caller must already be authenticated with manage_options.
	 *
	 * @param array<string, mixed> $spec Path, after_sha256, language, and optional budgets/ttl.
	 * @return array{token:string,expires_at:string,grant_id:string}|\WP_Error
	 */
	public static function issue( array $spec ) {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 || ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'stonewright_custom_code_grant_forbidden',
				__( 'Only authenticated administrators may issue custom-code grants.', 'stonewright' ),
				[ 'status' => 403 ]
			);
		}

		$language = strtolower( (string) ( $spec['language'] ?? '' ) );
		if ( ! in_array( $language, [ 'php', 'css', 'js', 'html' ], true ) ) {
			return new \WP_Error(
				'stonewright_custom_code_grant_invalid',
				__( 'language must be one of php, css, js, html.', 'stonewright' ),
				[ 'status' => 400 ]
			);
		}

		$after_sha = strtolower( (string) ( $spec['after_sha256'] ?? '' ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $after_sha ) ) {
			return new \WP_Error(
				'stonewright_custom_code_grant_invalid',
				__( 'after_sha256 must be a 64-char hex sha256 of the complete candidate file.', 'stonewright' ),
				[ 'status' => 400 ]
			);
		}

		$path = self::normalise_path( (string) ( $spec['path'] ?? '' ) );
		$ttl  = (int) ( $spec['ttl'] ?? self::DEFAULT_TTL );
		$ttl  = max( self::MIN_TTL, min( self::MAX_TTL, $ttl ) );

		$grant_id = wp_generate_uuid4();
		$payload  = [
			'grant_id'          => $grant_id,
			'user_id'           => $user_id,
			'site_fingerprint'  => (string) ( $spec['site_fingerprint'] ?? self::site_fingerprint() ),
			'path'              => $path,
			'operation_class'   => (string) ( $spec['operation_class'] ?? 'theme_file_write' ),
			'after_sha256'      => $after_sha,
			'language'          => $language,
			'max_changed_bytes' => (int) ( $spec['max_changed_bytes'] ?? 65536 ),
			'task_id'           => (string) ( $spec['task_id'] ?? '' ),
			'change_set_id'     => (string) ( $spec['change_set_id'] ?? '' ),
			'high_risk'         => ! empty( $spec['high_risk'] ),
			'issued_at'         => time(),
			'expires_at'        => time() + $ttl,
			'used'              => false,
		];

		$token = self::sign( $grant_id, $payload );
		set_transient( self::TRANSIENT_PREFIX . $grant_id, $payload, $ttl );

		return [
			'token'      => $token,
			'grant_id'   => $grant_id,
			'expires_at' => gmdate( 'c', $payload['expires_at'] ),
			'path'       => $path,
			'language'   => $language,
			'after_sha256' => $after_sha,
		];
	}

	/**
	 * Verify and consume a grant for a specific candidate write.
	 *
	 * @return true|\WP_Error
	 */
	public static function verify_and_consume(
		string $token,
		string $path,
		string $after_sha256,
		string $language,
		int $changed_bytes = 0
	) {
		$parsed = self::parse_token( $token );
		if ( $parsed instanceof \WP_Error ) {
			return $parsed;
		}

		$grant_id = $parsed['grant_id'];
		$stored   = get_transient( self::TRANSIENT_PREFIX . $grant_id );
		if ( ! is_array( $stored ) ) {
			return self::fail( 'stonewright_custom_code_grant_missing', __( 'Custom-code grant not found or expired.', 'stonewright' ) );
		}

		if ( ! empty( $stored['used'] ) ) {
			return self::fail( 'stonewright_custom_code_grant_reused', __( 'Custom-code grant was already used.', 'stonewright' ) );
		}

		if ( (int) ( $stored['expires_at'] ?? 0 ) < time() ) {
			delete_transient( self::TRANSIENT_PREFIX . $grant_id );
			return self::fail( 'stonewright_custom_code_grant_expired', __( 'Custom-code grant expired.', 'stonewright' ) );
		}

		if ( (int) ( $stored['user_id'] ?? 0 ) !== get_current_user_id() ) {
			return self::fail( 'stonewright_custom_code_grant_user_mismatch', __( 'Custom-code grant belongs to a different user.', 'stonewright' ) );
		}

		if ( (string) ( $stored['site_fingerprint'] ?? '' ) !== self::site_fingerprint() ) {
			return self::fail( 'stonewright_custom_code_grant_site_mismatch', __( 'Custom-code grant is bound to a different site.', 'stonewright' ) );
		}

		$grant_path = self::normalise_path( (string) ( $stored['path'] ?? '' ) );
		$want_path  = self::normalise_path( $path );
		if ( '' !== $grant_path && $grant_path !== $want_path ) {
			return self::fail( 'stonewright_custom_code_grant_path_mismatch', __( 'Custom-code grant path does not match the write target.', 'stonewright' ), [
				'grant_path' => $grant_path,
				'write_path' => $want_path,
			] );
		}

		$grant_hash = strtolower( (string) ( $stored['after_sha256'] ?? '' ) );
		$want_hash  = strtolower( $after_sha256 );
		if ( $grant_hash !== $want_hash ) {
			return self::fail( 'stonewright_custom_code_grant_hash_mismatch', __( 'Custom-code grant candidate hash does not match the write candidate.', 'stonewright' ) );
		}

		$grant_lang = strtolower( (string) ( $stored['language'] ?? '' ) );
		if ( $grant_lang !== strtolower( $language ) ) {
			return self::fail( 'stonewright_custom_code_grant_language_mismatch', __( 'Custom-code grant language does not match.', 'stonewright' ) );
		}

		$max_bytes = (int) ( $stored['max_changed_bytes'] ?? 65536 );
		if ( $changed_bytes > $max_bytes ) {
			return self::fail( 'stonewright_custom_code_grant_size_exceeded', __( 'Changed bytes exceed the grant budget.', 'stonewright' ), [
				'changed_bytes' => $changed_bytes,
				'max_changed_bytes' => $max_bytes,
			] );
		}

		// Consume single-use.
		$stored['used'] = true;
		set_transient( self::TRANSIENT_PREFIX . $grant_id, $stored, max( 1, (int) $stored['expires_at'] - time() ) );

		return true;
	}

	/**
	 * Proposal payload when a grant is required but missing.
	 *
	 * @param array<string, mixed> $extra
	 * @return array<string, mixed>
	 */
	public static function missing_grant_proposal( array $extra = [] ): array {
		$admin = admin_url( 'admin.php?page=stonewright-custom-code-approval' );
		return array_merge(
			[
				'ok'                   => false,
				'applied'              => false,
				'approval_required'    => true,
				'operation_class'      => 'custom_code',
				'approval_url'         => $admin,
				'error_code'           => 'custom_code_grant_required',
				'message'              => __( 'Custom PHP/CSS/JS/HTML writes require an operator custom-code grant after a proven native gap. Complete dry_run, open the approval URL in wp-admin, then retry with custom_code_grant.', 'stonewright' ),
				'recommended_next'     => 'dry_run then operator grant',
			],
			$extra
		);
	}

	public static function site_fingerprint(): string {
		$url    = home_url( '/' );
		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
		return hash( 'sha256', $url . '|' . (string) $blog_id );
	}

	private static function normalise_path( string $path ): string {
		$path = str_replace( '\\', '/', trim( $path ) );
		$path = ltrim( $path, '/' );
		return $path;
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private static function sign( string $grant_id, array $payload ): string {
		$body = $grant_id . '.' . base64_encode( wp_json_encode( [
			'g' => $grant_id,
			'u' => (int) $payload['user_id'],
			'h' => (string) $payload['after_sha256'],
			'e' => (int) $payload['expires_at'],
		] ) );
		$sig = hash_hmac( 'sha256', $body, wp_salt( 'auth' ) );
		return $body . '.' . $sig;
	}

	/**
	 * @return array{grant_id:string}|\WP_Error
	 */
	private static function parse_token( string $token ) {
		$parts = explode( '.', $token );
		if ( count( $parts ) < 3 ) {
			return self::fail( 'stonewright_custom_code_grant_invalid', __( 'Malformed custom-code grant token.', 'stonewright' ) );
		}
		$grant_id = $parts[0];
		$sig      = $parts[ count( $parts ) - 1 ];
		$body     = implode( '.', array_slice( $parts, 0, -1 ) );
		$expect   = hash_hmac( 'sha256', $body, wp_salt( 'auth' ) );
		if ( ! hash_equals( $expect, $sig ) ) {
			return self::fail( 'stonewright_custom_code_grant_invalid', __( 'Custom-code grant signature invalid.', 'stonewright' ) );
		}
		if ( ! preg_match( '/^[a-f0-9-]{36}$/i', $grant_id ) ) {
			return self::fail( 'stonewright_custom_code_grant_invalid', __( 'Custom-code grant id invalid.', 'stonewright' ) );
		}
		return [ 'grant_id' => $grant_id ];
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private static function fail( string $code, string $message, array $data = [] ): \WP_Error {
		return new \WP_Error(
			$code,
			$message,
			array_merge( [ 'status' => 400, 'retryable' => false ], $data )
		);
	}
}
