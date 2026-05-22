<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Site;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class CreateRevision extends AbilityKernel {

	public function name(): string {
		return 'stonewright/site-create-revision';
	}

	public function label(): string {
		return __( 'Create revision', 'stonewright' );
	}

	public function description(): string {
		return __( 'Creates a WordPress revision for a post or page.', 'stonewright' );
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
				'revision_id' => [ 'type' => 'integer' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		return Permissions::edit_post( $post_id );
	}

	public function execute( array $args ): array|\WP_Error {
		$post_id     = (int) $args['post_id'];
		$revision_id = wp_save_post_revision( $post_id );
		if ( ! $revision_id ) {
			return $this->error( 'revision_failed', __( 'Failed to create revision.', 'stonewright' ) );
		}
		return [ 'revision_id' => (int) $revision_id ];
	}
}
