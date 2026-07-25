<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Design;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Design\Direction\DesignDirectionService;
use Stonewright\WpMcp\Design\Direction\DirectionSyncTarget;
use Stonewright\WpMcp\Design\Direction\ElementorKitSyncPlanner;
use Stonewright\WpMcp\Design\Direction\ElementorKitWriter;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Ability: stonewright/design-direction-sync-apply
 *
 * Writes a design direction into the live Elementor kit globals. This is the
 * only place a stored contract changes what the site actually renders, so it
 * carries the full destructive envelope:
 *
 * - The registry forces the task context token.
 * - Production-safe mode requires a confirmation token bound to this direction
 *   id *and* the reviewed `base_hash`, so a token cannot be replayed against a
 *   different plan.
 * - The caller must pass the dry run's `base_hash`. The kit is re-read and
 *   re-planned here; a kit that moved since the dry run is refused as stale
 *   rather than overwritten.
 * - A direction that is not sync-ready, or whose plan contains any value the kit
 *   cannot store, is refused outright — sync never coerces a value to make it
 *   fit.
 * - The typed writer snapshots the kit before mutating it and merges only the
 *   planned entry properties.
 * - After writing, the kit is read back and re-planned. Any operation still
 *   outstanding means the write did not take effect, and the receipt reports
 *   failure instead of success.
 *
 * @stonewright-status stable
 */
final class DirectionSyncApply extends AbilityKernel {

	use ConfirmationGuard;

	private DesignDirectionService $service;

	public function __construct( ?DesignDirectionService $service = null ) {
		$this->service = $service ?? new DesignDirectionService();
	}

	public function name(): string {
		return 'stonewright/design-direction-sync-apply';
	}

	public function label(): string {
		return __( 'Apply design direction to Elementor kit', 'stonewright' );
	}

	public function description(): string {
		return __( 'Writes a sync-ready design direction into the live Elementor kit globals. Requires the dry run base hash, snapshots the kit, and verifies the result.', 'stonewright' );
	}

