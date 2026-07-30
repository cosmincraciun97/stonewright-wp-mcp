<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV3;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Elementor\PostCacheInvalidator;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class UpdatePageSettings extends AbilityKernel {

	public function name(): string {
		return 'stonewright/elementor-v3-update-page-settings';
	}

	public function label(): string {
		return __( 'Update Elementor page settings', 'stonewright' );
	}

	public function description(): string {
		return __( 'Updates _elementor_page_settings (background, layout, custom CSS).', 'stonewright' );
	}

	public function category(): string {
		return 'elementor';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'post_id'  => [ 'type' => 'integer', 'minimum' => 1 ],
				'settings' => [ 'type' => 'object' ],
				'mode'     => [ 'type' => 'string', 'enum' => [ 'merge', 'replace' ], 'default' => 'merge' ],
			],
			'required'             => [ 'post_id', 'settings' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'post_id'     => [ 'type' => 'integer' ],
				'snapshot_id' => [ 'type' => 'string' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		$id = (int) ( $args['post_id'] ?? 0 );
		return Permissions::edit_post( $id );
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$post_id     = (int) $args['post_id'];
				$snapshot_id = Backup::snapshot_post( $post_id );

				$existing = get_post_meta( $post_id, '_elementor_page_settings', true );
				if ( ! is_array( $existing ) ) {
					$existing = [];
				}

				$mode = isset( $args['mode'] ) ? (string) $args['mode'] : 'merge';
				$next = 'replace' === $mode
					? (array) $args['settings']
					: array_merge( $existing, (array) $args['settings'] );

				if ( false === update_post_meta( $post_id, '_elementor_page_settings', $next ) && $next !== $existing ) {
					return $this->error( 'write_failed', __( 'Could not save Elementor page settings.', 'stonewright' ) );
				}

				PostCacheInvalidator::invalidate( $post_id );

				return [
					'post_id'     => $post_id,
					'snapshot_id' => $snapshot_id,
				];
			}
		);
	}
}
