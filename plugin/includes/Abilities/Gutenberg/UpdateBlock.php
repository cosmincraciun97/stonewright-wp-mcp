<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Gutenberg;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\BlockSerializer;
use Stonewright\WpMcp\Support\BlockTree;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class UpdateBlock extends AbilityKernel {

	public function name(): string {
		return 'stonewright/blocks-update';
	}

	public function label(): string {
		return __( 'Update block', 'stonewright' );
	}

	public function description(): string {
		return __( 'Updates the attrs and/or innerHTML of a block at a given path within a post.', 'stonewright' );
	}

	public function category(): string {
		return 'gutenberg';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'post_id'   => [ 'type' => 'integer', 'minimum' => 1 ],
				'path'      => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
				'attrs'     => [ 'type' => 'object' ],
				'innerHTML' => [ 'type' => 'string' ],
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

				$snapshot_id = Backup::snapshot_post( $post_id );
				$blocks      = parse_blocks( $post->post_content );
				$path        = array_map( 'intval', (array) $args['path'] );

				$mutation = [];
				if ( isset( $args['attrs'] ) && is_array( $args['attrs'] ) ) {
					$mutation['attrs'] = $args['attrs'];
				}
				if ( isset( $args['innerHTML'] ) ) {
					$mutation['innerHTML']    = (string) $args['innerHTML'];
					$mutation['innerContent'] = [ (string) $args['innerHTML'] ];
				}

				$mutated = BlockTree::update( $blocks, $path, $mutation );
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
