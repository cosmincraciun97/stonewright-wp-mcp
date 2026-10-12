<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Site;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Elementor\RestoredCss;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Restores a post from a Stonewright snapshot (change timeline undo).
 *
 * @stonewright-status stable
 */
final class ChangeRestore extends AbilityKernel {

	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/change-restore';
	}

	public function label(): string {
		return __( 'Change restore', 'stonewright' );
	}

	public function description(): string {
		return __( 'Restores a post from a Stonewright snapshot. Takes a snapshot of the current state first and returns it as pre_restore_snapshot_id, so the restore can be undone; refuses when that snapshot cannot be stored. When the restore changes an Elementor document it also regenerates the generated CSS of that one post, and css_regeneration says whether that worked; when it did not, the content is still restored and warnings gives the next step. Requires confirmation_token in production-safe mode.', 'stonewright' );
	}

	public function category(): string {
		return 'site';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'post_id', 'snapshot_id' ],
			'properties'           => [
				'post_id'            => [ 'type' => 'integer', 'minimum' => 1 ],
				'snapshot_id'        => [ 'type' => 'string', 'minLength' => 1 ],
				'confirmation_token' => [
					'type'        => 'string',
					'description' => 'Required in production-safe mode.',
				],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'                      => [ 'type' => 'boolean' ],
				'post_id'                 => [ 'type' => 'integer' ],
				'snapshot_id'             => [ 'type' => 'string' ],
				'restored'                => [ 'type' => 'boolean' ],
				'pre_restore_snapshot_id' => [ 'type' => 'string', 'description' => 'Snapshot of the state before the restore. Pass it as snapshot_id to undo the restore.' ],
				'css_regeneration'        => [
					'type'        => 'object',
					'description' => 'Present when the restore changed an Elementor document: whether the generated CSS of the post was regenerated. status not_regenerated means the content is restored but the page may still load the old stylesheet; reason says why and next_step names the ability to call.',
					'properties'  => [
						'status'    => [ 'type' => 'string', 'enum' => [ 'regenerated', 'not_regenerated' ] ],
						'reason'    => [ 'type' => 'string' ],
						'next_step' => [ 'type' => 'string' ],
					],
				],
				'warnings'                => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
			],
			'required'   => [ 'ok', 'post_id', 'snapshot_id', 'restored' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		if ( ! Permissions::edit_post( $post_id ) ) {
			return $this->error( 'cannot_edit_post', __( 'You cannot restore this post.', 'stonewright' ) );
		}
		return $this->publish_error( $post_id, (string) ( $args['snapshot_id'] ?? '' ) ) ?? true;
	}

	/**
	 * A snapshot that holds a published, scheduled or private status brings that status back, so restoring it
	 * needs the publish capability of the post type, as publishing the post by hand would.
	 */
	private function publish_error( int $post_id, string $snapshot_id ): ?\WP_Error {
		$snapshot = '' === $snapshot_id ? null : Backup::get_snapshot( $post_id, $snapshot_id );
		$post     = get_post( $post_id );
		if ( null === $snapshot || ! $post ) {
			return null;
		}
		$capability = Permissions::publish_cap_for_status( (string) $post->post_type, (string) ( $snapshot['post_status'] ?? '' ) );
		if ( null !== $capability && ! current_user_can( $capability ) ) {
			return $this->error(
				'cannot_publish',
				__( 'The snapshot holds a published, scheduled or private status, and you cannot publish this kind of post.', 'stonewright' ),
				[ 'status' => 403 ]
			);
		}
		return null;
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$post_id     = (int) ( $args['post_id'] ?? 0 );
				$snapshot_id = (string) ( $args['snapshot_id'] ?? '' );

				$denied = Permissions::edit_post( $post_id )
					? $this->publish_error( $post_id, $snapshot_id )
					: $this->error( 'cannot_edit_post', __( 'You cannot restore this post.', 'stonewright' ), [ 'status' => 403 ] );
				if ( null !== $denied ) {
					return $denied;
				}

				$verify_args = [
					'post_id'     => $post_id,
					'snapshot_id' => $snapshot_id,
				];
				$token_error = $this->confirmation_token_error( $args, $verify_args );
				if ( null !== $token_error ) {
					return $token_error;
				}

				if ( $post_id <= 0 || '' === $snapshot_id ) {
					return $this->error( 'invalid_args', __( 'post_id and snapshot_id are required.', 'stonewright' ) );
				}

				if ( null === Backup::get_snapshot( $post_id, $snapshot_id ) ) {
					return $this->error( 'snapshot_not_found', __( 'Snapshot not found.', 'stonewright' ) );
				}

				// Snapshot the current state before overwriting so the restore is reversible. The history limit must
				// not drop the snapshot that is about to be restored, and no snapshot means no restore.
				$pre_restore_id = Backup::snapshot_post( $post_id, [ $snapshot_id ] );
				if ( '' === $pre_restore_id ) {
					return $this->error( 'backup_failed', __( 'Backup snapshot failed; restore aborted.', 'stonewright' ), [ 'status' => 500 ] );
				}

				$ok = Backup::restore_snapshot( $post_id, $snapshot_id );
				if ( ! $ok ) {
					return $this->error( 'restore_failed', __( 'Could not restore snapshot.', 'stonewright' ) );
				}

				$result = [
					'post_id'                 => $post_id,
					'snapshot_id'             => $snapshot_id,
					'restored'                => true,
					'pre_restore_snapshot_id' => $pre_restore_id,
				];
				// The content is restored either way; the result says whether the generated CSS of the page followed.
				$css = RestoredCss::last( $post_id );
				if ( null !== $css ) {
					$result['css_regeneration'] = $css;
					$note                       = RestoredCss::warning( $css );
					if ( '' !== $note ) {
						$result['warnings'] = [ $note ];
					}
				}

				return $this->ok( $result );
			}
		);
	}

	/**
	 * @param array<string, mixed>           $args
	 * @param array<string, mixed>|\WP_Error $result
	 * @return array<string, scalar|null>
	 */
	protected function audit_metadata( array $args, array|\WP_Error $result, int $elapsed_ms ): array {
		return [
			'post_id'     => (int) ( $args['post_id'] ?? 0 ),
			'snapshot_id' => isset( $args['snapshot_id'] ) ? (string) $args['snapshot_id'] : null,
			'elapsed_ms'  => $elapsed_ms,
			'restored'    => is_array( $result ) ? (bool) ( $result['restored'] ?? false ) : false,
		];
	}
}
