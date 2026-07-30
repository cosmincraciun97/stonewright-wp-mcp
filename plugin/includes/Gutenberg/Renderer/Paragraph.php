<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Gutenberg\Renderer;

use Stonewright\WpMcp\DesignTokens\Resolver;
use Stonewright\WpMcp\Gutenberg\TokenMapper;

/**
 * Renders a DesignSpec paragraph node as a core/paragraph block.
 */
final class Paragraph {

	/**
	 * @param array<string, mixed> $node
	 * @param Resolver|null        $resolver Token resolver (null = no token mapping).
	 * @return array<string, mixed>
	 */
	public static function render( array $node, string $path, ?Resolver $resolver = null ): array {
		$text  = (string) ( $node['text'] ?? '' );
		$align = isset( $node['align'] ) ? (string) $node['align'] : null;

		$attrs = [];
		if ( null !== $align && in_array( $align, [ 'left', 'center', 'right' ], true ) ) {
			$attrs['align'] = $align;
		}

		if ( null !== $resolver ) {
			$attrs = TokenMapper::apply( $node, $attrs, $resolver, 'text' );
		}

		$class = 'wp-block-paragraph';
		if ( null !== $align ) {
			$class .= ' has-text-align-' . esc_attr( $align );
		}

		$html = '<p class="' . esc_attr( $class ) . '">' . esc_html( $text ) . '</p>';

		return [
			'blockName'    => 'core/paragraph',
			'attrs'        => $attrs,
			'innerHTML'    => $html,
			'innerContent' => [ $html ],
			'innerBlocks'  => [],
		];
	}
}
