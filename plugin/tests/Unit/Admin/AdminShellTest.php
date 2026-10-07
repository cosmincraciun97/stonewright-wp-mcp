<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\AdminShell;
use Stonewright\WpMcp\Admin\MenuRegistry;

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
		MenuRegistry::reset_for_tests();
		$_GET = [];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_user_meta']       = [];
		MenuRegistry::reset_for_tests();
		$_GET = [];
	}

	/** @param array<string, mixed> $args */
	private function shell( string $slug, array $args = [], string $content = '<p class="sw-notice">Stonewright notice</p>' ): string {
		ob_start();
		AdminShell::open( $slug, $args );
		echo $content;
		AdminShell::close();

		return (string) ob_get_clean();
	}

	public function test_pages_lists_every_registered_page_by_slug(): void {
		$pages = AdminShell::pages();

		self::assertSame( 'Setup', $pages['stonewright'] );
		self::assertSame( 'Overview', $pages['stonewright-status'] );
		self::assertSame( 'Troubleshoot', $pages['stonewright-troubleshoot'] );
		self::assertSame( 'AI Abilities', $pages['stonewright-abilities'] );
		self::assertSame( 'Audit log', $pages['stonewright-audit-log'] );
		self::assertSame( 'Prompt library', $pages['stonewright-prompts'] );
		self::assertArrayNotHasKey( 'stonewright-design-studio', $pages );
		self::assertArrayNotHasKey( 'stonewright-blueprints', $pages );
		self::assertArrayNotHasKey( 'stonewright-visual-workspace', $pages );
	}

	public function test_open_prints_a_page_header_in_place_of_the_header_band(): void {
		$html = $this->shell( 'stonewright-abilities' );

		self::assertStringContainsString( 'class="sw-shell wrap stonewright-admin-shell"', $html );
		self::assertStringContainsString( 'data-sw-shell', $html );
		self::assertStringContainsString( '<h1 class="sw-ui-page-title">AI Abilities</h1>', $html );
		self::assertStringContainsString( 'class="sw-ui-page-lede"', $html );
		self::assertStringContainsString( 'Search, inspect, and toggle the MCP tool surface', $html );
		self::assertStringNotContainsString( 'sw-shell__header', $html );
		self::assertStringNotContainsString( 'sw-shell__nav', $html );
		self::assertStringNotContainsString( 'sw-shell__brand', $html );
		self::assertStringNotContainsString( 'role="banner"', $html );
		self::assertStringNotContainsString( 'sw-mode-pill', $html );
		self::assertStringNotContainsString( '0.0.0-test', $html );
		self::assertStringContainsString( 'Stonewright notice', $html );
		self::assertStringContainsString( '</div><!-- .sw-shell -->', $html );
	}

	public function test_there_is_exactly_one_h1_and_one_navigation_system(): void {
		$html = $this->shell( 'stonewright-skills' );

		self::assertSame( 1, substr_count( $html, '<h1' ) );
		self::assertSame( 1, substr_count( $html, '<nav' ), 'One tab bar and no second navigation.' );
	}

	public function test_a_skip_link_is_the_first_thing_and_lands_on_the_content(): void {
		$html = $this->shell( 'stonewright-skills' );

		$skip = strpos( $html, 'class="screen-reader-shortcut" href="#sw-main"' );
		self::assertNotFalse( $skip );
		self::assertLessThan( (int) strpos( $html, '<h1' ), $skip );
		self::assertLessThan( (int) strpos( $html, '<nav' ), $skip );
		self::assertMatchesRegularExpression( '/<div class="sw-shell__main" id="sw-main" tabindex="-1">/', $html );
		self::assertGreaterThan( (int) strpos( $html, '<nav' ), (int) strpos( $html, 'id="sw-main"' ), 'The link jumps past the header and the tab bar.' );
	}

	public function test_the_header_ends_with_the_marker_wordpress_places_notices_after(): void {
		$html = $this->shell( 'stonewright-skills' );

		self::assertSame( 1, substr_count( $html, '<hr class="wp-header-end">' ) );
		$marker = (int) strpos( $html, '<hr class="wp-header-end">' );
		self::assertGreaterThan( (int) strpos( $html, '</nav>' ), $marker, 'After the header and the tab bar.' );
		self::assertLessThan( (int) strpos( $html, 'id="sw-main"' ), $marker, 'Before the content.' );
		self::assertLessThan( (int) strpos( $html, 'data-sw-notice-drawer' ), $marker, 'The overflow drawer follows the notices.' );
	}

	public function test_the_notice_drawer_starts_hidden_and_never_holds_page_content(): void {
		$html = $this->shell( 'stonewright', [], '<div class="notice notice-error stonewright-notice"><p>Own notice</p></div>' );

		self::assertMatchesRegularExpression( '/<details class="sw-notice-drawer" data-sw-notice-drawer[^>]* hidden>/', $html );
		$drawer_end = (int) strpos( $html, '</details>' );
		self::assertGreaterThan( $drawer_end, (int) strpos( $html, 'Own notice' ), 'Content is rendered after the drawer, never inside it.' );
		self::assertStringContainsString( 'data-sw-notice-labels=', $html );
	}

	public function test_a_hub_with_several_pages_gets_a_tab_bar_that_marks_the_current_page(): void {
		$html = $this->shell( 'stonewright-memory' );

		self::assertStringContainsString( '<nav aria-label="Knowledge sections">', $html );
		self::assertMatchesRegularExpression( '/<a class="sw-ui-hubnav__link" href="[^"]*page=stonewright-memory" aria-current="page">Memory<\/a>/', $html );
		foreach ( [ 'stonewright-skills', 'stonewright-context', 'stonewright-design', 'stonewright-prompts' ] as $slug ) {
			self::assertStringContainsString( 'page=' . $slug, $html, $slug );
		}
		self::assertSame( 1, substr_count( $html, 'aria-current="page"' ) );
	}

	public function test_a_hub_with_one_page_has_no_tab_bar(): void {
		foreach ( [ 'stonewright-status', 'stonewright-abilities' ] as $slug ) {
			self::assertStringNotContainsString( 'sw-ui-hubnav', $this->shell( $slug ), $slug );
		}
	}

	public function test_the_custom_code_tabs_follow_the_request(): void {
		$_GET['tab'] = 'library';
		$html        = $this->shell( 'stonewright-sandbox' );

		self::assertMatchesRegularExpression( '/<a class="sw-ui-hubnav__link" href="[^"]*tab=library" aria-current="page">Library<\/a>/', $html );
		self::assertStringContainsString( '<h1 class="sw-ui-page-title">Custom code</h1>', $html );
	}

	public function test_a_page_can_name_its_own_hub_title_lede_and_actions(): void {
		MenuRegistry::add( 'stonewright-rescue', 'Rescue', 'activity', [ 'order' => 30 ] );
		$html = $this->shell(
			'stonewright-rescue',
			[
				'title'   => 'Rescue',
				'lede'    => 'Roll back a change.',
				'actions' => '<button type="button" class="sw-ui-btn">Open in safe mode</button>',
			]
		);

		self::assertStringContainsString( '<h1 class="sw-ui-page-title">Rescue</h1>', $html );
		self::assertStringContainsString( '<p class="sw-ui-page-lede">Roll back a change.</p>', $html );
		self::assertMatchesRegularExpression( '/<div class="sw-ui-page-header__aside">.*Open in safe mode.*<\/div><\/header>/s', $html );
		self::assertStringContainsString( '<nav aria-label="Activity sections">', $html );
		self::assertMatchesRegularExpression( '/page=stonewright-rescue" aria-current="page">Rescue</', $html );
	}

	public function test_an_explicit_empty_hub_removes_the_tab_bar(): void {
		self::assertStringNotContainsString( 'sw-ui-hubnav', $this->shell( 'stonewright-memory', [ 'hub' => '' ] ) );
	}

	public function test_the_title_is_escaped(): void {
		$html = $this->shell( 'stonewright-memory', [ 'title' => '<script>alert(1)</script>', 'lede' => '<b>x</b>' ] );

		self::assertStringNotContainsString( '<script>', $html );
		self::assertStringNotContainsString( '<b>x</b>', $html );
	}

	public function test_a_page_that_is_still_changing_says_beta_in_words_with_a_visible_explanation(): void {
		$html = $this->shell( 'stonewright-context' );

		self::assertMatchesRegularExpression( '/<span class="sw-ui-badge sw-ui-badge--info">Beta<\/span>/', $html );
		self::assertMatchesRegularExpression( '/<span class="sw-ui-hint">[^<]+<\/span>/', $html );
		self::assertStringNotContainsString( 'EXP', $html );
		self::assertStringNotContainsString( 'data-sw-tooltip', $html, 'Nothing essential lives in a hover tooltip.' );

		self::assertStringNotContainsString( 'Beta', $this->shell( 'stonewright-skills' ) );
	}

	public function test_the_beta_sidebar_title_is_the_label_and_a_word(): void {
		self::assertSame(
			'<span class="sw-menu-label">Troubleshoot</span> <span class="sw-menu-beta">Beta</span>',
			AdminShell::beta_menu_title( 'Troubleshoot' )
		);
		self::assertStringNotContainsString( '<script', AdminShell::beta_menu_title( '<script>' ) );
	}

	public function test_an_unregistered_page_still_opens_with_a_title_and_no_tab_bar(): void {
		$html = $this->shell( 'stonewright-unknown' );

		self::assertStringContainsString( '<h1 class="sw-ui-page-title">Stonewright</h1>', $html );
		self::assertStringNotContainsString( 'sw-ui-hubnav', $html );
	}
}
