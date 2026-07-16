<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\AdminShell;

/**
 * @covers \Stonewright\WpMcp\Admin\AdminShell
 */
final class AdminShellTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps']        = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_current_user_id']  = 7;
		$GLOBALS['stonewright_test_options']          = [
			'stonewright_mode' => 'staging',
		];
		$GLOBALS['stonewright_test_user_meta']        = [];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_user_meta']       = [];
	}

	public function test_pages_registry_includes_all_registered_admin_pages(): void {
		$pages = AdminShell::pages();
		$slugs = array_keys( $pages );

		self::assertContains( 'stonewright', $slugs );
		self::assertContains( 'stonewright-abilities', $slugs );
		self::assertContains( 'stonewright-blueprints', $slugs );
		self::assertContains( 'stonewright-sandbox', $slugs );
		self::assertContains( 'stonewright-skills', $slugs );
		self::assertContains( 'stonewright-memory', $slugs );
		self::assertContains( 'stonewright-audit-log', $slugs );
		self::assertContains( 'stonewright-status', $slugs );
		self::assertSame( 'Setup', $pages['stonewright'] );
	}

	public function test_menu_groups_are_at_most_six_and_cover_all_page_slugs(): void {
		$groups = AdminShell::menu_groups();
		self::assertLessThanOrEqual( 6, count( $groups ) );
		self::assertGreaterThanOrEqual( 1, count( $groups ) );

		$ids = array_column( $groups, 'id' );
		self::assertContains( 'overview', $ids );
		self::assertContains( 'connect', $ids );
		self::assertContains( 'capabilities', $ids );
		self::assertContains( 'workflows', $ids );
		self::assertContains( 'design-library', $ids );
		self::assertContains( 'safety-diagnostics', $ids );

		$from_groups = [];
		foreach ( $groups as $group ) {
			self::assertArrayHasKey( 'label', $group );
			self::assertNotEmpty( $group['pages'] );
			foreach ( array_keys( $group['pages'] ) as $slug ) {
				$from_groups[] = $slug;
			}
		}
		self::assertSame( array_keys( AdminShell::pages() ), $from_groups );
	}

	public function test_open_and_close_produce_shell_markup_with_nav_and_mode_pill(): void {
		ob_start();
		AdminShell::open( 'stonewright' );
		echo '<p class="sw-notice">Stonewright notice</p>';
		AdminShell::close();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'class="sw-shell', $html );
		self::assertStringContainsString( 'sw-shell__header', $html );
		self::assertStringContainsString( 'sw-shell__nav', $html );
		self::assertStringContainsString( 'sw-shell__nav-group', $html );
		self::assertStringContainsString( 'data-sw-nav-group="connect"', $html );
		self::assertStringContainsString( 'data-sw-nav-group="safety-diagnostics"', $html );
		self::assertStringContainsString( 'sw-shell__content', $html );
		self::assertStringContainsString( 'sw-notice-drawer', $html );
		self::assertStringContainsString( 'aria-current="page"', $html );
		self::assertStringContainsString( 'admin.php?page=stonewright-abilities', $html );
		self::assertStringContainsString( 'sw-mode-pill', $html );
		self::assertStringContainsString( 'staging', $html );
		self::assertStringContainsString( 'sw-theme-toggle', $html );
		self::assertStringContainsString( 'aria-pressed=', $html );
		self::assertStringContainsString( 'Stonewright notice', $html );
		self::assertStringContainsString( '</div><!-- .sw-shell -->', $html );
	}

	public function test_open_marks_current_nav_item_and_escapes_mode_value(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';

		ob_start();
		AdminShell::open( 'stonewright-status' );
		AdminShell::close();
		$html = (string) ob_get_clean();

		self::assertMatchesRegularExpression(
			'/<a[^>]+href="[^"]*page=stonewright-status"[^>]*aria-current="page"/',
			$html
		);
		self::assertStringContainsString( 'sw-mode-pill--production-safe', $html );
		self::assertStringContainsString( 'production-safe', $html );
		self::assertStringNotContainsString( '<script>', $html );
	}

	public function test_open_applies_dark_theme_class_from_user_meta(): void {
		$GLOBALS['stonewright_test_user_meta'][7]['stonewright_admin_theme'] = 'dark';

		ob_start();
		AdminShell::open( 'stonewright' );
		AdminShell::close();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'sw-theme-dark', $html );
		self::assertStringContainsString( 'aria-pressed="true"', $html );
	}

	public function test_resolve_theme_defaults_to_light(): void {
		self::assertSame( 'light', AdminShell::resolve_theme() );
	}
}
