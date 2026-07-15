<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV3;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * Export Elementor JSON as git-friendly mirror files under uploads.
 *
 * @stonewright-status experimental
 */
final class DesignMirrorExport extends AbilityKernel {

	public function name(): string {
		return 'stonewright/design-mirror-export';
	}

	public function label(): string {
		return __( 'Design mirror export', 'stonewright' );
	}

	public function description(): string {
		return __( 'Exports Elementor tree JSON for selected posts into wp-content/uploads/stonewright-mirror/ with sorted keys for stable diffs. Read-only; does not run git.', 'stonewright' );
	}

	public function category(): string {
		return 'elementor';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'post_ids' ],
			'properties'           => [
				'post_ids' => [
					'type'  => 'array',
					'items' => [ 'type' => 'integer', 'minimum' => 1 ],
					'minItems' => 1,
					'maxItems' => 50,
				],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => true,
			'properties'           => [
				'ok'      => [ 'type' => 'boolean' ],
				'dir'     => [ 'type' => 'string' ],
				'exports' => [ 'type' => 'array' ],
			],
			'required'             => [ 'ok', 'dir', 'exports' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		$ids = isset( $args['post_ids'] ) && is_array( $args['post_ids'] ) ? $args['post_ids'] : [];
		foreach ( $ids as $id ) {
			if ( ! Permissions::edit_post( (int) $id ) ) {
				return $this->error( 'cannot_edit', __( 'Missing edit permission for one or more posts.', 'stonewright' ) );
			}
		}
		return true;
	}

	public function execute( array $args ): array|\WP_Error {
		$ids = isset( $args['post_ids'] ) && is_array( $args['post_ids'] ) ? $args['post_ids'] : [];
		if ( [] === $ids ) {
			return $this->error( 'invalid_args', __( 'post_ids is required.', 'stonewright' ) );
		}

		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) {
			return $this->error( 'upload_dir', (string) $upload['error'] );
		}
		$base = trailingslashit( (string) $upload['basedir'] ) . 'stonewright-mirror';
		if ( ! wp_mkdir_p( $base ) ) {
			return $this->error( 'mkdir_failed', __( 'Could not create stonewright-mirror directory.', 'stonewright' ) );
		}

		$exports = [];
		foreach ( $ids as $raw_id ) {
			$post_id = (int) $raw_id;
			$post    = get_post( $post_id );
			if ( ! $post ) {
				$exports[] = [
					'post_id' => $post_id,
					'ok'      => false,
					'error'   => 'not_found',
				];
				continue;
			}

			$tree = ElementorData::read( $post_id );
			$payload = [
				'post_id'   => $post_id,
				'slug'      => (string) $post->post_name,
				'title'     => (string) $post->post_title,
				'exported'  => gmdate( 'c' ),
				'elementor' => $this->ksort_recursive( $tree ),
			];
			$json = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( false === $json ) {
				$exports[] = [
					'post_id' => $post_id,
					'ok'      => false,
					'error'   => 'encode_failed',
				];
				continue;
			}

			$slug = sanitize_title( (string) ( $post->post_name ?: ( 'post-' . $post_id ) ) );
			$file = $base . '/' . $slug . '.json';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$written = file_put_contents( $file, $json . "\n" );
			$exports[] = [
				'post_id'  => $post_id,
				'ok'       => false !== $written,
				'path'     => $file,
				'bytes'    => false === $written ? 0 : (int) $written,
			];
		}

		return [
			'ok'      => true,
			'dir'     => $base,
			'exports' => $exports,
		];
	}

	/**
	 * @param mixed $value
	 * @return mixed
	 */
	private function ksort_recursive( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$is_list = array_is_list( $value );
		foreach ( $value as $k => $v ) {
			$value[ $k ] = $this->ksort_recursive( $v );
		}
		if ( ! $is_list ) {
			ksort( $value );
		}
		return $value;
	}
}
