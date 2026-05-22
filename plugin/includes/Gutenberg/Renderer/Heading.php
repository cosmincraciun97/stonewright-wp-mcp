<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Gutenberg\Renderer;

use Stonewright\WpMcp\DesignTokens\Resolver;
use Stonewright\WpMcp\Gutenberg\TokenMapper;

/**
 * Renders a DesignSpec heading node as a core/heading block.
 */
final class Heading {

	/**
	 * @param array<string, mixed> $node
	 * @param Resolver|null        $resolver Token resolver (null = no token mapping).
	 * @return array<string, mixed>
	 */
	public static function render( array $node, string $path, ?Resolver $resolver = null ): array {
		$level = max( 1, min( 6, (int) ( $node['level'] ?? 2 ) ) );
		$text  = (string) ( $node['text'] ?? '' );

		$attrs = [];
		if ( 2 !== $level ) {
			$attrs['level'] = $level;
		}

		if ( null !== $resolver ) {
			$attrs = TokenMapper::apply( $node, $attrs, $resolver, 'text' );
		}

		$html = '<h' . $level . ' class="wp-block-heading">' . esc_html( $text ) . '</h' . $level . '>';

		return [
			'blockName'    => 'core/heading',
			'attrs'        => $attrs,
			'innerHTML'    => $html,
			'innerContent' => [ $html ],
			'innerBlocks'  => [],
		];
	}
}
