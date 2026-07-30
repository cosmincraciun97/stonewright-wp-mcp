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
final class UploadMedia extends AbilityKernel {

	public function name(): string {
		return 'stonewright/media-upload';
	}

	public function label(): string {
		return __( 'Upload media', 'stonewright' );
	}

	public function description(): string {
		return __( 'Sideloads a file from a URL or base64 payload into the media library.', 'stonewright' );
	}

	public function category(): string {
		return 'media';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'url'             => [ 'type' => 'string', 'format' => 'uri' ],
				'base64'          => [ 'type' => 'string' ],
				'filename'        => [ 'type' => 'string', 'maxLength' => 255 ],
				'alt'             => [ 'type' => 'string', 'maxLength' => 500 ],
				'caption'         => [ 'type' => 'string' ],
				'parent_post_id'  => [ 'type' => 'integer', 'minimum' => 0 ],
			],
			'anyOf'                => [
				[ 'required' => [ 'url' ] ],
				[ 'required' => [ 'base64', 'filename' ] ],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id'   => [ 'type' => 'integer' ],
				'url'  => [ 'type' => 'string' ],
				'mime' => [ 'type' => 'string' ],
			],
			'required'   => [ 'id', 'url' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::upload_files();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
				require_once ABSPATH . 'wp-admin/includes/media.php';
				require_once ABSPATH . 'wp-admin/includes/image.php';

				$parent  = isset( $args['parent_post_id'] ) ? (int) $args['parent_post_id'] : 0;
				$tmp_path = '';
				$filename = isset( $args['filename'] ) ? sanitize_file_name( (string) $args['filename'] ) : '';

				if ( ! empty( $args['url'] ) ) {
					$tmp = download_url( (string) $args['url'], 60 );
					if ( is_wp_error( $tmp ) ) {
						return $tmp;
					}
					$tmp_path = $tmp;
					if ( '' === $filename ) {
						$filename = sanitize_file_name( basename( wp_parse_url( (string) $args['url'], PHP_URL_PATH ) ?: 'upload.bin' ) );
					}
				} elseif ( ! empty( $args['base64'] ) ) {
					$decoded = base64_decode( (string) $args['base64'], true );
					if ( false === $decoded ) {
						return $this->error( 'invalid_base64', __( 'Could not decode base64 payload.', 'stonewright' ) );
					}
					$uploads = wp_upload_dir();
					$tmp_path = trailingslashit( $uploads['basedir'] ) . 'stonewright-' . wp_generate_uuid4();
					if ( false === file_put_contents( $tmp_path, $decoded ) ) {
						return $this->error( 'write_failed', __( 'Could not write temporary file.', 'stonewright' ) );
					}
				} else {
					return $this->error( 'missing_source', __( 'Provide either url or base64.', 'stonewright' ) );
				}

				$file_array = [
					'name'     => '' !== $filename ? $filename : 'upload.bin',
					'tmp_name' => $tmp_path,
				];

				$id = media_handle_sideload( $file_array, $parent );
				if ( is_wp_error( $id ) ) {
					@unlink( $tmp_path );
					return $id;
				}

				if ( ! empty( $args['alt'] ) ) {
					update_post_meta( (int) $id, '_wp_attachment_image_alt', sanitize_text_field( (string) $args['alt'] ) );
				}
				if ( ! empty( $args['caption'] ) ) {
					wp_update_post(
						[
							'ID'           => (int) $id,
							'post_excerpt' => sanitize_text_field( (string) $args['caption'] ),
						]
					);
				}

				return [
					'id'   => (int) $id,
					'url'  => (string) wp_get_attachment_url( (int) $id ),
					'mime' => (string) get_post_mime_type( (int) $id ),
				];
			}
		);
	}
}
