<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

use Stonewright\WpMcp\Admin\Pages\SandboxLibraryPage;
use Stonewright\WpMcp\Admin\Pages\StatusPage;
use Stonewright\WpMcp\Admin\RestApi;
use Stonewright\WpMcp\Sandbox\CrashRecovery;

/**
 * Top-level bootstrap for admin features.
 *
 * Registers the Status page, Sandbox Library page, and REST API extensions
 * added for the current admin UI. The existing admin pages (ConfigurationPage, SandboxPage,
 * AuditLogPage, AbilitiesPage) continue to register themselves through
 * PluginRegistration::register_hooks(). AdminBootstrap supplements them.
 */
final class AdminBootstrap {

	private static bool $registered = false;

	/**
	 * Register all admin hooks. Idempotent — safe to call multiple times.
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		StatusPage::register();
		SandboxLibraryPage::register();
		AdminShell::register();

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
			'stonewright-admin-shell',
			$url_base . 'assets/admin/shell.css',
			[],
			$version
		);

		wp_enqueue_style(
			'stonewright-admin',
			$url_base . 'assets/admin/admin.css',
			[ 'stonewright-admin-shell' ],
			$version
		);

		wp_enqueue_style(
			'stonewright-admin-ds',
			$url_base . 'assets/css/stonewright-admin.css',
			[ 'stonewright-admin-shell' ],
			$version
		);

		wp_enqueue_script(
			'stonewright-admin-shell',
			$url_base . 'assets/admin/shell.js',
			[],
			$version,
			true
		);

		wp_localize_script(
			'stonewright-admin-shell',
			'stonewrightShell',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( AdminShell::THEME_NONCE ),
			]
		);

		wp_enqueue_script(
			'stonewright-admin',
			$url_base . 'assets/admin/admin.js',
			[ 'stonewright-admin-shell' ],
			$version,
			true
		);

		// Page-scoped premium styles (only on Stonewright admin pages).
		$page = isset( $_GET['page'] ) ? sanitize_key( (string) wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page_styles = [
			'stonewright'             => 'setup.css',
			'stonewright-abilities'   => 'abilities.css',
			'stonewright-status'      => 'dashboard.css',
			'stonewright-audit-log'   => 'audit.css',
			'stonewright-skills'      => 'skills-memory.css',
			'stonewright-memory'      => 'skills-memory.css',
			'stonewright-sandbox'     => 'sandbox.css',
		];

		if ( isset( $page_styles[ $page ] ) ) {
			$handle = 'stonewright-admin-' . str_replace( [ 'stonewright-', '.css' ], [ '', '' ], $page_styles[ $page ] );
			if ( 'setup.css' === $page_styles[ $page ] ) {
				$handle = 'stonewright-admin-setup';
			} elseif ( 'skills-memory.css' === $page_styles[ $page ] ) {
				$handle = 'stonewright-admin-skills-memory';
			} elseif ( 'abilities.css' === $page_styles[ $page ] ) {
				$handle = 'stonewright-admin-abilities';
			} elseif ( 'dashboard.css' === $page_styles[ $page ] ) {
				$handle = 'stonewright-admin-dashboard';
			} elseif ( 'audit.css' === $page_styles[ $page ] ) {
				$handle = 'stonewright-admin-audit';
			} elseif ( 'sandbox.css' === $page_styles[ $page ] ) {
				$handle = 'stonewright-admin-sandbox';
			}

			wp_enqueue_style(
				$handle,
				$url_base . 'assets/admin/' . $page_styles[ $page ],
				[ 'stonewright-admin-shell', 'stonewright-admin' ],
				$version
			);
		}

		// Top-level Setup also matches via hook suffix when page query is missing.
		if ( ( 'stonewright' === $page || str_contains( $hook_suffix, 'toplevel_page_stonewright' ) )
			&& ! wp_style_is( 'stonewright-admin-setup', 'enqueued' )
		) {
			wp_enqueue_style(
				'stonewright-admin-setup',
				$url_base . 'assets/admin/setup.css',
				[ 'stonewright-admin-shell', 'stonewright-admin' ],
				$version
			);
		}

		if ( 'stonewright' === $page || str_contains( $hook_suffix, 'toplevel_page_stonewright' ) ) {
			wp_localize_script(
				'stonewright-admin',
				'stonewrightSetup',
				[
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'stonewright_setup_client' ),
				]
			);
		}
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
