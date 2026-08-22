<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Gutenberg;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Gutenberg\Finalizer\BlockQueue;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Cancels queued, serialized, or failed block-finalizer changes by explicit id.
 *
 * @stonewright-status stable
 */
final class CancelFinalizerChanges extends AbilityKernel {
	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/blocks-finalizer-cancel';
	}

	public function label(): string {
		return __( 'Cancel Gutenberg finalizer changes', 'stonewright' );
	}

	public function description(): string {
		return __( 'Cancels queued, serialized, or failed block-finalizer changes by id. Does not delete persisted content.', 'stonewright' );
	}

	public function category(): string {
		return 'gutenberg';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'change_ids'         => [
					'type'     => 'array',
					'items'    => [ 'type' => 'string' ],
					'minItems' => 1,
					'maxItems' => 20,
				],
				'dry_run'            => [ 'type' => 'boolean', 'default' => false ],
				'confirmation_token' => [ 'type' => 'string' ],
			],
			'required'             => [ 'change_ids' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'                  => [ 'type' => 'boolean' ],
				'dry_run'             => [ 'type' => 'boolean' ],
				'removed_count'       => [ 'type' => 'integer' ],
				'change_ids'          => [ 'type' => 'array' ],
				'previous_statuses'   => [ 'type' => 'array' ],
				'post_ids'            => [ 'type' => 'array' ],
				'verification_status' => [ 'type' => 'string' ],
				'effect_verified'     => [ 'type' => 'boolean' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		$ids  = isset( $args['change_ids'] ) && is_array( $args['change_ids'] ) ? $args['change_ids'] : [];
		$seen = false;
		foreach ( $ids as $id ) {
			$record = BlockQueue::get( (string) $id );
			if ( ! is_array( $record ) ) {
				continue;
			}
			$seen = true;
			if ( ! Permissions::edit_post( (int) ( $record['post_id'] ?? 0 ) ) ) {
				return false;
			}
		}

		return $seen ? true : Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$ids = BlockQueue::canonicalize_change_ids( $args['change_ids'] ?? null );
				if ( $ids instanceof \WP_Error ) {
					return $ids;
				}

				$dry_run = ! empty( $args['dry_run'] );
				if ( ! $dry_run ) {
					$token_error = $this->confirmation_token_error(
						$args,
						[ 'change_ids' => $ids ]
					);
					if ( null !== $token_error ) {
						return $token_error;
					}
				}

				return BlockQueue::cancel( $ids, $dry_run, (int) get_current_user_id() );
			}
		);
	}
}
