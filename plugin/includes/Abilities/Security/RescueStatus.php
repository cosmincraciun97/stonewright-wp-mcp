<?php
/**
 * Read-only status of the rescue journal.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Security;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Security\RescueRollback;

/**
 * Lists what Stonewright Rescue knows: open incidents, changes that were never verified, and
 * the latest journaled changes. It reads the journal only; it changes nothing and runs no probe.
 *
 * @stonewright-status stable
 */
final class RescueStatus extends AbilityKernel {

	public function name(): string {
		return 'stonewright/rescue-status';
	}

	public function label(): string {
		return __( 'Rescue status', 'stonewright' );
	}

	public function description(): string {
		return __( 'Lists open rescue incidents (a change that left the site failing and could not be rolled back, or a PHP fatal recorded after a change), changes that were armed but never verified, and the latest journaled changes, each with the rollback it would run. Read-only: it changes nothing and runs no probe. Call it when a response carries pending_incident.', 'stonewright' );
	}

	public function category(): string {
		return 'security';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [],
		];
	}

	public function output_schema(): array {
		$incident = [
			'type'       => 'object',
			'properties' => [
				'incident_id'   => [ 'type' => 'string' ],
				'ability'       => [ 'type' => 'string' ],
				'resource_type' => [ 'type' => 'string' ],
				'resource_key'  => [ 'type' => 'string' ],
				'state'         => [ 'type' => 'string' ],
				'since'         => [ 'type' => 'string' ],
				'client'        => [ 'type' => 'string' ],
				'recipe'        => [
					'type'       => 'object',
					'properties' => [
						'type'      => [ 'type' => 'string' ],
						'available' => [ 'type' => 'boolean' ],
						'plan'      => [ 'type' => 'string' ],
					],
				],
				'probe'         => [ 'type' => [ 'object', 'null' ] ],
			],
		];
		return [
			'type'       => 'object',
			'properties' => [
				'ok'             => [ 'type' => 'boolean' ],
				'open_incidents' => [ 'type' => 'array', 'items' => $incident ],
				'unconfirmed'    => [ 'type' => 'array', 'items' => $incident ],
				'recent'         => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'incident_id' => [ 'type' => 'string' ],
							'ability'     => [ 'type' => 'string' ],
							'state'       => [ 'type' => 'string' ],
							'armed_at'    => [ 'type' => 'string' ],
						],
					],
				],
				'journal'        => [ 'type' => 'object' ],
				'helper'         => [
					'type'        => 'object',
					'description' => 'The rescue helper (a must-use file): state, and whether safe mode can start.',
					'properties'  => [
						'state'     => [ 'type' => 'string' ],
						'safe_mode' => [ 'type' => 'boolean' ],
					],
				],
			],
			'required'   => [ 'ok', 'open_incidents', 'unconfirmed', 'recent', 'journal' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::manage_options();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit_read(
			$args,
			function (): array {
				return $this->ok( RescueRollback::status() );
			}
		);
	}
}
