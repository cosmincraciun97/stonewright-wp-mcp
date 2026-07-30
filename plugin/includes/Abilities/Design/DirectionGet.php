<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Design;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Design\Direction\DesignDirectionService;
use Stonewright\WpMcp\Design\Direction\DirectionContract;
use Stonewright\WpMcp\Design\Direction\DirectionSummary;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Ability: stonewright/design-direction-get
 *
 * Returns one stored direction: its compact summary plus the validated
 * contract every renderer and verification step reads. Version history is
 * opt-in and never carries the stored contracts — the revision hash is enough
 * to tell revisions apart, and restoring one is a separate write ability.
 *
 * @stonewright-status stable
 */
final class DirectionGet extends AbilityKernel {

	private DesignDirectionService $service;

	public function __construct( ?DesignDirectionService $service = null ) {
		$this->service = $service ?? new DesignDirectionService();
	}

	public function name(): string {
		return 'stonewright/design-direction-get';
	}

	public function label(): string {
		return __( 'Get design direction', 'stonewright' );
	}

	public function description(): string {
		return __( 'Returns one design direction contract by id or slug, with optional revision history. Read-only.', 'stonewright' );
	}

	public function category(): string {
		return 'design';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'id'               => [
					'type'        => 'integer',
					'description' => 'Direction id. Either id or slug is required.',
				],
				'slug'             => [
					'type'        => 'string',
					'description' => 'Direction slug. Either id or slug is required.',
				],
				'include_versions' => [
					'type'        => 'boolean',
					'description' => 'Include revision history without stored contracts. Defaults to false.',
				],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'        => [ 'type' => 'boolean' ],
				'direction' => [ 'type' => 'object' ],
				'versions'  => [
					'type'  => 'array',
					'items' => [ 'type' => 'object' ],
				],
			],
			'required'   => [ 'ok', 'direction', 'versions' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::read();
	}

	public function execute( array $args ): array|\WP_Error {
		$id   = isset( $args['id'] ) ? (int) $args['id'] : 0;
		$slug = isset( $args['slug'] ) && is_string( $args['slug'] ) ? $args['slug'] : '';

		if ( $id < 1 && '' === $slug ) {
			return new \WP_Error(
				DirectionContract::ERROR_CODE,
				__( 'A design direction id or slug is required.', 'stonewright' ),
				[ 'status' => 400 ]
			);
		}

		$record = $id > 0
			? $this->service->get( $id )
			: $this->service->find_by_slug( $slug );

		if ( null === $record ) {
			return new \WP_Error(
				DesignDirectionService::NOT_FOUND_CODE,
				__( 'That design direction does not exist.', 'stonewright' ),
				[
					'status'       => 404,
					'direction_id' => $id,
					'slug'         => $slug,
				]
			);
		}

		$active_id = (int) get_option( DesignDirectionService::ACTIVE_OPTION, 0 );
		$direction = DirectionSummary::row( $record, $active_id );

		$direction['contract']    = is_array( $record['contract'] ?? null ) ? $record['contract'] : [];
		$direction['source_refs'] = is_array( $record['source_refs'] ?? null ) ? $record['source_refs'] : [];

		$versions = true === ( $args['include_versions'] ?? false )
			? DirectionSummary::history( $this->service->versions( (int) $direction['id'] ) )
			: [];

		return $this->ok(
			[
				'direction' => $direction,
				'versions'  => $versions,
			]
		);
	}
}
