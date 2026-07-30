<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Gutenberg\Renderer;

/**
 * Renders a DesignSpec quote node as a core/quote block.
 */
final class Quote {

	/**
	 * @param array<string, mixed> $node
	 * @return array<string, mixed>
	 */
	public static function render( array $node, string $path ): array {
		$text       = (string) ( $node['text'] ?? '' );
		$citation   = (string) ( $node['citation'] ?? '' );

		$p_html     = '<p>' . esc_html( $text ) . '</p>';
		$cite_html  = '' !== $citation ? '<cite>' . esc_html( $citation ) . '</cite>' : '';

		$paragraph  = [
			'blockName'    => 'core/paragraph',
			'attrs'        => [],
			'innerHTML'    => $p_html,
			'innerContent' => [ $p_html ],
			'innerBlocks'  => [],
		];

		$open  = '<blockquote class="wp-block-quote">';
		$close = $cite_html . '</blockquote>';

		return [
			'blockName'    => 'core/quote',
			'attrs'        => [],
			'innerHTML'    => $open . $p_html . $close,
			'innerContent' => [ $open, null, $close ],
			'innerBlocks'  => [ $paragraph ],
		];
	}
}
