<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\V4;

/** Central gate for experimental Elementor V4 writes. */
final class V4FeatureGate {
	/** @var bool|null */
	private static ?bool $atomic_module_present_for_tests = null;

	public static function check( bool $write = false ): bool|\WP_Error {
		if ( ! get_option( 'stonewright_elementor_v4_atomic', false ) ) {
			return new \WP_Error( 'feature_disabled', 'Elementor V4 atomic features are disabled.' );
		}
		if ( ! self::atomic_widgets_module_present() ) {
			return new \WP_Error(
				'stonewright_v4_unavailable',
				__( 'Elementor V4 atomic abilities require an Elementor version with the Atomic Widgets module (Elementor 3.31+).', 'stonewright' ),
				[
					'status'            => 409,
					'required_module'   => 'Elementor\\Modules\\AtomicWidgets\\Module',
					'required_version'  => '3.31+',
					'elementor_version' => defined( 'ELEMENTOR_VERSION' ) ? (string) ELEMENTOR_VERSION : '',
				]
			);
		}
		if ( $write && 'production-safe' === get_option( 'stonewright_mode', 'development' ) ) {
			return new \WP_Error( 'stonewright_v4_experimental_production_block', 'Elementor V4 writes remain blocked in production-safe mode until the adapter is promoted stable.' );
		}
		return true;
	}

	public static function atomic_widgets_module_present(): bool {
		if ( null !== self::$atomic_module_present_for_tests ) {
			return self::$atomic_module_present_for_tests;
		}

		return class_exists( '\\Elementor\\Modules\\AtomicWidgets\\Module' );
	}

	/**
	 * @internal Tests only.
	 */
	public static function set_atomic_module_present_for_tests( ?bool $present ): void {
		self::$atomic_module_present_for_tests = $present;
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
