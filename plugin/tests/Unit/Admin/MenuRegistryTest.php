<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\MenuRegistry;

/**
 * @covers \Stonewright\WpMcp\Admin\MenuRegistry
 */
final class MenuRegistryTest extends TestCase {

	protected function setUp(): void {
		MenuRegistry::reset_for_tests();
		$_GET                                  = [];
		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true ];
	}

	protected function tearDown(): void {
		MenuRegistry::reset_for_tests();
		$_GET                                  = [];
		$GLOBALS['stonewright_test_user_caps'] = [];
	}

	public function test_there_are_six_hubs_in_sidebar_order(): void {
		$hubs = MenuRegistry::hubs();

		self::assertSame( [ 'overview', 'setup', 'abilities', 'knowledge', 'custom-code', 'activity' ], array_column( $hubs, 'id' ) );
		self::assertSame( [ 'Overview', 'Setup', 'AI Abilities', 'Knowledge', 'Custom code', 'Activity' ], array_column( $hubs, 'label' ) );
	}

	public function test_every_registered_page_belongs_to_a_hub_and_keeps_its_slug(): void {
		$slugs = array_keys( MenuRegistry::pages() );

		foreach ( [
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
		] as $slug ) {
			self::assertContains( $slug, $slugs, $slug );
			self::assertContains( MenuRegistry::hub_for( $slug ), array_column( MenuRegistry::hubs(), 'id' ), $slug );
		}
	}

	public function test_hub_tabs_follow_the_information_architecture(): void {
		$tabs = static function ( string $hub ): array {
			return array_map(
				static fn ( array $entry ): string => $entry['slug'] . ( '' !== $entry['tab'] ? '#' . $entry['tab'] : '' ),
				MenuRegistry::hub_entries( $hub )
			);
		};

		self::assertSame( [ 'stonewright-status' ], $tabs( 'overview' ) );
		self::assertSame( [ 'stonewright', 'stonewright-troubleshoot' ], $tabs( 'setup' ) );
		self::assertSame( [ 'stonewright-abilities' ], $tabs( 'abilities' ) );
		self::assertSame(
			[ 'stonewright-skills', 'stonewright-memory', 'stonewright-context', 'stonewright-design', 'stonewright-prompts' ],
			$tabs( 'knowledge' )
		);
		self::assertSame(
			[
				'stonewright-sandbox#drafts',
				'stonewright-sandbox#library',
				'stonewright-sandbox#mu-plugins',
				'stonewright-sandbox#crash-recovery',
				'stonewright-custom-code-approval',
			],
			$tabs( 'custom-code' )
		);
		self::assertSame( [ 'stonewright-audit-log' ], $tabs( 'activity' ) );
	}

	public function test_new_pages_register_through_add_and_sort_by_order_inside_their_hub(): void {
		MenuRegistry::add( 'stonewright-rescue', 'Rescue', 'activity', [ 'order' => 30 ] );
		MenuRegistry::add( 'stonewright-block-finalizer', 'Block queue', 'activity', [ 'order' => 20, 'beta' => true, 'in_menu' => false ] );

		self::assertSame(
			[ 'stonewright-audit-log', 'stonewright-block-finalizer', 'stonewright-rescue' ],
			array_column( MenuRegistry::hub_entries( 'activity' ), 'slug' )
		);
		self::assertSame( 'activity', MenuRegistry::hub_for( 'stonewright-rescue' ) );
		self::assertTrue( MenuRegistry::entry( 'stonewright-block-finalizer' )['beta'] ?? false );
	}

	public function test_adding_the_same_page_twice_replaces_it(): void {
		MenuRegistry::add( 'stonewright-rescue', 'Rescue', 'activity', [ 'order' => 30 ] );
		MenuRegistry::add( 'stonewright-rescue', 'Rescue tools', 'activity', [ 'order' => 30 ] );

		$matches = array_filter( MenuRegistry::hub_entries( 'activity' ), static fn ( array $e ): bool => 'stonewright-rescue' === $e['slug'] );
		self::assertCount( 1, $matches );
		self::assertSame( 'Rescue tools', array_values( $matches )[0]['label'] );
	}

	public function test_an_unknown_hub_is_refused(): void {
		MenuRegistry::add( 'stonewright-elsewhere', 'Elsewhere', 'no-such-hub' );

		self::assertNull( MenuRegistry::entry( 'stonewright-elsewhere' ) );
		self::assertArrayNotHasKey( 'stonewright-elsewhere', MenuRegistry::pages() );
	}

	public function test_the_sidebar_lists_one_entry_per_page_with_hub_names_on_landing_pages(): void {
		MenuRegistry::add( 'stonewright-block-finalizer', 'Block queue', 'activity', [ 'order' => 20, 'beta' => true, 'in_menu' => false ] );
		MenuRegistry::add( 'stonewright-rescue', 'Rescue', 'activity', [ 'order' => 30 ] );

		$menu = [];
		foreach ( MenuRegistry::menu_entries() as $entry ) {
			$menu[ $entry['slug'] ] = MenuRegistry::menu_label( $entry );
		}

		self::assertSame(
			[
				'stonewright-status'               => 'Overview',
				'stonewright'                      => 'Setup',
				'stonewright-troubleshoot'         => 'Troubleshoot',
				'stonewright-abilities'            => 'AI Abilities',
				'stonewright-skills'               => 'Knowledge',
				'stonewright-memory'               => 'Memory',
				'stonewright-context'              => 'Context',
				'stonewright-design'               => 'Design',
				'stonewright-prompts'              => 'Prompt library',
				'stonewright-sandbox'              => 'Custom code',
				'stonewright-custom-code-approval' => 'Code approval',
				'stonewright-audit-log'            => 'Activity',
				'stonewright-rescue'               => 'Rescue',
			],
			$menu
		);
		self::assertArrayNotHasKey( 'stonewright-block-finalizer', $menu, 'The queue console stays out of the sidebar.' );
	}

	public function test_beta_pages_are_marked_and_the_rest_are_not(): void {
		foreach ( [ 'stonewright-troubleshoot', 'stonewright-context', 'stonewright-design' ] as $slug ) {
			self::assertTrue( MenuRegistry::entry( $slug )['beta'] ?? false, $slug );
		}
		foreach ( [ 'stonewright-status', 'stonewright', 'stonewright-abilities', 'stonewright-skills', 'stonewright-memory', 'stonewright-prompts', 'stonewright-audit-log' ] as $slug ) {
			self::assertFalse( MenuRegistry::entry( $slug )['beta'] ?? true, $slug );
		}
	}

	public function test_page_titles_use_sentence_case_and_agree_with_their_tab(): void {
		self::assertSame( 'Overview', MenuRegistry::entry( 'stonewright-status' )['title'] );
		self::assertSame( 'Prompt library', MenuRegistry::entry( 'stonewright-prompts' )['title'] );
		self::assertSame( 'Prompt library', MenuRegistry::entry( 'stonewright-prompts' )['label'] );
		self::assertSame( 'Audit log', MenuRegistry::entry( 'stonewright-audit-log' )['title'] );
		self::assertNotSame( '', MenuRegistry::entry( 'stonewright-skills' )['lede'] );
	}

	public function test_links_mark_the_current_page_and_the_current_tab(): void {
		$links = MenuRegistry::links( 'custom-code', 'stonewright-sandbox', 'library' );
		$byLabel = [];
		foreach ( $links as $link ) {
			$byLabel[ $link['label'] ] = $link;
		}

		self::assertSame( [ 'Drafts', 'Library', 'Active', 'Crash recovery', 'Approvals' ], array_keys( $byLabel ) );
		self::assertTrue( $byLabel['Library']['current'] );
		self::assertFalse( $byLabel['Drafts']['current'] );
		self::assertFalse( $byLabel['Approvals']['current'] );
		self::assertStringContainsString( 'page=stonewright-sandbox', $byLabel['Library']['url'] );
		self::assertStringContainsString( 'tab=library', $byLabel['Library']['url'] );
		self::assertStringContainsString( 'page=stonewright-custom-code-approval', $byLabel['Approvals']['url'] );
		self::assertStringNotContainsString( 'tab=', $byLabel['Approvals']['url'] );
	}

	public function test_the_default_tab_is_current_when_the_request_names_no_known_tab(): void {
		foreach ( [ '', 'unknown-tab' ] as $requested ) {
			$links   = MenuRegistry::links( 'custom-code', 'stonewright-sandbox', $requested );
			$current = array_values( array_filter( $links, static fn ( array $link ): bool => $link['current'] ) );
			self::assertCount( 1, $current, 'Exactly one tab is current for "' . $requested . '".' );
			self::assertSame( 'Drafts', $current[0]['label'] );
		}
	}

	public function test_a_page_outside_the_hub_marks_no_tab(): void {
		$links = MenuRegistry::links( 'knowledge', 'stonewright-status', '' );

		self::assertSame( [], array_values( array_filter( $links, static fn ( array $link ): bool => $link['current'] ) ) );
	}

	public function test_a_tab_can_show_a_count_and_a_failing_counter_shows_none(): void {
		MenuRegistry::add( 'stonewright-rescue', 'Rescue', 'activity', [ 'order' => 30, 'count' => static fn (): int => 2, 'count_label' => 'needing attention' ] );
		MenuRegistry::add( 'stonewright-broken', 'Broken', 'activity', [ 'order' => 40, 'count' => static function (): int {
			throw new \RuntimeException( 'unavailable' );
		} ] );

		$links = [];
		foreach ( MenuRegistry::links( 'activity', 'stonewright-audit-log', '' ) as $link ) {
			$links[ $link['label'] ] = $link;
		}

		self::assertSame( 2, $links['Rescue']['count'] );
		self::assertSame( 'needing attention', $links['Rescue']['count_label'] );
		self::assertNull( $links['Broken']['count'] );
	}

	public function test_a_zero_count_is_not_shown(): void {
		MenuRegistry::add( 'stonewright-rescue', 'Rescue', 'activity', [ 'order' => 30, 'count' => static fn (): int => 0 ] );

		$links = [];
		foreach ( MenuRegistry::links( 'activity', 'stonewright-audit-log', '' ) as $link ) {
			$links[ $link['label'] ] = $link;
		}

		self::assertNull( $links['Rescue']['count'] );
	}

	public function test_a_tab_the_user_cannot_open_is_not_listed(): void {
		MenuRegistry::add( 'stonewright-block-finalizer', 'Block queue', 'activity', [ 'order' => 20, 'in_menu' => false, 'capability' => 'edit_posts' ] );

		$GLOBALS['stonewright_test_user_caps'] = [ 'edit_posts' => true ];
		self::assertSame( [ 'Block queue' ], array_column( MenuRegistry::links( 'activity', 'stonewright-block-finalizer', '' ), 'label' ), 'An editor sees only what an editor can open.' );

		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true, 'edit_posts' => true ];
		self::assertSame( [ 'Audit log', 'Block queue' ], array_column( MenuRegistry::links( 'activity', 'stonewright-audit-log', '' ), 'label' ) );

		$GLOBALS['stonewright_test_user_caps'] = [];
	}

	public function test_the_request_tab_is_read_from_the_query_string(): void {
		$_GET['tab'] = 'Crash-Recovery';

		self::assertSame( 'crash-recovery', MenuRegistry::requested_tab() );
	}
}
