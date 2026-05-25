<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Renderer;

use Stonewright\WpMcp\DesignTokens\Resolver;
use Stonewright\WpMcp\Elementor\Renderer\Responsive;
use Stonewright\WpMcp\Elementor\Renderer\StyleMapper;

/**
 * Renders a DesignSpec `divider` node as an Elementor divider widget.
 */
final class Divider {

	/**
	 * Map from `style.*` keys to Elementor divider-widget settings.
	 *
	 * @return array<string, string|array<string, mixed>>
	 */
	private static function style_map(): array {
		return [
			'color'      => [ 'key' => 'color', 'is_color' => true ],
			'width'      => [ 'key' => 'width', 'is_size' => true ],
			'padding'    => [ 'key' => '_padding', 'is_dimension' => true ],
			'margin'     => [ 'key' => '_margin', 'is_dimension' => true ],
			'background' => [ 'key' => '_background_color', 'is_background' => true ],
		];
	}

	/**
	 * @param array<string, mixed> $node
	 * @param Resolver             $resolver
	 * @param string               $canonical_path
	 * @return array<string, mixed>
	 */
	public static function render( array $node, Resolver $resolver, string $canonical_path ): array {
		$settings = [];

		if ( isset( $node['style'] ) && is_string( $node['style'] ) ) {
			$settings['style'] = $node['style'];
		}

		if ( isset( $node['gap'] ) ) {
			$settings = Responsive::apply( $settings, 'gap', $node['gap'] );
		}

		if ( isset( $node['weight'] ) ) {
			// Scalar int: normalise into an Elementor size object (existing behaviour).
			// Array with breakpoint keys (desktop/tablet/mobile): pass through
			// Responsive::apply so each per-breakpoint value is written to the
			// correct Elementor key.
			if ( ! is_array( $node['weight'] ) ) {
				$settings['weight'] = [
					'unit' => 'px',
					'size' => (int) $node['weight'],
				];
			} else {
				$settings = Responsive::apply( $settings, 'weight', $node['weight'] );
			}
		}

		if ( isset( $node['align'] ) ) {
			$settings = Responsive::apply( $settings, 'align', $node['align'] );
		}

		if ( isset( $node['color'] ) ) {
			$settings['color'] = (string) $resolver->resolve( (string) $node['color'] );
		}

		if ( isset( $node['width'] ) ) {
			$settings['width'] = [
				'unit' => '%',
				'size' => (int) $node['width'],
			];
		}

		$style = StyleMapper::node_style( $node, $resolver );
		if ( [] !== $style ) {
			$settings = StyleMapper::apply( $settings, $style, self::style_map() );
		}

		return [
			'id'         => Section::stable_id( $canonical_path ),
			'elType'     => 'widget',
			'widgetType' => 'divider',
			'settings'   => $settings,
			'elements'   => [],
		];
	}
}
