<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Renderer;

use Stonewright\WpMcp\DesignTokens\Resolver;
use Stonewright\WpMcp\Elementor\Renderer\StyleMapper;

/**
 * Renders a DesignSpec `icon-list` node as an Elementor free Icon List widget.
 *
 * This is a free Elementor widget — no Pro required.
 *
 * Spec shape:
 * {
 *   type: "icon-list",
 *   view: "inline",             // 'traditional' | 'inline' (default 'traditional')
 *   icon_align: "left",         // 'left'|'right' (maps to 'start'|'end' in Elementor)
 *   link_click: "full_width",   // 'full_width' | 'inline'
 *   divider: true,              // show dividers between items
 *   items: [
 *     {
 *       text: "Check",
 *       url: "https://example.com",
 *       external: false,
 *       nofollow: false,
 *       icon: { value: "fas fa-check", library: "fa-solid" }
 *     }
 *   ]
 * }
 *
 * Elementor repeater key: `icon_list` (confirmed from core widget source).
 * Per-item keys: text, link (url/is_external/nofollow), selected_icon (value/library).
 *
 * Real setting keys confirmed from:
 *   elementor/includes/widgets/icon-list.php
 */
final class IconList {

	/**
	 * @return array<string, string|array<string, mixed>>
	 */
	private static function style_map(): array {
		return [
			'color'           => [ 'key' => 'icon_color', 'is_color' => true ],
			'text_color'      => [ 'key' => 'text_color', 'is_color' => true ],
			'icon_color'      => [ 'key' => 'icon_color', 'is_color' => true ],
			'font_size'       => [ 'key' => 'icon_size', 'is_size' => true ],
			'icon_size'       => [ 'key' => 'icon_size', 'is_size' => true ],
			'space_between'   => [ 'key' => 'space_between', 'is_size' => true ],
			'font_family'     => 'text_typography_font_family',
			'font_weight'     => 'text_typography_font_weight',
			'text_transform'  => 'text_typography_text_transform',
			'text_decoration' => 'text_typography_text_decoration',
			'padding'         => [ 'key' => '_padding', 'is_dimension' => true ],
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

		// Layout mode.
		$view = (string) ( $node['view'] ?? 'traditional' );
		$settings['view'] = in_array( $view, [ 'traditional', 'inline' ], true ) ? $view : 'traditional';

		// Link click target.
		if ( isset( $node['link_click'] ) ) {
			$link_click = (string) $node['link_click'];
			$settings['link_click'] = in_array( $link_click, [ 'full_width', 'inline' ], true )
				? $link_click
				: 'full_width';
		}

		// Divider.
		if ( isset( $node['divider'] ) ) {
			$settings['divider'] = $node['divider'] ? 'yes' : '';
		}

		if ( isset( $node['divider_color'] ) ) {
			$settings['divider_color'] = (string) $resolver->resolve( (string) $node['divider_color'] );
		}

		// Build the icon_list repeater.
		$raw_items = is_array( $node['items'] ?? null ) ? (array) $node['items'] : [];
		$icon_list = [];

		foreach ( $raw_items as $i => $item ) {
			$item = is_array( $item ) ? $item : [];

			// Support both `icon: { value, library }` and flat `icon_value`/`icon_library`.
			$icon_raw = is_array( $item['icon'] ?? null ) ? (array) $item['icon'] : [];
			$icon_val = (string) ( $icon_raw['value'] ?? $item['icon_value'] ?? 'fas fa-check' );
			$icon_lib = (string) ( $icon_raw['library'] ?? $item['icon_library'] ?? 'fa-solid' );

			$icon_list[] = [
				'_id'           => Section::stable_id( $canonical_path . '.item.' . $i ),
				'text'          => (string) ( $item['text'] ?? '' ),
				'link'          => [
					'url'         => (string) ( $item['url'] ?? $item['link']['url'] ?? '' ),
					'is_external' => ! empty( $item['external'] ?? $item['link']['is_external'] ?? false ),
					'nofollow'    => ! empty( $item['nofollow'] ?? $item['link']['nofollow'] ?? false ),
				],
				'selected_icon' => [
					'value'   => $icon_val,
					'library' => $icon_lib,
				],
			];
		}

		$settings['icon_list'] = $icon_list;

		// Style block.
		if ( isset( $node['style'] ) && is_array( $node['style'] ) ) {
			$style    = self::resolve_style( (array) $node['style'], $resolver );
			$settings = StyleMapper::apply( $settings, $style, self::style_map() );
		}

		return [
			'id'         => Section::stable_id( $canonical_path ),
			'elType'     => 'widget',
			'widgetType' => 'icon-list',
			'settings'   => $settings,
			'elements'   => [],
		];
	}

	/**
	 * @param array<string, mixed> $style
	 * @return array<string, mixed>
	 */
	private static function resolve_style( array $style, Resolver $resolver ): array {
		foreach ( $style as $k => $v ) {
			if ( is_string( $v ) ) {
				$style[ $k ] = $resolver->resolve( $v );
			}
		}
		return $style;
	}
}
