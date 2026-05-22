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
final class DuplicatePage extends AbilityKernel {

	public function name(): string {
		return 'stonewright/content-duplicate-page';
	}

	public function label(): string {
		return __( 'Duplicate page', 'stonewright' );
	}

	public function description(): string {
		return __( 'Creates a draft copy of a page or post including content, meta, and Elementor data.', 'stonewright' );
	}

	public function category(): string {
		return 'content';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'id'           => [ 'type' => 'integer', 'minimum' => 1 ],
				'title_suffix' => [ 'type' => 'string' ],
			],
			'required'             => [ 'id' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'new_id'       => [ 'type' => 'integer' ],
				'meta_skipped' => [
					'type'        => 'array',
					'description' => __( 'Meta keys refused by Permissions::can_edit_post_meta().', 'stonewright' ),
					'items'       => [ 'type' => 'string' ],
				],
			],
			'required'   => [ 'new_id' ],
		];
	}

	/**
	 * @stonewright-cap edit_post($args['id']) + create_posts_for($source_post_type)
	 */
	public function permission_callback( array $args ): bool|\WP_Error {
		$id = (int) ( $args['id'] ?? 0 );
		if ( $id <= 0 ) {
			return new \WP_Error(
				'stonewright_invalid_input',
				__( 'Invalid content ID.', 'stonewright' ),
				[ 'status' => 400 ]
			);
		}

		$post = get_post( $id );
		if ( ! $post ) {
			return new \WP_Error(
				'stonewright_not_found',
				__( 'Content not found.', 'stonewright' ),
				[ 'status' => 404 ]
			);
		}

		if ( ! Permissions::edit_post( $id ) ) {
			return new \WP_Error(
				'stonewright_forbidden',
				__( 'Insufficient capability to edit the source content.', 'stonewright' ),
				[ 'status' => 403 ]
			);
		}

		if ( ! Permissions::can_create_post_type( (string) $post->post_type ) ) {
			return new \WP_Error(
				'stonewright_forbidden',
				__( 'Insufficient capability to create content of this type.', 'stonewright' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$id   = (int) $args['id'];
				$post = get_post( $id );
				if ( ! $post ) {
					return $this->error( 'not_found', __( 'Page not found.', 'stonewright' ) );
				}

				$suffix = (string) ( $args['title_suffix'] ?? ' (copy)' );

				$new_id = wp_insert_post(
					[
						'post_title'   => $post->post_title . $suffix,
						'post_content' => $post->post_content,
						'post_excerpt' => $post->post_excerpt,
						'post_status'  => 'draft',
						'post_type'    => $post->post_type,
						'post_parent'  => $post->post_parent,
					],
					true
				);

				if ( is_wp_error( $new_id ) ) {
					return $new_id;
				}

				$meta_skipped = [];
				foreach ( get_post_meta( $id ) as $key => $values ) {
					if ( '_edit_lock' === $key || '_edit_last' === $key ) {
						continue;
					}
					if ( ! Permissions::can_edit_post_meta( (int) $new_id, $key ) ) {
						$meta_skipped[] = $key;
						continue;
					}
					foreach ( (array) $values as $value ) {
						add_post_meta( (int) $new_id, $key, maybe_unserialize( $value ) );
					}
				}

				return [ 'new_id' => (int) $new_id, 'meta_skipped' => $meta_skipped ];
			}
		);
	}
}
