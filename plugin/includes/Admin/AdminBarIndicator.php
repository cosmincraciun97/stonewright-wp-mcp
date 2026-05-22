<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

/**
 * Admin bar indicator for Stonewright.
 *
 * Displays a red "Stonewright ON" badge in the WP admin bar whenever the
 * master enable toggle is active, giving admins a persistent visual reminder
 * that AI abilities are live on this site.
 */
final class AdminBarIndicator {

	/**
	 * Register the admin_bar_menu hook. Call from admin_init or plugins_loaded.
	 */
	public static function register(): void {
		add_action( 'admin_bar_menu', [ self::class, 'add_node' ], 80 );
	}

	/**
	 * Add the indicator node to the admin bar.
	 *
	 * @param \WP_Admin_Bar $bar The admin bar instance.
	 */
	public static function add_node( \WP_Admin_Bar $bar ): void {
		if ( ! get_option( 'stonewright_enabled', false ) ) {
			return;
		}

		$bar->add_node( [
			'id'    => 'stonewright-on',
			'title' => '<span style="background:#d63638;color:#fff;padding:0 8px;border-radius:3px;font-weight:600;">Stonewright ON</span>',
			'href'  => admin_url( 'admin.php?page=' . ConfigurationPage::SLUG ),
		] );

		// Ensure the ab-item text colour is visible against the red badge background.
		add_action( 'admin_head', [ self::class, 'output_styles' ] );
		add_action( 'wp_head', [ self::class, 'output_styles' ] );
	}

	/**
	 * Inline style that keeps the admin-bar item text white.
	 *
	 * Hooked to both admin_head (back end) and wp_head (front end with admin bar).
	 */
	public static function output_styles(): void {
		echo '<style>#wpadminbar #wp-admin-bar-stonewright-on .ab-item { color: #fff; }</style>' . "\n";
	}
}
