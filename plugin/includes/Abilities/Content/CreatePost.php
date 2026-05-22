<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Content;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class CreatePost extends AbilityKernel {

	public function name(): string {
		return 'stonewright/content-create-post';
	}

	public function label(): string {
		return __( 'Create post', 'stonewright' );
	}

	public function description(): string {
		return __( 'Creates a post (default post type "post") with title, content, status, and optional terms.', 'stonewright' );
	}

	public function category(): string {
		return 'content';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'title'      => [ 'type' => 'string', 'maxLength' => 255 ],
				'content'    => [ 'type' => 'string' ],
				'excerpt'    => [ 'type' => 'string' ],
				'status'     => [ 'type' => 'string', 'enum' => [ 'draft', 'publish', 'private', 'pending', 'future' ], 'default' => 'draft' ],
				'post_type'  => [ 'type' => 'string', 'default' => 'post' ],
				'categories' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
				'tags'       => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'meta'       => [ 'type' => 'object' ],
			],
			'required'             => [ 'title' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id'           => [ 'type' => 'integer' ],
				'meta_skipped' => [
					'type'        => 'array',
					'description' => __( 'Meta keys refused by Permissions::can_edit_post_meta().', 'stonewright' ),
					'items'       => [ 'type' => 'string' ],
				],
			],
			'required'   => [ 'id' ],
		];
	}

	/**
	 * @stonewright-cap create_posts_for($args['post_type']) + publish_posts when status in {publish, private, future}
	 */
	public function permission_callback( array $args ): bool|\WP_Error {
		$post_type = sanitize_key( (string) ( $args['post_type'] ?? 'post' ) );

		if ( ! Permissions::can_create_post_type( $post_type ) ) {
			return new \WP_Error(
				'stonewright_forbidden',
				__( 'Insufficient capability to create posts of this type.', 'stonewright' ),
				[ 'status' => 403 ]
			);
		}

		$status     = (string) ( $args['status'] ?? 'draft' );
		$publish_cap = Permissions::publish_cap_for_status( $post_type, $status );
		if ( null !== $publish_cap && ! current_user_can( $publish_cap ) ) {
			return new \WP_Error(
				'stonewright_forbidden',
				__( 'Insufficient capability to publish posts of this type.', 'stonewright' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$id = wp_insert_post(
					[
						'post_title'   => sanitize_text_field( (string) $args['title'] ),
						'post_content' => wp_kses_post( (string) ( $args['content'] ?? '' ) ),
						'post_excerpt' => sanitize_text_field( (string) ( $args['excerpt'] ?? '' ) ),
						'post_status'  => (string) ( $args['status'] ?? 'draft' ),
						'post_type'    => sanitize_key( (string) ( $args['post_type'] ?? 'post' ) ),
					],
					true
				);

				if ( is_wp_error( $id ) ) {
					return $id;
				}

				if ( ! empty( $args['categories'] ) ) {
					wp_set_post_categories( (int) $id, array_map( 'absint', (array) $args['categories'] ) );
				}
				if ( ! empty( $args['tags'] ) ) {
					wp_set_post_tags( (int) $id, array_map( 'sanitize_text_field', (array) $args['tags'] ) );
				}

				$meta_skipped = [];
				if ( ! empty( $args['meta'] ) && is_array( $args['meta'] ) ) {
					foreach ( $args['meta'] as $key => $value ) {
						$key = sanitize_key( (string) $key );
						if ( '' === $key ) {
							continue;
						}
						if ( ! Permissions::can_edit_post_meta( (int) $id, $key ) ) {
							$meta_skipped[] = $key;
							continue;
						}
						update_post_meta( (int) $id, $key, $value );
					}
				}

				return [ 'id' => (int) $id, 'meta_skipped' => $meta_skipped ];
			}
		);
	}
}
