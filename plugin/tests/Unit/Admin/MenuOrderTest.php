<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\MenuOrder;
use Stonewright\WpMcp\Admin\MenuRegistry;

/**
 * @covers \Stonewright\WpMcp\Admin\MenuOrder
 */
final class MenuOrderTest extends TestCase {

	protected function setUp(): void {
		MenuRegistry::reset_for_tests();
		// The page registers itself, as RescuePage::register() does at boot.
		MenuRegistry::add( 'stonewright-rescue', 'Rescue', 'activity', [ 'order' => 30 ] );
		unset( $GLOBALS['submenu'] );
		$GLOBALS['stonewright_test_actions'] = [];
		$GLOBALS['stonewright_test_filters'] = [];
	}

	protected function tearDown(): void {
		MenuRegistry::reset_for_tests();
		unset( $GLOBALS['submenu'] );
		$GLOBALS['stonewright_test_actions'] = [];
		$GLOBALS['stonewright_test_filters'] = [];
	}

	/** @return array<int, array<int, string>> The entries as the pages register them today, in registration order. */
	private function registered(): array {
		return [
			[ 'Setup', 'manage_options', 'stonewright', 'Setup' ],
			[ 'Code approval', 'manage_options', 'stonewright-custom-code-approval', 'Custom Code Approval' ],
			[ 'AI Abilities', 'manage_options', 'stonewright-abilities', 'AI Abilities' ],
			[ 'Workflows', 'manage_options', 'stonewright-sandbox', 'Sandbox' ],
			[ 'Skills', 'manage_options', 'stonewright-skills', 'Skills' ],
			[ 'Memory', 'manage_options', 'stonewright-memory', 'Memory & Instructions' ],
			[ 'Audit Log', 'manage_options', 'stonewright-audit-log', 'Audit Log' ],
			[ 'Dashboard', 'manage_options', 'stonewright-status', 'Dashboard' ],
			[ '<span class="sw-menu-label">Design</span> <span class="sw-menu-exp">EXP</span>', 'manage_options', 'stonewright-design', 'Design' ],
			[ '<span class="sw-menu-label">Context</span> <span class="sw-menu-exp">EXP</span>', 'manage_options', 'stonewright-context', 'Context' ],
			[ '<span class="sw-menu-label">Troubleshoot</span> <span class="sw-menu-exp">EXP</span>', 'manage_options', 'stonewright-troubleshoot', 'Troubleshoot' ],
			[ 'Prompts', 'manage_options', 'stonewright-prompts', 'Prompt Library' ],
			[ 'Rescue', 'manage_options', 'stonewright-rescue', 'Rescue' ],
		];
	}

	public function test_the_pass_is_registered_after_every_page_has_added_its_entry(): void {
		MenuOrder::register();

		$priorities = array_column( $GLOBALS['stonewright_test_actions']['admin_menu'] ?? [], 'priority' );
		self::assertSame( [ 999 ], $priorities );
	}

	public function test_it_puts_the_overview_first_so_the_top_level_link_opens_it(): void {
		$GLOBALS['submenu'] = [ 'stonewright' => $this->registered() ];

		MenuOrder::apply();

		self::assertSame( 'stonewright-status', $GLOBALS['submenu']['stonewright'][0][2] );
	}

	public function test_it_orders_the_sidebar_by_hub(): void {
		$GLOBALS['submenu'] = [ 'stonewright' => $this->registered() ];

		MenuOrder::apply();

		self::assertSame(
			[
				'stonewright-status',
				'stonewright',
				'stonewright-troubleshoot',
				'stonewright-abilities',
				'stonewright-skills',
				'stonewright-memory',
				'stonewright-context',
				'stonewright-design',
				'stonewright-prompts',
				'stonewright-sandbox',
				'stonewright-custom-code-approval',
				'stonewright-audit-log',
				'stonewright-rescue',
			],
			array_column( $GLOBALS['submenu']['stonewright'], 2 )
		);
		self::assertSame( range( 0, 12 ), array_keys( $GLOBALS['submenu']['stonewright'] ), 'Keys are sequential so the first entry is the top-level link.' );
	}

