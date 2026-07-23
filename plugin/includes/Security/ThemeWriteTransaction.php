<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

/**
 * Atomic theme-file write with backup, readback, optional smoke, and rollback.
 */
final class ThemeWriteTransaction {

	public const DEFAULT_MAX_CHANGED_BYTES = 65536;

	/**
	 * Apply a verified candidate to an allowlisted theme path.
	 *
	 * @param array<string, mixed> $plan Absolute path, relative path, before/after bytes, language, smoke options.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function apply( array $plan ) {
		$absolute = (string) ( $plan['absolute'] ?? '' );
		$relative = (string) ( $plan['relative'] ?? '' );
		$before   = (string) ( $plan['before'] ?? '' );
		$after    = (string) ( $plan['after'] ?? '' );
		$language = strtolower( (string) ( $plan['language'] ?? self::detect_language( $relative ) ) );
		$max_bytes = (int) ( $plan['max_changed_bytes'] ?? self::DEFAULT_MAX_CHANGED_BYTES );

		if ( '' === $absolute || '' === $relative ) {
			return self::err( 'theme_write_path_required', __( 'Theme write path is required.', 'stonewright' ) );
		}

		$before_hash = hash( 'sha256', $before );
		$after_hash  = hash( 'sha256', $after );
		$changed_bytes = abs( strlen( $after ) - strlen( $before ) );

		if ( $changed_bytes > $max_bytes ) {
			return self::err(
				'theme_write_change_budget_exceeded',
				__( 'Theme file change exceeds the default byte budget. Prefer marker-bounded replacements or raise the budget explicitly with operator approval.', 'stonewright' ),
				[
					'changed_bytes'     => $changed_bytes,
					'max_changed_bytes' => $max_bytes,
				]
			);
		}

		// Full-file validation before any target mutation.
		$validation = self::validate_candidate( $after, $language );
		if ( $validation instanceof \WP_Error ) {
			return $validation;
		}

		$backup = self::write_backup( $absolute, $before );
		if ( $backup instanceof \WP_Error ) {
			return $backup;
		}

		$dir = dirname( $absolute );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return self::err( 'theme_file_mkdir_failed', __( 'Could not create theme directory for the target path.', 'stonewright' ) );
		}

		$temp = $absolute . '.sw-tmp-' . bin2hex( random_bytes( 4 ) );
		$written = file_put_contents( $temp, $after, LOCK_EX );
		if ( false === $written ) {
			@unlink( $temp );
			return self::err( 'theme_file_write_failed', __( 'Failed to write temporary theme file.', 'stonewright' ) );
		}
		@chmod( $temp, 0644 );

		if ( ! @rename( $temp, $absolute ) ) {
			// Fallback: copy then unlink temp.
			if ( false === file_put_contents( $absolute, $after, LOCK_EX ) ) {
				@unlink( $temp );
				return self::err( 'theme_file_write_failed', __( 'Failed to atomically replace theme file.', 'stonewright' ) );
			}
			@unlink( $temp );
		}

		// Readback must match candidate hash exactly.
		$readback = is_file( $absolute ) ? (string) file_get_contents( $absolute ) : '';
		$read_hash = hash( 'sha256', $readback );
		if ( ! hash_equals( $after_hash, $read_hash ) ) {
			$rollback = self::rollback( $absolute, $before, $before_hash );
			return self::err(
				'theme_write_readback_mismatch',
				__( 'Theme file readback did not match the candidate. Rollback attempted.', 'stonewright' ),
				[
					'execution_status'    => 'ok',
					'verification_status' => 'failed',
					'rollback_status'     => $rollback['status'],
					'before_sha256'       => $before_hash,
					'after_sha256'        => $after_hash,
					'readback_sha256'     => $read_hash,
					'backup_path'         => $backup,
					'rollback'            => $rollback,
				]
			);
		}

		$smoke = [
			'status'  => 'skipped',
			'reason'  => 'not_requested',
		];
		if ( empty( $plan['skip_smoke'] ) ) {
			$smoke = self::fresh_bootstrap_smoke( isset( $plan['smoke_url'] ) ? (string) $plan['smoke_url'] : null );
			if ( 'failed' === ( $smoke['status'] ?? '' ) ) {
				$rollback = self::rollback( $absolute, $before, $before_hash );
				$second   = self::fresh_bootstrap_smoke( null );
				return self::err(
					'theme_write_smoke_failed',
					__( 'Fresh WordPress bootstrap smoke failed after theme write. Original file restored when possible.', 'stonewright' ),
					[
						'execution_status'    => 'ok',
						'verification_status' => 'failed',
						'rollback_status'     => $rollback['status'],
						'before_sha256'       => $before_hash,
						'after_sha256'        => $after_hash,
						'backup_path'         => $backup,
						'smoke_summary'       => $smoke,
						'post_rollback_smoke' => $second,
						'rollback'            => $rollback,
					]
				);
			}
		}

		return [
			'ok'                  => true,
			'changed'             => $before_hash !== $after_hash,
			'path'                => $relative,
			'before_sha256'       => $before_hash,
			'after_sha256'        => $after_hash,
			'changed_bytes'       => $changed_bytes,
			'backup_path'         => $backup,
			'execution_status'    => 'ok',
			'verification_status' => 'verified',
			'rollback_status'     => 'not_needed',
			'validator_summary'   => [
				'language' => $language,
				'result'   => 'pass',
			],
			'smoke_summary'       => $smoke,
			'effect_verified'     => true,
		];
	}

	/**
	 * Restore original bytes and verify hash.
	 *
	 * @return array{status:string,before_sha256:string,readback_sha256?:string,error?:string}
	 */
	public static function rollback( string $absolute, string $original_bytes, string $original_hash ): array {
		$temp = $absolute . '.sw-rollback-' . bin2hex( random_bytes( 3 ) );
		if ( false === file_put_contents( $temp, $original_bytes, LOCK_EX ) ) {
			return [
				'status'         => 'failed',
				'before_sha256'  => $original_hash,
				'error'          => 'rollback_temp_write_failed',
				'recovery_ref'   => 'uploads/stonewright-theme-backups',
				'severity'      => true,
			];
		}
		if ( ! @rename( $temp, $absolute ) ) {
			if ( false === file_put_contents( $absolute, $original_bytes, LOCK_EX ) ) {
				@unlink( $temp );
				return [
					'status'        => 'failed',
					'before_sha256' => $original_hash,
					'error'         => 'rollback_replace_failed',
					'recovery_ref'  => 'uploads/stonewright-theme-backups',
					'severity'     => true,
				];
			}
			@unlink( $temp );
		}

		$read = is_file( $absolute ) ? (string) file_get_contents( $absolute ) : '';
		$rh   = hash( 'sha256', $read );
		if ( ! hash_equals( $original_hash, $rh ) ) {
			return [
				'status'           => 'failed',
				'before_sha256'    => $original_hash,
				'readback_sha256'  => $rh,
				'error'            => 'rollback_readback_mismatch',
				'recovery_ref'     => 'uploads/stonewright-theme-backups',
				'severity'        => true,
			];
		}

		return [
			'status'          => 'succeeded',
			'before_sha256'   => $original_hash,
			'readback_sha256' => $rh,
		];
	}

