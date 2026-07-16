<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Revisions;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Restores a post to a revision after snapshotting the current post.
 *
 * @stonewright-status stable
 */
final class PostRevisionRestore extends AbilityKernel {
	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/post-revision-restore';
	}

	public function label(): string {
		return __( 'Revision: Restore', 'stonewright' );
	}

	public function description(): string {
		return __( 'Restores a post to a revision after snapshotting the current post.', 'stonewright' );
	}

	public function category(): string {
		return 'revisions';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'revision_id'        => [ 'type' => 'integer', 'minimum' => 1 ],
				'confirmation_token' => [ 'type' => 'string' ],
			],
			'required'             => [ 'revision_id' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => true,
			'properties'           => [
				'ok'      => [ 'type' => 'boolean' ],
				'post_id' => [ 'type' => 'integer' ],
			],
			'required'             => [ 'ok', 'post_id' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		$rev = get_post( (int) ( $args['revision_id'] ?? 0 ) );
		if ( ! $rev ) {
			return false;
		}
		return Permissions::edit_post( (int) $rev->post_parent );
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$verify = $args;
				unset( $verify['confirmation_token'] );
				$token_error = $this->confirmation_token_error( $args, $verify );
				if ( null !== $token_error ) {
					return $token_error;
				}
				$rev = get_post( (int) $args['revision_id'] );
				if ( ! $rev || 'revision' !== $rev->post_type ) {
					return new \WP_Error( 'stonewright_revision_not_found', 'Revision not found.' );
				}
				$post_id  = (int) $rev->post_parent;
				$snapshot = Backup::snapshot_post( $post_id );
				if ( '' === $snapshot ) {
					return new \WP_Error( 'stonewright_backup_failed', 'Snapshot failed; restore aborted.' );
				}
				$restored = wp_restore_post_revision( (int) $args['revision_id'] );
				if ( ! $restored ) {
					return new \WP_Error( 'stonewright_revision_restore_failed', 'Restore failed.' );
				}
				return [
					'ok'          => true,
					'post_id'     => $post_id,
					'revision_id' => (int) $args['revision_id'],
					'snapshot_id' => $snapshot,
				];
			}
		);
	}
}
