<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Patterns;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class ListPatterns extends AbilityKernel {

	public function name(): string {
		return 'stonewright/patterns-list';
	}

	public function label(): string {
		return __( 'List patterns', 'stonewright' );
	}

	public function description(): string {
		return __( 'Lists registered block patterns plus user-defined synced patterns (wp_block CPT).', 'stonewright' );
	}

	public function category(): string {
		return 'patterns';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'include_user' => [ 'type' => 'boolean', 'default' => true ],
				'search'       => [ 'type' => 'string' ],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'registered' => [ 'type' => 'array' ],
				'user'       => [ 'type' => 'array' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		$search       = isset( $args['search'] ) ? mb_strtolower( (string) $args['search'] ) : '';
		$include_user = ! isset( $args['include_user'] ) || (bool) $args['include_user'];

		$registry   = \WP_Block_Patterns_Registry::get_instance();
		$registered = [];
		foreach ( $registry->get_all_registered() as $pattern ) {
			$title = (string) ( $pattern['title'] ?? '' );
			$name  = (string) ( $pattern['name'] ?? '' );
			if ( '' !== $search && false === stripos( $name . ' ' . $title, $search ) ) {
				continue;
			}
			$registered[] = [
				'name'        => $name,
				'title'       => $title,
				'description' => (string) ( $pattern['description'] ?? '' ),
				'categories'  => (array) ( $pattern['categories'] ?? [] ),
				'keywords'    => (array) ( $pattern['keywords'] ?? [] ),
				'viewport'    => (int) ( $pattern['viewportWidth'] ?? 0 ),
			];
		}

		$user = [];
		if ( $include_user ) {
			$posts = get_posts(
				[
					'post_type'      => 'wp_block',
					'posts_per_page' => 100,
					's'              => $search,
					'post_status'    => 'publish',
				]
			);
			foreach ( $posts as $post ) {
				$user[] = [
					'id'    => (int) $post->ID,
					'title' => $post->post_title,
					'slug'  => $post->post_name,
				];
			}
		}

		return [
			'registered' => $registered,
			'user'       => $user,
		];
	}
}
