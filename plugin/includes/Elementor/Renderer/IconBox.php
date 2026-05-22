<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Renderer;

use Stonewright\WpMcp\DesignTokens\Resolver;

/**
 * Renders a DesignSpec `icon-box` node as an Elementor icon-box widget.
 *
 * Spec shape:
 *   { type: "icon-box", icon: "fas fa-star", title: "...", description: "...", link: { url: "..." } }
 */
final class IconBox {

	/**
	 * @param array<string, mixed> $node
	 * @param Resolver             $resolver
	 * @param string               $canonical_path
	 * @return array<string, mixed>
	 */
	public static function render( array $node, Resolver $resolver, string $canonical_path ): array {
		$icon_value   = (string) ( $node['icon'] ?? 'fas fa-star' );
		$icon_library = (string) ( $node['library'] ?? 'fa-solid' );

		$settings = [
			'icon'        => [
				'value'   => $icon_value,
				'library' => $icon_library,
			],
			'title_text'  => (string) ( $node['title'] ?? '' ),
			'description_text' => (string) ( $node['description'] ?? '' ),
		];

		if ( isset( $node['title_size'] ) ) {
			$settings['title_size'] = (string) $node['title_size'];
		}

		if ( isset( $node['align'] ) ) {
			$settings['position'] = (string) $node['align'];
		}

		if ( isset( $node['link']['url'] ) ) {
			$settings['link'] = [ 'url' => (string) $node['link']['url'] ];
		}

		if ( isset( $node['icon_color'] ) ) {
			$settings['primary_color'] = (string) $resolver->resolve( (string) $node['icon_color'] );
		}

		return [
			'id'         => Section::stable_id( $canonical_path ),
			'elType'     => 'widget',
			'widgetType' => 'icon-box',
			'settings'   => $settings,
			'elements'   => [],
		];
	}
}
