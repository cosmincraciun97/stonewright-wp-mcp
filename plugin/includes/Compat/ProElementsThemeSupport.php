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
		add_action( 'elementor/theme/register_locations', [ self::class, 'register_default_locations' ] );
	}

	public static function declare_support(): void {
		if ( ! self::pro_elements_active() ) {
			return;
		}

		$rescue = (bool) apply_filters( 'stonewright_proelements_theme_support_rescue', true );
		if ( ! $rescue ) {
			return;
		}

		if ( ! current_theme_supports( 'elementor-pro' ) ) {
			add_theme_support( 'elementor-pro' );
		}
	}

	/**
	 * Register the default theme-builder locations (`header`, `footer`)
	 * on behalf of themes that don't do it themselves.
	 *
	 * ProElements / Elementor Pro injects Stonewright-created
	 * header/footer templates into the matching `Locations_Manager`
	 * location. If the theme never registered the location (e.g. Hello
	 * Elementor uses its own `hello_elementor_header` / `_footer` hooks
	 * instead of Pro's location system), the templates have nowhere to
	 * render. We register the standard pair so the templates appear in
	 * the document where any Elementor-aware theme would expect them.
	 *
	 * @param object $manager Locations_Manager from Elementor Pro.
	 */
	public static function register_default_locations( $manager ): void {
		if ( ! is_object( $manager ) || ! method_exists( $manager, 'register_location' ) ) {
			return;
		}

		$rescue = (bool) apply_filters( 'stonewright_proelements_register_default_locations', true );
		if ( ! $rescue ) {
			return;
		}

		$registered = method_exists( $manager, 'get_locations' ) ? (array) $manager->get_locations() : [];

		if ( ! isset( $registered['header'] ) ) {
			$manager->register_location( 'header', [
				'label'     => __( 'Header', 'stonewright' ),
				'multiple'  => false,
				'edit_in_content' => false,
			] );
		}

		if ( ! isset( $registered['footer'] ) ) {
			$manager->register_location( 'footer', [
				'label'     => __( 'Footer', 'stonewright' ),
				'multiple'  => false,
				'edit_in_content' => false,
			] );
		}
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
