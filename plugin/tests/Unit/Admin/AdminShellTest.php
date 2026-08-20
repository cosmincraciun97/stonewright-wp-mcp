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
		self::assertNotContains( 'stonewright-design-studio', $slugs );
		self::assertNotContains( 'stonewright-blueprints', $slugs );
		self::assertNotContains( 'stonewright-visual-workspace', $slugs );
		self::assertContains( 'stonewright-prompts', $slugs );
		self::assertContains( 'stonewright-sandbox', $slugs );
		self::assertContains( 'stonewright-block-finalizer', $slugs );
		self::assertSame( 'Block Editor Queue', $pages['stonewright-block-finalizer'] );
		self::assertContains( 'stonewright-skills', $slugs );
		self::assertContains( 'stonewright-memory', $slugs );
		self::assertContains( 'stonewright-audit-log', $slugs );
		self::assertContains( 'stonewright-status', $slugs );
		self::assertContains( 'stonewright-design', $slugs );
		self::assertContains( 'stonewright-context', $slugs );
		self::assertContains( 'stonewright-troubleshoot', $slugs );
		self::assertSame( 'Setup', $pages['stonewright'] );
		self::assertSame( 'Troubleshoot', $pages['stonewright-troubleshoot'] );
		self::assertSame( 'Dashboard', $pages['stonewright-status'] );
		self::assertSame( 'AI Abilities', $pages['stonewright-abilities'] );
		self::assertSame( 'Audit Log', $pages['stonewright-audit-log'] );
		self::assertSame( 'Memory', $pages['stonewright-memory'] );
		self::assertSame( 'Skills', $pages['stonewright-skills'] );
		self::assertSame( 'Design', $pages['stonewright-design'] );
		self::assertSame( 'Context', $pages['stonewright-context'] );
	}

	public function test_menu_groups_are_at_most_six_and_cover_all_page_slugs(): void {
		$groups = AdminShell::menu_groups();
		self::assertCount( 5, $groups );
		self::assertLessThanOrEqual( 6, count( $groups ) );
		self::assertGreaterThanOrEqual( 1, count( $groups ) );

		$ids = array_column( $groups, 'id' );
		self::assertContains( 'overview', $ids );
		self::assertContains( 'connect', $ids );
		self::assertContains( 'capabilities', $ids );
		self::assertContains( 'workflows', $ids );
		self::assertNotContains( 'design-library', $ids );
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

		$connect = [];
		foreach ( $groups as $group ) {
			if ( 'connect' === $group['id'] ) {
				$connect = $group['pages'];
			}
		}
		self::assertSame(
			[
				'stonewright'               => 'Setup',
				'stonewright-troubleshoot'  => 'Troubleshoot',
			],
			$connect
		);

		$workflows = [];
		$safety    = [];
		foreach ( $groups as $group ) {
			if ( 'workflows' === $group['id'] ) {
				$workflows = $group['pages'];
			}
			if ( 'safety-diagnostics' === $group['id'] ) {
				$safety = $group['pages'];
			}
		}
		self::assertSame(
			[
				'stonewright-context'         => 'Context',
				'stonewright-skills'          => 'Skills',
				'stonewright-memory'          => 'Memory',
				'stonewright-design'          => 'Design',
				'stonewright-sandbox'         => 'Sandbox',
				'stonewright-block-finalizer' => 'Block Editor Queue',
				'stonewright-prompts'         => 'Prompts',
			],
			$workflows
		);
		self::assertSame(
			[
				'stonewright-audit-log' => 'Audit Log',
			],
			$safety
		);

		$overview = [];
		$capabilities = [];
		foreach ( $groups as $group ) {
			if ( 'overview' === $group['id'] ) {
				$overview = $group;
			}
			if ( 'capabilities' === $group['id'] ) {
				$capabilities = $group;
			}
		}
		self::assertSame( 'Dashboard', $overview['label'] );
		self::assertSame( 'Dashboard', $overview['pages']['stonewright-status'] ?? null );
		self::assertSame( 'AI Abilities', $capabilities['label'] );
		self::assertSame( 'AI Abilities', $capabilities['pages']['stonewright-abilities'] ?? null );
	}

	public function test_open_and_close_produce_shell_markup_without_header_meta(): void {
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
		self::assertStringContainsString( 'data-sw-nav-group="workflows"', $html );
		self::assertStringContainsString( 'data-sw-nav-group="safety-diagnostics"', $html );
		self::assertStringContainsString( 'sw-shell__content', $html );
		self::assertStringContainsString( 'sw-notice-drawer', $html );
		self::assertStringContainsString( 'aria-current="page"', $html );
		self::assertStringContainsString( 'admin.php?page=stonewright-abilities', $html );
		self::assertStringNotContainsString( 'sw-mode-pill', $html );
		self::assertStringNotContainsString( 'sw-shell__meta', $html );
		self::assertStringNotContainsString( 'sw-shell__version', $html );
		self::assertStringNotContainsString( '0.0.0-test', $html );
		self::assertStringContainsString( 'Stonewright notice', $html );
		self::assertStringContainsString( '</div><!-- .sw-shell -->', $html );
		$this->assert_experimental_nav_markup( $html );
	}

	public function test_open_marks_current_nav_item_and_omits_mode_from_header(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';

		ob_start();
		AdminShell::open( 'stonewright-status' );
		AdminShell::close();
		$html = (string) ob_get_clean();

		self::assertMatchesRegularExpression(
			'/<a[^>]+href="[^"]*page=stonewright-status"[^>]*aria-current="page"/',
			$html
		);
		self::assertStringNotContainsString( 'sw-mode-pill', $html );
		self::assertStringNotContainsString( 'sw-mode-pill--production-safe', $html );
		self::assertStringNotContainsString( 'sw-shell__meta', $html );
		self::assertStringNotContainsString( '<script>', $html );
	}

	/**
	 * Experimental is inline text on the same nav line — not a pill or chip.
	 */
	private function assert_experimental_nav_markup( string $html ): void {
		$experimental = [
			'stonewright-troubleshoot'  => 'Troubleshoot',
			'stonewright-context'       => 'Context',
			'stonewright-design'        => 'Design',
			'stonewright-block-finalizer' => 'Block Editor Queue',
		];
		foreach ( $experimental as $slug => $label ) {
			self::assertMatchesRegularExpression(
				'/page=' . preg_quote( $slug, '/' ) . '"[^>]*>' . preg_quote( $label, '/' ) . ' <span class="sw-shell__exp">Experimental<\/span><\/a>/',
				$html
			);
		}

		$plain = [
			'stonewright'               => 'Setup',
			'stonewright-status'        => 'Dashboard',
			'stonewright-abilities'     => 'AI Abilities',
			'stonewright-skills'        => 'Skills',
			'stonewright-memory'        => 'Memory',
			'stonewright-sandbox'       => 'Sandbox',
			'stonewright-prompts'       => 'Prompts',
			'stonewright-audit-log'     => 'Audit Log',
		];
		foreach ( $plain as $slug => $label ) {
			self::assertMatchesRegularExpression(
				'/page=' . preg_quote( $slug, '/' ) . '"[^>]*>' . preg_quote( $label, '/' ) . '<\/a>/',
				$html
			);
		}

		self::assertSame( 4, substr_count( $html, 'class="sw-shell__exp"' ) );
		self::assertStringNotContainsString( 'sw-shell__exp--pill', $html );
		self::assertStringNotContainsString( 'sw-exp-chip', $html );
	}

}
