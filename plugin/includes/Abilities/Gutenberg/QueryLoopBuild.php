<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Gutenberg;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Typed helper that builds a core/query + core/post-template block spec.
 *
 * Does not invent unregistered blocks. Output is a block spec suitable for
 * insert / batch-mutate / finalizer paths.
 *
 * @stonewright-status stable
 */
final class QueryLoopBuild extends AbilityKernel {

	private const INNER_CANDIDATES = [
		'core/post-title',
		'core/post-featured-image',
		'core/post-excerpt',
		'core/post-date',
	];

	public function name(): string {
		return 'stonewright/blocks-query-loop-build';
	}

	public function label(): string {
		return __( 'Build Query Loop block spec', 'stonewright' );
	}

	public function description(): string {
		return __( 'Builds a typed core/query + core/post-template block spec from post type, taxonomy, count, order, and inherit. Refuses unregistered blocks.', 'stonewright' );
	}

	public function category(): string {
		return 'gutenberg';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'post_type' => [
					'type'        => 'string',
					'default'     => 'post',
					'description' => 'Registered post type to query.',
				],
				'taxonomy'  => [
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => [
						'taxonomy' => [ 'type' => 'string' ],
						'terms'    => [
							'type'  => 'array',
							'items' => [ 'type' => 'string' ],
						],
					],
				],
				'count'     => [
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 100,
					'default' => 3,
				],
				'order'     => [
					'type'    => 'string',
					'enum'    => [ 'asc', 'desc' ],
					'default' => 'desc',
				],
				'orderby'   => [
					'type'    => 'string',
					'enum'    => [ 'date', 'title', 'modified', 'menu_order' ],
					'default' => 'date',
				],
				'inherit'   => [
					'type'    => 'boolean',
					'default' => false,
				],
				'post_id'   => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'Optional. When set, insert the spec into this post via blocks-insert.',
				],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'    => [ 'type' => 'boolean' ],
				'block' => [ 'type' => 'object' ],
			],
			'required'   => [ 'ok', 'block' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		if ( $post_id > 0 ) {
			return Permissions::edit_post( $post_id );
		}
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$post_type = sanitize_key( (string) ( $args['post_type'] ?? 'post' ) );
				if ( '' === $post_type ) {
					$post_type = 'post';
				}
				if ( ! get_post_type_object( $post_type ) ) {
					return $this->error( 'post_type_unregistered', __( 'Post type is not registered.', 'stonewright' ) );
				}

				if ( ! $this->block_is_registered( 'core/query' ) ) {
					return $this->error( 'block_unregistered', __( 'core/query is not registered on this site.', 'stonewright' ) );
				}
				if ( ! $this->block_is_registered( 'core/post-template' ) ) {
					return $this->error( 'block_unregistered', __( 'core/post-template is not registered on this site.', 'stonewright' ) );
				}

				$count   = max( 1, min( 100, (int) ( $args['count'] ?? 3 ) ) );
				$order   = strtolower( (string) ( $args['order'] ?? 'desc' ) );
				$order   = in_array( $order, [ 'asc', 'desc' ], true ) ? $order : 'desc';
				$orderby = (string) ( $args['orderby'] ?? 'date' );
				$orderby = in_array( $orderby, [ 'date', 'title', 'modified', 'menu_order' ], true ) ? $orderby : 'date';
				$inherit = (bool) ( $args['inherit'] ?? false );

				$query = [
					'perPage'  => $count,
					'pages'    => 0,
					'offset'   => 0,
					'postType' => $post_type,
					'order'    => $order,
					'orderBy'  => $orderby,
					'inherit'  => $inherit,
				];

				$taxonomy = isset( $args['taxonomy'] ) && is_array( $args['taxonomy'] ) ? $args['taxonomy'] : [];
				$tax_name = sanitize_key( (string) ( $taxonomy['taxonomy'] ?? '' ) );
				$terms    = isset( $taxonomy['terms'] ) && is_array( $taxonomy['terms'] )
					? array_values( array_filter( array_map( 'sanitize_title', $taxonomy['terms'] ) ) )
					: [];
				if ( '' !== $tax_name && [] !== $terms ) {
					$query['taxQuery'] = [ $tax_name => $terms ];
				}

				$inner = [];
				foreach ( self::INNER_CANDIDATES as $candidate ) {
					if ( ! $this->block_is_registered( $candidate ) ) {
						continue;
					}
					$inner[] = [
						'name'        => $candidate,
						'attrs'       => [],
						'innerBlocks' => [],
					];
				}

				$spec = [
					'name'        => 'core/query',
					'attrs'       => [ 'query' => $query ],
					'innerBlocks' => [
						[
							'name'        => 'core/post-template',
							'attrs'       => [],
							'innerBlocks' => $inner,
						],
					],
				];

				return [
					'ok'    => true,
					'block' => $spec,
				];
			}
		);
	}

	private function block_is_registered( string $name ): bool {
		if ( ! class_exists( \WP_Block_Type_Registry::class ) ) {
			return false;
		}
		$registry = \WP_Block_Type_Registry::get_instance();
		if ( method_exists( $registry, 'is_registered' ) ) {
			return (bool) $registry->is_registered( $name );
		}
		return null !== $registry->get_registered( $name );
	}
}
