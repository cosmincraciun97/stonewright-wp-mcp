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
	public const PROPOSAL_PREFIX  = 'sw_cc_proposal_';
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
		$operator_user_id = get_current_user_id();
		if ( $operator_user_id <= 0 || ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'stonewright_custom_code_grant_forbidden',
				__( 'Only authenticated administrators may issue custom-code grants.', 'stonewright' ),
				[ 'status' => 403 ]
			);
		}
		$user_id = max( 1, (int) ( $spec['bound_user_id'] ?? $operator_user_id ) );

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
			'proposal_id'       => (string) ( $spec['proposal_id'] ?? '' ),
			'operator_user_id'  => $operator_user_id,
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
	 * Persist a bounded, source-free dry-run proposal for human approval.
	 *
	 * @param array<string, mixed> $spec
	 * @return array{proposal_id:string,approval_url:string,expires_at:string}|\WP_Error
	 */
	public static function stage_proposal( array $spec ) {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 || ! current_user_can( 'manage_options' ) ) {
			return self::fail( 'stonewright_custom_code_grant_forbidden', __( 'Only authenticated administrators may stage custom-code proposals.', 'stonewright' ) );
		}

		$language = strtolower( (string) ( $spec['language'] ?? '' ) );
		$after    = strtolower( (string) ( $spec['after_sha256'] ?? '' ) );
		$path     = self::normalise_path( (string) ( $spec['path'] ?? '' ) );
		if (
			'' === $path
			|| ! in_array( $language, [ 'php', 'css', 'js', 'html' ], true )
			|| ! preg_match( '/^[a-f0-9]{64}$/', $after )
		) {
			return self::fail( 'stonewright_custom_code_proposal_invalid', __( 'Custom-code proposal requires a path, supported language, and complete candidate hash.', 'stonewright' ) );
		}

		$proposal_id = wp_generate_uuid4();
		$expires     = time() + self::DEFAULT_TTL;
		$payload     = [
			'proposal_id'       => $proposal_id,
			'requested_by'      => $user_id,
			'site_fingerprint'  => self::site_fingerprint(),
			'path'              => $path,
			'language'          => $language,
			'before_sha256'     => strtolower( (string) ( $spec['before_sha256'] ?? '' ) ),
			'after_sha256'      => $after,
			'changed_bytes'     => max( 0, (int) ( $spec['changed_bytes'] ?? 0 ) ),
			'max_changed_bytes' => max( 1, (int) ( $spec['max_changed_bytes'] ?? 65536 ) ),
			'risk_class'        => sanitize_key( (string) ( $spec['risk_class'] ?? 'custom_code' ) ),
			'native_gap'        => self::bounded_native_gap( $spec['native_gap'] ?? [] ),
			'diff_preview'      => self::bounded_diff( $spec['diff_preview'] ?? [] ),
			'test_plan'         => self::bounded_string_list( $spec['test_plan'] ?? [] ),
			'rollback_plan'     => mb_substr( sanitize_textarea_field( (string) ( $spec['rollback_plan'] ?? '' ) ), 0, 500 ),
			'expires_at'        => $expires,
		];
		set_transient( self::PROPOSAL_PREFIX . $proposal_id, $payload, self::DEFAULT_TTL );

		return [
			'proposal_id' => $proposal_id,
			'approval_url'=> add_query_arg(
				[
					'page'        => 'stonewright-custom-code-approval',
					'proposal_id' => $proposal_id,
				],
				admin_url( 'admin.php' )
			),
			'expires_at'  => gmdate( 'c', $expires ),
		];
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function proposal( string $proposal_id ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return self::fail( 'stonewright_custom_code_grant_forbidden', __( 'Only authenticated administrators may view custom-code proposals.', 'stonewright' ) );
		}
		$stored = get_transient( self::PROPOSAL_PREFIX . $proposal_id );
		if ( ! is_array( $stored ) || (int) ( $stored['expires_at'] ?? 0 ) < time() ) {
			return self::fail( 'stonewright_custom_code_proposal_missing', __( 'Custom-code proposal was not found or expired. Run dry_run again.', 'stonewright' ) );
		}
		if ( (string) ( $stored['site_fingerprint'] ?? '' ) !== self::site_fingerprint() ) {
			return self::fail( 'stonewright_custom_code_grant_site_mismatch', __( 'Custom-code proposal belongs to a different site.', 'stonewright' ) );
		}
		return $stored;
	}

	/**
	 * Human approval boundary. The grant is bound to the MCP user who staged
	 * the dry run, not silently widened to the browser operator.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function approve_proposal( string $proposal_id ) {
		$proposal = self::proposal( $proposal_id );
		if ( $proposal instanceof \WP_Error ) {
			return $proposal;
		}

		$issued = self::issue(
			[
				'path'              => $proposal['path'],
				'after_sha256'      => $proposal['after_sha256'],
				'language'          => $proposal['language'],
				'max_changed_bytes' => $proposal['max_changed_bytes'],
				'high_risk'         => str_contains( (string) $proposal['risk_class'], 'high_risk' ),
				'proposal_id'       => $proposal_id,
				'bound_user_id'     => (int) $proposal['requested_by'],
			]
		);
		if ( is_array( $issued ) ) {
			delete_transient( self::PROPOSAL_PREFIX . $proposal_id );
		}
		return $issued;
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
		int $changed_bytes = 0,
		bool $requires_high_risk = false
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
		if ( $requires_high_risk && empty( $stored['high_risk'] ) ) {
			return self::fail( 'stonewright_custom_code_grant_risk_mismatch', __( 'This operation requires a high-risk custom-code grant.', 'stonewright' ) );
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
	 * @return array<string, mixed>
	 */
	private static function bounded_native_gap( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}
		return [
			'reason'          => mb_substr( sanitize_textarea_field( (string) ( $value['reason'] ?? '' ) ), 0, 500 ),
			'methods_tried'   => self::bounded_string_list( $value['methods_tried'] ?? [] ),
			'evidence_ref'    => mb_substr( sanitize_text_field( (string) ( $value['evidence_ref'] ?? '' ) ), 0, 200 ),
		];
	}

	/**
	 * @return array{changed_lines:int,preview:string}
	 */
	private static function bounded_diff( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return [ 'changed_lines' => 0, 'preview' => '' ];
		}
		return [
			'changed_lines' => max( 0, (int) ( $value['changed_lines'] ?? 0 ) ),
			'preview'       => mb_substr( sanitize_textarea_field( (string) ( $value['preview'] ?? '' ) ), 0, 5000 ),
		];
	}

	/**
	 * @return list<string>
	 */
	private static function bounded_string_list( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}
		$out = [];
		foreach ( array_slice( $value, 0, 10 ) as $item ) {
			if ( is_scalar( $item ) ) {
				$out[] = mb_substr( sanitize_text_field( (string) $item ), 0, 300 );
			}
		}
		return $out;
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
			array_merge(
				[
					'status'              => 400,
					'retryable'           => false,
					'execution_status'    => 'blocked',
					'verification_status' => 'blocked',
				],
				$data
			)
		);
	}
}
