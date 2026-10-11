<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Media;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Media\RemoteDownload;
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
		return __( 'Sideloads a file from a URL or base64 payload into the media library. The file is capped at 25 MB. A parent post needs edit access.', 'stonewright' );
	}

	public function category(): string {
		return 'media';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'confirmation_token' => [ 'type' => 'string' ],
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

	/**
	 * @stonewright-cap upload_files + edit_post($args['parent_post_id']) when a parent is given
	 */
	public function permission_callback( array $args ): bool|\WP_Error {
		if ( ! Permissions::upload_files() ) {
			return false;
		}
		return self::parent_error( (int) ( $args['parent_post_id'] ?? 0 ) ) ?? true;
	}

	/**
	 * An error when the attachment would be attached to a post the user cannot edit.
	 *
	 * @internal Shared with the batch ability, which checks every item.
	 */
	public static function parent_error( int $parent ): ?\WP_Error {
		if ( $parent > 0 && ! Permissions::edit_post( $parent ) ) {
			return new \WP_Error(
				'stonewright_forbidden',
				__( 'Insufficient capability to attach media to this post.', 'stonewright' ),
				[ 'status' => 403, 'parent_post_id' => $parent ]
			);
		}
		return null;
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit_write( $args, fn( array $args ) => $this->upload( $args ) );
	}

	/**
	 * Uploads one item of a batch.
	 *
	 * The batch ability has already verified the confirmation token for the whole batch, so this asks for none.
	 * The item still gets its own audit row and ledger entry, as a single upload does.
	 *
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute_batch_item( array $args ): array|\WP_Error {
		return $this->audit_covered_write( $args, fn( array $args ) => $this->upload( $args ) );
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>|\WP_Error
	 */
	private function upload( array $args ): array|\WP_Error {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$parent = isset( $args['parent_post_id'] ) ? (int) $args['parent_post_id'] : 0;
		// Checked here as well as in the permission callback: a batch is permission-checked once, item by item, but this
		// path is also reached on its own.
		$parent_error = self::parent_error( $parent );
		if ( null !== $parent_error ) {
			return $parent_error;
		}

		$tmp_path = '';
		$filename = isset( $args['filename'] ) ? sanitize_file_name( (string) $args['filename'] ) : '';

		if ( ! empty( $args['url'] ) ) {
			$tmp = RemoteDownload::fetch( (string) $args['url'], 60 );
			if ( is_wp_error( $tmp ) ) {
				return $tmp;
			}
			$tmp_path = $tmp;
			if ( '' === $filename ) {
				$filename = sanitize_file_name( basename( wp_parse_url( (string) $args['url'], PHP_URL_PATH ) ?: 'upload.bin' ) );
			}
		} elseif ( ! empty( $args['base64'] ) ) {
			$too_large = RemoteDownload::base64_size_error( (string) $args['base64'] );
			if ( null !== $too_large ) {
				return $too_large;
			}
			$decoded = base64_decode( (string) $args['base64'], true );
			if ( false === $decoded ) {
				return $this->error( 'invalid_base64', __( 'Could not decode base64 payload.', 'stonewright' ) );
			}
			if ( strlen( $decoded ) > RemoteDownload::max_bytes() ) {
				return RemoteDownload::too_large_error();
			}
			// A temporary file from core, in the system temporary folder, not a file in the uploads root.
			$tmp_path = wp_tempnam( '' !== $filename ? $filename : 'stonewright-upload' );
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
				wp_slash(
					[
						'ID'           => (int) $id,
						'post_excerpt' => sanitize_text_field( (string) $args['caption'] ),
					]
				)
			);
		}

		return [
			'id'   => (int) $id,
			'url'  => (string) wp_get_attachment_url( (int) $id ),
			'mime' => (string) get_post_mime_type( (int) $id ),
		];
	}
}
