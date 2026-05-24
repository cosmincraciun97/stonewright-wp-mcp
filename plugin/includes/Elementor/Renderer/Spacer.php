<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Renderer;

use Stonewright\WpMcp\DesignTokens\Resolver;
use Stonewright\WpMcp\Elementor\Renderer\Responsive;

/**
 * Renders a DesignSpec `spacer` node as an Elementor spacer widget.
 */
final class Spacer {

	/**
	 * @param array<string, mixed> $node
	 * @param Resolver             $resolver
	 * @param string               $canonical_path
	 * @return array<string, mixed>
	 */
	public static function render( array $node, Resolver $resolver, string $canonical_path ): array {
		// When an explicit `space` value (scalar or responsive map) is provided, route
		// it through Responsive::apply so callers can pass per-breakpoint maps.
		if ( isset( $node['space'] ) ) {
			$settings = Responsive::apply( [], 'space', $node['space'] );

			return [
				'id'         => Section::stable_id( $canonical_path ),
				'elType'     => 'widget',
				'widgetType' => 'spacer',
				'settings'   => $settings,
				'elements'   => [],
			];
		}

		$height = 40;
		if ( isset( $node['height'] ) ) {
			$height = (int) $node['height'];
		} elseif ( isset( $node['spacing'] ) ) {
			$spacing_key = (string) $node['spacing'];
			$resolved    = $resolver->spacing( $spacing_key );
			if ( $resolved > 0 ) {
				$height = $resolved;
			}
		}

		return [
			'id'         => Section::stable_id( $canonical_path ),
			'elType'     => 'widget',
			'widgetType' => 'spacer',
			'settings'   => [
				'space' => [
					'unit' => 'px',
					'size' => $height,
				],
			],
			'elements'   => [],
		];
	}
}
