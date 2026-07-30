<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Site;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Compact inventory of registered post types with status counts.
 *
 * @stonewright-status stable
 */
final class ContentInventory extends AbilityKernel {

	public function name(): string {
		return 'stonewright/content-inventory';
	}

	public function label(): string {
		return __( 'Content inventory', 'stonewright' );
	}

	public function description(): string {
		return __( 'Compact inventory of post types with publish/draft/trash counts for generalist workflows.', 'stonewright' );
	}

	public function category(): string {
		return 'site';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'public_only' => [
					'type'        => 'boolean',
					'default'     => true,
					'description' => 'When true (default), only public post types are included.',
				],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'types' => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'slug'      => [ 'type' => 'string' ],
							'label'     => [ 'type' => 'string' ],
							'public'    => [ 'type' => 'boolean' ],
							'rest_base' => [ 'type' => 'string' ],
							'counts'    => [
								'type'       => 'object',
								'properties' => [
									'publish' => [ 'type' => 'integer' ],
									'draft'   => [ 'type' => 'integer' ],
									'trash'   => [ 'type' => 'integer' ],
									'total'   => [ 'type' => 'integer' ],
								],
							],
						],
					],
				],
				'total_types' => [ 'type' => 'integer' ],
			],
			'required'   => [ 'types', 'total_types' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array {
		$public_only = array_key_exists( 'public_only', $args ) ? (bool) $args['public_only'] : true;
		$types       = function_exists( 'get_post_types' )
			? get_post_types( $public_only ? [ 'public' => true ] : [], 'objects' )
			: [];

		$items = [];
		foreach ( (array) $types as $obj ) {
			if ( ! $obj instanceof \WP_Post_Type ) {
				continue;
			}
			$slug = (string) $obj->name;
			if ( '' === $slug || 'attachment' === $slug ) {
				continue;
			}

			$counts_obj = function_exists( 'wp_count_posts' ) ? wp_count_posts( $slug ) : null;
			$publish    = is_object( $counts_obj ) ? (int) ( $counts_obj->publish ?? 0 ) : 0;
			$draft      = is_object( $counts_obj )
				? (int) ( $counts_obj->draft ?? 0 ) + (int) ( $counts_obj->pending ?? 0 )
				: 0;
			$trash      = is_object( $counts_obj ) ? (int) ( $counts_obj->trash ?? 0 ) : 0;
			$rest_base  = is_string( $obj->rest_base ) ? $obj->rest_base : $slug;

			$items[] = [
				'slug'      => $slug,
				'label'     => (string) $obj->label,
				'public'    => (bool) $obj->public,
				'rest_base' => $rest_base,
				'counts'    => [
					'publish' => $publish,
					'draft'   => $draft,
					'trash'   => $trash,
					'total'   => $publish + $draft + $trash,
				],
			];
		}

		usort(
			$items,
			static function ( array $a, array $b ): int {
				return strcasecmp( $a['slug'], $b['slug'] );
			}
		);

		return [
			'types'       => $items,
			'total_types' => count( $items ),
		];
	}
}
