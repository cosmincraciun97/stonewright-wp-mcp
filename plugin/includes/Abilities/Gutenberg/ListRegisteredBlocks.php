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
final class ListRegisteredBlocks extends AbilityKernel {

	public function name(): string {
		return 'stonewright/blocks-list-registered';
	}

	public function label(): string {
		return __( 'List registered blocks', 'stonewright' );
	}

	public function description(): string {
		return __( 'Lists every block type registered with the WP_Block_Type_Registry.', 'stonewright' );
	}

	public function category(): string {
		return 'gutenberg';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'namespace' => [ 'type' => 'string' ],
				'search'    => [ 'type' => 'string' ],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'blocks' => [ 'type' => 'array' ],
			],
			'required'   => [ 'blocks' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		$ns     = isset( $args['namespace'] ) ? (string) $args['namespace'] : '';
		$search = isset( $args['search'] ) ? mb_strtolower( (string) $args['search'] ) : '';

		$registry = \WP_Block_Type_Registry::get_instance();
		$blocks   = [];

		foreach ( $registry->get_all_registered() as $name => $type ) {
			if ( '' !== $ns && 0 !== strpos( $name, $ns . '/' ) ) {
				continue;
			}
			$title = isset( $type->title ) ? (string) $type->title : '';
			if ( '' !== $search && false === stripos( $name . ' ' . $title, $search ) ) {
				continue;
			}

			$blocks[] = [
				'name'        => $name,
				'title'       => $title,
				'category'    => isset( $type->category ) ? (string) $type->category : '',
				'description' => isset( $type->description ) ? (string) $type->description : '',
				'icon'        => isset( $type->icon ) ? (string) $type->icon : '',
				'keywords'    => isset( $type->keywords ) ? (array) $type->keywords : [],
				'supports'    => isset( $type->supports ) ? (array) $type->supports : [],
				'example'     => isset( $type->example ) ? (array) $type->example : [],
				'is_dynamic'  => is_callable( $type->render_callback ?? null ),
			];
		}

		return [ 'blocks' => $blocks ];
	}
}
