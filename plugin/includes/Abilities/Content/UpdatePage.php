<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Content;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class UpdatePage extends AbilityKernel {

	public function name(): string {
		return 'stonewright/content-update-page';
	}

	public function label(): string {
		return __( 'Update page', 'stonewright' );
	}

	public function description(): string {
		return __( 'Updates an existing page. Creates a snapshot before writing.', 'stonewright' );
	}

	public function category(): string {
		return 'content';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'id'      => [ 'type' => 'integer', 'minimum' => 1 ],
				'title'   => [ 'type' => 'string', 'maxLength' => 255 ],
				'content' => [ 'type' => 'string' ],
				'excerpt' => [ 'type' => 'string' ],
				'status'  => [ 'type' => 'string', 'enum' => [ 'draft', 'publish', 'private', 'pending', 'future' ] ],
				'template' => [
					'type'        => 'string',
					'description' => 'Optional page template slug, e.g. elementor_canvas to remove theme header/footer.',
				],
				'meta'    => [ 'type' => 'object' ],
			],
			'required'             => [ 'id' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id'           => [ 'type' => 'integer' ],
				'snapshot_id'  => [ 'type' => 'string' ],
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
	 * @stonewright-cap edit_post($args['id']) + publish_posts_for(post_type) when status in {publish, private, future}
	 */
	public function permission_callback( array $args ): bool|\WP_Error {
		$id = (int) ( $args['id'] ?? 0 );
		if ( $id <= 0 ) {
			return new \WP_Error(
				'stonewright_invalid_input',
				__( 'Invalid page ID.', 'stonewright' ),
				[ 'status' => 400 ]
			);
		}

		$post = get_post( $id );
		if ( ! $post ) {
			return new \WP_Error(
				'stonewright_not_found',
				__( 'Page not found.', 'stonewright' ),
				[ 'status' => 404 ]
			);
		}

		if ( ! Permissions::edit_post( $id ) ) {
			return new \WP_Error(
				'stonewright_forbidden',
				__( 'Insufficient capability to edit this page.', 'stonewright' ),
				[ 'status' => 403 ]
			);
		}

		$status = (string) ( $args['status'] ?? '' );
		if ( '' !== $status ) {
			$post_type   = (string) $post->post_type;
			$publish_cap = Permissions::publish_cap_for_status( $post_type, $status );
			if ( null !== $publish_cap && ! current_user_can( $publish_cap ) ) {
				return new \WP_Error(
					'stonewright_forbidden',
					__( 'Insufficient capability to publish pages.', 'stonewright' ),
					[ 'status' => 403 ]
				);
			}
		}

		return true;
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$id = (int) $args['id'];
				if ( ! get_post( $id ) ) {
					return $this->error( 'not_found', __( 'Page not found.', 'stonewright' ) );
				}

				$snapshot_id = Backup::snapshot_post( $id );

				$payload = [ 'ID' => $id ];
				if ( isset( $args['title'] ) ) {
					$payload['post_title'] = sanitize_text_field( (string) $args['title'] );
				}
				if ( isset( $args['content'] ) ) {
					$payload['post_content'] = wp_kses_post( (string) $args['content'] );
				}
				if ( isset( $args['excerpt'] ) ) {
					$payload['post_excerpt'] = sanitize_text_field( (string) $args['excerpt'] );
				}
				if ( isset( $args['status'] ) ) {
					$payload['post_status'] = (string) $args['status'];
				}

				$result = wp_update_post( $payload, true );
				if ( is_wp_error( $result ) ) {
					return $result;
				}

				$meta_skipped = [];
				if ( ! empty( $args['template'] ) ) {
					$template_key = '_wp_page_template';
					if ( Permissions::can_edit_post_meta( $id, $template_key ) ) {
						update_post_meta( $id, $template_key, sanitize_text_field( (string) $args['template'] ) );
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
						if ( ! Permissions::can_edit_post_meta( $id, $key ) ) {
							$meta_skipped[] = $key;
							continue;
						}
						update_post_meta( $id, $key, $value );
					}
				}

				return [
					'id'           => $id,
					'snapshot_id'  => $snapshot_id,
					'meta_skipped' => $meta_skipped,
				];
			}
		);
	}
}
