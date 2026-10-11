<?php
/**
 * Undo or redo one change of the change history.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Security;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\ChangeLedger;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\ChangeHistoryView;

/**
 * Hands the call to the rollback engine, which owns every gate: permission, the rules for code (an administrator at wp-admin
 * is the approval, so an ability call gets the approval-required answer as it is), drift, the production-safe confirmation
 * token, the claim, the health probe and the audit row. The engine verifies the token itself, so the ability does not
 * verify it again (a token works once); it writes no audit row of its own, because the engine writes one for every call.
 *
 * The call is never marked as approved by a person. The caller is the actor of the new row, and the way is `ability`.
 *
 * @stonewright-status stable
 */
final class ChangeRollback extends AbilityKernel {

	public function name(): string {
		return 'stonewright/change-rollback';
	}

	public function label(): string {
		return __( 'Change rollback', 'stonewright' );
	}

	public function description(): string {
		return __( 'Undoes one change from the change history, or redoes a rollback (pass the change_id of the rollback row). Always call with dry_run true first: it returns the plan (a short diff, drift, newer changes to the same item, whether an administrator is needed) and changes nothing. A run puts the item back as it was before the change, probes the site and records a rollback row; a failing probe puts the earlier state back. Drift (the item was edited after the change) is refused unless force_drift is true; pass expected_current_sha256 from the plan to undo exactly the state you saw. In production-safe mode a run needs a confirmation_token from stonewright-security-issue-confirmation-token, issued for the confirmation_args that the dry run returns (all four keys, as returned). A change to code (theme file, custom code, sandbox file, Customizer CSS) is never undone on a call: the answer is stonewright_rescue_approval_required with approval_url. Show it to the user, ask an administrator to use Stonewright > Activity > Changes, and stop; do not retry. Needs manage_options.', 'stonewright' );
	}

	public function category(): string {
		return 'security';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'change_id'               => [
					'type'        => 'string',
					'pattern'     => '^cs-[a-f0-9]{24}$',
					'description' => 'The change to undo, or the rollback row to redo.',
				],
				'dry_run'                 => [
					'type'    => 'boolean',
					'default' => false,
				],
				'force_drift'             => [
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'Undo even though the item was edited after the change, overwriting those edits.',
				],
				'expected_current_sha256' => [
					'type'        => 'string',
					'pattern'     => '^[a-fA-F0-9]{32,64}$',
					'description' => 'The current_sha256 of the plan. The run stops when the item is not in that state any more.',
				],
				'permanent'               => [
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'For families that can remove for good, for example an upload that the change created.',
				],
				'confirmation_token'      => [
					'type'        => 'string',
					'description' => 'Required in production-safe mode for a run, not for a dry run. Issue it for the confirmation_args of a dry run.',
				],
			],
			'required'             => [ 'change_id' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'                 => 'object',
			// A change that the Rescue journal also tracks goes through RescueRollback, whose answer adds its own fields.
			'additionalProperties' => true,
			'properties'           => [
				'ok'                    => [ 'type' => 'boolean' ],
				'change_id'             => [ 'type' => 'string' ],
				'dry_run'               => [ 'type' => 'boolean' ],
				'restorable'            => [ 'type' => 'boolean' ],
				'kind'                  => [ 'type' => 'string', 'enum' => [ 'rollback', 'redo' ] ],
				'family'                => [ 'type' => 'string' ],
				'resource_type'         => [ 'type' => 'string' ],
				'resource_id'           => [ 'type' => 'string' ],
				'summary'               => [ 'type' => 'string' ],
				'status'                => [ 'type' => 'string' ],
				'path'                  => [ 'type' => 'string', 'enum' => [ 'ledger', 'journal' ] ],
				'would_apply'           => [ 'type' => 'string' ],
				'diff'                  => [
					'type'        => 'object',
					'description' => 'Dry run: how much the undo adds, removes and changes in each part. The lines are in stonewright-change-diff-get.',
				],
				'drift'                 => [ 'type' => 'boolean' ],
				'drift_known'           => [ 'type' => 'boolean' ],
				'requires_force'        => [ 'type' => 'boolean' ],
				'already_restored'      => [ 'type' => 'boolean' ],
				'current_sha256'        => [ 'type' => 'string' ],
				'expected_sha256'       => [ 'type' => 'string' ],
				'newer_changes'         => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'change_id' => [ 'type' => 'string' ],
							'ability'   => [ 'type' => 'string' ],
							'since'     => [ 'type' => 'string' ],
						],
					],
				],
				'warnings'              => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'approval_required'     => [ 'type' => 'boolean' ],
				'approval_url'          => [ 'type' => 'string' ],
				'confirmation_required' => [ 'type' => 'boolean' ],
				'confirmation_args'     => [
					'type'        => 'object',
					'description' => 'Production-safe mode, dry run: the arguments to issue the confirmation token for.',
				],
				'rollback_status'       => [ 'type' => 'string' ],
				'rollback_change_id'    => [ 'type' => 'string', 'description' => 'The row the run wrote; run this ability on it to redo.' ],
				'state'                 => [ 'type' => 'string' ],
				'site_status'           => [ 'type' => 'string' ],
				'verification_status'   => [ 'type' => 'string' ],
				'probe'                 => [ 'type' => [ 'object', 'null' ] ],
				'drift_forced'          => [ 'type' => 'boolean' ],
				'limits'                => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'restorable_again'      => [ 'type' => 'boolean' ],
				'receipt'               => [ 'type' => 'object' ],
			],
			'required'             => [ 'ok', 'change_id' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::manage_options();
	}

	public function execute( array $args ): array|\WP_Error {
		$id = isset( $args['change_id'] ) && is_string( $args['change_id'] ) ? $args['change_id'] : '';
		if ( ! ChangeLedger::is_valid_id( $id ) ) {
			return new \WP_Error( 'stonewright_change_invalid_id', __( 'That is not a change id.', 'stonewright' ), [ 'status' => 400 ] );
		}
		$options = ChangeHistoryView::rollback_input( $args );
		if ( $options instanceof \WP_Error ) {
			return $options;
		}

		$result = \Stonewright\WpMcp\Security\ChangeRollback::run(
			$id,
			array_merge(
				$options,
				[
					'by'    => 'ability',
					'actor' => (int) get_current_user_id(),
				]
			)
		);
		if ( $result instanceof \WP_Error ) {
			return $result;
		}

		return $this->shape( $result, $id, $options );
	}

	/**
	 * A dry run keeps the plan but not its diff, and says what a token has to be issued for.
	 *
	 * @param array<string, mixed> $result  The answer of the engine.
	 * @param array<string, mixed> $options The options of the call.
	 * @return array<string, mixed>
	 */
	private function shape( array $result, string $id, array $options ): array {
		if ( empty( $result['dry_run'] ) ) {
			return $result;
		}
		if ( is_array( $result['diff'] ?? null ) ) {
			$result['diff'] = ChangeHistoryView::diff_brief( $result['diff'] );
		}
		foreach ( [ 'current_sha256', 'expected_sha256' ] as $key ) {
			if ( isset( $result[ $key ] ) && is_string( $result[ $key ] ) ) {
				$result[ $key ] = substr( $result[ $key ], 0, ChangeHistoryView::HASH_PREFIX );
			}
		}
		if ( ! empty( $result['confirmation_required'] ) ) {
			$result['confirmation_args'] = \Stonewright\WpMcp\Security\ChangeRollback::confirmation_args( $id, $options );
		}
		return $result;
	}
}