	public function test_it_names_each_entry_after_the_registry(): void {
		$GLOBALS['submenu'] = [ 'stonewright' => $this->registered() ];

		MenuOrder::apply();

		$labels = [];
		foreach ( $GLOBALS['submenu']['stonewright'] as $item ) {
			$labels[ $item[2] ] = $item[0];
		}
		self::assertSame( 'Overview', $labels['stonewright-status'] );
		self::assertSame( 'Setup', $labels['stonewright'] );
		self::assertSame( 'Knowledge', $labels['stonewright-skills'] );
		self::assertSame( 'Custom code', $labels['stonewright-sandbox'] );
		self::assertSame( 'Activity', $labels['stonewright-audit-log'] );
		self::assertSame( 'Prompt library', $labels['stonewright-prompts'] );
		self::assertSame( 'Rescue', $labels['stonewright-rescue'] );
	}

	public function test_an_experimental_page_carries_the_exp_marker_with_words_for_assistive_technology(): void {
		$GLOBALS['submenu'] = [ 'stonewright' => $this->registered() ];

		MenuOrder::apply();

		$labels = [];
		foreach ( $GLOBALS['submenu']['stonewright'] as $item ) {
			$labels[ $item[2] ] = $item[0];
		}
		foreach ( [ 'stonewright-troubleshoot', 'stonewright-context', 'stonewright-design' ] as $slug ) {
			self::assertStringContainsString( 'class="sw-menu-exp"', $labels[ $slug ], $slug );
			self::assertStringContainsString( '>EXP<', $labels[ $slug ], $slug );
			self::assertStringContainsString( 'data-sw-tip="This feature is experimental."', $labels[ $slug ], $slug );
			self::assertStringContainsString( '<span class="screen-reader-text"> This feature is experimental.</span>', $labels[ $slug ], $slug );
			self::assertStringNotContainsString( 'Beta', $labels[ $slug ], $slug );
		}
		self::assertStringNotContainsString( 'EXP', $labels['stonewright-abilities'] );
		self::assertSame( 'Overview', $labels['stonewright-status'], 'The names and the order of the sidebar are the registry\'s.' );
	}

	public function test_it_keeps_slugs_capabilities_and_page_titles_and_leaves_unknown_pages_last(): void {
		$items   = $this->registered();
		$items[] = [ 'Other tool', 'edit_posts', 'stonewright-other', 'Other tool page' ];
		$GLOBALS['submenu'] = [ 'stonewright' => $items ];

		MenuOrder::apply();

		$by_slug = [];
		foreach ( $GLOBALS['submenu']['stonewright'] as $item ) {
			$by_slug[ $item[2] ] = $item;
		}
		self::assertCount( 14, $by_slug );
		self::assertSame( 'edit_posts', $by_slug['stonewright-other'][1] );
		self::assertSame( 'Other tool', $by_slug['stonewright-other'][0] );
		self::assertSame( 'manage_options', $by_slug['stonewright-status'][1] );
		self::assertSame( 'Memory & Instructions', $by_slug['stonewright-memory'][3] );
		self::assertSame( 'stonewright-other', end( $GLOBALS['submenu']['stonewright'] )[2] );
	}

	public function test_a_page_the_user_cannot_see_stays_out(): void {
		$GLOBALS['submenu'] = [ 'stonewright' => array_slice( $this->registered(), 0, 3 ) ];

		MenuOrder::apply();

		self::assertSame( [ 'stonewright', 'stonewright-abilities', 'stonewright-custom-code-approval' ], array_column( $GLOBALS['submenu']['stonewright'], 2 ), 'Only the entries that exist are ordered; none is invented.' );
	}

	public function test_it_is_idempotent_and_survives_a_missing_menu(): void {
		MenuOrder::apply();
		self::assertEmpty( $GLOBALS['submenu'] ?? null );

		$GLOBALS['submenu'] = [ 'stonewright' => $this->registered() ];
		MenuOrder::apply();
		$once = $GLOBALS['submenu']['stonewright'];
		MenuOrder::apply();

		self::assertSame( $once, $GLOBALS['submenu']['stonewright'] );
	}
}
