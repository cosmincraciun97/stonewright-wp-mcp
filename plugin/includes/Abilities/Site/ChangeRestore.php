<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Site;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
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
		return __( 'Restores a post from a Stonewright snapshot. Requires confirmation_token in production-safe mode.', 'stonewright' );
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
				'ok'          => [ 'type' => 'boolean' ],
				'post_id'     => [ 'type' => 'integer' ],
				'snapshot_id' => [ 'type' => 'string' ],
				'restored'    => [ 'type' => 'boolean' ],
			],
			'required'   => [ 'ok', 'post_id', 'snapshot_id', 'restored' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		if ( ! Permissions::edit_post( $post_id ) ) {
			return $this->error( 'cannot_edit_post', __( 'You cannot restore this post.', 'stonewright' ) );
		}
		return true;
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$post_id     = (int) ( $args['post_id'] ?? 0 );
				$snapshot_id = (string) ( $args['snapshot_id'] ?? '' );

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

				// Snapshot current state before overwriting so restore is reversible.
				Backup::snapshot_post( $post_id );

				$ok = Backup::restore_snapshot( $post_id, $snapshot_id );
				if ( ! $ok ) {
					return $this->error( 'restore_failed', __( 'Could not restore snapshot.', 'stonewright' ) );
				}

				return $this->ok(
					[
						'post_id'     => $post_id,
						'snapshot_id' => $snapshot_id,
						'restored'    => true,
					]
				);
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
