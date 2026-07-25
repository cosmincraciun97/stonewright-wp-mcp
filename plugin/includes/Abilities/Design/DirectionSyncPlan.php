<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Design;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Design\Direction\DesignDirectionService;
use Stonewright\WpMcp\Design\Direction\DirectionSyncTarget;
use Stonewright\WpMcp\Design\Direction\ElementorKitSyncPlanner;
use Stonewright\WpMcp\Design\Direction\ElementorKitWriter;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Ability: stonewright/design-direction-sync-plan
 *
 * The dry run for kit synchronization. It reads the stored direction and the
 * live Elementor kit and returns exactly what would change, what the kit has no
 * global for, and what values it cannot store. Nothing is written.
 *
 * The returned `base_hash` is the concurrency guard: the apply ability requires
 * it and refuses when the live kit no longer matches, so a plan a human reviewed
 * cannot be applied to a kit that moved in the meantime.
 *
 * @stonewright-status stable
 */
final class DirectionSyncPlan extends AbilityKernel {

	private DesignDirectionService $service;

	public function __construct( ?DesignDirectionService $service = null ) {
		$this->service = $service ?? new DesignDirectionService();
	}

	public function name(): string {
		return 'stonewright/design-direction-sync-plan';
	}

	public function label(): string {
		return __( 'Plan design direction sync', 'stonewright' );
	}

	public function description(): string {
		return __( 'Dry run: compares a design direction with the live Elementor kit and returns the operations, warnings, and blockers. Writes nothing.', 'stonewright' );
	}

	public function category(): string {
		return 'design';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'id'     => [
					'type'        => 'integer',
					'description' => 'Direction id. Either id or slug is required.',
				],
				'slug'   => [
					'type'        => 'string',
					'description' => 'Direction slug. Either id or slug is required.',
				],
				'kit_id' => [
					'type'        => 'integer',
					'description' => 'Elementor kit post id. Defaults to the active kit.',
				],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'             => [ 'type' => 'boolean' ],
				'id'             => [ 'type' => 'integer' ],
				'slug'           => [ 'type' => 'string' ],
				'kit_id'         => [ 'type' => 'integer' ],
				'contract_hash'  => [ 'type' => 'string' ],
				'base_hash'      => [ 'type' => 'string' ],
				'operations'     => [
					'type'  => 'array',
					'items' => [ 'type' => 'object' ],
				],
				'warnings'       => [
					'type'  => 'array',
					'items' => [ 'type' => 'object' ],
				],
				'blocked'        => [
					'type'  => 'array',
					'items' => [ 'type' => 'object' ],
				],
				'ready_to_apply' => [ 'type' => 'boolean' ],
			],
			'required'   => [ 'ok', 'id', 'kit_id', 'base_hash', 'operations', 'warnings', 'blocked', 'ready_to_apply' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::can_manage_design();
	}

	public function execute( array $args ): array|\WP_Error {
		$record = DirectionSyncTarget::resolve( $this->service, $args );
		if ( $record instanceof \WP_Error ) {
			return $record;
		}

		$kit_id = DirectionSyncTarget::kit_id( $args );
		if ( $kit_id instanceof \WP_Error ) {
			return $kit_id;
		}

		$contract = is_array( $record['contract'] ?? null ) ? $record['contract'] : [];
		$plan     = ElementorKitSyncPlanner::plan( $contract, ElementorKitWriter::read( $kit_id ) );
		if ( $plan instanceof \WP_Error ) {
			return $plan;
		}

		return $this->ok(
			[
				'id'             => (int) $record['id'],
				'slug'           => (string) $record['slug'],
				'kit_id'         => $kit_id,
				'contract_hash'  => (string) ( $record['contract_hash'] ?? '' ),
				'base_hash'      => (string) $plan['base_hash'],
				'operations'     => $plan['operations'],
				'warnings'       => $plan['warnings'],
				'blocked'        => $plan['blocked'],
				'ready_to_apply' => (bool) $plan['ready_to_apply'],
			]
		);
	}
}
