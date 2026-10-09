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

	public function test_tab_links_are_the_tabs_of_one_page_and_mark_the_current_one(): void {
		$links   = MenuRegistry::tab_links( 'stonewright-sandbox', 'library' );
		$byLabel = [];
		foreach ( $links as $link ) {
			$byLabel[ $link['label'] ] = $link;
		}

		self::assertSame( [ 'Drafts', 'Library', 'Active', 'Crash recovery' ], array_keys( $byLabel ), 'Only the tabs of this page: the band lists the other pages.' );
		self::assertTrue( $byLabel['Library']['current'] );
		self::assertFalse( $byLabel['Drafts']['current'] );
		self::assertStringContainsString( 'page=stonewright-sandbox', $byLabel['Library']['url'] );
		self::assertStringContainsString( 'tab=library', $byLabel['Library']['url'] );
	}

	public function test_a_page_without_tabs_of_its_own_has_no_tab_links(): void {
		foreach ( [ 'stonewright-status', 'stonewright-skills', 'stonewright-custom-code-approval', 'stonewright-unknown' ] as $slug ) {
			self::assertSame( [], MenuRegistry::tab_links( $slug, '' ), $slug );
		}
	}

	public function test_the_default_tab_is_current_when_the_request_names_no_known_tab(): void {
		foreach ( [ '', 'unknown-tab' ] as $requested ) {
			$links   = MenuRegistry::tab_links( 'stonewright-sandbox', $requested );
			$current = array_values( array_filter( $links, static fn ( array $link ): bool => $link['current'] ) );
			self::assertCount( 1, $current, 'Exactly one tab is current for "' . $requested . '".' );
			self::assertSame( 'Drafts', $current[0]['label'] );
		}
	}

	public function test_a_tab_can_show_a_count_and_a_failing_counter_shows_none(): void {
		MenuRegistry::add( 'stonewright-tabbed', 'First', 'activity', [ 'order' => 30, 'tab' => 'one', 'default' => true, 'count' => static fn (): int => 2, 'count_label' => 'needing attention' ] );
		MenuRegistry::add(
			'stonewright-tabbed',
			'Second',
			'activity',
			[
				'order' => 31,
				'tab'   => 'two',
				'count' => static function (): int {
					throw new \RuntimeException( 'unavailable' );
				},
			]
		);
		MenuRegistry::add( 'stonewright-tabbed', 'Third', 'activity', [ 'order' => 32, 'tab' => 'three', 'count' => static fn (): int => 0 ] );

		$links = [];
		foreach ( MenuRegistry::tab_links( 'stonewright-tabbed', '' ) as $link ) {
			$links[ $link['label'] ] = $link;
		}

		self::assertSame( 2, $links['First']['count'] );
		self::assertSame( 'needing attention', $links['First']['count_label'] );
		self::assertNull( $links['Second']['count'] );
		self::assertNull( $links['Third']['count'], 'A zero count is not shown.' );
	}

	public function test_a_tab_the_user_cannot_open_is_not_listed(): void {
		MenuRegistry::add( 'stonewright-tabbed', 'Open', 'activity', [ 'order' => 30, 'tab' => 'open', 'default' => true ] );
		MenuRegistry::add( 'stonewright-tabbed', 'Restricted', 'activity', [ 'order' => 31, 'tab' => 'restricted', 'capability' => 'edit_posts' ] );

		self::assertSame( [ 'Open' ], array_column( MenuRegistry::tab_links( 'stonewright-tabbed', '' ), 'label' ) );

		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true, 'edit_posts' => true ];
		self::assertSame( [ 'Open', 'Restricted' ], array_column( MenuRegistry::tab_links( 'stonewright-tabbed', '' ), 'label' ) );
	}

	/** @return list<array{hub: string, label: string, links: list<array<string, mixed>>}> */
	private function band( string $current = 'stonewright-status' ): array {
		MenuRegistry::add( 'stonewright-block-finalizer', 'Block queue', 'activity', [ 'order' => 20, 'beta' => true, 'in_menu' => false, 'capability' => 'edit_posts', 'count' => static fn (): int => 3, 'count_label' => 'queued or failed changes' ] );
		MenuRegistry::add( 'stonewright-rescue', 'Rescue', 'activity', [ 'order' => 30 ] );
		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true, 'edit_posts' => true ];

		return MenuRegistry::band_groups( $current );
	}

	public function test_the_band_has_one_link_per_page_in_registry_order(): void {
		$labels = [];
		foreach ( $this->band() as $group ) {
			foreach ( $group['links'] as $link ) {
				$labels[] = $link['label'];
			}
		}

		self::assertSame(
			[ 'Overview', 'Setup', 'Troubleshoot', 'AI Abilities', 'Skills', 'Memory', 'Context', 'Design', 'Prompt library', 'Custom code', 'Code approval', 'Audit log', 'Block queue', 'Rescue' ],
			$labels
		);
	}

	public function test_the_band_groups_the_links_by_hub(): void {
		$groups = $this->band();

		self::assertSame( [ 'overview', 'setup', 'abilities', 'knowledge', 'custom-code', 'activity' ], array_column( $groups, 'hub' ) );
		self::assertSame( [ 'Overview', 'Setup', 'AI Abilities', 'Knowledge', 'Custom code', 'Activity' ], array_column( $groups, 'label' ) );
		self::assertSame( [ 1, 2, 1, 5, 2, 3 ], array_map( static fn ( array $group ): int => count( $group['links'] ), $groups ) );
	}

	public function test_the_band_names_a_link_after_its_menu_label_then_the_title_of_a_page_with_tabs_then_its_label(): void {
		$text = [];
		foreach ( $this->band() as $group ) {
			foreach ( $group['links'] as $link ) {
				$text[ $link['url'] ] = $link['label'];
			}
		}

		self::assertContains( 'Code approval', $text, 'The entry that has a menu label uses it.' );
		self::assertContains( 'Custom code', $text, 'A page with several tabs uses its title, not the label of its first tab.' );
		self::assertNotContains( 'Drafts', $text );
		self::assertContains( 'Memory', $text, 'Every other page uses its label, not its longer title.' );
		self::assertNotContains( 'Memory & instructions', $text );
		self::assertNotContains( 'Custom code approval', $text );
	}

	public function test_a_band_link_goes_to_the_page_without_a_tab(): void {
		foreach ( $this->band() as $group ) {
			foreach ( $group['links'] as $link ) {
				self::assertStringContainsString( 'page=stonewright', $link['url'] );
				self::assertStringNotContainsString( 'tab=', $link['url'] );
			}
		}
	}

	public function test_the_current_link_is_the_page_on_any_of_its_tabs(): void {
		$_GET['tab'] = 'crash-recovery';
		$current     = [];
		foreach ( $this->band( 'stonewright-sandbox' ) as $group ) {
			foreach ( $group['links'] as $link ) {
				if ( $link['current'] ) {
					$current[] = $link['label'];
				}
			}
		}
		self::assertSame( [ 'Custom code' ], $current );

		$none = [];
		foreach ( $this->band( 'stonewright-unknown' ) as $group ) {
			foreach ( $group['links'] as $link ) {
				$none[] = $link['current'];
			}
		}
		self::assertNotContains( true, $none, 'A page that is not registered marks no link.' );
	}

	public function test_the_band_lists_only_the_pages_the_user_can_open(): void {
		$this->band();
		$GLOBALS['stonewright_test_user_caps'] = [ 'edit_posts' => true ];
		$groups                                = MenuRegistry::band_groups( 'stonewright-block-finalizer' );

		self::assertSame( [ 'activity' ], array_column( $groups, 'hub' ), 'An editor sees only what an editor can open.' );
		self::assertSame( [ 'Block queue' ], array_column( $groups[0]['links'], 'label' ) );

		$GLOBALS['stonewright_test_user_caps'] = [];
		self::assertSame( [], MenuRegistry::band_groups( 'stonewright' ) );
	}

	public function test_band_links_carry_the_experimental_flag_and_a_count(): void {
		$links = [];
		foreach ( $this->band() as $group ) {
			foreach ( $group['links'] as $link ) {
				$links[ $link['label'] ] = $link;
			}
		}

		foreach ( [ 'Troubleshoot', 'Context', 'Design', 'Block queue' ] as $label ) {
			self::assertTrue( $links[ $label ]['beta'], $label );
		}
		foreach ( [ 'Overview', 'Setup', 'Skills', 'Custom code', 'Audit log', 'Rescue' ] as $label ) {
			self::assertFalse( $links[ $label ]['beta'], $label );
		}
		self::assertSame( 3, $links['Block queue']['count'] );
		self::assertSame( 'queued or failed changes', $links['Block queue']['count_label'] );
		self::assertNull( $links['Skills']['count'] );
	}

	public function test_the_request_tab_is_read_from_the_query_string(): void {
		$_GET['tab'] = 'Crash-Recovery';

		self::assertSame( 'crash-recovery', MenuRegistry::requested_tab() );
	}
}
