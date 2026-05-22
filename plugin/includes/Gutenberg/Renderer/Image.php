<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Gutenberg\Renderer;

use Stonewright\WpMcp\DesignTokens\Resolver;
use Stonewright\WpMcp\Gutenberg\TokenMapper;
use Stonewright\WpMcp\Gutenberg\UrlGuard;

/**
 * Renders a DesignSpec image node as a core/image block.
 */
final class Image {

	/**
	 * @param array<string, mixed> $node
	 * @param Resolver|null        $resolver Token resolver (null = no token mapping).
	 * @return array<string, mixed>
	 */
	public static function render( array $node, string $path, ?Resolver $resolver = null ): array {
		$url  = (string) ( $node['url'] ?? '' );
		$alt  = (string) ( $node['alt'] ?? '' );
		$id   = isset( $node['id'] ) ? (int) $node['id'] : 0;
		$size = isset( $node['size'] ) ? (string) $node['size'] : 'large';

		$safe_url = '' !== $url ? UrlGuard::safe_url( $url ) : null;

		$attrs = [ 'sizeSlug' => $size ];
		if ( $id > 0 ) {
			$attrs['id'] = $id;
		}

		if ( null !== $resolver ) {
			$attrs = TokenMapper::apply( $node, $attrs, $resolver, 'background' );
		}

		$img_class = 'wp-block-image__img';
		if ( $id > 0 ) {
			$img_class .= ' wp-image-' . $id;
		}

		$src_attr = null !== $safe_url ? esc_url( $safe_url ) : '';
		$html = '<figure class="wp-block-image size-' . esc_attr( $size ) . '">'
			. '<img src="' . $src_attr . '" alt="' . esc_attr( $alt ) . '" class="' . esc_attr( $img_class ) . '"/>'
			. '</figure>';

		return [
			'blockName'    => 'core/image',
			'attrs'        => $attrs,
			'innerHTML'    => $html,
			'innerContent' => [ $html ],
			'innerBlocks'  => [],
		];
	}
}
