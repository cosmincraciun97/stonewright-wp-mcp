<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Gutenberg;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Gutenberg\Finalizer\BlockQueue;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Bounded pending-finalizer list. Never includes full block_spec.
 *
 * @stonewright-status stable
 */
final class GetPendingBatch extends AbilityKernel {

	public function name(): string {
		return 'stonewright/blocks-pending-batch';
	}

	public function label(): string {
		return __( 'Pending Gutenberg finalizer batch', 'stonewright' );
	}

	public function description(): string {
		return __( 'Lists compact queued block-finalizer changes without full block specs.', 'stonewright' );
	}

	public function category(): string {
		return 'gutenberg';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'post_id' => [ 'type' => 'integer', 'minimum' => 1 ],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'items'        => [ 'type' => 'array' ],
				'queued_count' => [ 'type' => 'integer' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		return $post_id > 0 ? Permissions::edit_post( $post_id ) : Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		$items   = BlockQueue::list();
		if ( $post_id > 0 ) {
			$items = array_values(
				array_filter(
					$items,
					static fn( array $item ): bool => (int) ( $item['post_id'] ?? 0 ) === $post_id
				)
			);
		}
		return [
			'items'        => $items,
			'queued_count' => BlockQueue::pending_count(),
		];
	}
}
