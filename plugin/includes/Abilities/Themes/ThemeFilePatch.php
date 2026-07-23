<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Themes;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Security\CustomCodeGrant;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Security\ThemeWriteTransaction;

/**
 * Patch allowlisted theme files with validation, grant, backup, atomic write, smoke, rollback.
 *
 * Modes: append | insert_after_marker | replace_between_markers | replace_all.
 *
 * @stonewright-status stable
 */
final class ThemeFilePatch extends AbilityKernel {
	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/theme-file-patch';
	}

	public function label(): string {
		return __( 'Theme: patch allowlisted file', 'stonewright' );
	}

	public function description(): string {
		return __( 'Safely patch child-theme CSS/JS/PHP within an allowlist. Requires dry_run first for code assets, full-file validation, operator custom-code grant for apply, atomic write, readback, and bootstrap smoke with automatic rollback. Prefer marker-bounded replacements over unrestricted append.', 'stonewright' );
	}

	public function category(): string {
		return 'themes';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'path', 'mode', 'content' ],
			'properties'           => [
				'path'               => [
					'type'        => 'string',
					'minLength'   => 1,
					'description' => 'Theme-relative path, e.g. style.css.',
				],
				'theme'              => [
					'type'    => 'string',
					'enum'    => [ 'stylesheet', 'template' ],
					'default' => 'stylesheet',
				],
				'mode'               => [
					'type' => 'string',
					'enum' => [ 'append', 'insert_after_marker', 'replace_between_markers', 'replace_all' ],
				],
				'content'            => [
					'type'        => 'string',
					'description' => 'Text to append/insert, or the replacement body between markers.',
				],
				'marker'             => [
					'type'        => 'string',
					'description' => 'Required for insert_after_marker.',
				],
				'start_marker'       => [
					'type'        => 'string',
					'description' => 'Required for replace_between_markers (inclusive start).',
				],
				'end_marker'         => [
					'type'        => 'string',
					'description' => 'Required for replace_between_markers (inclusive end).',
				],
				'create_if_missing'  => [
					'type'    => 'boolean',
					'default' => false,
				],
				'dry_run'            => [
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'Required true first for PHP/CSS/JS apply. Returns candidate hashes, risk, and approval requirement without writing.',
				],
				'custom_code_grant'  => [
					'type'        => 'string',
					'description' => 'Single-use operator grant bound to candidate after_sha256. Required to apply PHP/CSS/JS writes.',
				],
				'smoke_url'          => [
					'type'        => 'string',
					'description' => 'Optional public URL to smoke after theme changes.',
				],
				'max_changed_bytes'  => [
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 262144,
					'description' => 'Change-size budget (default 65536).',
				],
				'confirmation_token' => [
					'type'        => 'string',
					'description' => 'Required in production-safe mode.',
				],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => true,
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		$path = (string) ( $args['path'] ?? '' );
		// PHP patches must not be authorized by edit_css alone (would escalate to RCE).
		if ( ThemeFilePaths::is_php_path( $path ) ) {
			return Permissions::manage_options() || Permissions::edit_theme_options();
		}
		return Permissions::edit_theme_options() || Permissions::edit_css();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $runtime_args ): array|\WP_Error {
				$verify = $runtime_args;
				unset( $verify['confirmation_token'], $verify['custom_code_grant'] );
				$token_error = $this->confirmation_token_error( $runtime_args, $verify );
				if ( $token_error instanceof \WP_Error ) {
					return $token_error;
				}

				$resolved = ThemeFilePaths::resolve(
					(string) ( $runtime_args['path'] ?? '' ),
					(string) ( $runtime_args['theme'] ?? 'stylesheet' )
				);
				if ( $resolved instanceof \WP_Error ) {
					return $resolved;
				}

				$mode     = (string) ( $runtime_args['mode'] ?? '' );
				$content  = (string) ( $runtime_args['content'] ?? '' );
				$dry_run  = (bool) ( $runtime_args['dry_run'] ?? false );
				$create   = (bool) ( $runtime_args['create_if_missing'] ?? false );
				$exists   = is_file( $resolved['absolute'] );
				$language = ThemeWriteTransaction::detect_language( $resolved['relative'] );
				$is_code  = in_array( $language, [ 'php', 'css', 'js' ], true );

				if ( ! $exists && ! $create ) {
					return $this->error(
						'theme_file_not_found',
						__( 'Theme file not found. Set create_if_missing:true to create allowlisted files.', 'stonewright' ),
						[ 'status' => 404, 'path' => $resolved['relative'] ]
					);
				}

				// Prefer marker-bounded modes for PHP.
				if ( 'php' === $language && 'append' === $mode && ! $dry_run ) {
					// Still allowed with grant, but risk elevated.
				}

				$before = $exists ? (string) file_get_contents( $resolved['absolute'] ) : '';
				$after  = self::apply_patch( $before, $mode, $content, $runtime_args );
				if ( $after instanceof \WP_Error ) {
					return $after;
				}

				$before_hash   = hash( 'sha256', $before );
				$after_hash    = hash( 'sha256', $after );
				$changed       = $before_hash !== $after_hash;
				$changed_bytes = abs( strlen( $after ) - strlen( $before ) );
				$max_bytes     = (int) ( $runtime_args['max_changed_bytes'] ?? ThemeWriteTransaction::DEFAULT_MAX_CHANGED_BYTES );

				// Validate complete candidate before any write (including dry_run reporting).
				$validation = ThemeWriteTransaction::validate_candidate( $after, $language );
				$validator_summary = [
					'language' => $language,
					'result'   => $validation instanceof \WP_Error ? 'fail' : 'pass',
				];
				if ( $validation instanceof \WP_Error ) {
					$data = (array) $validation->get_error_data();
					return new \WP_Error(
						$validation->get_error_code(),
						$validation->get_error_message(),
						array_merge(
							$data,
							[
								'path'                => $resolved['relative'],
								'before_sha256'       => $before_hash,
								'after_sha256'        => $after_hash,
								'execution_status'    => 'ok',
								'verification_status' => 'failed',
								'rollback_status'     => 'not_needed',
								'effect_verified'     => false,
								'validator_summary'   => $validator_summary,
							]
						)
					);
				}

				$diff_preview = self::bounded_diff_preview( $before, $after );
				$risk         = self::risk_class( $language, $mode, $changed_bytes );

				if ( $dry_run || ! $changed ) {
					return [
						'ok'                  => true,
						'dry_run'             => true,
						'changed'             => $changed,
						'path'                => $resolved['relative'],
						'mode'                => $mode,
						'language'            => $language,
						'before_bytes'        => strlen( $before ),
						'after_bytes'         => strlen( $after ),
						'changed_bytes'       => $changed_bytes,
						'before_sha256'       => $before_hash,
						'after_sha256'        => $after_hash,
						'backup_path'         => null,
						'preview'             => self::preview_tail( $after ),
						'diff_preview'        => $diff_preview,
						'validator_summary'   => $validator_summary,
						'risk_class'          => $risk,
						'approval_required'   => $is_code && $changed,
						'approval_url'        => admin_url( 'admin.php?page=stonewright-custom-code-approval' ),
						'execution_status'    => 'ok',
						'verification_status' => 'dry_run',
						'rollback_status'     => 'not_needed',
						'effect_verified'     => true, // dry_run is intentionally non-mutating.
						'operation_class'     => 'theme_file_write',
						'resource_type'       => 'theme_file',
						'resource_ref'        => $resolved['relative'],
					];
				}

				// Applying code assets requires a custom-code grant bound to candidate hash.
				if ( $is_code ) {
					$grant = (string) ( $runtime_args['custom_code_grant'] ?? '' );
					if ( '' === $grant ) {
						$proposal = CustomCodeGrant::missing_grant_proposal(
							[
								'path'          => $resolved['relative'],
								'language'      => $language,
								'after_sha256'  => $after_hash,
								'before_sha256' => $before_hash,
								'changed_bytes' => $changed_bytes,
								'diff_preview'  => $diff_preview,
								'risk_class'    => $risk,
								'test_plan'     => [
									'Validate complete candidate (already done in dry_run).',
									'Issue custom-code grant for after_sha256.',
									'Apply with same content + grant.',
									'Confirm smoke and Memory/Audit effect fields.',
								],
								'rollback_plan' => 'Automatic rollback on smoke/readback failure; backups under uploads/stonewright-theme-backups.',
								'execution_status'    => 'blocked',
								'verification_status' => 'blocked',
								'rollback_status'     => 'not_needed',
								'effect_verified'     => false,
								'operation_class'     => 'custom_code',
								'resource_type'       => 'theme_file',
								'resource_ref'        => $resolved['relative'],
							]
						);
						return new \WP_Error(
							'stonewright_custom_code_grant_required',
							(string) $proposal['message'],
							array_merge( [ 'status' => 400, 'retryable' => false ], $proposal )
						);
					}

					$grant_ok = CustomCodeGrant::verify_and_consume(
						$grant,
						$resolved['relative'],
						$after_hash,
						$language,
						$changed_bytes
					);
					if ( $grant_ok instanceof \WP_Error ) {
						return $grant_ok;
					}
				}

				$applied = ThemeWriteTransaction::apply(
					[
						'absolute'           => $resolved['absolute'],
						'relative'           => $resolved['relative'],
						'before'             => $before,
						'after'              => $after,
						'language'           => $language,
						'smoke_url'          => isset( $runtime_args['smoke_url'] ) ? (string) $runtime_args['smoke_url'] : null,
						'max_changed_bytes'  => $max_bytes,
					]
				);
				if ( $applied instanceof \WP_Error ) {
					return $applied;
				}

				return array_merge(
					$applied,
					[
						'dry_run'       => false,
						'mode'          => $mode,
						'language'      => $language,
						'before_bytes'  => strlen( $before ),
						'after_bytes'   => strlen( $after ),
						'preview'       => self::preview_tail( $after ),
						'operation_class' => 'theme_file_write',
						'resource_type'   => 'theme_file',
						'resource_ref'    => $resolved['relative'],
					]
				);
			}
		);
	}

	/**
	 * @param array<string, mixed> $args
	 * @return string|\WP_Error
	 */
	private static function apply_patch( string $before, string $mode, string $content, array $args ) {
		return match ( $mode ) {
			'append' => self::ends_with_newline( $before ) || '' === $before
				? $before . $content
				: $before . "\n" . $content,
			'insert_after_marker' => self::insert_after_marker( $before, (string) ( $args['marker'] ?? '' ), $content ),
			'replace_between_markers' => self::replace_between(
				$before,
				(string) ( $args['start_marker'] ?? '' ),
				(string) ( $args['end_marker'] ?? '' ),
				$content
			),
			'replace_all' => $content,
			default => new \WP_Error(
				'stonewright_theme_file_mode_invalid',
				__( 'Unsupported patch mode.', 'stonewright' ),
				[ 'status' => 400, 'mode' => $mode ]
			),
		};
	}

	/** @return string|\WP_Error */
	private static function insert_after_marker( string $before, string $marker, string $content ) {
		if ( '' === $marker ) {
			return new \WP_Error(
				'stonewright_theme_file_marker_required',
				__( 'marker is required for insert_after_marker.', 'stonewright' ),
				[ 'status' => 400 ]
			);
		}
		$pos = strpos( $before, $marker );
		if ( false === $pos ) {
			return new \WP_Error(
				'stonewright_theme_file_marker_missing',
				__( 'Marker not found in theme file.', 'stonewright' ),
				[
					'status'     => 400,
					'marker'     => $marker,
					'error_code' => 'marker_missing',
				]
			);
		}
		$insert_at = $pos + strlen( $marker );
		return substr( $before, 0, $insert_at ) . "\n" . $content . substr( $before, $insert_at );
	}

	/** @return string|\WP_Error */
	private static function replace_between( string $before, string $start, string $end, string $content ) {
		if ( '' === $start || '' === $end ) {
			return new \WP_Error(
				'stonewright_theme_file_markers_required',
				__( 'start_marker and end_marker are required for replace_between_markers.', 'stonewright' ),
				[ 'status' => 400 ]
			);
		}
		$start_pos = strpos( $before, $start );
		if ( false === $start_pos ) {
			return new \WP_Error(
				'stonewright_theme_file_marker_missing',
				__( 'start_marker not found in theme file.', 'stonewright' ),
				[ 'status' => 400, 'marker' => $start, 'error_code' => 'start_marker_missing' ]
			);
		}
		$end_pos = strpos( $before, $end, $start_pos + strlen( $start ) );
		if ( false === $end_pos ) {
			return new \WP_Error(
				'stonewright_theme_file_marker_missing',
				__( 'end_marker not found after start_marker.', 'stonewright' ),
				[ 'status' => 400, 'marker' => $end, 'error_code' => 'end_marker_missing' ]
			);
		}
		$end_pos += strlen( $end );
		return substr( $before, 0, $start_pos ) . $start . "\n" . $content . "\n" . $end . substr( $before, $end_pos );
	}

	private static function ends_with_newline( string $text ): bool {
		return '' !== $text && ( "\n" === substr( $text, -1 ) || "\r" === substr( $text, -1 ) );
	}

	private static function preview_tail( string $text, int $max = 400 ): string {
		if ( strlen( $text ) <= $max ) {
			return $text;
		}
		return '…' . substr( $text, -$max );
	}

	/**
	 * Bounded unified-diff style preview (no full source dump).
	 *
	 * @return array{changed_lines:int,preview:string}
	 */
	private static function bounded_diff_preview( string $before, string $after ): array {
		$b         = explode( "\n", $before );
		$a         = explode( "\n", $after );
		$max       = max( count( $b ), count( $a ) );
		$lines     = [];
		$line_count = 0;
		$changed   = 0;
		for ( $i = 0; $i < $max && $line_count < 40; $i++ ) {
			$bl = $b[ $i ] ?? null;
			$al = $a[ $i ] ?? null;
			if ( $bl === $al ) {
				continue;
			}
			++$changed;
			if ( null !== $bl ) {
				$lines[] = '- ' . mb_substr( (string) $bl, 0, 120 );
				++$line_count;
			}
			if ( null !== $al ) {
				$lines[] = '+ ' . mb_substr( (string) $al, 0, 120 );
				++$line_count;
			}
		}
		return [
			'changed_lines' => $changed,
			'preview'       => implode( "\n", $lines ),
		];
	}

	private static function risk_class( string $language, string $mode, int $changed_bytes ): string {
		if ( 'php' === $language ) {
			return 'high_risk_active_theme_php';
		}
		if ( $changed_bytes > 16384 || 'replace_all' === $mode ) {
			return 'elevated';
		}
		return 'standard_custom_code';
	}

	/** @return array<int, string> */
	protected function audit_redacted_keys(): array {
		return array_merge( parent::audit_redacted_keys(), [ 'content', 'custom_code_grant' ] );
	}

	protected function audit_metadata( array $args, array|\WP_Error $result, int $elapsed_ms ): array {
		$data = $result instanceof \WP_Error ? (array) $result->get_error_data() : $result;
		return [
			'duration_ms'         => $elapsed_ms,
			'operation_class'     => (string) ( $data['operation_class'] ?? 'theme_file_write' ),
			'resource_type'       => 'theme_file',
			'resource_ref'        => (string) ( $data['path'] ?? $data['resource_ref'] ?? ( $args['path'] ?? '' ) ),
			'execution_status'    => (string) ( $data['execution_status'] ?? ( $result instanceof \WP_Error ? 'error' : 'ok' ) ),
			'verification_status' => (string) ( $data['verification_status'] ?? ( $result instanceof \WP_Error ? 'failed' : 'verified' ) ),
			'rollback_status'     => (string) ( $data['rollback_status'] ?? 'not_needed' ),
			'before_sha256'       => (string) ( $data['before_sha256'] ?? '' ),
			'after_sha256'        => (string) ( $data['after_sha256'] ?? '' ),
			'changed_bytes'       => (int) ( $data['changed_bytes'] ?? 0 ),
			'effect_verified'     => (bool) ( $data['effect_verified'] ?? false ),
			'dry_run'             => (bool) ( $args['dry_run'] ?? false ),
		];
	}
}
