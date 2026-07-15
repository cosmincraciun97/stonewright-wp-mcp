<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Blueprints;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Blueprints\BlueprintStore;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Compact blueprint catalog (id, industry, hash — no full DesignSpec).
 *
 * @stonewright-status stable
 */
final class ListBlueprints extends AbilityKernel {

	public function name(): string {
		return 'stonewright/blueprint-list';
	}

	public function label(): string {
		return __( 'List blueprints', 'stonewright' );
	}

	public function description(): string {
		return __( 'Lists bundled Stonewright DesignSpec blueprints in compact form (name, industry, section ids, spec hash).', 'stonewright' );
	}

	public function category(): string {
		return 'design';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'industry' => [ 'type' => 'string', 'description' => 'Filter by industry slug.' ],
				'search'   => [ 'type' => 'string', 'description' => 'Case-insensitive search over id, name, description.' ],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'blueprints' => [ 'type' => 'array' ],
				'count'      => [ 'type' => 'integer' ],
			],
			'required'   => [ 'blueprints', 'count' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		$industry = isset( $args['industry'] ) ? sanitize_key( (string) $args['industry'] ) : '';
		$search   = isset( $args['search'] ) ? (string) $args['search'] : '';
		$list     = BlueprintStore::list( $industry, $search );

		return [
			'blueprints' => $list,
			'count'      => count( $list ),
		];
	}
}
