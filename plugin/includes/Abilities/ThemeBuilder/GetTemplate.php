<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ThemeBuilder;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\ElementorData;
use Stonewright\WpMcp\ThemeBuilder\TemplateStore;

/**
 * Read a Theme Builder template — id, title, type, conditions, and the
 * parsed `_elementor_data` tree.
 *
 * Use this before SetConditions if you only want to edit one rule rather
 * than replace the whole array, or before the V3/V4 element-edit abilities
 * to position writes against the existing structure.
 *
 * @stonewright-status stable
 */
final class GetTemplate extends AbilityKernel {

	public function name(): string {
		return 'stonewright/theme-builder-get-template';
	}

	public function label(): string {
		return __( 'Theme Builder: Get template', 'stonewright' );
	}

	public function description(): string {
		return __( 'Reads a Theme Builder template (data tree + conditions + type).', 'stonewright' );
	}

	public function category(): string {
		return 'theme-builder';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'template_id' => [ 'type' => 'integer', 'minimum' => 1 ],
			],
			'required'             => [ 'template_id' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'template_id'   => [ 'type' => 'integer' ],
				'title'         => [ 'type' => 'string' ],
				'template_type' => [ 'type' => 'string' ],
				'conditions'    => [ 'type' => 'array' ],
				'tree'          => [ 'type' => 'array' ],
			],
			'required'   => [ 'template_id', 'title', 'template_type', 'conditions', 'tree' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$id   = (int) $args['template_id'];
				$post = get_post( $id );
				if ( ! $post || 'elementor_library' !== $post->post_type ) {
					return $this->error(
						'not_a_template',
						__( 'Post is not an elementor_library template.', 'stonewright' )
					);
				}
				$conditions = get_post_meta( $id, '_elementor_conditions', true );
				return [
					'template_id'   => $id,
					'title'         => (string) get_the_title( $id ),
					'template_type' => TemplateStore::get_type( $id ),
					'conditions'    => is_array( $conditions ) ? $conditions : [],
					'tree'          => ElementorData::read( $id ),
				];
			}
		);
	}
}
