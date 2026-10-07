<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Assets;

use PHPUnit\Framework\TestCase;

/**
 * Source contracts of the "tab links and deep links" block of sw-ui.js: a tab that is a link keeps working
 * without script, with script it switches the view in place, remembers it in the address and lets a link to
 * something inside a hidden view open that view. Behaviour is exercised in a browser by e2e/tests/setup-ui.spec.ts.
 *
 * @coversNothing
 */
final class SwUiTabLinksContractTest extends TestCase {

	private static function block(): string {
		$script = CssSource::read( 'admin/sw-ui.js' );
		$start  = strpos( $script, 'Tab links and deep links' );
		self::assertNotFalse( $start, 'The block has its own heading.' );

		return substr( $script, (int) $start );
	}

	public function test_a_tab_that_is_a_link_is_switched_in_place_and_the_address_remembers_the_view(): void {
		$block = self::block();

		self::assertStringContainsString( 'data-sw-ui-tabs-param', $block );
		self::assertStringContainsString( "tagName === 'A'", $block );
		self::assertStringContainsString( 'preventDefault', $block );
		self::assertStringContainsString( 'history.replaceState', $block );
		self::assertMatchesRegularExpression( '/try \{.*history\.replaceState.*catch/s', $block, 'A blocked history API changes nothing.' );
	}

	public function test_a_key_that_moves_between_tabs_updates_the_address_after_the_tab_list_has_moved_focus(): void {
		$block = self::block();

		// A keydown listener runs before or after the tab list moves focus depending on registration order; the
		// address must follow the tab that took focus, so it is read from the focus event.
		self::assertStringContainsString( "addEventListener( 'focusin'", $block );
		self::assertStringContainsString( 'aria-selected', $block );
		self::assertStringNotContainsString( "'keydown'", $block );
	}

	public function test_a_form_that_returns_to_the_page_returns_to_the_view_that_was_open(): void {
		self::assertStringContainsString( '_wp_http_referer', self::block() );
	}

	public function test_a_link_to_something_inside_a_hidden_view_opens_that_view(): void {
		$block = self::block();

		self::assertStringContainsString( "addEventListener( 'hashchange'", $block );
		self::assertStringContainsString( 'location.hash', $block );
		self::assertStringContainsString( '[role="tabpanel"]', $block );
		self::assertStringContainsString( 'scrollToElement', $block, 'It scrolls through the helper that honours reduced motion.' );
		self::assertMatchesRegularExpression( '/function initTabLinks\(\) \{[^}]*initTabs\( document \)/s', $block, 'The tab lists are wired before a hash at load opens a view.' );
	}

	public function test_it_builds_no_markup(): void {
		$block = self::block();

		foreach ( [ 'innerHTML', 'insertAdjacentHTML', 'document.write', 'eval(' ] as $unsafe ) {
			self::assertStringNotContainsString( $unsafe, $block, $unsafe );
		}
	}
}
