<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Gutenberg\Renderer;

use Stonewright\WpMcp\DesignTokens\Resolver;

/**
 * Renders a DesignSpec gallery node as core/gallery wrapping core/image blocks.
 */
final class Gallery {

	/**
	 * @param array<string, mixed> $node
	 * @param Resolver|null        $resolver
	 * @return array<string, mixed>
	 */
	public static function render( array $node, string $path, ?Resolver $resolver = null ): array {
		$images = [];
		if ( isset( $node['images'] ) && is_array( $node['images'] ) ) {
			$images = $node['images'];
		} elseif ( isset( $node['url'] ) ) {
			$images = [ $node ];
		}

		$inner = [];
		foreach ( $images as $index => $image ) {
			$inner[] = Image::render( (array) $image, $path . '.img' . $index, $resolver );
		}

		$ids = [];
		foreach ( $inner as $block ) {
			$id = (int) ( $block['attrs']['id'] ?? 0 );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		$attrs = [];
		if ( [] !== $ids ) {
			$attrs['ids'] = $ids;
		}

		$open  = '<figure class="wp-block-gallery has-nested-images columns-default is-cropped">';
		$close = '</figure>';
		$content = [ $open ];
		foreach ( $inner as $_ ) {
			$content[] = null;
		}
		$content[] = $close;

		return [
			'blockName'    => 'core/gallery',
			'attrs'        => $attrs,
			'innerHTML'    => $open . $close,
			'innerContent' => $content,
			'innerBlocks'  => $inner,
		];
	}
}
