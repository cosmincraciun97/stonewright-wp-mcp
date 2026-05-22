<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Renderer;

use Stonewright\WpMcp\DesignTokens\Resolver;

/**
 * Renders a DesignSpec `icon` node as an Elementor icon widget.
 */
final class Icon {

	/**
	 * @param array<string, mixed> $node
	 * @param Resolver             $resolver
	 * @param string               $canonical_path
	 * @return array<string, mixed>
	 */
	public static function render( array $node, Resolver $resolver, string $canonical_path ): array {
		$icon_value   = (string) ( $node['icon'] ?? $node['value'] ?? 'fas fa-star' );
		$icon_library = (string) ( $node['library'] ?? 'fa-solid' );

		$settings = [
			'icon' => [
				'value'   => $icon_value,
				'library' => $icon_library,
			],
		];

		if ( isset( $node['size'] ) ) {
			$settings['size'] = [
				'unit' => isset( $node['size_unit'] ) ? (string) $node['size_unit'] : 'px',
				'size' => (int) $node['size'],
			];
		}

		if ( isset( $node['color'] ) ) {
			$settings['primary_color'] = (string) $resolver->resolve( (string) $node['color'] );
		}

		if ( isset( $node['align'] ) ) {
			$settings['align'] = (string) $node['align'];
		}

		if ( isset( $node['link']['url'] ) ) {
			$settings['link'] = [ 'url' => (string) $node['link']['url'] ];
		}

		return [
			'id'         => Section::stable_id( $canonical_path ),
			'elType'     => 'widget',
			'widgetType' => 'icon',
			'settings'   => $settings,
			'elements'   => [],
		];
	}
}
