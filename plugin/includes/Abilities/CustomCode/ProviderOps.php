<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\CustomCode;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\CustomCode\ProviderRegistry;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Provider-neutral custom-code pipeline.
 *
 * Actions: discover | list | read | dry-run | apply | verify | rollback
 *
 * Dry-run returns approval_url and must stop before opening approval or obtaining
 * a grant. Apply requires a human-issued single-use custom_code_grant.
 * Apply/rollback also require confirmation_token in production-safe mode.
 *
 * @stonewright-status stable
 */
final class ProviderOps extends AbilityKernel {
	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/custom-code-provider';
	}

	public function label(): string {
		return __( 'Custom code: provider pipeline', 'stonewright' );
	}

	public function description(): string {
		return __( 'Discover, list, read, dry-run, apply, verify, and roll back custom code through first-party adapters (WPCode, Code Snippets, Customizer CSS, theme files). Writes require dry_run then a human-issued custom-code grant; agents must stop at the approval URL.', 'stonewright' );
	}

	public function category(): string {
		return 'custom-code';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'action' ],
			'properties'           => [
				'action'                 => [
					'type' => 'string',
					'enum' => [ 'discover', 'list', 'read', 'dry-run', 'apply', 'verify', 'rollback' ],
				],
				'provider'               => [
					'type'        => 'string',
					'description' => 'Provider id: wpcode | code-snippets | customizer-css | theme-file. Required except for discover.',
				],
				'target_id'              => [
					'type'        => 'string',
					'description' => 'Provider-local id or path (snippet id, theme-relative path, or "active" for Customizer CSS).',
				],
				'code'                   => [
					'type'        => 'string',
					'description' => 'Candidate code body for dry-run/apply. Never logged in audit.',
				],
				'content'                => [
					'type'        => 'string',
					'description' => 'Alias of code.',
				],
				'language'               => [
					'type' => 'string',
					'enum' => [ 'php', 'css', 'js', 'html' ],
				],
				'custom_code_grant'      => [
					'type'        => 'string',
					'description' => 'Single-use human-issued grant bound to after_sha256. Required for apply.',
				],
				'expected_before_sha256' => [
					'type'        => 'string',
					'description' => 'Optimistic concurrency: live hash must match dry-run before hash.',
				],
				'before_sha256'          => [ 'type' => 'string' ],
				'expected_sha256'        => [ 'type' => 'string' ],
				'after_sha256'           => [ 'type' => 'string' ],
				'snapshot_id'            => [ 'type' => 'string' ],
				'max_changed_bytes'      => [
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 262144,
				],
				'limit'                  => [
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 200,
					'default' => 50,
				],
				'mode'                   => [
					'type'        => 'string',
					'description' => 'Theme-file patch mode when provider=theme-file.',
				],
				'native_gap'             => [
					'type'                 => 'object',
					'additionalProperties' => true,
				],
				'confirmation_token'     => [ 'type' => 'string' ],
				'path'                   => [ 'type' => 'string' ],
				'css'                    => [ 'type' => 'string' ],
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
		$action      = sanitize_key( (string) ( $args['action'] ?? '' ) );
		$provider_id = sanitize_key( (string) ( $args['provider'] ?? '' ) );

		// List/read/verify expose snippet bodies, hashes, or theme/customizer code.
		// Snippet plugins stay manage_options-only; edit_theme_options is accepted
		// only for the two WordPress theme-owned providers.
		if ( in_array( $action, [ 'list', 'read', 'verify' ], true ) ) {
			$theme_owned = in_array( $provider_id, [ 'theme-file', 'customizer-css' ], true );
			return ( Permissions::manage_options() || ( $theme_owned && Permissions::edit_theme_options() ) )
				? true
				: new \WP_Error(
					'stonewright_permission_denied',
					__( 'Snippet code requires manage_options; theme-file and Customizer CSS reads also accept edit_theme_options.', 'stonewright' ),
					[ 'status' => 403 ]
				);
		}

		// Dry-run staging and mutations require manage_options (grant boundary).
		if ( in_array( $action, [ 'dry-run', 'apply', 'rollback' ], true ) ) {
			return Permissions::manage_options()
				? true
				: new \WP_Error(
					'stonewright_permission_denied',
					__( 'Custom-code dry-run/apply/rollback requires manage_options.', 'stonewright' ),
					[ 'status' => 403 ]
				);
		}

		// Discover only returns plugin presence — no code bodies.
		if ( 'discover' === $action ) {
			if ( ! Permissions::read() ) {
				return new \WP_Error(
					'stonewright_permission_denied',
					__( 'Custom-code discover requires a logged-in reader.', 'stonewright' ),
					[ 'status' => 403 ]
				);
			}
			return true;
		}

		return new \WP_Error(
			'stonewright_permission_denied',
			__( 'Unsupported custom-code action.', 'stonewright' ),
			[ 'status' => 403 ]
		);
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$action = sanitize_key( (string) ( $args['action'] ?? '' ) );
				if ( 'discover' === $action ) {
					return [
						'ok'        => true,
						'providers' => ProviderRegistry::discover_all(),
						'pipeline'  => [ 'discover', 'list', 'read', 'dry-run', 'approval stop', 'apply', 'verify', 'rollback' ],
						'rules'     => [
							'Agents must stop after dry-run and show approval_url, path, hashes, and summary.',
							'Never open the approval page or obtain a grant unless the user explicitly asks.',
							'Direct/pluginless mode may call discover only without code-edit caps; list/read require manage_options or edit_theme_options; dry-run/apply require plugin auth.',
						],
					];
				}

				// Production-safe: apply and rollback need confirmation tokens.
				if ( in_array( $action, [ 'apply', 'rollback' ], true ) ) {
					$verify_args = $args;
					unset( $verify_args['confirmation_token'], $verify_args['custom_code_grant'] );
					$token_error = $this->confirmation_token_error( $args, $verify_args );
					if ( $token_error instanceof \WP_Error ) {
						return $token_error;
					}
				}

				$provider_id = sanitize_key( (string) ( $args['provider'] ?? '' ) );
				if ( '' === $provider_id ) {
					return new \WP_Error(
						'stonewright_custom_code_provider_required',
						__( 'provider is required for this action.', 'stonewright' ),
						[ 'status' => 400 ]
					);
				}
				$provider = ProviderRegistry::get( $provider_id );
				if ( null === $provider ) {
					return new \WP_Error(
						'stonewright_custom_code_provider_unknown',
						__( 'Unknown custom-code provider.', 'stonewright' ),
						[
							'status'    => 404,
							'provider'  => $provider_id,
							'available' => array_keys( ProviderRegistry::all() ),
						]
					);
				}

				return match ( $action ) {
					'list' => $provider->list( $args ),
					'read' => $provider->read( (string) ( $args['target_id'] ?? $args['path'] ?? '' ) ),
					'dry-run' => $provider->dry_run( $args ),
					'apply' => $provider->apply( $args ),
					'verify' => $provider->verify( $args ),
					'rollback' => $provider->rollback( $args ),
					default => new \WP_Error(
						'stonewright_custom_code_action_invalid',
						__( 'Unsupported custom-code provider action.', 'stonewright' ),
						[ 'status' => 400, 'action' => $action ]
					),
				};
			}
		);
	}

	/**
	 * Never audit code bodies or grants.
	 *
	 * @return array<int, string>
	 */
	protected function audit_redacted_keys(): array {
		return array_merge(
			parent::audit_redacted_keys(),
			[
				'code',
				'content',
				'css',
				'custom_code_grant',
				'confirmation_token',
			]
		);
	}

	/**
	 * @param array<string, mixed>          $args
	 * @param array<string, mixed>|\WP_Error $result
	 * @return array<string, scalar|null>
	 */
	protected function audit_metadata( array $args, array|\WP_Error $result, int $elapsed_ms ): array {
		$meta = [
			'operation_class' => 'custom_code',
			'duration_ms'     => max( 0, $elapsed_ms ),
			'resource_type'   => 'custom_code_provider',
		];
		if ( isset( $args['provider'] ) && is_scalar( $args['provider'] ) ) {
			$meta['provider'] = sanitize_key( (string) $args['provider'] );
		}
		if ( isset( $args['action'] ) && is_scalar( $args['action'] ) ) {
			$meta['action'] = sanitize_key( (string) $args['action'] );
		}
		if ( isset( $args['target_id'] ) && is_scalar( $args['target_id'] ) ) {
			$meta['resource_ref'] = mb_substr( sanitize_text_field( (string) $args['target_id'] ), 0, 190 );
		}
		if ( is_array( $result ) ) {
			foreach ( [ 'before_sha256', 'after_sha256', 'changed_bytes', 'path', 'snapshot_id', 'verification_status', 'rollback_status', 'effect_verified' ] as $key ) {
				// array_key_exists (not isset) so explicit null values remain eligible.
				if ( ! array_key_exists( $key, $result ) ) {
					continue;
				}
				$value = $result[ $key ];
				if ( is_scalar( $value ) || null === $value ) {
					/** @var scalar|null $value */
					$meta[ $key ] = $value;
				}
			}
			if ( isset( $result['path'] ) && is_scalar( $result['path'] ) ) {
				$meta['normalized_path'] = sanitize_text_field( (string) $result['path'] );
				$meta['resource_ref']    = sanitize_text_field( (string) $result['path'] );
			}
		}
		return $meta;
	}
}
