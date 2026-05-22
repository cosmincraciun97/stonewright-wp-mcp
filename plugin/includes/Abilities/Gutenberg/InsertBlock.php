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
final class InsertBlock extends AbilityKernel {

	public function name(): string {
		return 'stonewright/blocks-insert';
	}

	public function label(): string {
		return __( 'Insert block', 'stonewright' );
	}

	public function description(): string {
		return __( 'Inserts a block into a post at a given path/position. Takes a snapshot first.', 'stonewright' );
	}

	public function category(): string {
		return 'gutenberg';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'post_id'  => [ 'type' => 'integer', 'minimum' => 1 ],
				'block'    => [
					'type'       => 'object',
					'properties' => [
						'name'        => [ 'type' => 'string' ],
						'attrs'       => [ 'type' => 'object' ],
						'innerHTML'   => [ 'type' => 'string' ],
						'innerBlocks' => [ 'type' => 'array' ],
					],
					'required'   => [ 'name' ],
				],
				'path'     => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
				'position' => [ 'type' => 'integer' ],
			],
			'required'             => [ 'post_id', 'block' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'post_id'     => [ 'type' => 'integer' ],
				'snapshot_id' => [ 'type' => 'string' ],
				'path'        => [ 'type' => 'array' ],
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
				$post_id     = (int) $args['post_id'];
				$post        = get_post( $post_id );
				if ( ! $post ) {
					return $this->error( 'not_found', __( 'Post not found.', 'stonewright' ) );
				}

				$snapshot_id = Backup::snapshot_post( $post_id );
				$blocks      = parse_blocks( $post->post_content );

				$path        = isset( $args['path'] ) ? array_map( 'intval', (array) $args['path'] ) : [];
				$position    = isset( $args['position'] ) ? (int) $args['position'] : count( $blocks );
				$new_block   = $this->normalize_input_block( (array) $args['block'] );

				$mutated = BlockTree::insert( $blocks, $path, $position, $new_block );
				$html    = BlockSerializer::serialize( $mutated );

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
					'path'        => array_merge( $path, [ $position ] ),
				];
			}
		);
	}

	private function normalize_input_block( array $block ): array {
		return [
			'blockName'    => (string) ( $block['name'] ?? '' ),
			'attrs'        => isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : [],
			'innerHTML'    => (string) ( $block['innerHTML'] ?? '' ),
			'innerContent' => [ (string) ( $block['innerHTML'] ?? '' ) ],
			'innerBlocks'  => array_map( [ $this, 'normalize_input_block' ], (array) ( $block['innerBlocks'] ?? [] ) ),
		];
	}
}
