<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Schema;

/**
 * The controls of a live Elementor element.
 *
 * When Elementor loads the controls of an element for a request that is not the editor, a preview or a REST call
 * that has defined its constant yet, it keeps the style controls apart from the others, and `get_controls()` lists
 * only the rest. The style controls are standard controls all the same (colors, typography, alignment, borders), so
 * the schema holds both and a standard style setting is never refused as unknown.
 */
final class LiveControls {

	/**
	 * @return array<string, mixed> Raw Elementor controls by key.
	 */
	public static function of( object $element ): array {
		$controls = method_exists( $element, 'get_controls' ) ? (array) $element->get_controls() : [];
		if ( ! method_exists( $element, 'get_stack' ) ) {
			return $controls;
		}
		try {
			$stack = $element->get_stack();
		} catch ( \Throwable ) {
			return $controls;
		}
		if ( is_array( $stack ) && is_array( $stack['style_controls'] ?? null ) ) {
			// A control the first list already holds keeps its entry.
			$controls += $stack['style_controls'];
		}

		return $controls;
	}
}
