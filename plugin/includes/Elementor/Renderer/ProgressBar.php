<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Renderer;

use Stonewright\WpMcp\DesignTokens\Resolver;

/**
 * Renders a DesignSpec `progress-bar` node as an Elementor progress widget.
 *
 * Spec shape:
 *   { type: "progress-bar", title: "...", percent: 75, color: "{colors.primary}" }
 */
final class ProgressBar {

	/**
	 * @param array<string, mixed> $node
	 * @param Resolver             $resolver
	 * @param string               $canonical_path
	 * @return array<string, mixed>
	 */
	public static function render( array $node, Resolver $resolver, string $canonical_path ): array {
		$percent  = isset( $node['percent'] ) ? min( 100, max( 0, (int) $node['percent'] ) ) : 0;

		$settings = [
			'title'   => (string) ( $node['title'] ?? '' ),
			'percent' => [
				'unit' => '%',
				'size' => $percent,
			],
		];

		if ( isset( $node['display_percentage'] ) ) {
			$settings['display_percentage'] = ! empty( $node['display_percentage'] ) ? 'show' : 'hide';
		}

		if ( isset( $node['color'] ) ) {
			$settings['bar_color'] = (string) $resolver->resolve( (string) $node['color'] );
		}

		if ( isset( $node['style'] ) ) {
			$settings['striped'] = 'striped' === $node['style'] ? 'yes' : '';
		}

		return [
			'id'         => Section::stable_id( $canonical_path ),
			'elType'     => 'widget',
			'widgetType' => 'progress',
			'settings'   => $settings,
			'elements'   => [],
		];
	}
}
