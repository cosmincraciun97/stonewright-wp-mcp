<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Gutenberg\Renderer;

use Stonewright\WpMcp\DesignTokens\Resolver;
use Stonewright\WpMcp\Gutenberg\Renderer as GutenbergRenderer;
use Stonewright\WpMcp\Gutenberg\TokenMapper;
use Stonewright\WpMcp\Gutenberg\UrlGuard;

/**
 * Renders a DesignSpec cover node as a core/cover block.
 */
final class Cover {

	/**
	 * @param array<string, mixed>             $node
	 * @param array<int, array<string, mixed>> $diagnostics
	 * @param Resolver|null                    $resolver Token resolver (null = no token mapping).
	 * @return array<string, mixed>
	 */
	public static function render( array $node, string $path, array &$diagnostics, ?Resolver $resolver = null ): array {
		$url         = (string) ( $node['url'] ?? '' );
		$alt         = (string) ( $node['alt'] ?? '' );
		$overlay_hex = isset( $node['overlay_color'] ) ? (string) $node['overlay_color'] : '';
		$dim         = isset( $node['dim'] ) ? max( 0, min( 100, (int) $node['dim'] ) ) : 50;

		$safe_url = '' !== $url ? UrlGuard::safe_url( $url ) : null;

		$attrs = [ 'dimRatio' => $dim ];
		if ( null !== $safe_url ) {
			$attrs['url'] = $safe_url;
		}
		if ( '' !== $overlay_hex ) {
			$attrs['overlayColor']       = $overlay_hex;
			$attrs['customOverlayColor'] = $overlay_hex;
		}

		if ( null !== $resolver ) {
			$attrs = TokenMapper::apply( $node, $attrs, $resolver, 'background' );
		}

		// Inner blocks — optional content overlay.
		$inner_blocks    = [];
		$inner_node_list = isset( $node['blocks'] ) && is_array( $node['blocks'] ) ? $node['blocks'] : [];
		foreach ( $inner_node_list as $b_idx => $child ) {
			$rendered = GutenbergRenderer::render_block( (array) $child, $path . '.b' . $b_idx, $diagnostics, $resolver );
			if ( null !== $rendered ) {
				$inner_blocks[] = $rendered;
			}
		}

		$open  = '<div class="wp-block-cover">';
		$close = '</div>';

		$content = [ $open ];
		foreach ( $inner_blocks as $_ ) {
			$content[] = null;
		}
		$content[] = $close;

		return [
			'blockName'    => 'core/cover',
			'attrs'        => $attrs,
			'innerHTML'    => $open . $close,
			'innerContent' => $content,
			'innerBlocks'  => $inner_blocks,
		];
	}
}
