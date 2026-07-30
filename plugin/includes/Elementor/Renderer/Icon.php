<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Renderer;

use Stonewright\WpMcp\DesignTokens\Resolver;
use Stonewright\WpMcp\Elementor\Renderer\Responsive;

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
			// Scalar: normalise into Elementor size object (existing behaviour).
			// Array with breakpoint keys: pass through Responsive::apply so each
			// per-breakpoint value (expected to be a size object already) lands on
			// the correct Elementor key (size, size_tablet, size_mobile).
			if ( ! is_array( $node['size'] ) ) {
				$settings['size'] = [
					'unit' => isset( $node['size_unit'] ) ? (string) $node['size_unit'] : 'px',
					'size' => (int) $node['size'],
				];
			} else {
				$settings = Responsive::apply( $settings, 'size', $node['size'] );
			}
		}

		if ( isset( $node['color'] ) ) {
			$settings['primary_color'] = (string) $resolver->resolve( (string) $node['color'] );
		}

		if ( isset( $node['align'] ) ) {
			$settings = Responsive::apply( $settings, 'align', $node['align'] );
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
