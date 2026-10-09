<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV3;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\ElementorData;
use Stonewright\WpMcp\Support\LegacyMirrorCleanup;

/**
 * Export Elementor JSON for selected posts as git-friendly text.
 *
 * The JSON is returned in the ability result to the authenticated caller. No
 * export file is written, so nothing is published under a public path.
 *
 * @stonewright-status experimental
 */
final class DesignMirrorExport extends AbilityKernel {

	/** Upper bound for the JSON text returned by one call. */
	private const MAX_RESPONSE_BYTES = 1500000;

	public function name(): string {
		return 'stonewright/design-mirror-export';
	}

	public function label(): string {
		return __( 'Design mirror export', 'stonewright' );
	}

	public function description(): string {
		return __( 'Returns the Elementor tree JSON of selected posts in the result, one entry per post with a suggested filename, byte count, SHA-256 and the JSON text, with sorted keys for stable diffs. Writes no file and publishes nothing; export files that earlier versions left in the uploads folder are removed. The total JSON per call is capped. Does not run git.', 'stonewright' );
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
				'confirmation_token' => [ 'type' => 'string' ],
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
				'exports' => [
					'type'  => 'array',
					'items' => [
						'type'                 => 'object',
						'additionalProperties' => true,
						'properties'           => [
							'post_id'  => [ 'type' => 'integer' ],
							'ok'       => [ 'type' => 'boolean' ],
							'slug'     => [ 'type' => 'string' ],
							'filename' => [ 'type' => 'string' ],
							'bytes'    => [ 'type' => 'integer' ],
							'sha256'   => [ 'type' => 'string' ],
							'json'     => [ 'type' => 'string' ],
							'error'    => [ 'type' => 'string', 'enum' => [ 'not_found', 'forbidden', 'encode_failed', 'response_too_large' ] ],
						],
					],
				],
			],
			'required'             => [ 'ok', 'exports' ],
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
		return $this->audit_write(
			$args,
			function ( array $args ) {
				$ids = isset( $args['post_ids'] ) && is_array( $args['post_ids'] ) ? $args['post_ids'] : [];
				if ( [] === $ids ) {
					return $this->error( 'invalid_args', __( 'post_ids is required.', 'stonewright' ) );
				}

				// Remove and guard files that earlier versions published under uploads.
				LegacyMirrorCleanup::run();

				$exports = [];
				$total   = 0;
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
					if ( ! Permissions::edit_post( $post_id ) ) {
						$exports[] = [
							'post_id' => $post_id,
							'ok'      => false,
							'error'   => 'forbidden',
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
					$json  .= "
";
					$bytes  = strlen( $json );
					if ( $total + $bytes > self::MAX_RESPONSE_BYTES ) {
						$exports[] = [
							'post_id' => $post_id,
							'ok'      => false,
							'error'   => 'response_too_large',
							'bytes'   => $bytes,
						];
						continue;
					}
					$total += $bytes;

					$slug      = sanitize_title( (string) ( $post->post_name ?: ( 'post-' . $post_id ) ) );
					$exports[] = [
						'post_id'  => $post_id,
						'ok'       => true,
						'slug'     => $slug,
						'filename' => $slug . '.json',
						'bytes'    => $bytes,
						'sha256'   => hash( 'sha256', $json ),
						'json'     => $json,
					];
				}

				return [
					'ok'      => true,
					'exports' => $exports,
				];
			}
		);
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
