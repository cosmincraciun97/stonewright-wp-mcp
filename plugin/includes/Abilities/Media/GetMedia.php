<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Media;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class GetMedia extends AbilityKernel {

	public function name(): string {
		return 'stonewright/media-get';
	}

	public function label(): string {
		return __( 'Get media', 'stonewright' );
	}

	public function description(): string {
		return __( 'Returns attachment metadata including sizes, mime, alt text, and parent.', 'stonewright' );
	}

	public function category(): string {
		return 'media';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'id' => [ 'type' => 'integer', 'minimum' => 1 ],
			],
			'required'             => [ 'id' ],
		];
	}

	public function output_schema(): array {
		return [ 'type' => 'object' ];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::read();
	}

	public function execute( array $args ): array|\WP_Error {
		$id   = (int) $args['id'];
		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return $this->error( 'not_found', __( 'Attachment not found.', 'stonewright' ) );
		}

		$meta = wp_get_attachment_metadata( $id );

		return [
			'id'       => $id,
			'url'      => (string) wp_get_attachment_url( $id ),
			'mime'     => (string) get_post_mime_type( $id ),
			'alt'      => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'caption'  => $post->post_excerpt,
			'parent'   => (int) $post->post_parent,
			'width'    => isset( $meta['width'] ) ? (int) $meta['width'] : 0,
			'height'   => isset( $meta['height'] ) ? (int) $meta['height'] : 0,
			'sizes'    => isset( $meta['sizes'] ) && is_array( $meta['sizes'] ) ? $meta['sizes'] : [],
			'filesize' => isset( $meta['filesize'] ) ? (int) $meta['filesize'] : 0,
		];
	}
}
