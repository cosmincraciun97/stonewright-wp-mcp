<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\AdminBootstrap;
use Stonewright\WpMcp\Admin\AdminShell;

/**
 * Dark mode is removed. These tests exist so it cannot come back by accident.
 */
final class AdminShellNoDarkModeTest extends TestCase {

	private static function source( string $relative ): string {
		$path = dirname( __DIR__, 3 ) . '/' . $relative;
		self::assertFileExists( $path, $relative . ' must exist' );

		return (string) file_get_contents( $path );
	}

	public function test_admin_shell_has_no_theme_surface(): void {
		$php = self::source( 'includes/Admin/AdminShell.php' );
		self::assertStringNotContainsString( 'stonewright_admin_theme', $php );
		self::assertStringNotContainsString( 'sw-theme-dark', $php );
		self::assertStringNotContainsString( 'sw-theme-light', $php );
		self::assertStringNotContainsString( 'sw-theme-toggle', $php );
		self::assertStringNotContainsString( 'data-sw-theme', $php );
		self::assertStringNotContainsString( 'THEME_NONCE', $php );
		self::assertStringNotContainsString( 'ICON_SUN', $php );
		self::assertStringNotContainsString( 'ICON_MOON', $php );
	}

	public function test_admin_shell_class_has_no_theme_methods(): void {
		self::assertFalse( method_exists( AdminShell::class, 'resolve_theme' ) );
		self::assertFalse( method_exists( AdminShell::class, 'handle_set_theme' ) );
		self::assertFalse( method_exists( AdminShell::class, 'register' ) );
		self::assertFalse( defined( AdminShell::class . '::THEME_META_KEY' ) );
		self::assertFalse( defined( AdminShell::class . '::THEME_NONCE' ) );
		self::assertFalse( defined( AdminShell::class . '::ICON_SUN' ) );
	}

	public function test_admin_bootstrap_registers_no_theme_ajax_handler(): void {
		$previous_actions = $GLOBALS['stonewright_test_actions'] ?? null;
		AdminBootstrap::reset_for_tests();
		$GLOBALS['stonewright_test_actions'] = [];

		try {
			AdminBootstrap::register();

			self::assertArrayNotHasKey( 'wp_ajax_stonewright_set_admin_theme', $GLOBALS['stonewright_test_actions'] );
		} finally {
			AdminBootstrap::reset_for_tests();
			if ( null === $previous_actions ) {
				unset( $GLOBALS['stonewright_test_actions'] );
			} else {
				$GLOBALS['stonewright_test_actions'] = $previous_actions;
			}
		}
	}

	public function test_admin_bootstrap_has_no_theme_registration_or_localization(): void {
		$bootstrap = self::source( 'includes/Admin/AdminBootstrap.php' );
		self::assertStringNotContainsString( 'AdminShell::register()', $bootstrap );
		self::assertStringNotContainsString( 'THEME_NONCE', $bootstrap );
		self::assertStringNotContainsString( 'stonewrightShell', $bootstrap );
	}

	public function test_rendered_shell_has_no_theme_attributes_or_classes(): void {
		ob_start();
		AdminShell::open( 'stonewright' );
		AdminShell::close();
		$html = (string) ob_get_clean();

		self::assertStringNotContainsString( 'data-sw-theme', $html );
		self::assertStringNotContainsString( 'sw-theme-light', $html );
		self::assertStringNotContainsString( 'sw-theme-dark', $html );
	}

	public function test_shell_script_has_no_theme_code(): void {
		$js = self::source( 'assets/admin/shell.js' );
		self::assertStringNotContainsString( 'applyThemeClass', $js );
		self::assertStringNotContainsString( 'initThemeToggle', $js );
		self::assertStringNotContainsString( 'stonewright_set_admin_theme', $js );
		self::assertStringNotContainsString( 'data-sw-theme', $js );
		self::assertStringNotContainsString( 'sw-theme-light', $js );
	}

	public function test_shell_stylesheet_has_no_dark_surface(): void {
		$css = self::source( 'assets/admin/shell.css' );
		self::assertStringNotContainsString( 'prefers-color-scheme', $css );
		self::assertStringNotContainsString( 'sw-theme-dark', $css );
		self::assertStringNotContainsString( 'sw-theme-toggle', $css );
		self::assertStringNotContainsString( 'color-scheme: dark', $css );
		self::assertMatchesRegularExpression(
			'/:root\\s*\\{(?:(?!\\n\\}).)*?\\n\\s*color-scheme:\\s*light\\s*;/s',
			$css,
			'The root token map must permanently opt the shell into light rendering.'
		);
	}
}
