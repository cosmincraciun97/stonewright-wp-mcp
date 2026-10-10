<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin\Ui;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\Ui\Tabs;

/**
 * @covers \Stonewright\WpMcp\Admin\Ui\Tabs
 */
final class TabsTest extends TestCase {

	/** @return list<array{id: string, label: string, href?: string, count?: int|null, count_label?: string}> */
	private static function tabs(): array {
		return [
			[ 'id' => 'start', 'label' => 'Get started', 'href' => 'https://example.test/setup?tab=start' ],
			[ 'id' => 'connections', 'label' => 'Connections', 'href' => 'https://example.test/setup?tab=connections', 'count' => 2, 'count_label' => 'connected clients' ],
		];
	}

	public function test_the_list_is_a_named_tablist_with_one_selected_tab_and_a_roving_tabindex(): void {
		$html = Tabs::list( self::tabs(), 'start', [ 'label' => 'Setup views', 'prefix' => 'setup' ] );

		self::assertSame(
			'<div class="sw-ui-tabs" role="tablist" aria-label="Setup views" data-sw-ui-tabs>'
			. '<a class="sw-ui-tabs__tab" role="tab" id="setup-tab-start" href="https://example.test/setup?tab=start" aria-controls="setup-panel-start" aria-selected="true">Get started</a>'
			. '<a class="sw-ui-tabs__tab" role="tab" id="setup-tab-connections" href="https://example.test/setup?tab=connections" aria-controls="setup-panel-connections" aria-selected="false" tabindex="-1">Connections <span class="sw-ui-count sw-ui-num">2</span><span class="sw-ui-visually-hidden"> connected clients</span></a>'
			. '</div>',
			$html
		);
	}

	public function test_a_tab_without_a_destination_is_a_button(): void {
		$html = Tabs::list( [ [ 'id' => 'a', 'label' => 'A' ], [ 'id' => 'b', 'label' => 'B' ] ], 'b', [ 'label' => 'Views', 'prefix' => 'v' ] );

		self::assertStringContainsString( '<button class="sw-ui-tabs__tab" role="tab" id="v-tab-a" type="button" aria-controls="v-panel-a" aria-selected="false" tabindex="-1">A</button>', $html );
		self::assertStringContainsString( '<button class="sw-ui-tabs__tab" role="tab" id="v-tab-b" type="button" aria-controls="v-panel-b" aria-selected="true">B</button>', $html );
	}

	public function test_the_script_is_told_which_query_argument_remembers_the_tab(): void {
		$html = Tabs::list( self::tabs(), 'start', [ 'label' => 'Setup views', 'prefix' => 'setup', 'param' => 'tab' ] );

		self::assertStringContainsString( ' data-sw-ui-tabs data-sw-ui-tabs-param="tab">', $html );
	}

	public function test_an_unknown_current_tab_selects_the_first(): void {
		$html = Tabs::list( self::tabs(), 'nope', [ 'label' => 'Setup views', 'prefix' => 'setup' ] );

		self::assertStringContainsString( 'id="setup-tab-start" href="https://example.test/setup?tab=start" aria-controls="setup-panel-start" aria-selected="true"', $html );
		self::assertSame( 1, substr_count( $html, 'aria-selected="true"' ) );
	}

	public function test_a_panel_is_labelled_by_its_tab_and_only_the_selected_one_is_shown(): void {
		self::assertSame(
			'<div class="sw-ui-tabs__panel" role="tabpanel" id="setup-panel-start" aria-labelledby="setup-tab-start"><p>Body</p></div>',
			Tabs::panel( 'setup', 'start', '<p>Body</p>', true )
		);
		self::assertSame(
			'<div class="sw-ui-tabs__panel" role="tabpanel" id="setup-panel-settings" aria-labelledby="setup-tab-settings" hidden><p>Body</p></div>',
			Tabs::panel( 'setup', 'settings', '<p>Body</p>', false )
		);
	}

	public function test_text_and_urls_are_escaped(): void {
		$html = Tabs::list( [ [ 'id' => 'a', 'label' => '<b>x</b>', 'href' => 'javascript:alert(1)' ], [ 'id' => 'b', 'label' => 'B' ] ], 'a', [ 'label' => '"q"', 'prefix' => 'p' ] );

		self::assertStringNotContainsString( '<b>', $html );
		self::assertStringNotContainsString( 'javascript:', $html );
		self::assertStringContainsString( 'aria-label="&quot;q&quot;"', $html );
	}
}
