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
		return __( 'Rolls back a rescue incident, an armed change or a verified change (any of the last 50 the journal keeps) by incident_id, then probes the site. action "rollback" (default) runs the recorded recipe; "recheck" only probes again and closes the incident when the site loads, after a manual fix; dry_run returns the plan, which says how old the change is and warns when a newer change to the same item would be overwritten. Call it when a response carries pending_incident. An undo of a verified change saves the current state first and probes the site before and after: when the undo makes a working site fail, it is put back and the answer is stonewright_rescue_undo_reverted; when the current state cannot be saved, the answer is stonewright_rescue_undo_capture_failed and nothing changes. A verified change to code (theme file, custom code, sandbox file, Customizer CSS) is not undone on a call: the answer is stonewright_rescue_approval_required with approval_url, and the administrator rolls it back at Stonewright > Activity > Rescue. Requires confirmation_token in production-safe mode (not for a dry_run of the rollback).', 'stonewright' );
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
				'confirmation_token' => [
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
				if ( 'rollback' === $action && empty( $a['dry_run'] ) ) {
					$approval = RescueRollbackService::approval_refusal( $incident_id );
					if ( $approval instanceof \WP_Error ) {
						return $approval;
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
				return RescueRollbackService::run( $incident_id, [ 'by' => 'ability', 'dry_run' => ! empty( $a['dry_run'] ) ] );
			}
		);
	}
}
