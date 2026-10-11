<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\CustomCode;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\CustomCode\GrantedRollbackInterface;
use Stonewright\WpMcp\CustomCode\ProviderRegistry;
use Stonewright\WpMcp\CustomCode\ProviderSupport;
use Stonewright\WpMcp\CustomCode\VerifiedApplyInterface;
use Stonewright\WpMcp\Security\ChangeSet;
use Stonewright\WpMcp\Security\ChangeSetSources;
use Stonewright\WpMcp\Security\CustomCodeGrant;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Security\RescueGuard;

/**
 * Provider-neutral custom-code pipeline.
 *
 * Actions: discover | list | read | dry-run | apply | verify | rollback
 *
 * Dry-run returns approval_url and must stop before opening approval or obtaining
 * a grant. Apply requires a human-issued single-use custom_code_grant.
 * Rollback of a WPCode or Code Snippets snippet rewrites code, so it needs the same grant, bound to the
 * code it restores: rollback with dry_run stages it for approval and writes nothing, and a rollback
 * without the grant answers with the approval handoff and stops.
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
		return __( 'Discover, list, read, dry-run, apply, verify, and roll back custom code through first-party adapters (WPCode, Code Snippets, Customizer CSS, theme files). Writes, including a rollback of a WPCode or Code Snippets snippet, require a dry run (rollback takes dry_run:true) and then a human-issued custom-code grant; agents get approval_required and agent_must_stop with the approval URL and must stop there, never approve it themselves.', 'stonewright' );
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
					'description' => 'Single-use human-issued grant bound to after_sha256. Required for apply, and for rollback of a WPCode or Code Snippets snippet (bound to the code the snapshot restores).',
				],
				'dry_run'                => [
					'type'        => 'boolean',
					'description' => 'rollback only (WPCode, Code Snippets): stage the restore for human approval and return the approval handoff. Writes nothing and needs no grant.',
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
				'id'                     => [
					'type'        => 'string',
					'maxLength'   => 255,
					'description' => 'Alias of target_id for WPCode and Code Snippets.',
				],
				'mode'                   => [
					'type'        => 'string',
					'enum'        => [ 'append', 'insert_after_marker', 'replace_between_markers', 'replace_all' ],
					'default'     => 'replace_all',
					'description' => 'Theme-file patch mode when provider=theme-file.',
				],
				'marker'                 => [
					'type'        => 'string',
					'maxLength'   => 4096,
					'description' => 'Required for mode insert_after_marker (theme-file). Must match exactly once.',
				],
				'start_marker'           => [
					'type'        => 'string',
					'maxLength'   => 4096,
					'description' => 'Required for mode replace_between_markers (theme-file). Must match exactly once; the replacement keeps the marker.',
				],
				'end_marker'             => [
					'type'        => 'string',
					'maxLength'   => 4096,
					'description' => 'Required for mode replace_between_markers (theme-file). Must match exactly once, after start_marker.',
				],
				'create_if_missing'      => [
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'Theme-file only: create an allowlisted file that does not exist yet.',
				],
				'theme'                  => [
					'type'        => 'string',
					'enum'        => [ 'stylesheet', 'template' ],
					'default'     => 'stylesheet',
					'description' => 'Theme-file only: the active (child) theme, or the parent (template) theme.',
				],
				'smoke_url'              => [
					'type'        => 'string',
					'maxLength'   => 2048,
					'description' => 'Theme-file apply only: optional public URL to check after the write.',
				],
				'expected_active'        => [
					'type'        => 'boolean',
					'description' => 'verify only (WPCode): the active state the snippet is expected to have.',
				],
				'native_gap'             => [
					'type'                 => 'object',
					'additionalProperties' => true,
				],
				'confirmation_token'     => [ 'type' => 'string' ],
				'path'                   => [ 'type' => 'string' ],
				'css'                    => [ 'type' => 'string' ],
				'repair_of'              => ChangeSet::input_properties()['repair_of'],
				'supersedes'             => ChangeSet::input_properties()['supersedes'],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => true,
			'properties'           => [
				'change_set' => ChangeSet::output_property(),
			],
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

				// A rollback of snippet code needs the human grant like an apply. A plan, or a call without the grant,
				// is answered here, before a confirmation token is read, so that a token is not spent on a stop.
				if ( 'rollback' === $action ) {
					$stop = $this->rollback_approval_stop( $args );
					if ( null !== $stop ) {
						return $stop;
					}
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

				if ( 'apply' === $action && ! in_array( $provider_id, [ 'theme-file', 'customizer-css' ], true ) ) {
					RescueGuard::arm_custom_code_write( $provider_id, sanitize_text_field( (string) ( $args['target_id'] ?? $args['id'] ?? '' ) ) );
				}

				// The token of an apply was verified above, once, for this ability and these arguments. A provider that
				// delegates to a typed ability that checks a token too gets the verified entry points, so the single-use
				// token is not consumed a second time; the human grant is still checked by the delegated ability.
				$verified = $provider instanceof VerifiedApplyInterface;

				if ( 'rollback' === $action && $provider instanceof GrantedRollbackInterface ) {
					$grant_error = $this->rollback_grant_error( $provider, $args );
					if ( $grant_error instanceof \WP_Error ) {
						return $grant_error;
					}
				}

				return match ( $action ) {
					'list' => $provider->list( $args ),
					'read' => $provider->read( (string) ( $args['target_id'] ?? $args['path'] ?? '' ) ),
					'dry-run' => $verified ? $provider->dry_run_verified( $args ) : $provider->dry_run( $args ),
					'apply' => $verified ? $provider->apply_verified( $args ) : $provider->apply( $args ),
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
	 * The plan or the stop of a rollback that needs the human grant, or null when the call goes on.
	 *
	 * With dry_run true the pipeline stages the restore and returns the approval handoff. A call without a grant returns the same
	 * handoff as a refusal. Neither writes. A restore that changes nothing needs no approval.
	 *
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>|\WP_Error|null
	 */
	private function rollback_approval_stop( array $args ): array|\WP_Error|null {
		$provider_id = sanitize_key( (string) ( $args['provider'] ?? '' ) );
		$provider    = ProviderRegistry::get( $provider_id );
		if ( ! $provider instanceof GrantedRollbackInterface ) {
			return null;
		}
		$dry_run = true === ( $args['dry_run'] ?? false );
		if ( ! $dry_run && '' !== trim( (string) ( $args['custom_code_grant'] ?? '' ) ) ) {
			return null;
		}

		$candidate = $provider->rollback_candidate( $args );
		if ( $candidate instanceof \WP_Error ) {
			return $candidate;
		}
		$handoff = ProviderSupport::dry_run_handoff(
			$provider_id,
			$candidate['path'],
			$candidate['language'],
			$candidate['before'],
			$candidate['after'],
			[
				'risk_class'     => 'custom_code_' . $provider_id . '_rollback',
				'rollback_plan'  => 'Put the snapshot code back into the snippet.',
				'test_plan'      => [
					'Validate syntax for the restored language.',
					'Restore only with a human-issued single-use grant.',
					'Verify the readback hash.',
				],
				'response_extra' => [
					'target_id'   => (string) ( $args['target_id'] ?? $args['id'] ?? '' ),
					'snapshot_id' => (string) ( $args['snapshot_id'] ?? '' ),
				],
			]
		);
		if ( $handoff instanceof \WP_Error ) {
			return $handoff;
		}
		if ( $dry_run ) {
			return $handoff;
		}
		if ( empty( $handoff['approval_required'] ) ) {
			return null;
		}

		return new \WP_Error(
			'stonewright_custom_code_grant_required',
			__( 'Rolling back snippet code needs a human-issued one-time grant. Show the user the approval URL, path, hashes and summary, then stop. Do not open the approval page or issue, retrieve, or use a grant yourself.', 'stonewright' ),
			array_merge(
				[ 'status' => 400, 'retryable' => false ],
				CustomCodeGrant::missing_grant_proposal(
					[
						'path'          => $candidate['path'],
						'after_sha256'  => (string) $handoff['after_sha256'],
						'language'      => $candidate['language'],
						'changed_bytes' => (int) $handoff['changed_bytes'],
						'approval_url'  => (string) $handoff['approval_url'],
						'proposal_id'   => (string) $handoff['proposal_id'],
					]
				)
			)
		);
	}

	/**
	 * Checks and spends the human grant of a rollback that changes the snippet. The grant is bound to the code the
	 * snapshot restores, so a grant for other code does not unlock it.
	 *
	 * @param array<string, mixed> $args
	 */
	private function rollback_grant_error( GrantedRollbackInterface $provider, array $args ): ?\WP_Error {
		$candidate = $provider->rollback_candidate( $args );
		if ( $candidate instanceof \WP_Error ) {
			return $candidate;
		}
		if ( hash_equals( ProviderSupport::content_hash( $candidate['before'] ), ProviderSupport::content_hash( $candidate['after'] ) ) ) {
			return null;
		}
		$grant = ProviderSupport::consume_grant(
			(string) ( $args['custom_code_grant'] ?? '' ),
			$candidate['path'],
			ProviderSupport::content_hash( $candidate['after'] ),
			$candidate['language'],
			ProviderSupport::changed_bytes( $candidate['before'], $candidate['after'] )
		);
		return $grant instanceof \WP_Error ? $grant : null;
	}

	/**
	 * ChangeSetV1 of a dry-run, apply or rollback that a provider handled itself.
	 * A provider that delegates to a typed ability returns that ability's change set,
	 * which is kept as it is; the other actions report none.
	 *
	 * @param array<string, mixed>           $args
	 * @param array<string, mixed>|\WP_Error $result
	 * @return array<string, mixed>|null
	 */
	protected function change_set_inputs( array $args, array|\WP_Error $result, string $status ): ?array {
		$action = sanitize_key( (string) ( $args['action'] ?? '' ) );
		if ( ! in_array( $action, [ 'dry-run', 'apply', 'rollback' ], true ) || ( is_array( $result ) && is_array( $result['change_set'] ?? null ) ) ) {
			return null;
		}
		$data     = ChangeSetSources::data( $result );
		$provider = sanitize_key( (string) ( $args['provider'] ?? '' ) );
		$target   = (string) ( $data['target_id'] ?? $args['target_id'] ?? $args['path'] ?? '' );
		$ref      = $provider . ':' . ( '' !== $target ? $target : (string) ( $data['path'] ?? '' ) );
		$rollback = 'rollback' === $action;
		return ChangeSetSources::file(
			$args,
			$result,
			$status,
			ChangeSet::entry( 'custom_code', $ref, $rollback ? 'rollback' : 'apply', 0 ),
			$rollback ? [ 'restore' => true ] : [ 'recipe_kind' => 'provider_snapshot', 'recipe_target' => $ref ]
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
