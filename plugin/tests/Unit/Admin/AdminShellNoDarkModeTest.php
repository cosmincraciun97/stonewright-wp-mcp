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
		self::assertStringNotContainsString( 'sw-theme-toggle', $php );
		self::assertStringNotContainsString( 'ICON_MOON', $php );
	}

	public function test_admin_shell_class_has_no_theme_methods(): void {
		self::assertFalse( method_exists( AdminShell::class, 'resolve_theme' ) );
		self::assertFalse( method_exists( AdminShell::class, 'handle_set_theme' ) );
		self::assertFalse( defined( AdminShell::class . '::THEME_META_KEY' ) );
	}

	public function test_admin_bootstrap_registers_no_theme_ajax_handler(): void {
		AdminBootstrap::reset_for_tests();
		$GLOBALS['stonewright_test_actions'] = [];

		AdminBootstrap::register();

		self::assertArrayNotHasKey( 'wp_ajax_stonewright_set_admin_theme', $GLOBALS['stonewright_test_actions'] );
		AdminBootstrap::reset_for_tests();
	}

	public function test_shell_script_has_no_theme_code(): void {
		$js = self::source( 'assets/admin/shell.js' );
		self::assertStringNotContainsString( 'applyThemeClass', $js );
		self::assertStringNotContainsString( 'initThemeToggle', $js );
		self::assertStringNotContainsString( 'stonewright_set_admin_theme', $js );
	}

	public function test_shell_stylesheet_has_no_dark_surface(): void {
		$css = self::source( 'assets/admin/shell.css' );
		self::assertStringNotContainsString( 'prefers-color-scheme', $css );
		self::assertStringNotContainsString( 'sw-theme-dark', $css );
		self::assertStringNotContainsString( 'sw-theme-toggle', $css );
		self::assertStringNotContainsString( 'color-scheme: dark', $css );
	}
}
