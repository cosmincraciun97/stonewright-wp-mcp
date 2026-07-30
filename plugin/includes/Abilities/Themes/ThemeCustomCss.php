<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Themes;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\CodePayloadCanonicalizer;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Core\MethodRouter;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\CustomCodeGrant;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Security\ThemeWriteTransaction;

/**
 * Gets or updates theme custom CSS with backup before write.
 *
 * @stonewright-status stable
 */
final class ThemeCustomCss extends AbilityKernel {
	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/theme-custom-css';
	}

	public function label(): string {
		return __( 'Theme: Custom CSS', 'stonewright' );
	}

	public function description(): string {
		return __( 'Gets theme custom CSS or updates it after a native-gap dry-run, human-issued one-time custom-code grant, backup, and exact readback.', 'stonewright' );
	}

	public function category(): string {
		return 'themes';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'action'             => [ 'type' => 'string', 'enum' => [ 'get', 'update' ] ],
				'css'                => [ 'type' => 'string' ],
				'dry_run'            => [
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'Required true first for updates. Returns the exact candidate summary and human approval URL without writing.',
				],
				'custom_code_grant'  => [
					'type'        => 'string',
					'description' => 'Single-use human-issued grant bound to the exact Customizer CSS candidate hash.',
				],
				'native_gap'         => [
					'type'                 => 'object',
					'additionalProperties' => false,
					'description'          => 'Required for update dry-run. Proof that supported native controls cannot satisfy the requested effect.',
					'required'             => [ 'reason', 'methods_tried' ],
					'properties'           => [
						'reason'        => [ 'type' => 'string', 'minLength' => 1 ],
						'methods_tried' => [
							'type'     => 'array',
							'minItems' => 1,
							'items'    => [
								'type' => 'string',
								'enum' => [ 'typed_api', 'editor_command_bus', 'admin_form', 'browser_ui' ],
							],
						],
						'evidence_ref'  => [ 'type' => 'string' ],
					],
				],
				'max_changed_bytes'  => [
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 262144,
					'description' => 'Maximum approved change size in bytes. Defaults to 65536.',
				],
				'confirmation_token' => [ 'type' => 'string' ],
				'decode_escaped_layout' => [
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'Opt in to conservative decoding of escaped layout outside CSS strings and comments.',
				],
			],
			'required'             => [ 'action' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => true,
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_css();
	}

	public function execute( array $args ): array|\WP_Error {
		$args = CodePayloadCanonicalizer::canonicalize( $this->name(), $args );
		if ( $args instanceof \WP_Error ) {
			return $args;
		}

		return $this->audit(
			$args,
			function ( array $args ) {
				if ( 'get' === (string) $args['action'] ) {
					return [
						'css'        => (string) wp_get_custom_css(),
						'stylesheet' => get_stylesheet(),
					];
				}

				$verify = $args;
				unset( $verify['confirmation_token'], $verify['custom_code_grant'] );
				$token_error = $this->confirmation_token_error( $args, $verify );
				if ( null !== $token_error ) {
					return $token_error;
				}

				$before        = (string) wp_get_custom_css();
				$after         = (string) ( $args['css'] ?? '' );
				$stylesheet    = sanitize_key( (string) get_stylesheet() );
				$path          = 'customizer/custom-css/' . ( '' !== $stylesheet ? $stylesheet : 'active-theme' ) . '.css';
				$before_hash   = hash( 'sha256', $before );
				$after_hash    = hash( 'sha256', $after );
				$changed       = ! hash_equals( $before_hash, $after_hash );
				$changed_bytes = ThemeWriteTransaction::changed_bytes( $before, $after );
				$max_bytes     = (int) ( $args['max_changed_bytes'] ?? ThemeWriteTransaction::DEFAULT_MAX_CHANGED_BYTES );
				$dry_run      = (bool) ( $args['dry_run'] ?? false );
				$diff_preview = self::bounded_diff_preview( $before, $after );

				$validation = ThemeWriteTransaction::validate_candidate( $after, 'css' );
				if ( $validation instanceof \WP_Error ) {
					return $validation;
				}

				if ( $dry_run || ! $changed ) {
					$native_gap = null;
					$proposal   = null;
					if ( $changed ) {
						$native_gap = MethodRouter::validate_native_gap( $args['native_gap'] ?? null );
						if ( $native_gap instanceof \WP_Error ) {
							return $native_gap;
						}
						$proposal = CustomCodeGrant::stage_proposal(
							[
								'path'              => $path,
								'language'          => 'css',
								'before_sha256'     => $before_hash,
								'after_sha256'      => $after_hash,
								'changed_bytes'     => $changed_bytes,
								'max_changed_bytes' => $max_bytes,
								'risk_class'        => 'customizer_css',
								'native_gap'        => $native_gap,
								'diff_preview'      => $diff_preview,
								'test_plan'         => [
									'Validate the complete CSS candidate.',
									'Apply the exact approved hash.',
									'Verify exact Customizer CSS readback.',
								],
								'rollback_plan'     => 'Restore the backed-up Customizer CSS post if exact readback fails.',
							]
						);
						if ( $proposal instanceof \WP_Error ) {
							return $proposal;
						}
					}

					$approval_required = $changed;
					return [
						'ok'                  => true,
						'dry_run'             => true,
						'changed'             => $changed,
						'path'                => $path,
						'stylesheet'          => get_stylesheet(),
						'before_bytes'        => strlen( $before ),
						'after_bytes'         => strlen( $after ),
						'changed_bytes'       => $changed_bytes,
						'before_sha256'       => $before_hash,
						'after_sha256'        => $after_hash,
						'diff_preview'        => $diff_preview,
						'change_summary'      => self::change_summary( $path, $before, $after, $changed_bytes ),
						'approval_required'   => $approval_required,
						'approval_url'        => is_array( $proposal ) ? (string) $proposal['approval_url'] : '',
						'proposal_id'         => is_array( $proposal ) ? (string) $proposal['proposal_id'] : '',
						'proposal_expires_at' => is_array( $proposal ) ? (string) $proposal['expires_at'] : '',
						'agent_must_stop'     => $approval_required,
						'operator_action'     => $approval_required
							? 'Human reviews the proposal in wp-admin, issues the one-time grant, and sends the token back.'
							: 'No approval needed because the candidate is unchanged.',
						'native_gap'          => $native_gap,
						'execution_status'    => 'ok',
						'verification_status' => 'dry_run',
						'rollback_status'     => 'not_needed',
						'effect_verified'     => true,
						'operation_class'     => 'custom_code',
						'resource_type'       => 'customizer_css',
						'resource_ref'        => $path,
					];
				}

				$grant = (string) ( $args['custom_code_grant'] ?? '' );
				if ( '' === $grant ) {
					$proposal = CustomCodeGrant::missing_grant_proposal(
						[
							'path'                => $path,
							'language'            => 'css',
							'before_bytes'        => strlen( $before ),
							'after_bytes'         => strlen( $after ),
							'changed_bytes'       => $changed_bytes,
							'before_sha256'       => $before_hash,
							'after_sha256'        => $after_hash,
							'diff_preview'        => $diff_preview,
							'change_summary'      => self::change_summary( $path, $before, $after, $changed_bytes ),
							'execution_status'    => 'blocked',
							'verification_status' => 'blocked',
							'rollback_status'     => 'not_needed',
							'effect_verified'     => false,
							'resource_type'       => 'customizer_css',
							'resource_ref'        => $path,
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
					$path,
					$after_hash,
					'css',
					$changed_bytes
				);
				if ( $grant_ok instanceof \WP_Error ) {
					return $grant_ok;
				}

				$post = wp_get_custom_css_post();
				$backup_ref = '';
				if ( $post instanceof \WP_Post && $post->ID > 0 ) {
					$backup_ref = Backup::snapshot_post( (int) $post->ID );
				}
				$result = wp_update_custom_css_post( $after );
				if ( is_wp_error( $result ) ) {
					return $result;
				}

				$readback      = (string) wp_get_custom_css();
				$readback_hash = hash( 'sha256', $readback );
				if ( ! hash_equals( $after_hash, $readback_hash ) ) {
					$rollback        = wp_update_custom_css_post( $before );
					$rollback_status = is_wp_error( $rollback ) || (string) wp_get_custom_css() !== $before ? 'failed' : 'restored';
					return new \WP_Error(
						'stonewright_custom_css_readback_mismatch',
						__( 'Customizer CSS readback did not match the approved candidate. Rollback attempted.', 'stonewright' ),
						[
							'status'              => 500,
							'path'                => $path,
							'before_sha256'       => $before_hash,
							'after_sha256'        => $after_hash,
							'readback_sha256'     => $readback_hash,
							'backup_ref'          => $backup_ref,
							'changed_bytes'       => $changed_bytes,
							'execution_status'    => 'ok',
							'verification_status' => 'failed',
							'rollback_status'     => $rollback_status,
							'effect_verified'     => false,
						]
					);
				}

				return [
					'ok'                  => true,
					'applied'             => true,
					'dry_run'             => false,
					'changed'             => true,
					'css'                 => $readback,
					'path'                => $path,
					'stylesheet'          => get_stylesheet(),
					'before_bytes'        => strlen( $before ),
					'after_bytes'         => strlen( $after ),
					'changed_bytes'       => $changed_bytes,
					'before_sha256'       => $before_hash,
					'after_sha256'        => $after_hash,
					'readback_sha256'     => $readback_hash,
					'backup_ref'          => $backup_ref,
					'change_summary'      => self::change_summary( $path, $before, $after, $changed_bytes ),
					'execution_status'    => 'ok',
					'verification_status' => 'verified',
					'rollback_status'     => 'not_needed',
					'effect_verified'     => true,
					'operation_class'     => 'custom_code',
					'resource_type'       => 'customizer_css',
					'resource_ref'        => $path,
				];
			}
		);
	}

	/** @return array<int, string> */
	protected function audit_redacted_keys(): array {
		return array_merge( parent::audit_redacted_keys(), [ 'css', 'custom_code_grant' ] );
	}

	/** @return array{changed_lines:int,preview:string} */
	private static function bounded_diff_preview( string $before, string $after ): array {
		$before_lines = explode( "\n", $before );
		$after_lines  = explode( "\n", $after );
		$limit        = max( count( $before_lines ), count( $after_lines ) );
		$preview      = [];
		$preview_count = 0;
		$changed      = 0;

		for ( $index = 0; $index < $limit && $preview_count < 40; ++$index ) {
			$old = $before_lines[ $index ] ?? null;
			$new = $after_lines[ $index ] ?? null;
			if ( $old === $new ) {
				continue;
			}
			++$changed;
			if ( null !== $old ) {
				$preview[] = '- ' . mb_substr( (string) $old, 0, 120 );
				++$preview_count;
			}
			if ( null !== $new && $preview_count < 40 ) {
				$preview[] = '+ ' . mb_substr( (string) $new, 0, 120 );
				++$preview_count;
			}
		}

		return [
			'changed_lines' => $changed,
			'preview'       => mb_substr( implode( "\n", $preview ), 0, 5000 ),
		];
	}

	/** @return array{path:string,before_bytes:int,after_bytes:int,changed_bytes:int,summary:string} */
	private static function change_summary( string $path, string $before, string $after, int $changed_bytes ): array {
		return [
			'path'          => $path,
			'before_bytes'  => strlen( $before ),
			'after_bytes'   => strlen( $after ),
			'changed_bytes' => $changed_bytes,
			'summary'       => sprintf(
				/* translators: 1: CSS target path, 2: bytes before, 3: bytes after. */
				__( 'Replace Customizer CSS at %1$s (%2$d bytes to %3$d bytes).', 'stonewright' ),
				$path,
				strlen( $before ),
				strlen( $after )
			),
		];
	}

	protected function audit_metadata( array $args, array|\WP_Error $result, int $elapsed_ms ): array {
		$data = $result instanceof \WP_Error ? (array) $result->get_error_data() : $result;
		return [
			'duration_ms'         => $elapsed_ms,
			'operation_class'     => (string) ( $data['operation_class'] ?? 'custom_code' ),
			'resource_type'       => (string) ( $data['resource_type'] ?? 'customizer_css' ),
			'resource_ref'        => (string) ( $data['path'] ?? $data['resource_ref'] ?? '' ),
			'execution_status'    => (string) ( $data['execution_status'] ?? ( $result instanceof \WP_Error ? 'error' : 'ok' ) ),
			'verification_status' => (string) ( $data['verification_status'] ?? ( $result instanceof \WP_Error ? 'failed' : 'verified' ) ),
			'rollback_status'     => (string) ( $data['rollback_status'] ?? 'not_needed' ),
			'before_sha256'       => (string) ( $data['before_sha256'] ?? '' ),
			'after_sha256'        => (string) ( $data['after_sha256'] ?? '' ),
			'changed_bytes'       => (int) ( $data['changed_bytes'] ?? 0 ),
			'effect_verified'     => (bool) ( $data['effect_verified'] ?? false ),
			'dry_run'             => (bool) ( $args['dry_run'] ?? false ),
			'cause_key'           => (string) ( $data['cause_key'] ?? ( $result instanceof \WP_Error ? $result->get_error_code() : '' ) ),
		];
	}
}
