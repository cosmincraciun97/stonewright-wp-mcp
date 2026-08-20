<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\FSE;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Core\MethodRouter;
use Stonewright\WpMcp\Security\CustomCodeGrant;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Security\ThemeWriteTransaction;
use Stonewright\WpMcp\ThemeJson\Validator;

/**
 * Dry-run / grant-gated child-theme theme.json export handoff.
 *
 * Custom-code grant boundary: dry-run first, return approval_url / target path /
 * byte counts / summary, then STOP. Never apply a disk write without
 * custom_code_grant. Pluginless Direct must not write.
 *
 * @stonewright-status stable
 */
final class ThemeJsonHandoff extends AbilityKernel {
	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/theme-json-handoff';
	}

	public function label(): string {
		return __( 'Child-theme theme.json handoff', 'stonewright' );
	}

	public function description(): string {
		return __( 'Stages a validated child-theme theme.json candidate. Dry-run returns approval_url, path, byte counts, and summary, then stops. Apply requires a human-issued custom_code_grant. Pluginless Direct cannot write.', 'stonewright' );
	}

	public function category(): string {
		return 'fse';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'theme_json' ],
			'properties'           => [
				'theme_json'         => [
					'type'        => 'object',
					'description' => 'Full theme.json object to write to the active stylesheet.',
				],
				'dry_run'            => [
					'type'        => 'boolean',
					'default'     => true,
					'description' => 'Required true first. Returns candidate hashes and approval_url without writing.',
				],
				'custom_code_grant'  => [
					'type'        => 'string',
					'description' => 'Single-use human-issued grant bound to after_sha256. Required to apply.',
				],
				'native_gap'         => [
					'type'                 => 'object',
					'additionalProperties' => false,
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
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 262144,
				],
				'confirmation_token' => [ 'type' => 'string' ],
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
		return Permissions::can_manage_fse();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				if ( ! empty( $GLOBALS['stonewright_test_direct_mode'] ) || ( defined( 'STONEWRIGHT_DIRECT_MODE' ) && STONEWRIGHT_DIRECT_MODE ) ) {
					return $this->error(
						'direct_write_forbidden',
						__( 'Pluginless Direct mode cannot write theme.json. Use the plugin with an authenticated custom-code grant.', 'stonewright' ),
						[ 'status' => 403 ]
					);
				}

				$verify = $args;
				unset( $verify['confirmation_token'], $verify['custom_code_grant'] );
				$token_error = $this->confirmation_token_error( $args, $verify );
				if ( null !== $token_error ) {
					return $token_error;
				}

				$theme_json = $args['theme_json'] ?? null;
				if ( ! is_array( $theme_json ) ) {
					return $this->error( 'missing_theme_json', __( 'theme_json must be an object.', 'stonewright' ) );
				}

				$canonical = Validator::validate( $theme_json );
				if ( is_wp_error( $canonical ) ) {
					return $canonical;
				}

				$resolved = $this->resolve_theme_json_path();
				if ( $resolved instanceof \WP_Error ) {
					return $resolved;
				}

				$before        = is_file( $resolved['absolute'] ) ? (string) file_get_contents( $resolved['absolute'] ) : '';
				$after         = (string) wp_json_encode( $canonical, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
				$after        .= "\n";
				$before_hash   = hash( 'sha256', $before );
				$after_hash    = hash( 'sha256', $after );
				$changed       = $before_hash !== $after_hash;
				$changed_bytes = ThemeWriteTransaction::changed_bytes( $before, $after );
				$max_bytes     = (int) ( $args['max_changed_bytes'] ?? ThemeWriteTransaction::DEFAULT_MAX_CHANGED_BYTES );
				$dry_run       = ! array_key_exists( 'dry_run', $args ) || (bool) $args['dry_run'];
				$summary       = [
					'path'          => $resolved['relative'],
					'mode'          => 'replace_all',
					'before_bytes'  => strlen( $before ),
					'after_bytes'   => strlen( $after ),
					'changed_bytes' => $changed_bytes,
					'summary'       => sprintf(
						/* translators: 1: path, 2: bytes before, 3: bytes after. */
						__( 'Export theme.json to %1$s (%2$d bytes to %3$d bytes).', 'stonewright' ),
						$resolved['relative'],
						strlen( $before ),
						strlen( $after )
					),
				];

				if ( $dry_run || ! $changed ) {
					$proposal   = null;
					$native_gap = null;
					if ( $changed ) {
						$native_gap = MethodRouter::validate_native_gap( $args['native_gap'] ?? null );
						if ( $native_gap instanceof \WP_Error ) {
							return $native_gap;
						}
						$proposal = CustomCodeGrant::stage_proposal(
							[
								'path'              => $resolved['relative'],
								'language'          => 'html',
								'before_sha256'     => $before_hash,
								'after_sha256'      => $after_hash,
								'changed_bytes'     => $changed_bytes,
								'max_changed_bytes' => $max_bytes,
								'risk_class'        => 'elevated',
								'native_gap'        => $native_gap,
								'diff_preview'      => [
									'changed_lines' => 1,
									'preview'       => mb_substr( $after, 0, 400 ),
								],
								'test_plan'         => [
									'Validate theme.json against the bundled schema.',
									'Apply the exact approved hash to the child theme.',
									'Verify the active theme still boots.',
								],
								'rollback_plan'     => 'Restore the previous theme.json bytes from the Stonewright theme backup.',
							]
						);
						if ( $proposal instanceof \WP_Error ) {
							return $proposal;
						}
					}

					return [
						'ok'                  => true,
						'dry_run'             => true,
						'changed'             => $changed,
						'path'                => $resolved['relative'],
						'before_bytes'        => strlen( $before ),
						'after_bytes'         => strlen( $after ),
						'changed_bytes'       => $changed_bytes,
						'before_sha256'       => $before_hash,
						'after_sha256'        => $after_hash,
						'change_summary'      => $summary,
						'approval_required'   => $changed,
						'approval_url'        => is_array( $proposal ) ? (string) $proposal['approval_url'] : '',
						'proposal_id'         => is_array( $proposal ) ? (string) $proposal['proposal_id'] : '',
						'proposal_expires_at' => is_array( $proposal ) ? (string) $proposal['expires_at'] : '',
						'agent_must_stop'     => $changed,
						'operator_action'     => $changed
							? 'Human reviews the proposal in wp-admin, issues the one-time grant, and sends the token back.'
							: 'No approval needed because the candidate is unchanged.',
						'native_gap'          => $native_gap,
						'execution_status'    => 'ok',
						'verification_status' => 'dry_run',
						'rollback_status'     => 'not_needed',
						'effect_verified'     => true,
						'operation_class'     => 'theme_file_write',
						'resource_type'       => 'theme_file',
						'resource_ref'        => $resolved['relative'],
					];
				}

				$grant = (string) ( $args['custom_code_grant'] ?? '' );
				if ( '' === $grant ) {
					$proposal = CustomCodeGrant::missing_grant_proposal(
						[
							'path'           => $resolved['relative'],
							'language'       => 'html',
							'after_sha256'   => $after_hash,
							'before_sha256'  => $before_hash,
							'changed_bytes'  => $changed_bytes,
							'change_summary' => $summary,
							'risk_class'     => 'elevated',
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
					'html',
					$changed_bytes,
					false
				);
				if ( $grant_ok instanceof \WP_Error ) {
					return $grant_ok;
				}

				return ThemeWriteTransaction::apply(
					[
						'absolute'          => $resolved['absolute'],
						'relative'          => $resolved['relative'],
						'before'            => $before,
						'after'             => $after,
						'language'          => 'text',
						'max_changed_bytes' => $max_bytes,
					]
				);
			}
		);
	}

	/**
	 * @return array{relative:string,absolute:string}|\WP_Error
	 */
	private function resolve_theme_json_path() {
		$root     = \wp_normalize_path( (string) \get_stylesheet_directory() );
		$relative = 'theme.json';
		$absolute = \wp_normalize_path( rtrim( $root, '/' ) . '/' . $relative );
		if ( ! str_starts_with( $absolute, rtrim( $root, '/' ) . '/' ) ) {
			return $this->error( 'theme_file_path_traversal', __( 'Resolved path escapes the theme root.', 'stonewright' ) );
		}
		return [
			'relative' => $relative,
			'absolute' => $absolute,
		];
	}

	/**
	 * @return array<int, string>
	 */
	protected function audit_redacted_keys(): array {
		return array_merge( parent::audit_redacted_keys(), [ 'custom_code_grant', 'theme_json' ] );
	}
}
