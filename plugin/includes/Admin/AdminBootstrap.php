<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

use Stonewright\WpMcp\Admin\Pages\ContextPage;
use Stonewright\WpMcp\Admin\Pages\DesignPage;
use Stonewright\WpMcp\Admin\Pages\PromptLibraryPage;
use Stonewright\WpMcp\Admin\Pages\SandboxLibraryPage;
use Stonewright\WpMcp\Admin\Pages\StatusPage;
use Stonewright\WpMcp\Admin\Pages\TroubleshootPage;
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

		MenuOrder::register();
		PluginActionLinks::register();
		ActivationRedirect::register();
		HelpTabs::register();
		StatusPage::register();
		// Design Library admin UI (Design Studio / Visual Workspace / Blueprints)
		// is intentionally not registered. Typed design abilities remain available
		// over MCP; storage tables/options are preserved.
		DesignPage::register();
		ContextPage::register();
		TroubleshootPage::register();
		PromptLibraryPage::register();
		SandboxLibraryPage::register();
		add_action( 'rest_api_init', [ RestApi::class, 'register' ] );
		// DesignStudioRestApi stays unregistered with the Design Library UI.
		add_action( 'rest_api_init', [ SkillsRestApi::class, 'register' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
		add_action( 'admin_head', [ self::class, 'output_menu_styles' ] );
		add_action( 'admin_notices', [ CrashRecovery::class, 'admin_notice' ] );
		add_action( 'admin_notices', [ self::class, 'production_mode_mismatch_notice' ] );
		add_filter( 'kses_allowed_protocols', [ self::class, 'allow_cursor_protocol' ] );
	}

	/**
	 * Allow Cursor one-click MCP install links through WordPress URL sanitization.
	 *
	 * @param list<string> $protocols Allowed URL schemes.
	 * @return list<string>
	 */
	public static function allow_cursor_protocol( array $protocols ): array {
		if ( ! in_array( 'cursor', $protocols, true ) ) {
			$protocols[] = 'cursor';
		}

		return $protocols;
	}

	/**
	 * P0: production environment with non-production-safe Stonewright mode.
	 */
	public static function production_mode_mismatch_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! function_exists( 'wp_get_environment_type' ) || 'production' !== wp_get_environment_type() ) {
			return;
		}
		$mode = (string) get_option( 'stonewright_mode', 'development' );
		if ( 'production-safe' === $mode ) {
			return;
		}
		echo '<div class="notice notice-error stonewright-notice"><p><strong>';
		echo esc_html__( 'Stonewright P0:', 'stonewright' );
		echo '</strong> ';
		echo esc_html(
			sprintf(
				/* translators: %s: current mode */
				__( 'WordPress environment is production but Stonewright mode is "%s". Switch to production-safe before agent writes.', 'stonewright' ),
				$mode
			)
		);
		echo '</p></div>';
	}

	/**
	 * Sidebar Beta marker. shell.css only loads on Stonewright pages;
	 * the left menu is visible everywhere in wp-admin.
	 */
	public static function output_menu_styles(): void {
		if ( ! is_admin() ) {
			return;
		}
		if ( ! current_user_can( 'edit_posts' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<style id="stonewright-admin-menu">'
			. '#adminmenu .wp-submenu a .sw-menu-beta{display:inline-block;margin-inline-start:6px;padding:0 5px;border:1px solid currentColor;border-radius:3px;font-size:12px;font-weight:500;line-height:16px;white-space:nowrap;}'
			. '</style>' . "\n";
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

		$version  = defined( 'STONEWRIGHT_VERSION' ) ? (string) constant( 'STONEWRIGHT_VERSION' ) : '0.1.0';
		$url_base = defined( 'STONEWRIGHT_URL' ) ? (string) constant( 'STONEWRIGHT_URL' ) : '';
		// Bust browser cache when any shared admin asset changes without a version bump.
		$asset_mtimes = [];
		$plugin_path  = defined( 'STONEWRIGHT_PATH' ) ? (string) constant( 'STONEWRIGHT_PATH' ) : '';
		foreach ( [ 'assets/admin/sw-ui.css', 'assets/admin/sw-ui.js', 'assets/admin/shell.css', 'assets/admin/shell.js', 'assets/admin/admin.css', 'assets/admin/admin.js' ] as $asset ) {
			$path = $plugin_path . $asset;
			if ( '' !== $plugin_path && is_readable( $path ) ) {
				$asset_mtimes[] = (int) filemtime( $path );
			}
		}
		if ( [] !== $asset_mtimes ) {
			$version .= '.' . (string) max( $asset_mtimes );
		}

		if ( '' === $url_base ) {
			return;
		}

		// The shared UI layer is registered first and everything else depends on it, so its tokens and
		// components are in place before any page file. It applies only inside `.sw-ui`.
		wp_enqueue_style(
			'stonewright-ui',
			$url_base . 'assets/admin/sw-ui.css',
			[],
			$version
		);

		wp_enqueue_script(
			'stonewright-ui',
			$url_base . 'assets/admin/sw-ui.js',
			[],
			$version,
			true
		);

		wp_enqueue_style(
			'stonewright-admin-shell',
			$url_base . 'assets/admin/shell.css',
			[ 'stonewright-ui' ],
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
			[ 'stonewright-ui' ],
			$version,
			true
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
			'stonewright'               => 'setup.css',
			'stonewright-troubleshoot' => 'setup.css',
			'stonewright-abilities'     => 'abilities.css',
			'stonewright-prompts'       => 'pages/prompts.css',
			'stonewright-status'        => 'pages/overview.css',
			'stonewright-audit-log'     => 'audit.css',
			'stonewright-skills'        => 'pages/skills.css',
			'stonewright-memory'        => 'pages/memory.css',
			'stonewright-sandbox'       => 'sandbox.css',
			'stonewright-design'        => 'pages/design.css',
			'stonewright-context'       => 'pages/context.css',
			'stonewright-rescue'        => 'pages/rescue.css',
		];

		if ( isset( $page_styles[ $page ] ) ) {
			$handle = 'stonewright-admin-' . str_replace( [ 'stonewright-', '.css' ], [ '', '' ], $page_styles[ $page ] );
			if ( 'setup.css' === $page_styles[ $page ] ) {
				$handle = 'stonewright-admin-setup';
			} elseif ( 'abilities.css' === $page_styles[ $page ] ) {
				$handle = 'stonewright-admin-abilities';
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

		if ( 'stonewright' === $page || 'stonewright-troubleshoot' === $page || str_contains( $hook_suffix, 'toplevel_page_stonewright' ) ) {
			wp_localize_script(
				'stonewright-admin',
				'stonewrightSetup',
				[
					'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
					'nonce'            => wp_create_nonce( 'stonewright_setup_client' ),
					'appPasswordUrl'   => rest_url( 'stonewright/v1/app-password' ),
					'restNonce'        => wp_create_nonce( 'wp_rest' ),
					'username'         => (string) ( wp_get_current_user()->user_login ?? '' ),
				]
			);
		}

		// Design Studio / Visual Workspace admin assets are not enqueued —
		// Design Library UI is disabled. MCP design abilities remain available.

		// The skill lifecycle app and its boot payload load on that page only.
		if ( SkillsPage::SLUG === $page ) {
			wp_enqueue_script(
				'stonewright-admin-skills',
				$url_base . 'assets/admin/skills.js',
				[ 'stonewright-admin' ],
				$version,
				true
			);

			wp_localize_script(
				'stonewright-admin-skills',
				'stonewrightSkills',
				SkillsPage::boot_payload()
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
