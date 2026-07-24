<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Design;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Design\Direction\DesignDirectionRepository;
use Stonewright\WpMcp\Design\Direction\DesignDirectionService;
use Stonewright\WpMcp\Design\Direction\DirectionContract;
use Stonewright\WpMcp\Design\Direction\DirectionSummary;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Ability: stonewright/design-direction-list
 *
 * Read-only inventory of stored design directions. Rows are compact on
 * purpose: an agent deciding what to work on needs the slug, status, readiness
 * and contract hash, not the whole contract. Fetch one direction with
 * stonewright/design-direction-get when the contract itself is needed.
 *
 * @stonewright-status stable
 */
final class DirectionList extends AbilityKernel {

	private DesignDirectionService $service;

	public function __construct( ?DesignDirectionService $service = null ) {
		$this->service = $service ?? new DesignDirectionService();
	}

	public function name(): string {
		return 'stonewright/design-direction-list';
	}

	public function label(): string {
		return __( 'List design directions', 'stonewright' );
	}

	public function description(): string {
		return __( 'Lists stored design directions with status, revision, readiness, and contract hash. Reports which direction is active. Read-only.', 'stonewright' );
	}

	public function category(): string {
		return 'design';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'status' => [
					'type'        => 'string',
					'enum'        => DesignDirectionRepository::STATUSES,
					'description' => 'Optional status filter.',
				],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'         => [ 'type' => 'boolean' ],
				'count'      => [ 'type' => 'integer' ],
				'active_id'  => [ 'type' => 'integer' ],
				'directions' => [
					'type'  => 'array',
					'items' => [ 'type' => 'object' ],
				],
			],
			'required'   => [ 'ok', 'count', 'active_id', 'directions' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::read();
	}

	public function execute( array $args ): array|\WP_Error {
		$filters = [];

		if ( isset( $args['status'] ) ) {
			$status = is_string( $args['status'] ) ? $args['status'] : '';
			if ( ! in_array( $status, DesignDirectionRepository::STATUSES, true ) ) {
				return new \WP_Error(
					DirectionContract::ERROR_CODE,
					__( 'Unsupported design direction status filter.', 'stonewright' ),
					[
						'status'            => 400,
						'statuses_accepted' => DesignDirectionRepository::STATUSES,
					]
				);
			}

			$filters['status'] = $status;
		}

		$active_id = (int) get_option( DesignDirectionService::ACTIVE_OPTION, 0 );
		$rows      = [];

		foreach ( $this->service->list( $filters ) as $record ) {
			$rows[] = DirectionSummary::row( $record, $active_id );
		}

		return $this->ok(
			[
				'count'      => count( $rows ),
				'active_id'  => $active_id,
				'directions' => $rows,
			]
		);
	}
}
