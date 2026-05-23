<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Renderer;

use Stonewright\WpMcp\DesignTokens\Resolver;
use Stonewright\WpMcp\Elementor\Renderer\StyleMapper;

/**
 * Renders a DesignSpec `nav-menu` node as an Elementor Pro Nav Menu widget.
 *
 * Requires Elementor Pro. When Pro is not active the renderer falls back to an
 * Elementor free `icon-list` widget configured as an inline list — this keeps
 * the page structure valid and horizontal-like so the build is still usable.
 *
 * Pro path spec shape:
 * {
 *   type: "nav-menu",
 *   menu: "top-menu",          // WP nav menu slug or term_id as string
 *   layout: "horizontal",      // 'horizontal' | 'vertical' | 'dropdown'
 *   pointer: "underline",      // 'none'|'underline'|'overline'|'double-line'|'framed'|'background'|'text'
 *   align_items: "center"      // 'start'|'center'|'end'|'justify'
 * }
 *
 * Fallback spec shape accepted:
 * {
 *   type: "nav-menu",
 *   items: [
 *     { text: "Home", url: "https://example.com" },
 *     { text: "About", url: "/about" }
 *   ]
 * }
 *
 * Real setting keys confirmed from:
 *   pro-elements/modules/nav-menu/widgets/nav-menu.php
 */
class NavMenu {

	/**
	 * @param array<string, mixed>             $node
	 * @param Resolver                         $resolver
	 * @param string                           $canonical_path
	 * @param array<int, array<string, mixed>> $diagnostics
	 * @return array<string, mixed>
	 */
	public static function render( array $node, Resolver $resolver, string $canonical_path, array &$diagnostics = [] ): array {
		if ( ! static::pro_available() ) {
			return self::render_fallback( $node, $resolver, $canonical_path, $diagnostics );
		}

		$settings = [];

		if ( isset( $node['menu'] ) ) {
			$settings['menu'] = (string) $node['menu'];
		}

		$layout = (string) ( $node['layout'] ?? 'horizontal' );
		$settings['layout'] = in_array( $layout, [ 'horizontal', 'vertical', 'dropdown' ], true )
			? $layout
			: 'horizontal';

		if ( isset( $node['align_items'] ) ) {
			$align = (string) $node['align_items'];
			$settings['align_items'] = in_array( $align, [ 'start', 'center', 'end', 'justify' ], true )
				? $align
				: 'center';
		}

		if ( isset( $node['pointer'] ) ) {
			$pointer_options = [ 'none', 'underline', 'overline', 'double-line', 'framed', 'background', 'text' ];
			$pointer = (string) $node['pointer'];
			$settings['pointer'] = in_array( $pointer, $pointer_options, true ) ? $pointer : 'underline';
		}

		if ( isset( $node['full_width'] ) ) {
			$settings['full_width'] = $node['full_width'] ? 'stretch' : '';
		}

		if ( isset( $node['submenu_icon'] ) && is_array( $node['submenu_icon'] ) ) {
			$settings['submenu_icon'] = $node['submenu_icon'];
		}

		return [
			'id'         => Section::stable_id( $canonical_path ),
			'elType'     => 'widget',
			'widgetType' => 'nav-menu',
			'settings'   => $settings,
			'elements'   => [],
		];
	}

	/**
	 * When Elementor Pro is not active, emit an icon-list widget in inline
	 * mode using any items the caller provided. This mimics horizontal nav
	 * so the build still renders something usable on free Elementor.
	 *
	 * @param array<string, mixed>             $node
	 * @param array<int, array<string, mixed>> $diagnostics
	 * @return array<string, mixed>
	 */
	private static function render_fallback( array $node, Resolver $resolver, string $canonical_path, array &$diagnostics ): array {
		$diagnostics[] = [
			'code'     => ProGate::DIAGNOSTIC_REQUIRED,
			'type'     => 'nav-menu',
			'path'     => $canonical_path,
			'renderer' => 'elementor_v3',
			'message'  => 'Elementor Pro is required for the Nav Menu widget. Rendering fallback as inline icon-list.',
		];

		// Build icon-list items from spec.
		$icon_list   = [];
		$raw_items   = is_array( $node['items'] ?? null ) ? (array) $node['items'] : [];

		foreach ( $raw_items as $i => $item ) {
			$item       = is_array( $item ) ? $item : [];
			$icon_list[] = [
				'_id'  => Section::stable_id( $canonical_path . '.item.' . $i ),
				'text' => (string) ( $item['text'] ?? '' ),
				'link' => [
					'url'         => (string) ( $item['url'] ?? '' ),
					'is_external' => ! empty( $item['external'] ),
					'nofollow'    => ! empty( $item['nofollow'] ),
				],
				'selected_icon' => [
					'value'   => '',
					'library' => '',
				],
			];
		}

		return [
			'id'         => Section::stable_id( $canonical_path ),
			'elType'     => 'widget',
			'widgetType' => 'icon-list',
			'settings'   => [
				'icon_list'  => $icon_list,
				'view'       => 'inline',
				'link_click' => 'inline',
			],
			'elements'   => [],
		];
	}

	/**
	 * Returns true when Elementor Pro is active.
	 *
	 * Wraps ProGate::active() so tests can replace it by overriding this method
	 * in a subclass, and to keep the Pro-detection logic isolated.
	 */
	protected static function pro_available(): bool {
		return ProGate::active();
	}
}
