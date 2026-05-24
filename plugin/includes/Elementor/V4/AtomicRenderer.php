<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\V4;

use Stonewright\WpMcp\Support\ElementorData;

/**
 * Renders a DesignSpec node tree into Elementor V4 atomic shape.
 *
 * Each node carries a PascalCase {@see AtomicWidgetMap} type ('Heading',
 * 'Section', 'Image', ...), a `props` bag, and optional `children`. The
 * renderer walks the tree depth-first and emits the V4 element envelope:
 *
 *   { id, elType: 'widget'|'container', widgetType, settings, elements }
 *
 * V4 wraps every prop in a typed envelope `{ $$type, value }`:
 * `'string'` for text, `'image'` / `'link'` / `'svg'` for richer values,
 * `'size'` for length-like dimensions. Callers further upstream rely on the
 * envelope to drive Elementor's atomic schema validation.
 *
 * Unknown node types do not throw — they emit a fallback shell that carries
 * a `__unsupported` key. The {@see ElementorV4SpecRenderer} adapter walks
 * the tree, harvests those markers into diagnostics, and strips them before
 * the result reaches Elementor.
 */
final class AtomicRenderer {

	/**
	 * Render a single DesignSpec node into the V4 atomic element shape.
	 *
	 * @param array<string, mixed> $node
	 * @return array<string, mixed>
	 */
	public static function render_node( array $node ): array {
		$type   = (string) ( $node['type'] ?? '' );
		$widget = AtomicWidgetMap::widget_type( $type );

		if ( null === $widget ) {
			return [
				'id'            => ElementorData::generate_id(),
				'elType'        => 'widget',
				'widgetType'    => 'e-paragraph',
				'settings'      => [ 'paragraph' => [ '$$type' => 'string', 'value' => '' ] ],
				'elements'      => [],
				'__unsupported' => $type,
			];
		}

		$children = [];
		foreach ( (array) ( $node['children'] ?? [] ) as $child ) {
			if ( is_array( $child ) ) {
				$children[] = self::render_node( $child );
			}
		}

		return [
			'id'         => ElementorData::generate_id(),
			'elType'     => AtomicWidgetMap::is_container( $type ) ? 'container' : 'widget',
			'widgetType' => $widget,
			'settings'   => self::build_settings( $type, (array) ( $node['props'] ?? [] ) ),
			'elements'   => $children,
		];
	}

	/**
	 * Build the per-type `settings` payload, each prop wrapped in V4's
	 * typed envelope. Unknown props are silently dropped — the atomic
	 * schema will reject unrecognised keys downstream anyway.
	 *
	 * @param array<string, mixed> $props
	 * @return array<string, mixed>
	 */
	private static function build_settings( string $type, array $props ): array {
		switch ( $type ) {
			case 'Heading':
				$s = [];
				if ( isset( $props['text'] ) ) {
					$s['title'] = [ '$$type' => 'string', 'value' => (string) $props['text'] ];
				}
				if ( isset( $props['level'] ) ) {
					$s['tag'] = [ '$$type' => 'string', 'value' => 'h' . (int) $props['level'] ];
				}
				return $s;

			case 'TextEditor':
				return isset( $props['text'] )
					? [ 'paragraph' => [ '$$type' => 'string', 'value' => (string) $props['text'] ] ]
					: [];

			case 'Image':
				$s = [];
				if ( isset( $props['url'] ) ) {
					$s['image'] = [ '$$type' => 'image', 'value' => [ 'src' => (string) $props['url'] ] ];
				}
				if ( isset( $props['alt'] ) ) {
					$s['alt'] = [ '$$type' => 'string', 'value' => (string) $props['alt'] ];
				}
				return $s;

			case 'Button':
				$s = [];
				if ( isset( $props['text'] ) ) {
					$s['text'] = [ '$$type' => 'string', 'value' => (string) $props['text'] ];
				}
				if ( isset( $props['link'] ) ) {
					$s['link'] = [ '$$type' => 'link', 'value' => [ 'href' => (string) $props['link'] ] ];
				}
				return $s;

			case 'Divider':
				return [];

			case 'Icon':
				return isset( $props['svg'] )
					? [ 'svg' => [ '$$type' => 'svg', 'value' => (string) $props['svg'] ] ]
					: [];

			case 'Section':
			case 'Column':
			case 'Container':
				$s = [];
				if ( isset( $props['direction'] ) ) {
					$s['flex-direction'] = [ '$$type' => 'string', 'value' => (string) $props['direction'] ];
				}
				if ( isset( $props['gap'] ) ) {
					$s['gap'] = [ '$$type' => 'size', 'value' => (string) $props['gap'] ];
				}
				return $s;

			default:
				return [];
		}
	}
}
