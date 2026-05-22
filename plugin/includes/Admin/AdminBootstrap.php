<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

use Stonewright\WpMcp\Admin\Pages\SandboxLibraryPage;
use Stonewright\WpMcp\Admin\Pages\StatusPage;
use Stonewright\WpMcp\Admin\RestApi;
use Stonewright\WpMcp\Sandbox\CrashRecovery;

/**
 * Top-level bootstrap for Phase 8 admin features.
 *
 * Registers the Status page, Sandbox Library page, and REST API extensions
 * added in this phase. The existing admin pages (ConfigurationPage, SandboxPage,
 * AuditLogPage, AbilitiesPage) continue to register themselves through
 * PluginRegistration::register_hooks(). AdminBootstrap supplements them.
 */
final class AdminBootstrap {

	private static bool $registered = false;

	/**
	 * Register all Phase 8 hooks. Idempotent — safe to call multiple times.
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		StatusPage::register();
		SandboxLibraryPage::register();

		add_action( 'rest_api_init', [ RestApi::class, 'register' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
		add_action( 'admin_notices', [ CrashRecovery::class, 'admin_notice' ] );
	}

	/**
	 * Enqueue admin CSS and JS only on Stonewright admin pages.
	 *
	 * @param string $hook_suffix WP admin hook suffix for the current page.
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		// Only load on Stonewright sub-pages (hook suffix contains our page slugs).
		$is_stonewright_page = (
			str_contains( $hook_suffix, 'stonewright' )
		);

		if ( ! $is_stonewright_page ) {
			return;
		}

		$version = defined( 'STONEWRIGHT_VERSION' ) ? (string) constant( 'STONEWRIGHT_VERSION' ) : '0.1.0';
		$url_base = defined( 'STONEWRIGHT_URL' ) ? (string) constant( 'STONEWRIGHT_URL' ) : '';

		if ( '' === $url_base ) {
			return;
		}

		wp_enqueue_style(
			'stonewright-admin',
			$url_base . 'assets/admin/admin.css',
			[],
			$version
		);

		wp_enqueue_script(
			'stonewright-admin',
			$url_base . 'assets/admin/admin.js',
			[],
			$version,
			true
		);
	}

	/**
	 * Resets registration state — for use in tests only.
	 *
	 * @internal
	 */
	public static function reset_for_tests(): void {
		self::$registered = false;
	}
}
