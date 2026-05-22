<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Renderer;

use Stonewright\WpMcp\DesignTokens\Resolver;

/**
 * Renders a generic DesignSpec container/group node as an Elementor V3 container.
 *
 * Used for the `group` spec type and as a generic wrapper.
 */
final class Container {

	/**
	 * @param array<string, mixed> $node
	 * @param Resolver             $resolver
	 * @param string               $canonical_path
	 * @return array<string, mixed>
	 */
	public static function render( array $node, Resolver $resolver, string $canonical_path ): array {
		$direction = isset( $node['direction'] ) && 'row' === $node['direction'] ? 'row' : 'column';

		$settings = [
			'flex_direction' => $direction,
		];

		if ( isset( $node['background'] ) && is_array( $node['background'] ) ) {
			$bg = $node['background'];
			if ( isset( $bg['color'] ) ) {
				$settings['background_background'] = 'classic';
				$settings['background_color']      = (string) $resolver->resolve( $bg['color'] );
			}
		}

		if ( isset( $node['padding'] ) && is_array( $node['padding'] ) ) {
			$settings['padding'] = [
				'unit'     => 'px',
				'top'      => isset( $node['padding']['top'] ) ? (string) (int) $node['padding']['top'] : '0',
				'right'    => isset( $node['padding']['right'] ) ? (string) (int) $node['padding']['right'] : '0',
				'bottom'   => isset( $node['padding']['bottom'] ) ? (string) (int) $node['padding']['bottom'] : '0',
				'left'     => isset( $node['padding']['left'] ) ? (string) (int) $node['padding']['left'] : '0',
				'isLinked' => false,
			];
		}

		return [
			'id'       => Section::stable_id( $canonical_path ),
			'elType'   => 'container',
			'isInner'  => true,
			'settings' => $settings,
			'elements' => [],
		];
	}
}
