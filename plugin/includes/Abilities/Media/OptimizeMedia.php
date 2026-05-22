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
final class OptimizeMedia extends AbilityKernel {

	public function name(): string {
		return 'stonewright/media-optimize';
	}

	public function label(): string {
		return __( 'Optimize media', 'stonewright' );
	}

	public function description(): string {
		return __( 'Regenerates attachment metadata and intermediate image sizes.', 'stonewright' );
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
		return [
			'type'       => 'object',
			'properties' => [
				'id'    => [ 'type' => 'integer' ],
				'sizes' => [ 'type' => 'array' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		$id = (int) ( $args['id'] ?? 0 );
		return Permissions::edit_post( $id ) && Permissions::upload_files();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				require_once ABSPATH . 'wp-admin/includes/image.php';

				$id   = (int) $args['id'];
				$file = get_attached_file( $id );
				if ( ! $file || ! file_exists( $file ) ) {
					return $this->error( 'file_missing', __( 'Original file is missing.', 'stonewright' ) );
				}

				$meta = wp_generate_attachment_metadata( $id, $file );
				wp_update_attachment_metadata( $id, $meta );

				return [
					'id'    => $id,
					'sizes' => isset( $meta['sizes'] ) && is_array( $meta['sizes'] ) ? array_keys( $meta['sizes'] ) : [],
				];
			}
		);
	}
}
