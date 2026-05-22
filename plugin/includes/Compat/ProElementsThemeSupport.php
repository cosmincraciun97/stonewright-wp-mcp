<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Compat;

/**
 * Compatibility shim that makes ProElements / Elementor Pro's Theme Builder
 * inject our header / footer templates on themes that don't declare
 * `add_theme_support( 'elementor-pro' )` themselves.
 *
 * Why this exists — most current Elementor-ecosystem themes (Hello Elementor
 * included) historically declared `elementor-pro` theme support to opt in to
 * Pro's location-aware header/footer hijacking. Recent Hello Elementor
 * versions removed that explicit declaration, so a fresh
 * "Hello Elementor + ProElements + Stonewright" install renders Hello's
 * default header/footer even when Stonewright registers a fully-conditioned
 * `_elementor_library` template of type `header` or `footer`. The conditions
 * are saved correctly, ProElements' Conditions_Manager picks them up — but
 * Pro never replaces the chrome because the theme didn't opt in.
 *
 * Stonewright's plugin-level fix: declare `elementor-pro` theme support
 * on the user's behalf when (a) Stonewright is enabled, (b) ProElements
 * or Elementor Pro is active, and (c) the active theme has not already
 * declared it. We hook on `after_setup_theme` priority 100 so that any
 * legitimate theme declaration runs first; we only "rescue" themes that
 * neglected to.
 *
 * Users who do NOT want this rescue (e.g. they're integrating a custom
 * theme with its own elementor-pro support gating) can short-circuit via:
 *   add_filter( 'stonewright_proelements_theme_support_rescue', '__return_false' );
 */
final class ProElementsThemeSupport {

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;
		add_action( 'after_setup_theme', [ self::class, 'declare_support' ], 100 );
	}

	public static function declare_support(): void {
		if ( current_theme_supports( 'elementor-pro' ) ) {
			return; // Theme already opted in.
		}

		if ( ! self::pro_elements_active() ) {
			return; // Nothing to integrate with.
		}

		/**
		 * Whether to silently rescue themes that forgot to declare
		 * `elementor-pro` theme support. Defaults to true; set to false to
		 * opt out (e.g. when working with a custom theme that intentionally
		 * gates the support).
		 *
		 * @param bool $rescue
		 */
		$rescue = (bool) apply_filters( 'stonewright_proelements_theme_support_rescue', true );
		if ( ! $rescue ) {
			return;
		}

		add_theme_support( 'elementor-pro' );
	}

	private static function pro_elements_active(): bool {
		return class_exists( '\\ElementorPro\\Plugin' )
			|| class_exists( '\\ElementorPro\\Modules\\ThemeBuilder\\Module' )
			|| defined( 'ELEMENTOR_PRO_VERSION' )
			|| defined( 'PROELEMENTS_VERSION' );
	}

	/**
	 * For tests.
	 *
	 * @internal
	 */
	public static function reset_for_tests(): void {
		self::$registered = false;
	}
}
