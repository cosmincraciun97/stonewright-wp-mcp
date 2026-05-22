<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Site;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class BackupPage extends AbilityKernel {

	public function name(): string {
		return 'stonewright/site-backup-page';
	}

	public function label(): string {
		return __( 'Backup page', 'stonewright' );
	}

	public function description(): string {
		return __( 'Creates a Stonewright snapshot of a post or page (content, status, key meta) for safe rollback.', 'stonewright' );
	}

	public function category(): string {
		return 'site';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'post_id' => [ 'type' => 'integer', 'minimum' => 1 ],
			],
			'required'             => [ 'post_id' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'snapshot_id' => [ 'type' => 'string' ],
				'post_id'     => [ 'type' => 'integer' ],
			],
			'required'   => [ 'snapshot_id', 'post_id' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		if ( ! Permissions::edit_post( $post_id ) ) {
			return $this->error( 'cannot_edit_post', __( 'You cannot edit this post.', 'stonewright' ) );
		}
		return true;
	}

	public function execute( array $args ): array|\WP_Error {
		$post_id = (int) $args['post_id'];
		$id      = Backup::snapshot_post( $post_id );
		if ( '' === $id ) {
			return $this->error( 'snapshot_failed', __( 'Could not create snapshot.', 'stonewright' ) );
		}
		return [ 'snapshot_id' => $id, 'post_id' => $post_id ];
	}
}
