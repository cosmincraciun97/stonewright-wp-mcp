<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Gutenberg\Renderer;

use Stonewright\WpMcp\Gutenberg\UrlGuard;

/**
 * Renders a DesignSpec navigation node as core/navigation, optionally with a ref.
 */
final class Navigation {

	/**
	 * @param array<string, mixed> $node
	 * @return array<string, mixed>
	 */
	public static function render( array $node, string $path ): array {
		$ref   = isset( $node['ref'] ) ? (int) $node['ref'] : 0;
		$attrs = [];
		if ( $ref > 0 ) {
			$attrs['ref'] = $ref;
		}

		$inner = [];
		foreach ( (array) ( $node['links'] ?? [] ) as $link ) {
			$link  = is_array( $link ) ? $link : [];
			$url   = UrlGuard::safe_url( (string) ( $link['url'] ?? '' ) );
			$label = (string) ( $link['label'] ?? '' );
			if ( null === $url || '' === $label ) {
				continue;
			}
			$html    = '<a class="wp-block-navigation-item__content" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
			$inner[] = [
				'blockName'    => 'core/navigation-link',
				'attrs'        => [
					'label' => $label,
					'url'   => $url,
				],
				'innerHTML'    => $html,
				'innerContent' => [ $html ],
				'innerBlocks'  => [],
			];
		}

		$open  = '<nav class="wp-block-navigation">';
		$close = '</nav>';
		$content = [ $open ];
		foreach ( $inner as $_ ) {
			$content[] = null;
		}
		$content[] = $close;

		return [
			'blockName'    => 'core/navigation',
			'attrs'        => $attrs,
			'innerHTML'    => $open . $close,
			'innerContent' => $content,
			'innerBlocks'  => $inner,
		];
	}
}
