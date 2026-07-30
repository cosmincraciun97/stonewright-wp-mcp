<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Renderer;

use Stonewright\WpMcp\DesignTokens\Resolver;

/**
 * Renders a DesignSpec `image-box` node as an Elementor image-box widget.
 *
 * Spec shape:
 *   { type: "image-box", image: { url, id, alt }, title: "...", description: "..." }
 */
final class ImageBox {

	/**
	 * @param array<string, mixed> $node
	 * @param Resolver             $resolver
	 * @param string               $canonical_path
	 * @return array<string, mixed>
	 */
	public static function render( array $node, Resolver $resolver, string $canonical_path ): array {
		$image_data = isset( $node['image'] ) && is_array( $node['image'] ) ? $node['image'] : [];

		$settings = [
			'image' => [
				'url' => (string) ( $image_data['url'] ?? $node['url'] ?? '' ),
				'id'  => isset( $image_data['id'] ) ? (int) $image_data['id'] : '',
				'alt' => (string) ( $image_data['alt'] ?? $node['alt'] ?? '' ),
			],
			'title_text'       => (string) ( $node['title'] ?? '' ),
			'description_text' => (string) ( $node['description'] ?? '' ),
		];

		if ( isset( $node['title_size'] ) ) {
			$settings['title_size'] = (string) $node['title_size'];
		}

		if ( isset( $node['align'] ) ) {
			$settings['position'] = (string) $node['align'];
		}

		if ( isset( $node['link']['url'] ) ) {
			$settings['link_to']  = 'custom';
			$settings['link']     = [ 'url' => (string) $node['link']['url'] ];
		}

		return [
			'id'         => Section::stable_id( $canonical_path ),
			'elType'     => 'widget',
			'widgetType' => 'image-box',
			'settings'   => $settings,
			'elements'   => [],
		];
	}
}
