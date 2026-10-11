<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ThemeBuilder;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\ThemeBuilder\TemplateGuard;

/**
 * Delete a Theme Builder template.
 *
 * Needs `edit_theme_options` and `delete_post` on that template. Snapshots the post first (audit trail +
 * restore path) and refuses when the snapshot cannot be stored, then moves the elementor_library post to the
 * trash. `force=true` is refused: the snapshots live in the template's own meta, so a permanent delete would
 * remove the way back.
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
		return __( 'Moves an elementor_library template to the trash. Snapshots first and refuses when the snapshot fails. A permanent delete (force=true) is refused. Needs permission to delete that template.', 'stonewright' );
	}

	public function category(): string {
		return 'theme-builder';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'confirmation_token' => [ 'type' => 'string' ],
				'template_id' => [ 'type' => 'integer', 'minimum' => 1 ],
				'force'       => [ 'type' => 'boolean', 'default' => false, 'description' => 'Refused when true: templates are moved to the trash, not deleted permanently.' ],
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
		return Permissions::edit_theme_options() ? ( TemplateGuard::object_error( 'delete_post', (int) ( $args['template_id'] ?? 0 ) ) ?? true ) : false;
	}

	protected function write_precondition_error( array $args ): ?\WP_Error {
		$id     = (int) ( $args['template_id'] ?? 0 );
		$denied = TemplateGuard::execute_error( 'delete_post', $id );
		if ( null !== $denied ) {
			return $denied;
		}
		$post = get_post( $id );
		if ( ! $post || 'elementor_library' !== $post->post_type ) {
			return $this->error(
				'not_a_template',
				__( 'Post is not an elementor_library template.', 'stonewright' )
			);
		}
		if ( ! empty( $args['force'] ) ) {
			// The snapshots live in the template's own meta, so a permanent delete would remove the way back.
			return $this->error(
				'force_delete_refused',
				__( 'A template is not deleted permanently here, because its snapshots would be deleted with it. Call again without force to move it to the trash; empty the trash in wp-admin to remove it for good.', 'stonewright' ),
				[ 'status' => 400 ]
			);
		}
		return null;
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit_write(
			$args,
			function ( array $args ) {
				$id          = (int) $args['template_id'];
				$snapshot_id = Backup::snapshot_post( $id );
				if ( '' === $snapshot_id ) {
					return $this->backup_failed_error();
				}
				$deleted = wp_delete_post( $id, false );
				return [
					'template_id' => $id,
					'snapshot_id' => $snapshot_id,
					'deleted'     => false !== $deleted && null !== $deleted,
				];
			}
		);
	}
}