	/**
	 * Loopback request against a minimal health URL.
	 *
	 * @return array<string, mixed>
	 */
	public static function fresh_bootstrap_smoke( ?string $url = null ): array {
		$target = $url && '' !== trim( $url ) ? $url : home_url( '/?stonewright_smoke=1' );
		// Prefer REST index as a cheap bootstrap probe.
		if ( null === $url || '' === trim( (string) $url ) ) {
			$target = rest_url( '/' );
		}

		$response = wp_remote_get(
			$target,
			[
				'timeout'     => 8,
				'redirection' => 2,
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
				'headers'     => [
					'X-Stonewright-Smoke' => '1',
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			// Local unit bootstrap without HTTP stack: treat transport-unavailable as skip, not fail.
			$code = $response->get_error_code();
			if ( in_array( $code, [ 'http_request_not_executed', 'http_request_failed' ], true ) && self::is_test_env() ) {
				return [
					'status' => 'skipped',
					'reason' => 'test_env_no_http',
					'url'    => self::redact_url( $target ),
				];
			}
			return [
				'status'  => 'failed',
				'reason'  => 'request_error',
				'error'   => $response->get_error_message(),
				'url'     => self::redact_url( $target ),
			];
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 500 || 0 === $code ) {
			return [
				'status' => 'failed',
				'reason' => 'http_' . $code,
				'url'    => self::redact_url( $target ),
			];
		}

		return [
			'status' => 'passed',
			'http'   => $code,
			'url'    => self::redact_url( $target ),
		];
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function validate_candidate( string $after, string $language ) {
		return match ( $language ) {
			'php' => PhpSyntaxValidator::validate_complete_file( $after ),
			'css' => self::validate_css( $after ),
			'js'  => self::validate_js( $after ),
			default => true,
		};
	}

	/** @return true|\WP_Error */
	private static function validate_css( string $source ) {
		// Balanced braces / no obvious null bytes — production-safe lightweight check.
		if ( str_contains( $source, "\0" ) ) {
			return self::err( 'css_candidate_invalid', __( 'CSS candidate contains null bytes.', 'stonewright' ) );
		}
		$open  = substr_count( $source, '{' );
		$close = substr_count( $source, '}' );
		if ( $open !== $close ) {
			return self::err( 'css_candidate_invalid', __( 'CSS candidate has unbalanced braces.', 'stonewright' ) );
		}
		return true;
	}

	/** @return true|\WP_Error */
	private static function validate_js( string $source ) {
		if ( str_contains( $source, "\0" ) ) {
			return self::err( 'js_candidate_invalid', __( 'JS candidate contains null bytes.', 'stonewright' ) );
		}
		// Cheap paren/brace balance; not a full parser.
		foreach ( [ [ '{', '}' ], [ '(', ')' ], [ '[', ']' ] ] as [ $a, $b ] ) {
			if ( substr_count( $source, $a ) !== substr_count( $source, $b ) ) {
				return self::err( 'js_candidate_invalid', __( 'JS candidate has unbalanced brackets.', 'stonewright' ) );
			}
		}
		return true;
	}

	public static function detect_language( string $relative ): string {
		$lower = strtolower( $relative );
		if ( str_ends_with( $lower, '.php' ) ) {
			return 'php';
		}
		if ( str_ends_with( $lower, '.css' ) ) {
			return 'css';
		}
		if ( str_ends_with( $lower, '.js' ) ) {
			return 'js';
		}
		return 'text';
	}

	/** @return string|null|\WP_Error */
	private static function write_backup( string $absolute, string $before ) {
		if ( '' === $before ) {
			return null;
		}
		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) {
			return self::err( 'theme_file_backup_failed', __( 'Could not resolve uploads directory for theme backup.', 'stonewright' ) );
		}
		$dir = trailingslashit( (string) $upload['basedir'] ) . 'stonewright-theme-backups';
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return self::err( 'theme_file_backup_failed', __( 'Could not create theme backup directory.', 'stonewright' ) );
		}
		$basename = basename( $absolute );
		$target   = $dir . '/' . gmdate( 'Ymd-His' ) . '-' . hash( 'sha256', $absolute ) . '-' . $basename;
		if ( false === file_put_contents( $target, $before ) ) {
			return self::err( 'theme_file_backup_failed', __( 'Could not write theme backup file.', 'stonewright' ) );
		}
		return $target;
	}

	private static function redact_url( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return '[url]';
		}
		$host = (string) ( $parts['host'] ?? '' );
		$path = (string) ( $parts['path'] ?? '/' );
		return $host . $path;
	}

	private static function is_test_env(): bool {
		return defined( 'STONEWRIGHT_PHPUNIT' ) || getenv( 'STONEWRIGHT_PHPUNIT' ) || ( defined( 'WP_TESTS_DOMAIN' ) );
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private static function err( string $code, string $message, array $data = [] ): \WP_Error {
		return new \WP_Error(
			'stonewright_' . $code,
			$message,
			array_merge(
				[
					'status'              => 400,
					'retryable'           => false,
					'execution_status'    => 'ok',
					'verification_status' => 'failed',
				],
				$data
			)
		);
	}
}
