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
final class CreatePage extends AbilityKernel {

	public function name(): string {
		return 'stonewright/content-create-page';
	}

	public function label(): string {
		return __( 'Create page', 'stonewright' );
	}

	public function description(): string {
		return __( 'Creates a WordPress page from title, status, and optional block / Elementor content.', 'stonewright' );
	}

	public function category(): string {
		return 'content';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'title'    => [ 'type' => 'string', 'maxLength' => 255 ],
				'content'  => [ 'type' => 'string' ],
				'excerpt'  => [ 'type' => 'string' ],
				'status'   => [ 'type' => 'string', 'enum' => [ 'draft', 'publish', 'private', 'pending', 'future' ], 'default' => 'draft' ],
				'parent'   => [ 'type' => 'integer', 'minimum' => 0, 'default' => 0 ],
				'template' => [ 'type' => 'string' ],
				'slug'     => [ 'type' => 'string' ],
				'meta'     => [ 'type' => 'object' ],
			],
			'required'             => [ 'title' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id'           => [ 'type' => 'integer' ],
				'edit'         => [ 'type' => 'string' ],
				'preview'      => [ 'type' => 'string' ],
				'status'       => [ 'type' => 'string' ],
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
	 * @stonewright-cap create_posts_for('page') + publish_posts when status in {publish, private, future}
	 */
	public function permission_callback( array $args ): bool|\WP_Error {
		if ( ! Permissions::can_create_post_type( 'page' ) ) {
			return new \WP_Error(
				'stonewright_forbidden',
				__( 'Insufficient capability to create pages.', 'stonewright' ),
				[ 'status' => 403 ]
			);
		}

		$status      = (string) ( $args['status'] ?? 'draft' );
		$publish_cap = Permissions::publish_cap_for_status( 'page', $status );
		if ( null !== $publish_cap && ! current_user_can( $publish_cap ) ) {
			return new \WP_Error(
				'stonewright_forbidden',
				__( 'Insufficient capability to publish pages.', 'stonewright' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$payload = [
					'post_title'   => sanitize_text_field( (string) $args['title'] ),
					'post_content' => wp_kses_post( (string) ( $args['content'] ?? '' ) ),
					'post_excerpt' => sanitize_text_field( (string) ( $args['excerpt'] ?? '' ) ),
					'post_status'  => (string) ( $args['status'] ?? 'draft' ),
					'post_type'    => 'page',
					'post_parent'  => (int) ( $args['parent'] ?? 0 ),
				];

				if ( ! empty( $args['slug'] ) ) {
					$payload['post_name'] = sanitize_title( (string) $args['slug'] );
				}

				$id = wp_insert_post( $payload, true );
				if ( is_wp_error( $id ) ) {
					return $id;
				}

				$meta_skipped = [];

				if ( ! empty( $args['template'] ) ) {
					$template_key = '_wp_page_template';
					if ( Permissions::can_edit_post_meta( (int) $id, $template_key ) ) {
						update_post_meta( (int) $id, $template_key, sanitize_text_field( (string) $args['template'] ) );
					} else {
						$meta_skipped[] = $template_key;
					}
				}

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

				return [
					'id'           => (int) $id,
					'edit'         => get_edit_post_link( (int) $id, 'raw' ) ?: '',
					'preview'      => (string) get_preview_post_link( (int) $id ),
					'status'       => get_post_status( (int) $id ) ?: 'draft',
					'meta_skipped' => $meta_skipped,
				];
			}
		);
	}
}
