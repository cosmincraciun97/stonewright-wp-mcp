<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Renderer;

use Stonewright\WpMcp\DesignTokens\Resolver;

/**
 * Renders a DesignSpec `accordion` node as an Elementor accordion widget.
 *
 * Spec shape:
 *   {
 *     type: "accordion",
 *     items: [ { title: "...", content: "..." }, ... ]
 *   }
 */
final class Accordion {

	/**
	 * @param array<string, mixed> $node
	 * @param Resolver             $resolver
	 * @param string               $canonical_path
	 * @return array<string, mixed>
	 */
	public static function render( array $node, Resolver $resolver, string $canonical_path ): array {
		$items     = [];
		$raw_items = isset( $node['items'] ) && is_array( $node['items'] ) ? $node['items'] : [];
		foreach ( $raw_items as $i => $item ) {
			$item    = is_array( $item ) ? $item : [];
			$items[] = [
				'_id'             => Section::stable_id( $canonical_path . '.item.' . $i ),
				'tab_title'       => (string) ( $item['title'] ?? '' ),
				'tab_content'     => (string) ( $item['content'] ?? '' ),
			];
		}

		$settings = [
			'tabs' => $items,
		];

		return [
			'id'         => Section::stable_id( $canonical_path ),
			'elType'     => 'widget',
			'widgetType' => 'accordion',
			'settings'   => $settings,
			'elements'   => [],
		];
	}
}
