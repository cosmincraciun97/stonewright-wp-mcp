<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Renderer;

use Stonewright\WpMcp\DesignTokens\Resolver;
use Stonewright\WpMcp\Elementor\Renderer\Responsive;

/**
 * Renders a DesignSpec `image` node as an Elementor image widget.
 */
final class Image {

	/**
	 * @param array<string, mixed> $node
	 * @param Resolver             $resolver
	 * @param string               $canonical_path
	 * @return array<string, mixed>
	 */
	public static function render( array $node, Resolver $resolver, string $canonical_path ): array {
		$settings = [
			'image' => [
				'url' => (string) ( $node['url'] ?? '' ),
				'id'  => isset( $node['id'] ) ? (int) $node['id'] : '',
				'alt' => (string) ( $node['alt'] ?? '' ),
			],
		];

		if ( isset( $node['width'] ) ) {
			$settings = Responsive::apply( $settings, 'width', $node['width'] );
		}

		if ( isset( $node['align'] ) ) {
			$settings = Responsive::apply( $settings, 'align', $node['align'] );
		}

		if ( isset( $node['size'] ) ) {
			$settings['image_size'] = (string) $node['size'];
		}

		if ( isset( $node['link']['url'] ) ) {
			$settings['link_to'] = 'custom';
			$settings['link']    = [ 'url' => (string) $node['link']['url'] ];
		}

		if ( isset( $node['caption'] ) ) {
			$settings['caption_source'] = 'custom';
			$settings['caption']        = (string) $node['caption'];
		}

		return [
			'id'         => Section::stable_id( $canonical_path ),
			'elType'     => 'widget',
			'widgetType' => 'image',
			'settings'   => $settings,
			'elements'   => [],
		];
	}
}
