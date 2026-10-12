<?php
/**
 * Roll back a rescue incident.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Security;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Security\RescueRollback as RescueRollbackService;

/**
 * Runs the rollback recipe the change journal recorded before the write, then probes the site.
 *
 * The action "rollback" (the default) restores the state the change replaced. The action
 * "recheck" changes nothing: it probes the site again and closes the incident when the site
 * loads, for a change someone undid by hand. A dry run returns the plan without running it. The
 * outcome is recorded on the change set and in the audit log.
 *
 * @stonewright-status stable
 */
final class RescueRollback extends AbilityKernel {

	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/rescue-rollback';
	}

	public function label(): string {
		return __( 'Rescue rollback', 'stonewright' );
	}

	public function description(): string {
		return __( 'Rolls back a rescue incident, an armed change or a verified change (any of the last 50 the journal keeps) by incident_id, then probes the site. action "rollback" (default) runs the recorded recipe; "recheck" probes again and closes the incident only when every check that passed before the change passes again, after a manual fix (verification_reason says why it did not close: not_started, transport_unavailable, check_lost or site_failed); dry_run returns the plan, which says how old the change is, whether the item was edited after the change (drift, newer_changes, current_sha256) and warns when a newer change to the same item would be overwritten. Call it when a response carries pending_incident. A rollback does not overwrite a later edit: when the item changed after the change the answer is stonewright_change_drift and nothing is changed; run again with force_drift true and the expected_current_sha256 of the dry run to overwrite it. A site that fails now, with an incident or a failed rollback recorded for the change, is recovered without that confirmation. A rollback saves the current state first and probes the site before and after: when it makes a working site fail, it is put back and the answer is stonewright_rescue_undo_reverted; when the current state cannot be saved, the answer is stonewright_rescue_undo_capture_failed and nothing changes. A rollback that works is recorded under the change in the change history, where stonewright-change-rollback can redo it (history says when it could not be recorded). A verified change to code (theme file, custom code, sandbox file, Customizer CSS) is not undone on a call: the answer is stonewright_rescue_approval_required with approval_url, and the administrator rolls it back at Stonewright > Activity > Rescue. Requires confirmation_token in production-safe mode (not for a dry_run of the rollback).', 'stonewright' );
	}

	public function category(): string {
		return 'security';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'incident_id'        => [
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 96,
				],
				'action'             => [
					'type'    => 'string',
					'enum'    => [ 'rollback', 'recheck' ],
					'default' => 'rollback',
				],
				'dry_run'            => [
					'type'    => 'boolean',
					'default' => false,
				],
				'force_drift'             => [
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'Roll back even though the item was edited after the change, overwriting those edits. Only after a dry_run showed drift or newer_changes and the person agreed. A site that fails now, with a fault recorded against the change, needs no force.',
				],
				'expected_current_sha256' => [
					'type'        => 'string',
					'pattern'     => '^[a-fA-F0-9]{32,64}$',
					'description' => 'The current_sha256 of the dry_run. The rollback stops when the item is not in that state any more.',
				],
				'confirmation_token'      => [
					'type'        => 'string',
					'description' => 'Required in production-safe mode, except for a dry_run of the rollback action.',
				],
			],
			'required'             => [ 'incident_id' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'                  => [ 'type' => 'boolean' ],
				'incident_id'         => [ 'type' => 'string' ],
				'change_set_id'       => [ 'type' => 'string' ],
				'action'              => [ 'type' => 'string' ],
				'dry_run'             => [ 'type' => 'boolean' ],
				'ability'             => [ 'type' => 'string' ],
				'resource_type'       => [ 'type' => 'string' ],
				'resource_key'        => [ 'type' => 'string' ],
				'client'              => [ 'type' => 'string' ],
				'since'               => [ 'type' => 'string' ],
				'state'               => [ 'type' => 'string' ],
				'rollback_status'     => [ 'type' => 'string' ],
				'detail'              => [ 'type' => 'string' ],
				'site_status'         => [ 'type' => 'string' ],
				'verification_status' => [ 'type' => 'string' ],
				'verification_reason' => [ 'type' => 'string', 'enum' => [ 'verified', 'not_started', 'transport_unavailable', 'check_lost', 'site_failed' ], 'description' => 'Why the probe proves what it does: verified; not_started (no request could be made); transport_unavailable (requests were made and none reached a verdict); check_lost (a check that passed before the change did not answer); site_failed (the site answered with a failure).' ],
				'resolved'            => [ 'type' => 'boolean' ],
				'recipe'              => [ 'type' => [ 'string', 'object' ] ],
				'probe'               => [ 'type' => [ 'object', 'null' ] ],
				'age_seconds'         => [ 'type' => 'integer' ],
				'undo_guard'          => [ 'type' => 'string' ],
				'approval_required'   => [ 'type' => 'boolean' ],
				'approval_url'        => [ 'type' => 'string' ],
				'newer_changes'       => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'incident_id' => [ 'type' => 'string' ],
							'ability'     => [ 'type' => 'string' ],
							'since'       => [ 'type' => 'string' ],
						],
					],
				],
				'warnings'            => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
				'drift'               => [ 'type' => 'boolean' ],
				'drift_known'         => [ 'type' => 'boolean' ],
				'requires_force'      => [ 'type' => 'boolean' ],
				'current_sha256'      => [ 'type' => 'string' ],
				'expected_sha256'     => [ 'type' => 'string' ],
				'redo_available'      => [ 'type' => 'boolean' ],
				'mode'                => [ 'type' => 'string', 'enum' => [ 'normal', 'emergency' ] ],
				'drift_forced'        => [ 'type' => 'boolean' ],
				'rollback_change_id'  => [ 'type' => 'string' ],
				'restorable_again'    => [ 'type' => 'boolean' ],
				'history'             => [ 'type' => 'string', 'enum' => [ 'recorded', 'ledger_unavailable', 'no_ledger_row', 'not_restorable', 'not_recorded' ] ],
			],
			'required'   => [ 'ok', 'incident_id' ],
		];
	}
	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::manage_options();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $a ): array|\WP_Error {
				$action      = (string) ( $a['action'] ?? 'rollback' );
				$incident_id = (string) ( $a['incident_id'] ?? '' );
				// A verified change to code is undone by an administrator at the Rescue page. Say so before
				// asking for a token that would not change that.
				$options = [
					'by'                      => 'ability',
					'dry_run'                 => ! empty( $a['dry_run'] ),
					'force_drift'             => ! empty( $a['force_drift'] ),
					'expected_current_sha256' => isset( $a['expected_current_sha256'] ) && is_string( $a['expected_current_sha256'] ) ? $a['expected_current_sha256'] : '',
				];
				if ( 'rollback' === $action && empty( $a['dry_run'] ) ) {
					$approval = RescueRollbackService::approval_refusal( $incident_id );
					if ( $approval instanceof \WP_Error ) {
						return $approval;
					}
					// A rollback over a later edit, or of a state that is not the one the dry run showed, is refused here,
					// before the token below is looked at, so that the refusal does not use it up.
					$preflight = RescueRollbackService::preflight_refusal( $incident_id, $options );
					if ( $preflight instanceof \WP_Error ) {
						return $preflight;
					}
				}
				// Only the plan of a rollback is free. A recheck changes the incident (it can close it), so
				// "dry_run" does not take the confirmation away from it.
				if ( empty( $a['dry_run'] ) || 'rollback' !== $action ) {
					$verify = $a;
					unset( $verify['confirmation_token'] );
					$token_error = $this->confirmation_token_error( $a, $verify );
					if ( $token_error instanceof \WP_Error ) {
						return $token_error;
					}
				}

				if ( 'recheck' === $action ) {
					return RescueRollbackService::recheck( $incident_id );
				}
				return RescueRollbackService::run( $incident_id, $options );
			}
		);
	}
}
