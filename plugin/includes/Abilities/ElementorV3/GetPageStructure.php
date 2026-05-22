<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV3;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class GetPageStructure extends AbilityKernel {

	public function name(): string {
		return 'stonewright/elementor-v3-get-page-structure';
	}

	public function label(): string {
		return __( 'Get Elementor page structure', 'stonewright' );
	}

	public function description(): string {
		return __( 'Returns the full Elementor V3 element tree for a post.', 'stonewright' );
	}

	public function category(): string {
		return 'elementor';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'post_id' => [ 'type' => 'integer', 'minimum' => 1 ],
			],
			'required'             => [ 'post_id' ],
		];
	}

	public function output_schema(): array {
		return [ 'type' => 'object' ];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		$id = (int) ( $args['post_id'] ?? 0 );
		return Permissions::edit_post( $id );
	}

	public function execute( array $args ): array|\WP_Error {
		$post_id = (int) $args['post_id'];
		if ( ! get_post( $post_id ) ) {
			return $this->error( 'not_found', __( 'Post not found.', 'stonewright' ) );
		}

		$tree = ElementorData::read( $post_id );
		return [
			'post_id' => $post_id,
			'active'  => ElementorData::is_active( $post_id ),
			'tree'    => $tree,
			'count'   => count( ElementorData::flatten( $tree ) ),
		];
	}
}
