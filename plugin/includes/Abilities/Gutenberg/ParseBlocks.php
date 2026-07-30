<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Gutenberg;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class ParseBlocks extends AbilityKernel {

	public function name(): string {
		return 'stonewright/blocks-parse';
	}

	public function label(): string {
		return __( 'Parse blocks', 'stonewright' );
	}

	public function description(): string {
		return __( 'Parses post content or raw HTML into a block tree.', 'stonewright' );
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
				'html'    => [ 'type' => 'string' ],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'blocks' => [ 'type' => 'array' ],
			],
			'required'   => [ 'blocks' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		if ( ! empty( $args['post_id'] ) ) {
			return Permissions::edit_post( (int) $args['post_id'] );
		}
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		$html = isset( $args['html'] ) ? (string) $args['html'] : '';
		if ( '' === $html && ! empty( $args['post_id'] ) ) {
			$post = get_post( (int) $args['post_id'] );
			if ( ! $post ) {
				return $this->error( 'not_found', __( 'Post not found.', 'stonewright' ) );
			}
			$html = $post->post_content;
		}

		$blocks = parse_blocks( $html );
		return [ 'blocks' => $this->normalize( $blocks ) ];
	}

	private function normalize( array $blocks ): array {
		$out = [];
		foreach ( $blocks as $block ) {
			if ( null === ( $block['blockName'] ?? null ) && '' === trim( (string) ( $block['innerHTML'] ?? '' ) ) ) {
				continue;
			}
			$out[] = [
				'name'        => $block['blockName'] ?? null,
				'attrs'       => isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : [],
				'innerHTML'   => (string) ( $block['innerHTML'] ?? '' ),
				'innerBlocks' => $this->normalize( (array) ( $block['innerBlocks'] ?? [] ) ),
			];
		}
		return $out;
	}
}