	public function category(): string {
		return 'design';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'id'                 => [
					'type'        => 'integer',
					'description' => 'Direction id. Either id or slug is required.',
				],
				'slug'               => [
					'type'        => 'string',
					'description' => 'Direction slug. Either id or slug is required.',
				],
				'kit_id'             => [
					'type'        => 'integer',
					'description' => 'Elementor kit post id. Defaults to the active kit.',
				],
				'base_hash'         => [
					'type'        => 'string',
					'description' => 'The base_hash returned by stonewright/design-direction-sync-plan. The apply is refused when the live kit no longer matches it.',
				],
				'confirmation_token' => [
					'type'        => 'string',
					'description' => 'Required in production-safe mode. Must be issued for this direction id and base_hash.',
				],
			],
			'required'             => [ 'base_hash' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'                  => [ 'type' => 'boolean' ],
				'id'                  => [ 'type' => 'integer' ],
				'slug'                => [ 'type' => 'string' ],
				'kit_id'              => [ 'type' => 'integer' ],
				'applied'             => [ 'type' => 'integer' ],
				'operations'          => [
					'type'  => 'array',
					'items' => [ 'type' => 'object' ],
				],
				'warnings'            => [
					'type'  => 'array',
					'items' => [ 'type' => 'object' ],
				],
				'snapshot_id'         => [ 'type' => 'string' ],
				'before_sha256'       => [ 'type' => 'string' ],
				'after_sha256'        => [ 'type' => 'string' ],
				'operation_class'     => [ 'type' => 'string' ],
				'resource_type'       => [ 'type' => 'string' ],
				'verification_status' => [ 'type' => 'string' ],
				'effect_verified'     => [ 'type' => 'boolean' ],
			],
			'required'   => [ 'ok', 'id', 'kit_id', 'applied', 'operations', 'warnings', 'snapshot_id', 'before_sha256', 'after_sha256', 'effect_verified' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::can_manage_design();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ): array|\WP_Error {
				$base_hash = isset( $args['base_hash'] ) && is_string( $args['base_hash'] ) ? trim( $args['base_hash'] ) : '';

				if ( '' === $base_hash ) {
					return new \WP_Error(
						ElementorKitSyncPlanner::ERROR_CODE,
						__( 'The base_hash from a sync dry run is required.', 'stonewright' ),
						[ 'status' => 400 ]
					);
				}

				$record = DirectionSyncTarget::resolve( $this->service, $args );
				if ( $record instanceof \WP_Error ) {
					return $record;
				}

				$id     = (int) $record['id'];
				$kit_id = DirectionSyncTarget::kit_id( $args );
				if ( $kit_id instanceof \WP_Error ) {
					return $kit_id;
				}

				$blocked = $this->confirmation_token_error(
					$args,
					[
						'id'        => $id,
						'base_hash' => $base_hash,
					]
				);
				if ( null !== $blocked ) {
					return $blocked;
				}

				$unsnapshotable = ElementorKitWriter::snapshot_blocker( $kit_id );
				if ( null !== $unsnapshotable ) {
					return $unsnapshotable;
				}

				$contract = is_array( $record['contract'] ?? null ) ? $record['contract'] : [];
				$plan     = ElementorKitSyncPlanner::plan( $contract, ElementorKitWriter::read( $kit_id ) );
				if ( $plan instanceof \WP_Error ) {
					return $plan;
				}

				if ( (string) $plan['base_hash'] !== $base_hash ) {
					return new \WP_Error(
						ElementorKitSyncPlanner::STALE_CODE,
						__( 'The Elementor kit changed since the dry run. Re-run the sync plan and review it again.', 'stonewright' ),
						[
							'status'        => 409,
							'kit_id'        => $kit_id,
							'expected_hash' => $base_hash,
							'actual_hash'   => (string) $plan['base_hash'],
						]
					);
				}

				if ( [] !== $plan['blocked'] ) {
					return new \WP_Error(
						ElementorKitSyncPlanner::BLOCKED_CODE,
						__( 'This direction holds values the Elementor kit cannot store. Fix them in the contract instead of synchronizing.', 'stonewright' ),
						[
							'status'  => 422,
							'kit_id'  => $kit_id,
							'blocked' => $plan['blocked'],
						]
					);
				}

				if ( true !== ( $contract['readiness']['sync_ready'] ?? false ) ) {
					return new \WP_Error(
						DesignDirectionService::NOT_READY_CODE,
						__( 'This design direction is not marked sync_ready.', 'stonewright' ),
						[
							'status'       => 409,
							'direction_id' => $id,
						]
					);
				}

				$write = ElementorKitWriter::apply( $kit_id, $plan['operations'] );
				if ( $write instanceof \WP_Error ) {
					return $write;
				}

				// Read the kit back: an outstanding operation means the write did not land.
				$after = ElementorKitSyncPlanner::plan( $contract, ElementorKitWriter::read( $kit_id ) );
				if ( $after instanceof \WP_Error ) {
					return $after;
				}

				if ( [] !== $after['operations'] ) {
					return new \WP_Error(
						'stonewright_direction_verification_failed',
						__( 'The Elementor kit does not match the design direction after the write.', 'stonewright' ),
						[
							'status'              => 500,
							'kit_id'              => $kit_id,
							'direction_id'        => $id,
							'outstanding'         => $after['operations'],
							'verification_status' => 'failed',
						]
					);
				}

				return $this->ok(
					[
						'id'                  => $id,
						'slug'                => (string) $record['slug'],
						'kit_id'              => $kit_id,
						'applied'             => (int) $write['applied'],
						'operations'          => $plan['operations'],
						'warnings'            => $plan['warnings'],
						'snapshot_id'         => (string) $write['snapshot_id'],
						'before_sha256'       => $base_hash,
						'after_sha256'        => (string) $after['base_hash'],
						'operation_class'     => 'design_direction.sync_apply',
						'resource_type'       => 'elementor_kit',
						'verification_status' => 'verified',
						'effect_verified'     => true,
					]
				);
			}
		);
	}
}
