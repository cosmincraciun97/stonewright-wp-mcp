<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Gutenberg;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class GetBlockSchema extends AbilityKernel {

	public function name(): string {
		return 'stonewright/blocks-get-schema';
	}

	public function label(): string {
		return __( 'Get block schema', 'stonewright' );
	}

	public function description(): string {
		return __( 'Returns the full block.json-style schema for a registered block type.', 'stonewright' );
	}

	public function category(): string {
		return 'gutenberg';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'name' => [ 'type' => 'string' ],
			],
			'required'             => [ 'name' ],
		];
	}

	public function output_schema(): array {
		return [ 'type' => 'object' ];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		$name     = (string) $args['name'];
		$registry = \WP_Block_Type_Registry::get_instance();
		$type     = $registry->get_registered( $name );

		if ( null === $type ) {
			return $this->error( 'not_registered', sprintf( __( 'Block "%s" is not registered.', 'stonewright' ), $name ) );
		}

		return [
			'name'             => $name,
			'title'            => isset( $type->title ) ? (string) $type->title : '',
			'category'         => isset( $type->category ) ? (string) $type->category : '',
			'description'      => isset( $type->description ) ? (string) $type->description : '',
			'keywords'         => isset( $type->keywords ) ? (array) $type->keywords : [],
			'parent'           => isset( $type->parent ) ? (array) $type->parent : [],
			'ancestor'         => isset( $type->ancestor ) ? (array) $type->ancestor : [],
			'attributes'       => isset( $type->attributes ) ? (array) $type->attributes : [],
			'provides_context' => isset( $type->provides_context ) ? (array) $type->provides_context : [],
			'uses_context'     => isset( $type->uses_context ) ? (array) $type->uses_context : [],
			'supports'         => isset( $type->supports ) ? (array) $type->supports : [],
			'example'          => isset( $type->example ) ? (array) $type->example : [],
			'styles'           => isset( $type->styles ) ? (array) $type->styles : [],
			'variations'       => isset( $type->variations ) ? (array) $type->variations : [],
			'is_dynamic'       => is_callable( $type->render_callback ?? null ),
			'api_version'      => isset( $type->api_version ) ? (int) $type->api_version : 2,
		];
	}
}
