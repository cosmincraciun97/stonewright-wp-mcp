<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Media;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Batch media upload wrapper with per-item results.
 *
 * @stonewright-status stable
 */
final class UploadMediaBatch extends AbilityKernel {

	public function name(): string {
		return 'stonewright/media-upload-batch';
	}

	public function label(): string {
		return __( 'Upload media batch', 'stonewright' );
	}

	public function description(): string {
		return __( 'Uploads multiple media items in one request and returns per-item success or error details.', 'stonewright' );
	}

	public function category(): string {
		return 'media';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'items'         => [
					'type'     => 'array',
					'minItems' => 1,
					'maxItems' => 50,
					'items'    => [
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => [
							'url'            => [ 'type' => 'string', 'format' => 'uri' ],
							'base64'         => [ 'type' => 'string' ],
							'filename'       => [ 'type' => 'string', 'maxLength' => 255 ],
							'alt'            => [ 'type' => 'string', 'maxLength' => 500 ],
							'caption'        => [ 'type' => 'string' ],
							'parent_post_id' => [ 'type' => 'integer', 'minimum' => 0 ],
						],
						'anyOf'                => [
							[ 'required' => [ 'url' ] ],
							[ 'required' => [ 'base64', 'filename' ] ],
						],
					],
				],
				'stop_on_error' => [ 'type' => 'boolean', 'default' => false ],
			],
			'required'             => [ 'items' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'       => [ 'type' => 'boolean' ],
				'uploaded' => [ 'type' => 'integer' ],
				'failed'   => [ 'type' => 'integer' ],
				'items'    => [ 'type' => 'array' ],
			],
			'required'   => [ 'ok', 'uploaded', 'failed', 'items' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::upload_files();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$items = isset( $args['items'] ) && is_array( $args['items'] ) ? array_values( $args['items'] ) : [];
				if ( [] === $items ) {
					return $this->error( 'missing_items', __( 'At least one media item is required.', 'stonewright' ), [ 'status' => 400 ] );
				}

				$stop_on_error = ! empty( $args['stop_on_error'] );
				$worker        = new UploadMedia();
				$results       = [];
				$uploaded      = 0;
				$failed        = 0;

				foreach ( $items as $index => $item ) {
					$item = is_array( $item ) ? $item : [];
					$result = $worker->execute( $item );
					if ( is_wp_error( $result ) ) {
						++$failed;
						$results[] = self::wp_error_item( $index, $result );
						if ( $stop_on_error ) {
							break;
						}
						continue;
					}

					++$uploaded;
					$results[] = array_merge(
						[
							'index' => $index,
							'ok'    => true,
						],
						$result
					);
				}

				return [
					'ok'       => 0 === $failed,
					'uploaded' => $uploaded,
					'failed'   => $failed,
					'items'    => $results,
				];
			}
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function wp_error_item( int $index, \WP_Error $error ): array {
		return [
			'index' => $index,
			'ok'    => false,
			'error' => [
				'code'    => $error->get_error_code(),
				'message' => $error->get_error_message(),
				'data'    => (array) $error->get_error_data(),
			],
		];
	}
}
