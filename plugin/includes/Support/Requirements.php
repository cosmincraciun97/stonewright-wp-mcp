<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Support;

/**
 * Verifies host environment meets Stonewright's minimum requirements.
 */
final class Requirements {

	public static function met(): bool {
		return self::php_ok() && self::wp_ok();
	}

	public static function php_ok(): bool {
		return version_compare( PHP_VERSION, STONEWRIGHT_MIN_PHP, '>=' );
	}

	public static function wp_ok(): bool {
		global $wp_version;
		if ( ! isset( $wp_version ) ) {
			return false;
		}
		return version_compare( $wp_version, STONEWRIGHT_MIN_WP, '>=' );
	}

	public static function render_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$message = sprintf(
			/* translators: 1: minimum PHP, 2: minimum WP, 3: current PHP, 4: current WP. */
			esc_html__(
				'Stonewright requires PHP %1$s+ and WordPress %2$s+. Detected PHP %3$s and WordPress %4$s.',
				'stonewright'
			),
			esc_html( STONEWRIGHT_MIN_PHP ),
			esc_html( STONEWRIGHT_MIN_WP ),
			esc_html( PHP_VERSION ),
			esc_html( get_bloginfo( 'version' ) )
		);

		printf( '<div class="notice notice-error"><p>%s</p></div>', wp_kses_post( $message ) );
	}
}
