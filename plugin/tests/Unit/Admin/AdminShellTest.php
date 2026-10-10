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

	public function test_open_prints_the_band_then_a_page_header(): void {
		$html = $this->shell( 'stonewright-abilities' );

		self::assertStringContainsString( 'class="sw-shell wrap stonewright-admin-shell"', $html );
		self::assertStringContainsString( 'data-sw-shell', $html );
		self::assertStringContainsString( '<h1 class="sw-ui-page-title">AI Abilities</h1>', $html );
		self::assertStringContainsString( 'class="sw-ui-page-lede"', $html );
		self::assertStringContainsString( 'Search, inspect, and toggle the MCP tool surface', $html );
		self::assertStringContainsString( '<header class="sw-ui-band" role="banner" data-sw-ui-band>', $html );
		self::assertStringContainsString( '<nav class="sw-ui-band__nav" aria-label="Stonewright admin">', $html );
		self::assertGreaterThan( (int) strpos( $html, 'sw-ui-band' ), (int) strpos( $html, '<h1' ), 'The page header is below the band.' );
		self::assertStringNotContainsString( 'sw-shell__header', $html );
		self::assertStringNotContainsString( 'sw-shell__nav', $html );
		self::assertStringNotContainsString( 'sw-shell__brand', $html );
		self::assertStringNotContainsString( 'sw-mode-pill', $html );
		self::assertStringNotContainsString( '0.0.0-test', $html );
		self::assertStringContainsString( 'Stonewright notice', $html );
		self::assertStringContainsString( '</div><!-- .sw-shell -->', $html );
	}

	public function test_the_band_is_in_scope_of_the_layer_and_outside_the_content_column(): void {
		$html = $this->shell( 'stonewright-skills' );

		self::assertMatchesRegularExpression( '/<div class="sw-ui"><header class="sw-ui-band"/', $html );
		self::assertLessThan( (int) strpos( $html, 'class="sw-shell__content"' ), (int) strpos( $html, 'sw-ui-band' ), 'The band spans the page; the content column is narrower.' );
	}

	public function test_there_is_exactly_one_h1_and_one_navigation_for_a_page_without_tabs(): void {
		$html = $this->shell( 'stonewright-skills' );

		self::assertSame( 1, substr_count( $html, '<h1' ) );
		self::assertSame( 1, substr_count( $html, '<nav' ), 'The band; the other pages are not repeated in a tab bar.' );
		self::assertStringNotContainsString( 'sw-ui-hubnav', $html );
	}

	public function test_a_skip_link_is_the_first_thing_and_lands_on_the_content(): void {
		$html = $this->shell( 'stonewright-skills' );

		$skip = strpos( $html, 'class="screen-reader-shortcut" href="#sw-main"' );
		self::assertNotFalse( $skip );
		self::assertLessThan( (int) strpos( $html, 'sw-ui-band' ), $skip, 'The link comes before the band so the band can be skipped.' );
		self::assertLessThan( (int) strpos( $html, '<h1' ), $skip );
		self::assertLessThan( (int) strpos( $html, '<nav' ), $skip );
		self::assertMatchesRegularExpression( '/<div class="sw-shell__main" id="sw-main" tabindex="-1">/', $html );
		self::assertGreaterThan( (int) strpos( $html, '<h1' ), (int) strpos( $html, 'id="sw-main"' ), 'The link jumps past the band and the header.' );
	}

	public function test_the_header_ends_with_the_marker_wordpress_places_notices_after(): void {
		$html = $this->shell( 'stonewright-skills' );

		self::assertSame( 1, substr_count( $html, '<hr class="wp-header-end">' ) );
		$marker = (int) strpos( $html, '<hr class="wp-header-end">' );
		self::assertGreaterThan( (int) strpos( $html, '</nav></header></div>' ), $marker, 'After the band.' );
		self::assertGreaterThan( (int) strpos( $html, '<h1' ), $marker, 'After the page header.' );
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

	public function test_the_band_links_every_page_and_marks_the_one_that_is_open(): void {
		$html = $this->shell( 'stonewright-memory' );

		foreach ( [ 'stonewright-status', 'stonewright', 'stonewright-troubleshoot', 'stonewright-abilities', 'stonewright-skills', 'stonewright-memory', 'stonewright-context', 'stonewright-design', 'stonewright-prompts', 'stonewright-sandbox', 'stonewright-custom-code-approval', 'stonewright-audit-log' ] as $slug ) {
			self::assertMatchesRegularExpression( '/<a class="sw-ui-band__link[^"]*" href="[^"]*page=' . preg_quote( $slug, '/' ) . '"/', $html, $slug );
		}
		self::assertMatchesRegularExpression( '/<a class="sw-ui-band__link" href="[^"]*page=stonewright-memory" aria-current="page">Memory<\/a>/', $html );
		self::assertSame( 1, substr_count( $html, 'aria-current="page"' ) );
		self::assertStringContainsString( '<span class="sw-ui-band__label" aria-hidden="true">Knowledge</span>', $html );
	}

	public function test_the_band_lists_only_the_pages_the_user_can_open(): void {
		$GLOBALS['stonewright_test_user_caps'] = [ 'read' => true ];

		$html = $this->shell( 'stonewright-skills' );

		self::assertStringNotContainsString( 'sw-ui-band__link', $html );
		self::assertStringContainsString( 'sw-ui-band__brand', $html );
	}

	public function test_a_page_with_no_tabs_of_its_own_has_no_tab_bar_and_a_page_with_tabs_has_only_its_own(): void {
		foreach ( [ 'stonewright-status', 'stonewright-abilities', 'stonewright-memory', 'stonewright-custom-code-approval' ] as $slug ) {
			self::assertStringNotContainsString( 'sw-ui-hubnav', $this->shell( $slug ), $slug );
		}

		$html = $this->shell( 'stonewright-sandbox' );
		self::assertStringContainsString( '<nav aria-label="Custom code sections">', $html );
		preg_match_all( '/<a class="sw-ui-hubnav__link"[^>]*>([^<]*)</', $html, $tabs );
		self::assertSame( [ 'Drafts', 'Library', 'Active', 'Crash recovery' ], $tabs[1] );
		self::assertSame( 2, substr_count( $html, '<nav' ), 'The band and the tabs of the page itself.' );
	}

	public function test_the_custom_code_tabs_follow_the_request_and_the_band_keeps_the_page_current(): void {
		$_GET['tab'] = 'library';
		$html        = $this->shell( 'stonewright-sandbox' );

		self::assertMatchesRegularExpression( '/<a class="sw-ui-hubnav__link" href="[^"]*tab=library" aria-current="page">Library<\/a>/', $html );
		self::assertMatchesRegularExpression( '/<a class="sw-ui-band__link" href="[^"]*page=stonewright-sandbox" aria-current="page">Custom code<\/a>/', $html );
		self::assertSame( 2, substr_count( $html, 'aria-current="page"' ) );
		self::assertStringContainsString( '<h1 class="sw-ui-page-title">Custom code</h1>', $html );
	}

	public function test_a_page_can_name_its_own_title_lede_and_actions(): void {
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
		self::assertMatchesRegularExpression( '/page=stonewright-rescue" aria-current="page">Rescue</', $html );
	}

	public function test_a_page_that_is_a_view_of_another_page_marks_that_page_in_the_band(): void {
		$html = $this->shell( 'stonewright-sandbox-library', [ 'title' => 'Custom code', 'current' => 'stonewright-sandbox' ] );

		self::assertMatchesRegularExpression( '/page=stonewright-sandbox" aria-current="page">Custom code</', $html );
	}

	public function test_no_page_prints_a_product_name_line_above_its_title(): void {
		foreach ( [ 'stonewright-status', 'stonewright-sandbox', 'stonewright-context', 'stonewright-unknown' ] as $slug ) {
			$html = $this->shell( $slug );

			self::assertStringNotContainsString( 'page-header__eyebrow', $html, $slug );
			self::assertStringNotContainsString( 'page-header__logo', $html, $slug );
			self::assertMatchesRegularExpression( '/<div class="sw-ui-page-header__main"><h1 class="sw-ui-page-title">/', $html, $slug );
		}
	}

	public function test_the_title_is_escaped(): void {
		$html = $this->shell( 'stonewright-memory', [ 'title' => '<script>alert(1)</script>', 'lede' => '<b>x</b>' ] );

		self::assertStringNotContainsString( '<script>', $html );
		self::assertStringNotContainsString( '<b>x</b>', $html );
	}

	public function test_an_experimental_page_is_marked_in_the_band_and_not_in_its_header(): void {
		$html = $this->shell( 'stonewright-context' );

		self::assertSame( 3, substr_count( $html, 'class="sw-ui-band__exp"' ), 'Troubleshoot, Context and Design.' );
		self::assertStringNotContainsString( 'sw-ui-badge', $html, 'The page header has no Beta badge.' );
		self::assertStringNotContainsString( 'Beta', $html );
		self::assertStringNotContainsString( 'Still changing', $html );
		self::assertStringNotContainsString( 'sw-ui-hint', $html );
		self::assertMatchesRegularExpression( '/<div class="sw-ui-page-header__main">.*<\/div><\/header>/s', $html, 'Title, lede and actions stay.' );
	}

	public function test_the_beta_argument_no_longer_adds_a_badge(): void {
		$html = $this->shell( 'stonewright-skills', [ 'beta' => true ] );

		self::assertStringNotContainsString( 'sw-ui-badge', $html );
		self::assertStringNotContainsString( 'Beta', $html );
	}

	public function test_the_experimental_sidebar_title_is_the_label_the_marker_and_words_for_assistive_technology(): void {
		self::assertSame(
			'<span class="sw-menu-label">Troubleshoot</span> <span class="sw-menu-exp" aria-hidden="true" data-sw-tip="This feature is experimental.">EXP</span><span class="screen-reader-text"> This feature is experimental.</span>',
			AdminShell::beta_menu_title( 'Troubleshoot' )
		);
		self::assertStringNotContainsString( '<script', AdminShell::beta_menu_title( '<script>' ) );
		self::assertStringNotContainsString( 'Beta', AdminShell::beta_menu_title( 'Design' ) );
	}

	public function test_an_unregistered_page_still_opens_with_a_title_and_no_tab_bar(): void {
		$html = $this->shell( 'stonewright-unknown' );

		self::assertStringContainsString( '<h1 class="sw-ui-page-title">Stonewright</h1>', $html );
		self::assertStringNotContainsString( 'sw-ui-hubnav', $html );
		self::assertStringNotContainsString( 'aria-current', $html );
	}
}
