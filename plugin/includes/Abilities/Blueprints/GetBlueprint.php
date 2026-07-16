<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Blueprints;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Blueprints\BlueprintStore;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Returns a full blueprint including its DesignSpec payload.
 *
 * @stonewright-status stable
 */
final class GetBlueprint extends AbilityKernel {

	public function name(): string {
		return 'stonewright/blueprint-get';
	}

	public function label(): string {
		return __( 'Get blueprint', 'stonewright' );
	}

	public function description(): string {
		return __( 'Returns one bundled Stonewright blueprint including metadata and the full DesignSpec.', 'stonewright' );
	}

	public function category(): string {
		return 'design';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'blueprint_id' => [ 'type' => 'string', 'description' => 'Blueprint id (filename slug).' ],
				'id'           => [ 'type' => 'string', 'description' => 'Alias for blueprint_id.' ],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id'                      => [ 'type' => 'string' ],
				'name'                    => [ 'type' => 'string' ],
				'description'             => [ 'type' => 'string' ],
				'industry'                => [ 'type' => 'string' ],
				'palette'                 => [ 'type' => 'object' ],
				'fonts'                   => [ 'type' => 'object' ],
				'section_ids'             => [ 'type' => 'array' ],
				'spec'                    => [ 'type' => 'object' ],
				'version'                 => [ 'type' => [ 'string', 'integer' ] ],
				'page_type'               => [ 'type' => 'string' ],
				'required_content_facts'  => [ 'type' => 'array' ],
				'engine_compatibility'    => [ 'type' => [ 'object', 'array' ] ],
				'accessibility_intent'    => [ 'type' => [ 'string', 'object' ] ],
			],
			'required'   => [ 'id', 'name', 'spec' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		$id = (string) ( $args['blueprint_id'] ?? $args['id'] ?? '' );
		return BlueprintStore::get( $id );
	}
}
