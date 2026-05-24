<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Renderer;

use Stonewright\WpMcp\DesignTokens\Resolver;
use Stonewright\WpMcp\Elementor\Renderer\Responsive;

/**
 * Renders a DesignSpec `divider` node as an Elementor divider widget.
 */
final class Divider {

	/**
	 * @param array<string, mixed> $node
	 * @param Resolver             $resolver
	 * @param string               $canonical_path
	 * @return array<string, mixed>
	 */
	public static function render( array $node, Resolver $resolver, string $canonical_path ): array {
		$settings = [];

		if ( isset( $node['style'] ) ) {
			$settings['style'] = (string) $node['style'];
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

		return [
			'id'         => Section::stable_id( $canonical_path ),
			'elType'     => 'widget',
			'widgetType' => 'divider',
			'settings'   => $settings,
			'elements'   => [],
		];
	}
}
