<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Gutenberg\Renderer;

use Stonewright\WpMcp\DesignTokens\Resolver;
use Stonewright\WpMcp\Gutenberg\Renderer as GutenbergRenderer;
use Stonewright\WpMcp\Gutenberg\TokenMapper;

/**
 * Renders a DesignSpec columns node as a core/columns block containing
 * core/column children. Each column's 'blocks' sub-array is rendered recursively.
 */
final class Columns {

	/**
	 * @param array<string, mixed>             $node
	 * @param array<int, array<string, mixed>> $diagnostics
	 * @param Resolver|null                    $resolver Token resolver (null = no token mapping).
	 * @return array<string, mixed>
	 */
	public static function render( array $node, string $path, array &$diagnostics, ?Resolver $resolver = null ): array {
		$cols          = isset( $node['columns'] ) && is_array( $node['columns'] ) ? $node['columns'] : [];
		$column_blocks = [];

		foreach ( $cols as $c_idx => $col ) {
			$col          = (array) $col;
			$col_children = [];
			$inner_blocks = isset( $col['blocks'] ) && is_array( $col['blocks'] ) ? $col['blocks'] : [];
			foreach ( $inner_blocks as $b_idx => $child ) {
				$rendered = GutenbergRenderer::render_block( (array) $child, $path . '.c' . $c_idx . '.b' . $b_idx, $diagnostics, $resolver );
				if ( null !== $rendered ) {
					$col_children[] = $rendered;
				}
			}

			$col_attrs = [];
			if ( isset( $col['width'] ) ) {
				$col_attrs['width'] = (string) $col['width'];
			}

			$col_open    = '<div class="wp-block-column">';
			$col_close   = '</div>';
			$col_content = [ $col_open ];
			foreach ( $col_children as $_ ) {
				$col_content[] = null;
			}
			$col_content[] = $col_close;

			$column_blocks[] = [
				'blockName'    => 'core/column',
				'attrs'        => $col_attrs,
				'innerHTML'    => $col_open . $col_close,
				'innerContent' => $col_content,
				'innerBlocks'  => $col_children,
			];
		}

		$attrs = [];
		if ( null !== $resolver ) {
			$attrs = TokenMapper::apply( $node, $attrs, $resolver, 'background' );
		}

		$open  = '<div class="wp-block-columns">';
		$close = '</div>';

		$content = [ $open ];
		foreach ( $column_blocks as $_ ) {
			$content[] = null;
		}
		$content[] = $close;

		return [
			'blockName'    => 'core/columns',
			'attrs'        => $attrs,
			'innerHTML'    => $open . $close,
			'innerContent' => $content,
			'innerBlocks'  => $column_blocks,
		];
	}
}
