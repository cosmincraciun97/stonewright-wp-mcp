<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Gutenberg\Renderer;

/**
 * Renders a DesignSpec list node as a core/list block with core/list-item children.
 *
 * PHP's `list` keyword is reserved so the class is named ListBlock.
 */
final class ListBlock {

	/**
	 * @param array<string, mixed> $node
	 * @return array<string, mixed>
	 */
	public static function render( array $node, string $path ): array {
		$items    = isset( $node['items'] ) && is_array( $node['items'] ) ? $node['items'] : [];
		$ordered  = isset( $node['ordered'] ) && (bool) $node['ordered'];
		$tag      = $ordered ? 'ol' : 'ul';

		$item_blocks = [];
		foreach ( $items as $item ) {
			$text       = (string) $item;
			$li_html    = '<li>' . esc_html( $text ) . '</li>';
			$item_blocks[] = [
				'blockName'    => 'core/list-item',
				'attrs'        => [],
				'innerHTML'    => $li_html,
				'innerContent' => [ $li_html ],
				'innerBlocks'  => [],
			];
		}

		$attrs = $ordered ? [ 'ordered' => true ] : [];
		$open  = '<' . $tag . ' class="wp-block-list">';
		$close = '</' . $tag . '>';

		$content = [ $open ];
		foreach ( $item_blocks as $_ ) {
			$content[] = null;
		}
		$content[] = $close;

		return [
			'blockName'    => 'core/list',
			'attrs'        => $attrs,
			'innerHTML'    => $open . $close,
			'innerContent' => $content,
			'innerBlocks'  => $item_blocks,
		];
	}
}
