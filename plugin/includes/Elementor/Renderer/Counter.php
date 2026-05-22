<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Renderer;

use Stonewright\WpMcp\DesignTokens\Resolver;

/**
 * Renders a DesignSpec `counter` node as an Elementor counter widget.
 *
 * Spec shape:
 *   { type: "counter", starting_number: 0, ending_number: 100, title: "..." }
 */
final class Counter {

	/**
	 * @param array<string, mixed> $node
	 * @param Resolver             $resolver
	 * @param string               $canonical_path
	 * @return array<string, mixed>
	 */
	public static function render( array $node, Resolver $resolver, string $canonical_path ): array {
		$settings = [
			'starting_number' => (int) ( $node['starting_number'] ?? $node['from'] ?? 0 ),
			'ending_number'   => (int) ( $node['ending_number'] ?? $node['to'] ?? 100 ),
			'title'           => (string) ( $node['title'] ?? '' ),
		];

		if ( isset( $node['duration'] ) ) {
			$settings['duration'] = (int) $node['duration'];
		}

		if ( isset( $node['prefix'] ) ) {
			$settings['prefix'] = (string) $node['prefix'];
		}

		if ( isset( $node['suffix'] ) ) {
			$settings['suffix'] = (string) $node['suffix'];
		}

		if ( isset( $node['number_size'] ) ) {
			$settings['number_size'] = [
				'unit' => 'px',
				'size' => (int) $node['number_size'],
			];
		}

		return [
			'id'         => Section::stable_id( $canonical_path ),
			'elType'     => 'widget',
			'widgetType' => 'counter',
			'settings'   => $settings,
			'elements'   => [],
		];
	}
}
