<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Renderer;

use Stonewright\WpMcp\DesignTokens\Resolver;

/**
 * Renders a DesignSpec column node as an Elementor V3 inner container.
 *
 * In Elementor V3 flex containers, columns are inner containers with
 * flex_direction=column nested inside a row container.
 */
final class Column {

	/**
	 * @param array<string, mixed> $node
	 * @param Resolver             $resolver
	 * @param string               $canonical_path
	 * @return array<string, mixed>
	 */
	public static function render( array $node, Resolver $resolver, string $canonical_path ): array {
		$settings = [
			'flex_direction' => 'column',
		];

		if ( isset( $node['width'] ) ) {
			$settings['width'] = [
				'unit' => '%',
				'size' => (int) $node['width'],
			];
		}

		if ( isset( $node['padding'] ) && is_array( $node['padding'] ) ) {
			$settings['padding'] = self::dimensions( $node['padding'] );
		}

		return [
			'id'       => Section::stable_id( $canonical_path ),
			'elType'   => 'container',
			'isInner'  => true,
			'settings' => $settings,
			'elements' => [],
		];
	}

	/**
	 * @param array<string, mixed> $dim
	 * @return array<string, mixed>
	 */
	private static function dimensions( array $dim ): array {
		return [
			'unit'     => 'px',
			'top'      => isset( $dim['top'] ) ? (string) (int) $dim['top'] : '0',
			'right'    => isset( $dim['right'] ) ? (string) (int) $dim['right'] : '0',
			'bottom'   => isset( $dim['bottom'] ) ? (string) (int) $dim['bottom'] : '0',
			'left'     => isset( $dim['left'] ) ? (string) (int) $dim['left'] : '0',
			'isLinked' => false,
		];
	}
}
