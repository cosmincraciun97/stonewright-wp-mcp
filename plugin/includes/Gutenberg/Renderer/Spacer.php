<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Gutenberg\Renderer;

use Stonewright\WpMcp\DesignTokens\Resolver;
use Stonewright\WpMcp\Gutenberg\TokenMapper;

/**
 * Renders a DesignSpec spacer node as a core/spacer block.
 */
final class Spacer {

	/**
	 * @param array<string, mixed> $node
	 * @param Resolver|null        $resolver Token resolver (null = no token mapping).
	 * @return array<string, mixed>
	 */
	public static function render( array $node, string $path, ?Resolver $resolver = null ): array {
		$height = isset( $node['height'] ) ? (int) $node['height'] : 40;
		$attrs  = [ 'height' => $height . 'px' ];

		if ( null !== $resolver ) {
			$attrs = TokenMapper::apply( $node, $attrs, $resolver, 'background' );
		}
		$html   = '<div style="height:' . $height . 'px" aria-hidden="true" class="wp-block-spacer"></div>';

		return [
			'blockName'    => 'core/spacer',
			'attrs'        => $attrs,
			'innerHTML'    => $html,
			'innerContent' => [ $html ],
			'innerBlocks'  => [],
		];
	}
}
