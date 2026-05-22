<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Gutenberg;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\BlockSerializer;
use Stonewright\WpMcp\Support\BlockTree;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class RemoveBlock extends AbilityKernel {

	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/blocks-remove';
	}

	public function label(): string {
		return __( 'Remove block', 'stonewright' );
	}

	public function description(): string {
		return __( 'Removes the block at a given path within a post. Takes a snapshot first.', 'stonewright' );
	}

	public function category(): string {
		return 'gutenberg';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'post_id'            => [ 'type' => 'integer', 'minimum' => 1 ],
				'path'               => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
				'confirmation_token' => [ 'type' => 'string' ],
			],
			'required'             => [ 'post_id', 'path' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'post_id'     => [ 'type' => 'integer' ],
				'snapshot_id' => [ 'type' => 'string' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		$id = (int) ( $args['post_id'] ?? 0 );
		return Permissions::edit_post( $id );
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$post_id = (int) $args['post_id'];
				$post    = get_post( $post_id );
				if ( ! $post ) {
					return $this->error( 'not_found', __( 'Post not found.', 'stonewright' ) );
				}

				$verify_args = [
					'post_id' => $post_id,
					'path'    => $args['path'],
				];

				$token_error = $this->confirmation_token_error( $args, $verify_args );
				if ( null !== $token_error ) {
					return $token_error;
				}

				$snapshot_id = Backup::snapshot_post( $post_id );
				$blocks      = parse_blocks( $post->post_content );
				$path        = array_map( 'intval', (array) $args['path'] );

				$mutated = BlockTree::remove( $blocks, $path );
				if ( null === $mutated ) {
					return $this->error( 'invalid_path', __( 'Block path not found.', 'stonewright' ) );
				}

				$html   = BlockSerializer::serialize( $mutated );
				$result = wp_update_post(
					[
						'ID'           => $post_id,
						'post_content' => $html,
					],
					true
				);
				if ( is_wp_error( $result ) ) {
					return $result;
				}

				return [
					'post_id'     => $post_id,
					'snapshot_id' => $snapshot_id,
				];
			}
		);
	}
}
