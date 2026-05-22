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
final class CreatePattern extends AbilityKernel {

	public function name(): string {
		return 'stonewright/patterns-create';
	}

	public function label(): string {
		return __( 'Create synced pattern', 'stonewright' );
	}

	public function description(): string {
		return __( 'Creates a synced pattern (wp_block CPT) from block content.', 'stonewright' );
	}

	public function category(): string {
		return 'patterns';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'title'   => [ 'type' => 'string', 'maxLength' => 255 ],
				'content' => [ 'type' => 'string' ],
				'slug'    => [ 'type' => 'string', 'maxLength' => 200 ],
				'status'  => [ 'type' => 'string', 'enum' => [ 'publish', 'draft', 'private' ], 'default' => 'publish' ],
			],
			'required'             => [ 'title', 'content' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id'   => [ 'type' => 'integer' ],
				'slug' => [ 'type' => 'string' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts() && Permissions::edit_theme_options();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$id = wp_insert_post(
					[
						'post_title'   => sanitize_text_field( (string) $args['title'] ),
						'post_name'    => isset( $args['slug'] ) ? sanitize_title( (string) $args['slug'] ) : '',
						'post_content' => (string) $args['content'],
						'post_status'  => (string) ( $args['status'] ?? 'publish' ),
						'post_type'    => 'wp_block',
					],
					true
				);

				if ( is_wp_error( $id ) ) {
					return $id;
				}

				$post = get_post( (int) $id );
				return [
					'id'   => (int) $id,
					'slug' => $post ? (string) $post->post_name : '',
				];
			}
		);
	}
}
