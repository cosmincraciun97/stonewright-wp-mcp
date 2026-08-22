<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Patterns;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Deletes a user synced pattern after snapshot + confirmation in production-safe.
 *
 * @stonewright-status stable
 */
final class DeletePattern extends AbilityKernel {
	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/patterns-delete';
	}

	public function label(): string {
		return __( 'Delete synced pattern', 'stonewright' );
	}

	public function description(): string {
		return __( 'Trashes a user-defined synced pattern (wp_block CPT). Snapshots first. Requires confirmation in production-safe mode.', 'stonewright' );
	}

	public function category(): string {
		return 'patterns';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'id'                 => [ 'type' => 'integer', 'minimum' => 1 ],
				'force'              => [ 'type' => 'boolean', 'default' => false ],
				'confirmation_token' => [ 'type' => 'string' ],
			],
			'required'             => [ 'id' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id'          => [ 'type' => 'integer' ],
				'snapshot_id' => [ 'type' => 'string' ],
				'trashed'     => [ 'type' => 'boolean' ],
			],
			'required'   => [ 'id', 'snapshot_id', 'trashed' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts() && PatternSupport::can_write();
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

				$post = PatternSupport::require_pattern( (int) ( $args['id'] ?? 0 ) );
				if ( is_wp_error( $post ) ) {
					return $post;
				}

				$snapshot_id = Backup::snapshot_post( (int) $post->ID );
				$deleted     = wp_delete_post( (int) $post->ID, (bool) ( $args['force'] ?? false ) );
				if ( ! $deleted ) {
					return $this->error( 'delete_failed', __( 'Pattern could not be deleted.', 'stonewright' ) );
				}

				return [
					'id'          => (int) $post->ID,
					'snapshot_id' => $snapshot_id,
					'trashed'     => true,
				];
			}
		);
	}

	/**
	 * @return array<int, string>
	 */
	protected function audit_redacted_keys(): array {
		return array_merge( parent::audit_redacted_keys(), [ 'confirmation_token' ] );
	}
}
