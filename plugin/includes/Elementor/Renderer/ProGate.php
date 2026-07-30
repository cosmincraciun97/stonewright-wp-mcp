<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Renderer;

/**
 * Shared Elementor Pro detection helper.
 *
 * Used by any renderer that requires Elementor Pro (e.g. Form, Slides).
 * Keeps the detection logic and the diagnostic constant in one place.
 */
final class ProGate {

	/**
	 * Diagnostic code emitted when Elementor Pro is unavailable.
	 */
	public const DIAGNOSTIC_REQUIRED = 'elementor_pro_required';

	/**
	 * Returns true when Elementor Pro is active.
	 *
	 * Deliberately avoids calling is_plugin_active() in test environments where
	 * the function may not be loaded; falls back to class existence check.
	 */
	public static function active(): bool {
		if ( class_exists( '\\ElementorPro\\Plugin' ) ) {
			return true;
		}

		if ( class_exists( '\\ElementorPro\\Modules\\ThemeBuilder\\Module' )
			|| defined( 'ELEMENTOR_PRO_VERSION' )
			|| defined( 'PROELEMENTS_VERSION' ) ) {
			return true;
		}

		if ( function_exists( 'is_plugin_active' ) ) {
			return is_plugin_active( 'elementor-pro/elementor-pro.php' )
				|| is_plugin_active( 'pro-elements/pro-elements.php' );
		}

		return false;
	}
}
