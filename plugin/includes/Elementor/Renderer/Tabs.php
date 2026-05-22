<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Renderer;

use Stonewright\WpMcp\DesignTokens\Resolver;

/**
 * Renders a DesignSpec `tabs` node as an Elementor tabs widget.
 *
 * Spec shape:
 *   {
 *     type: "tabs",
 *     tabs: [ { title: "...", content: "..." }, ... ]
 *   }
 */
final class Tabs {

	/**
	 * @param array<string, mixed> $node
	 * @param Resolver             $resolver
	 * @param string               $canonical_path
	 * @return array<string, mixed>
	 */
	public static function render( array $node, Resolver $resolver, string $canonical_path ): array {
		$tab_items = [];
		$raw_tabs  = isset( $node['tabs'] ) && is_array( $node['tabs'] ) ? $node['tabs'] : [];
		foreach ( $raw_tabs as $i => $tab ) {
			$tab = is_array( $tab ) ? $tab : [];
			$tab_items[] = [
				'_id'          => Section::stable_id( $canonical_path . '.tab.' . $i ),
				'tab_title'    => (string) ( $tab['title'] ?? '' ),
				'tab_content'  => (string) ( $tab['content'] ?? '' ),
			];
		}

		$settings = [
			'tabs' => $tab_items,
		];

		if ( isset( $node['type_of_tabs'] ) ) {
			$settings['type'] = (string) $node['type_of_tabs'];
		}

		return [
			'id'         => Section::stable_id( $canonical_path ),
			'elType'     => 'widget',
			'widgetType' => 'tabs',
			'settings'   => $settings,
			'elements'   => [],
		];
	}
}
