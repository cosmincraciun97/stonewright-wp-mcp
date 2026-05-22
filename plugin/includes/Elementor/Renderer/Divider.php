<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Renderer;

use Stonewright\WpMcp\DesignTokens\Resolver;

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

		if ( isset( $node['weight'] ) ) {
			$settings['weight'] = [
				'unit' => 'px',
				'size' => (int) $node['weight'],
			];
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
