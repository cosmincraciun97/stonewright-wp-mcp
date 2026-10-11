<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor;

/**
 * One setting of the active Elementor kit as Elementor itself reports it.
 *
 * A kit that was never saved has no stored settings, yet Elementor reports its default colors and fonts (the four
 * system colors, for one), and a kit that was saved keeps the stored list instead. The real setting keys of the colors
 * and fonts are `system_colors` and `custom_colors`, and `system_typography` and `custom_typography`; Elementor has no
 * other global color or typography key.
 */
final class KitLiveSettings {

	/** The setting as Elementor reports it, or null when Elementor is not there to ask. */
	public static function get( string $key ): mixed {
		if ( ! class_exists( '\\Elementor\\Plugin' ) ) {
			return null;
		}
		try {
			$manager = isset( \Elementor\Plugin::$instance ) ? ( \Elementor\Plugin::$instance->kits_manager ?? null ) : null;
			$active  = is_object( $manager ) && method_exists( $manager, 'get_active_kit' ) ? $manager->get_active_kit() : null;

			return is_object( $active ) && method_exists( $active, 'get_settings' ) ? $active->get_settings( $key ) : null;
		} catch ( \Throwable $failure ) {
			unset( $failure );

			return null;
		}
	}
}
