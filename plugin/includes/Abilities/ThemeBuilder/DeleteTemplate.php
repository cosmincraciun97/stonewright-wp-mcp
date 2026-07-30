<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ThemeBuilder;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Delete a Theme Builder template.
 *
 * Snapshots the post first (audit trail + restore path) and then trashes
 * (or permanently deletes when `force=true`) the elementor_library post.
 *
 * @stonewright-status stable
 */
final class DeleteTemplate extends AbilityKernel {

	public function name(): string {
		return 'stonewright/theme-builder-delete-template';
	}

	public function label(): string {
		return __( 'Theme Builder: Delete template', 'stonewright' );
	}

	public function description(): string {
		return __( 'Trashes (or permanently deletes if force=true) an elementor_library template. Snapshots first.', 'stonewright' );
	}

	public function category(): string {
		return 'theme-builder';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'template_id' => [ 'type' => 'integer', 'minimum' => 1 ],
				'force'       => [ 'type' => 'boolean', 'default' => false ],
			],
			'required' => [ 'template_id' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'template_id' => [ 'type' => 'integer' ],
				'snapshot_id' => [ 'type' => 'string' ],
				'deleted'     => [ 'type' => 'boolean' ],
			],
			'required'   => [ 'template_id', 'deleted' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$id   = (int) $args['template_id'];
				$post = get_post( $id );
				if ( ! $post || 'elementor_library' !== $post->post_type ) {
					return $this->error(
						'not_a_template',
						__( 'Post is not an elementor_library template.', 'stonewright' )
					);
				}
				$snapshot_id = Backup::snapshot_post( $id );
				$deleted     = wp_delete_post( $id, (bool) ( $args['force'] ?? false ) );
				return [
					'template_id' => $id,
					'snapshot_id' => (string) $snapshot_id,
					'deleted'     => false !== $deleted && null !== $deleted,
				];
			}
		);
	}
}
