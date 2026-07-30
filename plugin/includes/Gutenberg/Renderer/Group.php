<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Gutenberg\Renderer;

use Stonewright\WpMcp\DesignTokens\Resolver;
use Stonewright\WpMcp\Gutenberg\Renderer as GutenbergRenderer;
use Stonewright\WpMcp\Gutenberg\TokenMapper;

/**
 * Renders a DesignSpec group/section/row node as a core/group block.
 */
final class Group {

	/**
	 * Render a generic group node from a spec block.
	 *
	 * @param array<string, mixed>             $node
	 * @param array<int, array<string, mixed>> $diagnostics
	 * @param Resolver|null                    $resolver Token resolver (null = no token mapping).
	 * @return array<string, mixed>
	 */
	public static function render( array $node, string $path, array &$diagnostics, ?Resolver $resolver = null ): array {
		$children     = [];
		$inner_blocks = isset( $node['blocks'] ) && is_array( $node['blocks'] ) ? $node['blocks'] : [];
		foreach ( $inner_blocks as $b_idx => $child ) {
			$rendered = GutenbergRenderer::render_block( (array) $child, $path . '.b' . $b_idx, $diagnostics, $resolver );
			if ( null !== $rendered ) {
				$children[] = $rendered;
			}
		}

		$attrs = [];
		if ( null !== $resolver ) {
			$attrs = TokenMapper::apply( $node, $attrs, $resolver, 'background' );
		}

		return self::build( $attrs, $children );
	}

	/**
	 * Build a core/group block that wraps a section's children.
	 *
	 * @param array<string, mixed>             $section
	 * @param array<int, array<string, mixed>> $children
	 * @return array<string, mixed>
	 */
	public static function from_section( array $section, array $children, string $path ): array {
		$attrs = [
			'tagName' => 'section',
			'layout'  => [
				'type'        => 'constrained',
				'contentSize' => '1200px',
			],
		];

		$style = [];
		if ( isset( $section['background']['color'] ) ) {
			$style['color']['background'] = (string) $section['background']['color'];
		}
		if ( isset( $section['padding'] ) && is_array( $section['padding'] ) ) {
			$style['spacing']['padding'] = [
				'top'    => self::px( $section['padding']['top']    ?? 0 ),
				'right'  => self::px( $section['padding']['right']  ?? 0 ),
				'bottom' => self::px( $section['padding']['bottom'] ?? 0 ),
				'left'   => self::px( $section['padding']['left']   ?? 0 ),
			];
		}
		if ( ! empty( $style ) ) {
			$attrs['style'] = $style;
		}

		return self::build( $attrs, $children );
	}

	/**
	 * @param array<string, mixed>             $attrs
	 * @param array<int, array<string, mixed>> $children
	 * @return array<string, mixed>
	 */
	private static function build( array $attrs, array $children ): array {
		$open  = '<div class="wp-block-group">';
		$close = '</div>';

		$content = [ $open ];
		foreach ( $children as $_ ) {
			$content[] = null;
		}
		$content[] = $close;

		return [
			'blockName'    => 'core/group',
			'attrs'        => $attrs,
			'innerHTML'    => $open . $close,
			'innerContent' => $content,
			'innerBlocks'  => $children,
		];
	}

	/**
	 * @param int|string $value
	 */
	private static function px( $value ): string {
		return is_numeric( $value ) ? (int) $value . 'px' : (string) $value;
	}
}
