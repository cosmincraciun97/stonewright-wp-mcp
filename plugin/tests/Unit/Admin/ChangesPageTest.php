<?php
/**
 * Stonewright > Changes: the page that lists the change ledger and shows one change as a diff.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\AdminShell;
use Stonewright\WpMcp\Admin\ChangesPage;
use Stonewright\WpMcp\Admin\ConfigurationPage;
use Stonewright\WpMcp\Admin\MenuRegistry;
use Stonewright\WpMcp\Tests\Unit\Admin\Fixtures\LedgerFixture;

/**
 * @covers \Stonewright\WpMcp\Admin\ChangesPage
 * @covers \Stonewright\WpMcp\Admin\ChangeDetail
 */
final class ChangesPageTest extends TestCase {

	use LedgerFixture;

	protected function setUp(): void {
		$this->ledger_up();
		$GLOBALS['stonewright_test_actions']         = [];
		$GLOBALS['stonewright_test_submenu_pages']   = [];
		$GLOBALS['stonewright_test_enqueued_styles']  = [];
		$GLOBALS['stonewright_test_enqueued_scripts'] = [];
		$GLOBALS['stonewright_test_user_caps']       = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_users']           = [
			(object) [ 'ID' => 7, 'user_login' => 'editor-one', 'display_name' => 'Editor One' ],
			(object) [ 'ID' => 8, 'user_login' => 'editor-two', 'display_name' => 'Editor Two' ],
		];
		$_GET  = [];
		$_POST = [];
		MenuRegistry::reset_for_tests();
	}

	protected function tearDown(): void {
		$this->ledger_down();
		$GLOBALS['stonewright_test_user_caps'] = [];
		$GLOBALS['stonewright_test_options']   = [];
		$GLOBALS['stonewright_test_posts']     = [];
		$GLOBALS['stonewright_test_users']     = [];
		$_GET  = [];
		$_POST = [];
		MenuRegistry::reset_for_tests();
	}

	/** @param array<string, string> $get */
	private function html( array $get = [] ): string {
		$_GET = $get;
		ob_start();
		try {
			ChangesPage::render();

			return (string) ob_get_contents();
		} finally {
			ob_end_clean();
		}
	}

	private function post( int $id, string $title ): void {
		$GLOBALS['stonewright_test_posts'][ $id ] = (object) [ 'ID' => $id, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title ];
	}

	/** @return list<string> Change ids in the order the table lists them. */
	private static function ids( string $html ): array {
		preg_match_all( '/<tr id="sw-change-(cs-[a-f0-9]{24})"/', $html, $found );

		return $found[1];
	}

	// ---- Registration and menu --------------------------------------------------------------------

	public function test_it_registers_the_menu_page_its_hooks_and_nothing_that_writes(): void {
		ChangesPage::register();
		do_action( 'admin_menu' );

		$page = $GLOBALS['stonewright_test_submenu_pages'][ ChangesPage::SLUG ] ?? null;
		self::assertIsArray( $page );
		self::assertSame( 'stonewright-changes', ChangesPage::SLUG );
		self::assertSame( ConfigurationPage::SLUG, $page['parent'] );
		self::assertSame( 'manage_options', $page['capability'] );
		self::assertSame( 'Changes', $page['menu_title'] );
		self::assertSame( [ ChangesPage::class, 'render' ], $page['callback'] );
		foreach ( [ 'init', 'admin_menu', 'admin_enqueue_scripts' ] as $hook ) {
			self::assertArrayHasKey( $hook, $GLOBALS['stonewright_test_actions'], $hook . ' must be registered.' );
		}
		foreach ( array_keys( $GLOBALS['stonewright_test_actions'] ) as $hook ) {
			self::assertStringStartsNotWith( 'admin_post_', (string) $hook, 'The page has no state-changing action.' );
			self::assertStringStartsNotWith( 'wp_ajax_', (string) $hook );
		}
	}

	public function test_changes_registers_itself_in_the_activity_hub_after_rescue_with_the_exp_marker(): void {
		ChangesPage::register();
		self::assertNull( MenuRegistry::entry( ChangesPage::SLUG ), 'The entry is registered on init, where labels can be translated.' );
		do_action( 'init' );
		MenuRegistry::add( 'stonewright-rescue', 'Rescue', 'activity', [ 'order' => 30, 'beta' => true ] );

		self::assertSame( 'Changes', AdminShell::pages()[ ChangesPage::SLUG ] );
		self::assertSame( 'activity', MenuRegistry::hub_for( ChangesPage::SLUG ) );
		self::assertSame( [ 'stonewright-audit-log', 'stonewright-rescue', 'stonewright-changes' ], array_column( MenuRegistry::hub_entries( 'activity' ), 'slug' ) );
		$entry = MenuRegistry::entry( ChangesPage::SLUG );
		self::assertSame( 'manage_options', $entry['capability'] ?? '' );
		self::assertTrue( $entry['beta'] ?? false, 'Changes carries the EXP marker in the band and the sidebar.' );
		self::assertSame( 'Changes', $entry['title'] ?? '' );
		self::assertStringContainsString( 'what each one changed', $entry['lede'] ?? '' );
	}

	public function test_the_band_lists_changes_after_rescue_and_only_to_people_who_can_open_it(): void {
		ChangesPage::add_to_menu_registry();
		MenuRegistry::add( 'stonewright-rescue', 'Rescue', 'activity', [ 'order' => 30, 'beta' => true ] );

		$labels = [];
		foreach ( MenuRegistry::band_groups( ChangesPage::SLUG ) as $group ) {
			foreach ( $group['links'] as $link ) {
				$labels[ $link['label'] ] = $link;
			}
		}
		self::assertSame( [ 'Audit log', 'Rescue', 'Changes' ], array_slice( array_keys( $labels ), -3 ) );
		self::assertTrue( $labels['Changes']['current'] );
		self::assertTrue( $labels['Changes']['beta'] );

		$GLOBALS['stonewright_test_user_caps'] = [ 'edit_posts' => true ];
		$after                                = [];
		foreach ( MenuRegistry::band_groups( ChangesPage::SLUG ) as $group ) {
			foreach ( $group['links'] as $link ) {
				$after[] = $link['label'];
			}
		}
		self::assertNotContains( 'Changes', $after );
	}

	public function test_the_stylesheet_comes_from_the_shared_page_style_map_and_the_script_from_the_page(): void {
		$bootstrap = (string) file_get_contents( dirname( __DIR__, 3 ) . '/includes/Admin/AdminBootstrap.php' );
		self::assertMatchesRegularExpression( "/'stonewright-changes'\s*=>\s*'pages\/changes\.css'/", $bootstrap, 'One entry in the page style map.' );

		ChangesPage::enqueue( 'stonewright_page_stonewright-changes' );
		self::assertSame( [], $GLOBALS['stonewright_test_enqueued_styles'], 'The page does not enqueue its own stylesheet a second time.' );
		self::assertSame( [ 'stonewright-admin-changes' ], $GLOBALS['stonewright_test_enqueued_scripts'] );

		$GLOBALS['stonewright_test_enqueued_scripts'] = [];
		ChangesPage::enqueue( 'toplevel_page_stonewright' );
		self::assertSame( [], $GLOBALS['stonewright_test_enqueued_scripts'], 'Only the Changes page loads the script.' );
	}

	// ---- Permission --------------------------------------------------------------------------------

	public function test_only_administrators_can_open_it_and_a_denied_request_reads_no_ledger_row(): void {
		$row                                   = $this->seed_change( [], [ 'post_title' => 'a' ], [ 'post_title' => 'b' ] );
		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => false, 'edit_posts' => true ];
		$this->db->statements                  = [];

		try {
			$this->html( [ 'change' => (string) $row['change_id'] ] );
			self::fail( 'A user without manage_options must not see the page.' );
		} catch ( \RuntimeException $denied ) {
			self::assertStringContainsString( 'wp_die', $denied->getMessage() );
		}
		self::assertSame( [], $this->db->statements, 'Not one query ran before the permission check.' );
	}

	// ---- Structure ---------------------------------------------------------------------------------

	public function test_the_page_uses_the_shared_frame_with_one_h1_and_changes_as_the_current_band_link(): void {
		$html = $this->html();

		self::assertStringContainsString( 'data-sw-shell', $html );
		self::assertSame( 1, substr_count( $html, '<h1' ) );
		self::assertStringContainsString( '<h1 class="sw-ui-page-title">Changes</h1>', $html );
		self::assertMatchesRegularExpression( '/<a class="sw-ui-band__link sw-ui-band__link--exp" href="[^"]*page=stonewright-changes" aria-current="page" data-sw-ui-tip="This feature is experimental\.">Changes<span class="sw-ui-band__exp" aria-hidden="true">EXP<\/span>/', $html );
		self::assertStringContainsString( 'sw-changes-page', $html );
		self::assertStringNotContainsString( 'sw-ui-hubnav', $html, 'Changes has no tabs of its own.' );
	}

	public function test_an_empty_ledger_says_what_the_page_is_for_and_offers_no_dead_control(): void {
		$html = $this->html();

		self::assertStringContainsString( 'sw-ui-empty--first-run', $html );
		self::assertStringContainsString( 'No changes have been recorded yet', $html );
		self::assertStringNotContainsString( '<table', $html );
		self::assertStringNotContainsString( '<dialog', $html );
	}

	public function test_the_header_links_to_rescue_for_incidents(): void {
		$html = $this->html();

		self::assertMatchesRegularExpression( '/<a class="sw-ui-btn"[^>]*href="[^"]*page=stonewright-rescue"[^>]*>Rescue/', $html );
	}

	// ---- The timeline ------------------------------------------------------------------------------

	public function test_changes_are_listed_newest_first_with_time_family_resource_ability_user_status_and_summary(): void {
		$this->post( 42, 'Home page' );
		$first  = $this->seed_change( [ 'summary' => 'Changed the heading.' ], [ 'post_title' => 'Home page' ], [ 'post_title' => 'Home page v2' ] );
		$second = $this->seed_change( [ 'family' => 'option', 'resource_type' => 'option', 'resource_id' => 'blogname', 'ability' => 'stonewright/settings-update', 'summary' => 'Renamed the site.', 'actor' => 8 ], [ 'value' => 'A' ], [ 'value' => 'B' ] );

		$html = $this->html();

		self::assertSame( [ $second['change_id'], $first['change_id'] ], self::ids( $html ) );
		$row = (string) preg_replace( '/^.*<tr id="sw-change-' . preg_quote( (string) $first['change_id'], '/' ) . '"(.*?)<\/tr>.*$/s', '$1', $html );
		self::assertMatchesRegularExpression( '#<time datetime="2026-09-13T12:\d\d:\d\dZ" title="2026-09-13 12:\d\d:\d\d UTC">#', $row, 'Time, in site time with the UTC time in the title.' );
		self::assertStringContainsString( '<span class="sw-ui-tag">Post</span>', $row );
		self::assertStringContainsString( '<a class="sw-ui-link" href="https://example.test/wp-admin/post.php?post=42&action=edit">Home page</a>', $row, 'The resource name links to its edit screen.' );
		self::assertStringContainsString( '<code>stonewright/content-update-page</code>', $row );
		self::assertStringContainsString( 'editor-one', $row );
		self::assertStringContainsString( 'example-client', $row, 'The client is shown with the user.' );
		self::assertStringContainsString( 'Verified', $row );
		self::assertStringContainsString( 'Changed the heading.', $row );
		self::assertStringContainsString( 'editor-two', $html );
	}

	public function test_a_resource_without_an_edit_screen_shows_its_name_and_type_as_text(): void {
		$this->seed_change( [ 'family' => 'option', 'resource_type' => 'option', 'resource_id' => 'blogname' ], [ 'value' => 'A' ], [ 'value' => 'B' ] );
		$this->seed_change( [ 'family' => 'theme_file', 'resource_type' => 'theme_file', 'resource_id' => 'example-theme/functions.php' ], 'a', 'b' );
		$this->seed_change( [ 'resource_id' => '9999' ], [ 'post_title' => 'a' ], [ 'post_title' => 'b' ] );

		$html = $this->html();

		self::assertStringContainsString( '<span class="sw-ui-tag">Option</span>', $html );
		self::assertStringContainsString( '<code>blogname</code>', $html );
		self::assertStringContainsString( '<code>example-theme/functions.php</code>', $html );
		self::assertStringContainsString( 'Post #9999', $html, 'A post that no longer exists keeps its id and gets no link.' );
		self::assertStringNotContainsString( 'post=9999', $html );
	}

	public function test_each_status_has_a_word_and_a_row_that_cannot_be_restored_says_why(): void {
		$this->seed_change( [], [ 'a' => '1' ], [ 'a' => '2' ], 'verified' );
		$this->seed_change( [], [ 'a' => '1' ], [ 'a' => '2' ], 'rolled_back_by' );
		$this->seed_change( [], [ 'a' => '1' ], [ 'a' => '2' ], 'incident' );
		$this->seed_change( [], [ 'a' => '1' ], null, 'failed' );
		$this->seed_change( [], [ 'a' => '1' ], [ 'a' => '2' ], 'probe_unavailable' );
		$this->seed_change( [ 'family' => 'option', 'resource_type' => 'option', 'resource_id' => 'blogname', 'restorable' => false, 'restorable_reason' => 'plugin_deleted' ], [ 'a' => '1' ], [ 'a' => '2' ] );

		$html = $this->html();

		foreach ( [ 'Verified', 'Rolled back', 'Incident', 'Failed', 'Not verified' ] as $word ) {
			self::assertMatchesRegularExpression( '#sw-ui-badge[^>]*>(?:<svg.*?</svg>)?' . $word . '</span>#', $html, $word );
		}
		self::assertStringContainsString( 'Not restorable: plugin deleted', $html );
	}

	public function test_a_rollback_row_is_tagged_with_its_kind(): void {
		$parent = $this->seed_change( [], [ 'a' => '1' ], [ 'a' => '2' ], 'rolled_back_by' );
		$this->seed_change( [ 'kind' => 'rollback', 'parent_id' => $parent['change_id'] ], [ 'a' => '2' ], [ 'a' => '1' ] );

		self::assertStringContainsString( '<span class="sw-ui-tag">Rollback</span>', $this->html() );
	}

	// ---- Filters -----------------------------------------------------------------------------------

	/** @return array<string, string> The ids of three fixtures by name. */
	private function three(): array {
		$this->post( 42, 'Home page' );
		$post   = $this->seed_change( [ 'family' => 'elementor', 'summary' => 'Post change.' ], [ 'post_title' => 'a' ], [ 'post_title' => 'b' ] );
		$option = $this->seed_change( [ 'family' => 'option', 'resource_type' => 'option', 'resource_id' => 'blogname', 'ability' => 'stonewright/settings-update', 'actor' => 8 ], [ 'v' => 'a' ], [ 'v' => 'b' ], 'rolled_back_by' );
		$file   = $this->seed_change( [ 'family' => 'theme_file', 'resource_type' => 'theme_file', 'resource_id' => 'example-theme/style.css', 'ability' => 'stonewright/theme-file-write', 'restorable' => false ], 'a', 'b', 'incident' );

		return [ 'post' => (string) $post['change_id'], 'option' => (string) $option['change_id'], 'file' => (string) $file['change_id'] ];
	}

	public function test_the_filter_form_is_a_search_form_that_names_how_each_field_matches(): void {
		$html = $this->html( [ 'family' => 'option' ] ) . $this->html();

		self::assertStringContainsString( 'role="search"', $html );
		self::assertStringContainsString( 'method="get"', $html );
		foreach ( [ 'family', 'resource', 'ability', 'user', 'from', 'to', 'status', 'restorable' ] as $name ) {
			self::assertStringContainsString( 'name="' . $name . '"', $html, $name );
		}
		self::assertStringContainsString( '<input type="hidden" name="page" value="stonewright-changes">', $html );
		self::assertStringContainsString( 'id="sw-changes-f-ability-rule"', $html );
		self::assertStringContainsString( 'Exact', $html );
	}

	public function test_family_filters_the_list_and_the_selected_value_stays_in_the_form(): void {
		$ids  = $this->three();
		$html = $this->html( [ 'family' => 'option' ] );

		self::assertSame( [ $ids['option'] ], self::ids( $html ) );
		self::assertMatchesRegularExpression( '#<option value="option" selected>Option</option>#', $html );
		self::assertStringContainsString( '1 change', $html );
	}

	public function test_resource_ability_and_user_filters_match_exactly_and_the_ability_prefix_is_optional(): void {
		$ids = $this->three();

		self::assertSame( [ $ids['file'] ], self::ids( $this->html( [ 'resource' => 'example-theme/style.css' ] ) ) );
		self::assertSame( [ $ids['option'] ], self::ids( $this->html( [ 'ability' => 'stonewright/settings-update' ] ) ) );
		self::assertSame( [ $ids['option'] ], self::ids( $this->html( [ 'ability' => 'settings-update' ] ) ), 'The stonewright/ prefix may be left out.' );
		self::assertSame( [ $ids['option'] ], self::ids( $this->html( [ 'user' => '8' ] ) ) );
		self::assertSame( [ $ids['option'] ], self::ids( $this->html( [ 'user' => 'editor-two' ] ) ), 'A login works as well as an id.' );
		self::assertSame( [], self::ids( $this->html( [ 'user' => 'nobody-here' ] ) ), 'An unknown login matches nothing instead of everything.' );
		self::assertSame( [], self::ids( $this->html( [ 'ability' => 'content' ] ) ), 'Exact, not contains.' );
	}

	public function test_status_groups_cover_verified_rolled_back_and_incident(): void {
		$ids = $this->three();

		self::assertSame( [ $ids['post'] ], self::ids( $this->html( [ 'status' => 'verified' ] ) ) );
		self::assertSame( [ $ids['option'] ], self::ids( $this->html( [ 'status' => 'rolled_back' ] ) ), 'Rolled back includes a change that a later rollback undid.' );
		self::assertSame( [ $ids['file'] ], self::ids( $this->html( [ 'status' => 'incident' ] ) ) );
		self::assertSame( [ $ids['file'], $ids['option'], $ids['post'] ], self::ids( $this->html( [ 'status' => 'not-a-status' ] ) ), 'An unknown status is ignored.' );
	}

	public function test_restorable_only_hides_what_cannot_be_undone(): void {
		$ids = $this->three();

		self::assertSame( [ $ids['option'], $ids['post'] ], self::ids( $this->html( [ 'restorable' => '1' ] ) ) );
		self::assertStringContainsString( 'name="restorable" value="1" checked', $this->html( [ 'restorable' => '1' ] ) );
	}

	public function test_the_date_range_is_whole_days_in_utc_and_a_bad_date_is_ignored(): void {
		$this->now = gmmktime( 12, 0, 0, 9, 10, 2026 );
		$old       = $this->seed_change( [], [ 'a' => '1' ], [ 'a' => '2' ] );
		$this->now = gmmktime( 12, 0, 0, 9, 12, 2026 );
		$new       = $this->seed_change( [], [ 'a' => '1' ], [ 'a' => '3' ] );

		self::assertSame( [ $old['change_id'] ], self::ids( $this->html( [ 'from' => '2026-09-10', 'to' => '2026-09-10' ] ) ) );
		self::assertSame( [ $new['change_id'] ], self::ids( $this->html( [ 'from' => '2026-09-11' ] ) ) );
		self::assertSame( [ $old['change_id'] ], self::ids( $this->html( [ 'to' => '2026-09-11' ] ) ) );
		self::assertSame( [ $new['change_id'], $old['change_id'] ], self::ids( $this->html( [ 'from' => 'yesterday', 'to' => "2026-09-31'; DROP TABLE x;--" ] ) ) );
	}

	public function test_a_filter_that_matches_nothing_offers_a_way_back(): void {
		$this->three();
		$html = $this->html( [ 'family' => 'menu' ] );

		self::assertStringContainsString( 'sw-ui-empty--no-results', $html );
		self::assertStringContainsString( 'No changes match these filters', $html );
		self::assertMatchesRegularExpression( '#<a class="sw-ui-btn[^"]*" href="https://example.test/wp-admin/admin.php\?page=stonewright-changes">Reset filters#', $html );
		self::assertStringNotContainsString( '<table', $html );
	}

	public function test_hostile_filter_values_are_escaped_and_never_widen_the_query(): void {
		$this->three();
		$html = $this->html( [ 'resource' => '"><script>alert(1)</script>', 'ability' => "x' OR '1'='1", 'user' => '<img src=x>', 'family' => '<script>' ] );

		self::assertStringNotContainsString( '<script>alert', $html );
		self::assertStringNotContainsString( '<img src=x>', $html );
		self::assertStringContainsString( '&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;', $html, 'The typed value comes back as text.' );
		self::assertSame( [], self::ids( $html ) );
		foreach ( $this->db->statements as $statement ) {
			self::assertStringNotContainsString( 'OR \'1\'=\'1', $statement );
		}
	}

	// ---- Pagination --------------------------------------------------------------------------------

	public function test_the_timeline_is_paged_by_25_and_the_links_keep_the_filters(): void {
		for ( $n = 0; $n < 30; ++$n ) {
			$this->seed_change( [ 'summary' => 'Change number ' . $n ], [ 'n' => (string) $n ], [ 'n' => 'x' . $n ] );
		}

		$one = $this->html();
		self::assertCount( 25, self::ids( $one ) );
		self::assertStringContainsString( 'Page 1 of 2', $one );
		self::assertStringContainsString( '30 changes', $one );
		self::assertMatchesRegularExpression( '#<a class="sw-ui-btn[^"]*" href="[^"]*page=stonewright-changes&family=post&paged=2"#', $this->html( [ 'family' => 'post' ] ), 'The next link keeps the filter.' );
		self::assertStringNotContainsString( 'Newer changes', $one );

		$two = $this->html( [ 'paged' => '2' ] );
		self::assertCount( 5, self::ids( $two ) );
		self::assertStringContainsString( 'Page 2 of 2', $two );
		self::assertStringContainsString( 'Newer changes', $two );
		self::assertStringNotContainsString( 'Older changes', $two );
		self::assertSame( [], array_intersect( self::ids( $one ), self::ids( $two ) ), 'No change appears on two pages.' );

		self::assertCount( 5, self::ids( $this->html( [ 'paged' => '99' ] ) ), 'A page past the end shows the last page.' );
		self::assertCount( 25, self::ids( $this->html( [ 'paged' => '-4' ] ) ) );
		self::assertCount( 25, self::ids( $this->html( [ 'paged' => 'x' ] ) ) );
	}

	// ---- The diff drawer ---------------------------------------------------------------------------

	public function test_a_row_opens_its_diff_with_a_link_that_works_without_script(): void {
		$ids  = $this->three();
		$html = $this->html();

		self::assertMatchesRegularExpression( '#<a class="sw-ui-btn sw-ui-btn--sm" href="https://example.test/wp-admin/admin\.php\?page=stonewright-changes&change=' . $ids['post'] . '" data-sw-changes-open="' . $ids['post'] . '">View diff<span class="sw-ui-visually-hidden"> of change cs-#', $html );
		self::assertStringNotContainsString( '<dialog', $html, 'No diff is rendered until one is asked for.' );
		self::assertStringNotContainsString( 'sw-ui-diff', $html );
	}

	public function test_the_drawer_is_one_server_rendered_dialog_for_the_change_asked_for_and_no_other(): void {
		$old   = "<?php\n// Secret marker one\nadd_action( 'init', 'a' );\n";
		$new   = "<?php\n// Secret marker one\nadd_action( 'init', 'b' );\n";
		$other = $this->seed_change( [ 'family' => 'theme_file', 'resource_type' => 'theme_file', 'resource_id' => 'example-theme/other.php' ], "<?php\n// ONLY-IN-THE-OTHER-CHANGE\n", "<?php\n// ONLY-IN-THE-OTHER-CHANGE changed\n" );
		$row   = $this->seed_change( [ 'family' => 'theme_file', 'resource_type' => 'theme_file', 'resource_id' => 'example-theme/functions.php' ], $old, $new );

		$html = $this->html( [ 'change' => (string) $row['change_id'] ] );

		self::assertSame( 1, substr_count( $html, '<dialog' ) );
		self::assertMatchesRegularExpression( '#<dialog id="sw-changes-drawer" class="sw-ui-dialog sw-ui-drawer" aria-labelledby="sw-changes-drawer-title" data-sw-ui-light-dismiss data-sw-changes-drawer data-sw-changes-id="' . $row['change_id'] . '" open>#', $html );
		self::assertSame( 1, substr_count( $html, 'data-sw-ui-diff="text"' ), 'One diff, computed for this request only.' );
		self::assertStringContainsString( 'example-theme/functions.php', $html );
		self::assertStringContainsString( '&#039;b&#039;', $html );
		self::assertStringNotContainsString( 'ONLY-IN-THE-OTHER-CHANGE', $html, 'The page never dumps the diffs of the other rows.' );
		self::assertStringContainsString( 'data-sw-changes-open="' . $other['change_id'] . '"', $html, 'The list is still there behind the drawer.' );
	}

	public function test_the_drawer_has_a_close_that_works_without_script_and_names_its_focus_target(): void {
		$row  = $this->seed_change( [], [ 'a' => '1' ], [ 'a' => '2' ] );
		$html = $this->html( [ 'change' => (string) $row['change_id'] ] );

		self::assertMatchesRegularExpression( '#<a class="sw-ui-btn sw-ui-btn--sm sw-ui-btn--icon" href="https://example.test/wp-admin/admin\.php\?page=stonewright-changes" aria-label="Close change details" data-sw-ui-dialog-close autofocus>#', $html );
		self::assertMatchesRegularExpression( '#<h2 class="sw-ui-dialog__title" id="sw-changes-drawer-title">Change cs-[a-f0-9]{8}#', $html );
	}

	/** @return array<string, array{0: array<string, mixed>, 1: string|array<mixed>, 2: string|array<mixed>, 3: string, 4: string}> */
	public static function families(): array {
		$tree = static fn ( string $title ): string => (string) json_encode( [ [ 'id' => 'a1b2c3d', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => $title ], 'elements' => [] ] ] );

		return [
			'a theme file is a text diff'      => [ [ 'family' => 'theme_file', 'resource_type' => 'theme_file', 'resource_id' => 'example-theme/style.css' ], ".a { color: red; }\n", ".a { color: blue; }\n", 'text', 'color: blue' ],
			'custom code is a text diff'       => [ [ 'family' => 'custom_code', 'resource_type' => 'snippet', 'resource_id' => 'example-snippet' ], "echo 'a';\n", "echo 'b';\n", 'text', 'echo' ],
			'block content is a block diff'    => [ [ 'family' => 'gutenberg' ], [ 'post_content' => "<!-- wp:paragraph -->\n<p>Old</p>\n<!-- /wp:paragraph -->" ], [ 'post_content' => "<!-- wp:paragraph -->\n<p>New</p>\n<!-- /wp:paragraph -->" ], 'blocks', 'core/paragraph' ],
			'an elementor page is an element diff' => [ [ 'family' => 'elementor' ], [ 'meta' => [ '_elementor_data' => $tree( 'Old' ) ] ], [ 'meta' => [ '_elementor_data' => $tree( 'New' ) ] ], 'elementor', 'a1b2c3d' ],
			'an option is a field diff'        => [ [ 'family' => 'option', 'resource_type' => 'option', 'resource_id' => 'blogname' ], [ 'value' => 'Old name' ], [ 'value' => 'New name' ], 'fields', 'New name' ],
		];
	}

	/**
	 * @dataProvider families
	 * @param array<string, mixed>     $spec
	 * @param string|array<mixed>      $before
	 * @param string|array<mixed>      $after
	 */
	public function test_the_drawer_shows_the_diff_that_fits_the_family( array $spec, string|array $before, string|array $after, string $kind, string $needle ): void {
		$row  = $this->seed_change( $spec, $before, $after );
		$html = $this->html( [ 'change' => (string) $row['change_id'] ] );

		self::assertStringContainsString( 'data-sw-ui-diff="' . $kind . '"', $html );
		self::assertStringContainsString( $needle, $html );
		self::assertStringContainsString( 'id="sw-changes-panel-diff"', $html );
	}

	public function test_a_script_in_a_diff_line_a_title_or_a_resource_id_renders_as_text(): void {
		$this->post( 42, '<script>alert("title")</script>Home' );
		$evil = '"><img src=x onerror=alert(1)>';
		$one  = $this->seed_change( [ 'family' => 'theme_file', 'resource_type' => 'theme_file', 'resource_id' => 'example-theme/' . $evil . '.php' ], "<?php\n", "<?php\n<script>alert('line')</script>\n" );
		$two  = $this->seed_change( [ 'summary' => '<script>alert("s")</script>safe words' ], [ 'post_title' => 'a' ], [ 'post_title' => '<script>alert("field")</script>' ] );

		foreach ( [ [], [ 'change' => (string) $one['change_id'] ], [ 'change' => (string) $two['change_id'] ], [ 'change' => (string) $two['change_id'], 'view' => 'details' ] ] as $get ) {
			$html = $this->html( $get );
			self::assertStringNotContainsString( '<script', $html, wp_json_encode( $get ) );
			self::assertStringNotContainsString( '<img src=x', $html, wp_json_encode( $get ) );
			self::assertStringNotContainsString( 'onerror="', $html );
		}
		$html = $this->html( [ 'change' => (string) $one['change_id'] ] );
		self::assertStringContainsString( '&lt;script&gt;alert(&#039;line&#039;)&lt;/script&gt;', $html );
		self::assertStringContainsString( 'safe words', $this->html() );
	}

	public function test_no_raw_image_is_ever_printed(): void {
		$row  = $this->seed_change( [ 'family' => 'option', 'resource_type' => 'option', 'resource_id' => 'example_settings' ], [ 'host' => 'a.example.test' ], [ 'host' => 'b.example.test' ] );
		$html = $this->html( [ 'change' => (string) $row['change_id'], 'view' => 'details' ] ) . $this->html( [ 'change' => (string) $row['change_id'] ] );

		self::assertStringNotContainsString( '"kind":"data"', $html, 'The stored envelope is never printed.' );
		self::assertStringNotContainsString( '{"v":1', $html );
		self::assertDoesNotMatchRegularExpression( '/\b[a-f0-9]{64}\b/', $html, 'No full hash and so no blob name.' );
		self::assertStringContainsString( 'b.example.test', $html, 'The diff shows the value, through the engine.' );
	}

	public function test_a_credential_in_an_image_never_reaches_the_page(): void {
		$row  = $this->seed_change(
			[ 'family' => 'option', 'resource_type' => 'option', 'resource_id' => 'example_mailer' ],
			[ 'host' => 'a.example.test', 'password' => 'sentinel-OLD-12345678' ],
			[ 'host' => 'b.example.test', 'password' => 'sentinel-NEW-87654321' ]
		);
		$html = $this->html( [ 'change' => (string) $row['change_id'] ] );

		self::assertStringNotContainsString( 'sentinel-', $html );
		self::assertStringContainsString( 'Not restorable: ', $html );
		self::assertStringContainsString( 'masked', $html );
	}

	public function test_a_change_that_cannot_be_restored_says_why_in_the_diff_tab(): void {
		$row  = $this->seed_change( [ 'family' => 'option', 'resource_type' => 'option', 'resource_id' => 'example_settings', 'restorable' => false, 'restorable_reason' => 'too_large' ], [ 'v' => 'a' ], [ 'v' => 'b' ] );
		$html = $this->html( [ 'change' => (string) $row['change_id'] ] );

		self::assertMatchesRegularExpression( '#sw-ui-notice--warn[^>]*role="alert"#', $html );
		self::assertStringContainsString( 'Not restorable: the content was too large to store', $html );
	}

	public function test_a_truncated_diff_says_so_above_the_lines(): void {
		$old = implode( "\n", array_map( static fn ( int $n ): string => 'line ' . $n, range( 1, 1500 ) ) );
		$new = implode( "\n", array_map( static fn ( int $n ): string => 'LINE ' . $n, range( 1, 1500 ) ) );
		$row = $this->seed_change( [ 'family' => 'theme_file', 'resource_type' => 'theme_file', 'resource_id' => 'example-theme/big.css' ], $old, $new );

		$html = $this->html( [ 'change' => (string) $row['change_id'] ] );

		self::assertStringContainsString( 'Not everything is shown', $html );
		self::assertLessThan( (int) strpos( $html, '<table class="sw-ui-diff__table">' ), (int) strpos( $html, 'Not everything is shown' ) );
	}

	public function test_a_change_whose_content_is_gone_or_never_stored_says_so_instead_of_failing(): void {
		$gone = $this->seed_change( [], [ 'a' => '1' ], [ 'a' => '2' ] );
		$this->drop_blobs();
		$none = $this->seed_change( [ 'family' => 'option', 'resource_type' => 'option', 'resource_id' => 'example_api_secret' ], [ 'v' => 'x' ], [ 'v' => 'y' ] );

		$html = $this->html( [ 'change' => (string) $gone['change_id'] ] );
		self::assertStringContainsString( 'no longer available', $html );
		self::assertStringNotContainsString( 'data-sw-ui-diff', $html );

		$html = $this->html( [ 'change' => (string) $none['change_id'] ] );
		self::assertStringContainsString( 'No content is kept for this change', $html );
	}

	public function test_a_change_that_is_not_in_the_ledger_is_a_notice_and_opens_no_drawer(): void {
		$this->seed_change( [], [ 'a' => '1' ], [ 'a' => '2' ] );

		foreach ( [ 'cs-' . str_repeat( '0', 24 ), 'cs-nothex', "x' OR 1=1 --", '../../etc/passwd', str_repeat( 'a', 500 ) ] as $given ) {
			$html = $this->html( [ 'change' => $given ] );

			self::assertStringNotContainsString( '<dialog', $html, $given );
			self::assertStringContainsString( 'That change is not in the history', $html, $given );
			self::assertStringContainsString( '<table', $html, 'The list still shows.' );
		}
	}

	// ---- Details and history ------------------------------------------------------------------------

	public function test_the_tabs_are_links_that_work_without_script_and_name_their_view_in_the_address(): void {
		$row  = $this->seed_change( [], [ 'a' => '1' ], [ 'a' => '2' ] );
		$html = $this->html( [ 'change' => (string) $row['change_id'] ] );

		self::assertStringContainsString( 'data-sw-ui-tabs data-sw-ui-tabs-param="view"', $html );
		foreach ( [ 'diff' => 'Diff', 'details' => 'Details', 'history' => 'History' ] as $view => $label ) {
			self::assertMatchesRegularExpression( '#<a class="sw-ui-tabs__tab" role="tab" id="sw-changes-tab-' . $view . '" href="[^"]*change=' . $row['change_id'] . '[^"]*' . ( 'diff' === $view ? '' : 'view=' . $view ) . '"[^>]*>' . $label . '</a>#', $html, $view );
		}
		self::assertSame( 3, substr_count( $html, 'role="tabpanel"' ) );
		self::assertSame( 2, preg_match_all( '/role="tabpanel"[^>]* hidden>/', $html ), 'Only the selected panel is shown.' );

		$history = $this->html( [ 'change' => (string) $row['change_id'], 'view' => 'history' ] );
		self::assertMatchesRegularExpression( '#id="sw-changes-tab-history"[^>]*aria-selected="true"#', $history );
		self::assertMatchesRegularExpression( '#id="sw-changes-panel-diff"[^>]*hidden#', $history );
	}

	public function test_details_list_the_facts_of_the_row_and_link_to_the_audit_log(): void {
		$row  = $this->seed_change( [ 'summary' => 'Updated the page.', 'change_set_id' => 'set-example-1' ], [ 'post_title' => 'a' ], [ 'post_title' => 'b' ] );
		$html = $this->html( [ 'change' => (string) $row['change_id'], 'view' => 'details' ] );

		foreach ( [ 'Change ID', 'Kind', 'Family', 'Resource', 'Ability', 'User', 'Client', 'Status', 'Recorded', 'Settled', 'Content before', 'Content after', 'Restorable', 'Change set', 'Audit log' ] as $term ) {
			self::assertStringContainsString( '<dt>' . $term . '</dt>', $html, $term );
		}
		self::assertStringContainsString( '<code>' . $row['change_id'] . '</code>', $html );
		self::assertMatchesRegularExpression( '#<dd>Yes</dd>#', $html );
		self::assertMatchesRegularExpression( '#page=stonewright-audit-log&change_set_id=' . $row['change_id'] . '"#', $html, 'The audit events of the change.' );
		self::assertMatchesRegularExpression( '#<dd>\d+ bytes, sha256 <code>[a-f0-9]{12}</code></dd>#', $html );
	}

	public function test_history_shows_the_parent_and_the_rollbacks_and_redos_of_a_change(): void {
		$change   = $this->seed_change( [ 'summary' => 'The original change.' ], [ 'a' => '1' ], [ 'a' => '2' ], 'rolled_back_by' );
		$rollback = $this->seed_change( [ 'kind' => 'rollback', 'parent_id' => $change['change_id'], 'summary' => 'Rolled it back.' ], [ 'a' => '2' ], [ 'a' => '1' ] );
		$redo     = $this->seed_change( [ 'kind' => 'redo', 'parent_id' => $rollback['change_id'], 'summary' => 'Did it again.' ], [ 'a' => '1' ], [ 'a' => '2' ] );

		$mine = $this->html( [ 'change' => (string) $change['change_id'], 'view' => 'history' ] );
		self::assertStringContainsString( 'class="sw-ui-lineage"', $mine );
		self::assertMatchesRegularExpression( '#data-sw-changes-node="' . $rollback['change_id'] . '"#', $mine, 'A rollback of this change is listed.' );
		self::assertStringContainsString( 'Rollback', $mine );
		self::assertMatchesRegularExpression( '#data-sw-changes-node="' . $change['change_id'] . '" aria-current="true"#', $mine );
		self::assertStringContainsString( 'This change has no parent', $mine );

		$middle = $this->html( [ 'change' => (string) $rollback['change_id'], 'view' => 'history' ] );
		self::assertMatchesRegularExpression( '#data-sw-changes-node="' . $change['change_id'] . '"#', $middle, 'The parent is listed.' );
		self::assertMatchesRegularExpression( '#data-sw-changes-node="' . $redo['change_id'] . '"#', $middle, 'The redo is listed.' );
		self::assertMatchesRegularExpression( '#href="[^"]*change=' . $change['change_id'] . '[^"]*">View diff#', $middle, 'Each relative links to its own diff.' );
		self::assertStringContainsString( 'Redo', $middle );
		self::assertStringContainsString( 'Rollback of', $middle );
	}

	public function test_a_change_with_no_relatives_says_so(): void {
		$row  = $this->seed_change( [], [ 'a' => '1' ], [ 'a' => '2' ] );
		$html = $this->html( [ 'change' => (string) $row['change_id'], 'view' => 'history' ] );

		self::assertStringContainsString( 'This change has no parent', $html );
		self::assertStringContainsString( 'Nothing has rolled this change back', $html );
	}

	// ---- Undo --------------------------------------------------------------------------------------

	public function test_undo_is_a_disabled_control_with_its_reason_and_the_page_has_no_post_form(): void {
		$row  = $this->seed_change( [], [ 'a' => '1' ], [ 'a' => '2' ] );
		$html = $this->html( [ 'change' => (string) $row['change_id'] ] ) . $this->html();

		self::assertMatchesRegularExpression( '#<button type="button" class="sw-ui-btn" disabled aria-describedby="sw-changes-undo-why">Undo this change</button>#', $html );
		self::assertMatchesRegularExpression( '#<p class="sw-ui-hint" id="sw-changes-undo-why">Undo arrives with the rollback engine</p>#', $html );
		self::assertStringNotContainsString( 'method="post"', $html );
		self::assertStringNotContainsString( 'admin-post.php', $html );
		self::assertStringNotContainsString( 'confirmation_token', $html );
	}

	public function test_the_page_wraps_everything_in_the_layer_scope(): void {
		$this->seed_change( [], [ 'a' => '1' ], [ 'a' => '2' ] );

		self::assertMatchesRegularExpression( '#<div class="sw-ui sw-ui-page sw-changes-page">#', $this->html() );
	}
}
