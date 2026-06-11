<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV3;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Applies multiple Elementor V3 spec writes while preserving per-write guards.
 *
 * @stonewright-status stable
 */
final class ApplyBundle extends AbilityKernel {

	public function name(): string {
		return 'stonewright/elementor-v3-apply-bundle';
	}

	public function label(): string {
		return __( 'Apply Elementor V3 spec bundle', 'stonewright' );
	}

	public function description(): string {
		return __( 'Applies multiple Elementor V3 page specs in one request. Each write still validates the spec, checks permissions, verifies destructive tokens when required, and snapshots before mutation.', 'stonewright' );
	}

	public function category(): string {
		return 'elementor';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'writes'        => [
					'type'     => 'array',
					'minItems' => 1,
					'maxItems' => 20,
					'items'    => [
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => [
							'post_id'            => [ 'type' => 'integer', 'minimum' => 1 ],
							'spec'               => [ 'type' => 'object' ],
							'replace'            => [ 'type' => 'boolean', 'default' => true ],
							'confirmation_token' => [ 'type' => 'string' ],
						],
						'required'             => [ 'post_id', 'spec' ],
					],
				],
				'stop_on_error' => [ 'type' => 'boolean', 'default' => true ],
			],
			'required'             => [ 'writes' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'      => [ 'type' => 'boolean' ],
				'applied' => [ 'type' => 'integer' ],
				'failed'  => [ 'type' => 'integer' ],
				'items'   => [ 'type' => 'array' ],
			],
			'required'   => [ 'ok', 'applied', 'failed', 'items' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$writes = isset( $args['writes'] ) && is_array( $args['writes'] ) ? array_values( $args['writes'] ) : [];
				if ( [] === $writes ) {
					return $this->error( 'missing_writes', __( 'At least one write is required.', 'stonewright' ), [ 'status' => 400 ] );
				}

				$stop_on_error = ! array_key_exists( 'stop_on_error', $args ) || (bool) $args['stop_on_error'];
				$worker        = new BuildPageFromSpec();
				$items         = [];
				$applied       = 0;
				$failed        = 0;

				foreach ( $writes as $index => $write ) {
					$write = is_array( $write ) ? $write : [];
					$post_id = (int) ( $write['post_id'] ?? 0 );
					if ( ! Permissions::edit_post( $post_id ) ) {
						++$failed;
						$items[] = self::error_item(
							$index,
							$post_id,
							'stonewright_forbidden',
							__( 'Current user cannot edit this post.', 'stonewright' )
						);
						if ( $stop_on_error ) {
							break;
						}
						continue;
					}

					$result = $worker->execute( $write );
					if ( is_wp_error( $result ) ) {
						++$failed;
						$items[] = self::wp_error_item( $index, $post_id, $result );
						if ( $stop_on_error ) {
							break;
						}
						continue;
					}

					++$applied;
					$items[] = array_merge(
						[
							'index'   => $index,
							'ok'      => true,
							'post_id' => $post_id,
						],
						$result
					);
				}

				return [
					'ok'      => 0 === $failed,
					'applied' => $applied,
					'failed'  => $failed,
					'items'   => $items,
				];
			}
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function wp_error_item( int $index, int $post_id, \WP_Error $error ): array {
		return self::error_item( $index, $post_id, $error->get_error_code(), $error->get_error_message(), (array) $error->get_error_data() );
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	private static function error_item( int $index, int $post_id, string $code, string $message, array $data = [] ): array {
		return [
			'index'   => $index,
			'ok'      => false,
			'post_id' => $post_id,
			'error'   => [
				'code'    => $code,
				'message' => $message,
				'data'    => $data,
			],
		];
	}
}
