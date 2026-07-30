<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\V4;

/** Central gate for experimental Elementor V4 writes. */
final class V4FeatureGate {
	public static function check( bool $write = false ): bool|\WP_Error {
		if ( ! get_option( 'stonewright_elementor_v4_atomic', false ) ) {
			return new \WP_Error( 'feature_disabled', 'Elementor V4 atomic features are disabled.' );
		}
		if ( $write && 'production-safe' === get_option( 'stonewright_mode', 'development' ) ) {
			return new \WP_Error( 'stonewright_v4_experimental_production_block', 'Elementor V4 writes remain blocked in production-safe mode until the adapter is promoted stable.' );
		}
		return true;
	}

	public static function active_kit_id(): int {
		if ( ! class_exists( '\\Elementor\\Plugin' ) ) {
			return 0;
		}
		try {
			$kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit();
			return $kit ? (int) $kit->get_id() : 0;
		} catch ( \Throwable $error ) {
			return 0;
		}
	}
}
