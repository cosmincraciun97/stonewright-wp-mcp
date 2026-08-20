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

	private const SUMMARY_MAX_BLOCKS = 80;
	private const COMPACT_MAX_BLOCKS = 80;

	public function name(): string {
		return 'stonewright/blocks-parse';
	}

	public function label(): string {
		return __( 'Parse blocks', 'stonewright' );
	}

	public function description(): string {
		return __( 'Parses post content or raw HTML into a block tree. Defaults to a name-and-count summary; use responseMode=full for innerHTML and attributes.', 'stonewright' );
	}

	public function category(): string {
		return 'gutenberg';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'post_id'      => [ 'type' => 'integer', 'minimum' => 1 ],
				'html'         => [ 'type' => 'string' ],
				'responseMode' => [
					'type'        => 'string',
					'enum'        => [ 'summary', 'compact', 'full' ],
					'default'     => 'summary',
					'description' => 'summary returns block names and counts; compact adds attribute keys; full restores the previous innerHTML and attrs dump.',
				],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'response_mode'  => [ 'type' => 'string' ],
				'block_count'    => [ 'type' => 'integer' ],
				'truncated'      => [ 'type' => 'boolean' ],
				'counts_by_name' => [ 'type' => 'object' ],
				'blocks'         => [ 'type' => 'array' ],
				'full_mode_hint' => [ 'type' => 'string' ],
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

		$mode   = self::response_mode( $args );
		$blocks = $this->normalize( parse_blocks( $html ) );
		$counts = [];
		$this->count_names( $blocks, $counts );

		if ( 'full' === $mode ) {
			return [
				'response_mode'  => $mode,
				'block_count'    => $this->node_count( $blocks ),
				'truncated'      => false,
				'counts_by_name' => $counts,
				'blocks'         => $blocks,
				'full_mode_hint' => 'Call with responseMode=full only when innerHTML or attributes are required for the next write.',
			];
		}

		$cap       = 'compact' === $mode ? self::COMPACT_MAX_BLOCKS : self::SUMMARY_MAX_BLOCKS;
		$truncated = false;
		$outline   = $this->outline( $blocks, $mode, $cap, $truncated );

		return [
			'response_mode'  => $mode,
			'block_count'    => $this->node_count( $blocks ),
			'truncated'      => $truncated,
			'counts_by_name' => $counts,
			'blocks'         => $outline,
			'full_mode_hint' => 'Call with responseMode=full only when innerHTML or attributes are required for the next write.',
		];
	}

	/**
	 * @param array<string, mixed> $args
	 */
	private static function response_mode( array $args ): string {
		$mode = strtolower( trim( (string) ( $args['responseMode'] ?? 'summary' ) ) );
		return in_array( $mode, [ 'summary', 'compact', 'full' ], true ) ? $mode : 'summary';
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

	/**
	 * @param list<array<string, mixed>> $blocks
	 * @param array<string, int>         $counts
	 */
	private function count_names( array $blocks, array &$counts ): void {
		foreach ( $blocks as $block ) {
			$name            = (string) ( $block['name'] ?? 'core/freeform' );
			$counts[ $name ] = ( $counts[ $name ] ?? 0 ) + 1;
			$this->count_names( (array) ( $block['innerBlocks'] ?? [] ), $counts );
		}
	}

	/**
	 * @param list<array<string, mixed>> $blocks
	 */
	private function node_count( array $blocks ): int {
		$count = 0;
		foreach ( $blocks as $block ) {
			++$count;
			$count += $this->node_count( (array) ( $block['innerBlocks'] ?? [] ) );
		}
		return $count;
	}

	/**
	 * @param list<array<string, mixed>> $blocks
	 * @return list<array<string, mixed>>
	 */
	private function outline( array $blocks, string $mode, int $remaining, bool &$truncated ): array {
		$out = [];
		foreach ( $blocks as $block ) {
			if ( $remaining <= 0 ) {
				$truncated = true;
				break;
			}
			--$remaining;
			$inner     = (array) ( $block['innerBlocks'] ?? [] );
			$row       = [
				'name'             => $block['name'] ?? null,
				'inner_block_count' => count( $inner ),
			];
			if ( 'compact' === $mode ) {
				$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : [];
				$row['attr_keys'] = array_values( array_map( 'strval', array_keys( $attrs ) ) );
			}
			if ( [] !== $inner ) {
				$row['innerBlocks'] = $this->outline( $inner, $mode, $remaining, $truncated );
				$remaining         -= $this->node_count( $row['innerBlocks'] );
			}
			$out[] = $row;
		}
		return $out;
	}
}
