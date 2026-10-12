<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\AdminShell;
use Stonewright\WpMcp\Admin\ConfigurationPage;
use Stonewright\WpMcp\Admin\MenuRegistry;
use Stonewright\WpMcp\Admin\RescuePage;
use Stonewright\WpMcp\Core\RescueInstaller;
use Stonewright\WpMcp\Tests\Unit\RescueRuntime\Support\MuRuntime;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\ChangeJournal;
use Stonewright\WpMcp\Security\ChangeJournalFile;
use Stonewright\WpMcp\Security\ChangeLedger;
use Stonewright\WpMcp\Security\ConfirmationToken;
use Stonewright\WpMcp\Security\HealthProbe;
use Stonewright\WpMcp\Security\ProbeToken;
use Stonewright\WpMcp\Security\Rollback\RollbackFamilies;
use Stonewright\WpMcp\Tests\Unit\Security\Rollback\FakeFamilyHandler;
use Stonewright\WpMcp\Tests\Unit\Security\Fixtures\LedgerWpdb;

/**
 * Stonewright > Rescue: the page that still works when the site does not.
 *
 * @covers \Stonewright\WpMcp\Admin\RescuePage
 */
final class RescuePageTest extends TestCase {

	private string $uploads;

	private mixed $original_wpdb = null;

	/** @var callable():string */
	private $site_state;

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$this->uploads = sys_get_temp_dir() . '/sw-page-' . bin2hex( random_bytes( 5 ) );
		mkdir( $this->uploads, 0700, true );
		$GLOBALS['stonewright_test_upload_dir']      = [ 'basedir' => $this->uploads, 'baseurl' => 'https://example.test/uploads', 'error' => false ];
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_actions']         = [];
		$GLOBALS['stonewright_test_submenu_pages']   = [];
		$GLOBALS['stonewright_test_enqueued_styles']  = [];
		$GLOBALS['stonewright_test_enqueued_scripts'] = [];
		$GLOBALS['stonewright_test_user_caps']       = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_current_user_id'] = 7;
		$GLOBALS['stonewright_test_user_caps_by_id'] = [ 7 => [ 'manage_options' => true, 'read' => true, 'edit_post' => true ] ];
		unset( $GLOBALS['stonewright_test_nonce_invalid'], $GLOBALS['stonewright_test_last_redirect'] );
		$_GET  = [];
		$_POST = [];
		ChangeJournal::reset_for_tests();
		RescuePage::reset_for_tests();
		MenuRegistry::reset_for_tests();
		RescuePage::set_safe_mode_resolver( false );
		$this->site( static fn (): string => 'healthy' );
	}

	protected function tearDown(): void {
		if ( null === $this->original_wpdb ) {
			unset( $GLOBALS['wpdb'] );
		} else {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		}
		ChangeLedger::reset_schema_health_cache_for_tests();
		HealthProbe::set_transport( null );
		RescuePage::set_safe_mode_resolver( null );
		RescuePage::reset_for_tests();
		MenuRegistry::reset_for_tests();
		ProbeToken::reset_for_tests();
		unset( $_SERVER['HTTP_X_STONEWRIGHT_PROBE'], $_SERVER['REQUEST_URI'] );
		ChangeJournal::reset_for_tests();
		unset( $GLOBALS['stonewright_test_upload_dir'], $GLOBALS['stonewright_test_nonce_invalid'], $GLOBALS['stonewright_test_last_redirect'] );
		$GLOBALS['stonewright_test_options']   = [];
		$GLOBALS['stonewright_test_user_caps'] = [];
		$_GET  = [];
		$_POST = [];
		self::remove_tree( $this->uploads );
	}

	/** @param callable():string $state */
	private function site( callable $state ): void {
		$this->site_state = $state;
		HealthProbe::set_transport(
			function ( string $url, array $args ) {
				return match ( ( $this->site_state )() ) {
					'broken' => [ 'response' => [ 'code' => 500 ], 'body' => '<body id="error-page"></body>', 'headers' => [] ],
					default  => [ 'response' => [ 'code' => 200 ], 'body' => str_contains( $url, 'wp-json' ) ? '{"namespaces":[]}' : 'ok', 'headers' => [] ],
				};
			}
		);
	}

	private function post( int $id, string $content ): void {
		$GLOBALS['stonewright_test_posts'][ $id ] = (object) [
			'ID' => $id, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Fixture', 'post_content' => $content,
			'post_excerpt' => '', 'post_parent' => 0, 'post_name' => 'fixture', 'meta' => [],
		];
	}

	/** @return array<string, mixed> The incident entry. */
	private function incident( string $state = 'rollback_failed', int $post_id = 31 ): array {
		$this->post( $post_id, 'original body' );
		$snapshot = Backup::snapshot_post( $post_id );
		$entry    = ChangeJournal::arm(
			[
				'ability'       => 'stonewright/elementor-v3-batch-mutate',
				'resource_type' => 'post',
				'resource_key'  => (string) $post_id,
				'recipe'        => [ 'type' => 'post_snapshot', 'ref' => $snapshot ],
				'recipe_detail' => [ 'post_id' => $post_id, 'snapshot_id' => $snapshot ],
				'scope'         => 'post',
			]
		);
		$GLOBALS['stonewright_test_posts'][ $post_id ]->post_content = 'broken body';
		if ( 'incident' === $state ) {
			// What the safe-boot plugin does after a fatal: flip the entry in the shared file.
			$this->flip_to_incident( (string) $entry['id'] );
			return ChangeJournal::get( (string) $entry['id'] );
		}
		ChangeJournal::settle(
			$entry['id'],
			$state,
			[
				'probe'    => [ 'status' => 'failed', 'coverage' => 'none', 'legs' => [ [ 'leg' => 'post', 'status' => 'failed', 'http' => 500, 'reason' => 'critical_error_page', 'ms' => 120 ] ], 'checked_at' => time() ],
				'rollback' => [ 'status' => 'failed', 'at' => time(), 'by' => 'auto', 'recipe' => 'post_snapshot', 'detail' => 'snapshot_missing', 'site' => 'still_failing' ],
			]
		);
		return ChangeJournal::get( $entry['id'] );
	}

	/** Records a fatal on one entry in the journal file, then lets the page's own import pick it up. */
	private function flip_to_incident( string $id ): void {
		$file = new ChangeJournalFile( $this->uploads . '/stonewright-state/' . (string) get_option( ChangeJournal::FILE_OPTION, '' ) );
		$file->transaction(
			static function ( array $document ) use ( $id ): array {
				foreach ( $document['entries'] as $index => $entry ) {
					if ( $entry['id'] === $id ) {
						$document['entries'][ $index ]['state']    = 'incident';
						$document['entries'][ $index ]['incident'] = [
							'recorded_at'    => time(),
							'file'           => 'wp-content/themes/site-a/functions.php',
							'line'           => 9,
							'type'           => 1,
							'message_sha256' => str_repeat( 'c', 64 ),
							'source'         => 'shutdown',
						];
					}
				}
				return $document;
			}
		);
		clearstatcache();
		ChangeJournal::sync_from_file();
	}

	/** The id the page shows and speaks: the eight characters after the prefix. */
	private static function short( string $id ): string {
		return substr( $id, 3, 8 );
	}

	private function html(): string {
		ob_start();
		try {
			RescuePage::render();
		} finally {
			$html = (string) ob_get_clean();
		}
		return $html;
	}

	// -- Registration ------------------------------------------------------------

	public function test_it_registers_the_menu_page_the_actions_and_its_script(): void {
		RescuePage::register();
		do_action( 'admin_menu' );

		$page = $GLOBALS['stonewright_test_submenu_pages'][ RescuePage::SLUG ] ?? null;
		self::assertIsArray( $page );
		self::assertSame( 'stonewright-rescue', RescuePage::SLUG );
		self::assertSame( ConfigurationPage::SLUG, $page['parent'], 'Registered like any page of the menu, so WordPress opens the Stonewright menu on it; MenuOrder hides the item.' );
		self::assertSame( 'manage_options', $page['capability'] );
		self::assertSame( 'Rescue', $page['menu_title'] );
		self::assertSame( [ RescuePage::class, 'render' ], $page['callback'] );
		foreach ( [ 'admin_post_stonewright_rescue_rollback', 'admin_post_stonewright_rescue_recheck', 'admin_post_stonewright_rescue_safe_mode', 'admin_enqueue_scripts' ] as $hook ) {
			self::assertArrayHasKey( $hook, $GLOBALS['stonewright_test_actions'], $hook . ' must be registered.' );
		}
	}

	public function test_the_address_marks_the_changes_entry_in_the_open_menu(): void {
		RescuePage::register();
		$_GET = [ 'page' => 'stonewright-rescue' ];

		self::assertSame( 'stonewright-changes', RescuePage::highlight_submenu( 'stonewright-rescue' ) );

		$_GET = [ 'page' => 'stonewright-status' ];
		self::assertSame( 'stonewright-status', RescuePage::highlight_submenu( 'stonewright-status' ) );
	}

	public function test_rescue_registers_itself_as_a_view_of_the_rescue_tab_of_the_changes_page(): void {
		MenuRegistry::reset_for_tests();
		RescuePage::register();
		self::assertNull( MenuRegistry::entry( 'stonewright-rescue' ), 'The entry is registered on init, where labels can be translated.' );
		do_action( 'init' );

		self::assertSame( 'Rescue', AdminShell::pages()['stonewright-rescue'] );
		self::assertSame( 'activity', MenuRegistry::hub_for( 'stonewright-rescue' ) );
		$entry = MenuRegistry::entry( 'stonewright-rescue' );
		self::assertSame( 'manage_options', $entry['capability'] ?? '' );
		self::assertTrue( $entry['beta'] ?? false, 'Rescue carries the EXP marker.' );
		self::assertSame( 'stonewright-changes', $entry['parent'] ?? '', 'It is a view of the Changes page...' );
		self::assertSame( 'rescue', $entry['parent_tab'] ?? '', '...of its Rescue tab.' );
		self::assertFalse( $entry['in_menu'] ?? true );
		self::assertStringContainsString( 'Roll back a change that stopped the site from loading', $entry['lede'] ?? '' );
		self::assertSame( [ 'stonewright-changes', 'rescue' ], MenuRegistry::resolve( 'stonewright-rescue' ) );
		$slugs = array_map( static fn ( array $link ): string => $link['label'], MenuRegistry::tab_links( 'stonewright-rescue', '' ) );
		self::assertSame( [ 'History', 'Rescue' ], $slugs, 'Its tab bar is the Changes page tab bar.' );
	}

	public function test_the_stylesheet_comes_from_the_shared_page_style_map_and_the_script_from_the_page(): void {
		$bootstrap = (string) file_get_contents( dirname( __DIR__, 3 ) . '/includes/Admin/AdminBootstrap.php' );
		self::assertMatchesRegularExpression( "/'stonewright-rescue'\s*=>\s*'pages\/rescue\.css'/", $bootstrap, 'One entry in the page style map.' );

		RescuePage::enqueue( 'stonewright_page_stonewright-rescue' );
		self::assertSame( [], $GLOBALS['stonewright_test_enqueued_styles'], 'The page does not enqueue its own stylesheet a second time.' );
		self::assertSame( [ 'stonewright-admin-rescue' ], $GLOBALS['stonewright_test_enqueued_scripts'] );

		$GLOBALS['stonewright_test_enqueued_scripts'] = [];
		RescuePage::enqueue( 'toplevel_page_stonewright' );
		self::assertSame( [], $GLOBALS['stonewright_test_enqueued_scripts'], 'Only the Rescue page loads the script.' );
	}

	public function test_only_administrators_can_open_it(): void {
		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => false ];

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'wp_die' );
		$this->html();
	}

	// -- Structure ---------------------------------------------------------------

	public function test_the_page_always_carries_the_marker_the_health_probe_looks_for(): void {
		self::assertStringContainsString( 'data-sw-rescue-probe="ok"', $this->html() );
	}

	public function test_it_uses_the_shared_page_header_with_one_h1(): void {
		$html = $this->html();

		self::assertStringContainsString( '<h1 class="sw-ui-page-title">Rescue</h1><p class="sw-ui-page-lede">Roll back a change that stopped the site from loading', $html );
		self::assertSame( 1, substr_count( $html, '<h1' ) );
		self::assertMatchesRegularExpression( '/<a class="sw-ui-band__link sw-ui-band__link--exp" href="[^"]*page=stonewright-changes" aria-current="page" data-sw-ui-tip="This feature is experimental\.">Changes<span class="sw-ui-band__exp" aria-hidden="true">EXP<\/span>/', $html, 'Changes is the current link of the band, in the Activity group, with the EXP marker: Rescue is its tab.' );
		self::assertStringNotContainsString( 'page=stonewright-rescue" aria-current', $html );
		self::assertStringContainsString( '<span class="sw-ui-band__label" aria-hidden="true">Activity</span>', $html );
		self::assertMatchesRegularExpression( '#<nav aria-label="Changes sections"><ul class="sw-ui-hubnav"><li><a class="sw-ui-hubnav__link" href="[^"]*page=stonewright-changes&tab=history">History</a></li><li><a class="sw-ui-hubnav__link" href="[^"]*page=stonewright-changes&tab=rescue" aria-current="page">Rescue</a></li></ul></nav>#', $html, 'The tab bar of the Changes page, with Rescue current.' );
	}

	public function test_a_warning_callout_says_what_rescue_does_and_what_it_never_does(): void {
		$html = $this->html();

		self::assertSame( 1, preg_match( '/<div class="sw-rescue-callout sw-rescue-callout--warn"[^>]*>(.*?)<\/div>/s', $html, $match ) );
		self::assertStringContainsString( 'What Rescue does', $match[1] );
		self::assertStringContainsString( 'What it never does', $match[1] );
		self::assertStringContainsString( 'wp-config.php', $match[1], 'The limits are stated, not hidden.' );
		self::assertSame( 2, substr_count( $match[1], '<p>' ), 'One sentence each.' );
	}

	public function test_the_stats_band_counts_open_and_unverified_changes_and_names_the_mode(): void {
		$this->incident();
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';

		$html = $this->html();

		self::assertMatchesRegularExpression( '/<dt>Open incidents<\/dt>\s*<dd>1<\/dd>/', $html );
		self::assertMatchesRegularExpression( '/<dt>Not verified<\/dt>\s*<dd>0<\/dd>/', $html );
		self::assertMatchesRegularExpression( '/<dt>Last rollback<\/dt>\s*<dd>None yet<\/dd>/', $html );
		self::assertMatchesRegularExpression( '/<dt>Mode<\/dt>\s*<dd>Production-safe<\/dd>/', $html );
	}

	public function test_the_last_rollback_is_shown_once_one_has_happened(): void {
		$entry = $this->incident();
		$_POST = [ '_stonewright_nonce' => 'x', 'incident_id' => $entry['id'] ];
		RescuePage::process_rollback_request();
		$_POST = [];

		self::assertMatchesRegularExpression( '/<dt>Last rollback<\/dt>\s*<dd><time datetime="\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z">/', $this->html() );
	}

	public function test_with_nothing_open_it_says_so_plainly_and_offers_no_rollback(): void {
		$html = $this->html();

		self::assertStringContainsString( 'Nothing to rescue', $html );
		self::assertStringNotContainsString( 'stonewright_rescue_rollback', $html );
		self::assertStringNotContainsString( '<dialog', $html );
		self::assertMatchesRegularExpression( '/<dt>Open incidents<\/dt>\s*<dd>0<\/dd>/', $html );
	}

	// -- The table of changes ----------------------------------------------------

	public function test_an_open_incident_shows_what_changed_when_who_and_the_evidence(): void {
		$entry = $this->incident();

		$html = $this->html();

		self::assertStringContainsString( $entry['id'], $html );
		self::assertStringContainsString( 'stonewright/elementor-v3-batch-mutate', $html, 'Which ability made the change.' );
		self::assertStringContainsString( 'Post 31', $html, 'What changed.' );
		self::assertMatchesRegularExpression( '/<time datetime="\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z"/', $html, 'When.' );
		self::assertStringContainsString( 'HTTP 500', $html, 'The probe evidence.' );
		self::assertStringContainsString( 'critical_error_page', $html );
		self::assertStringContainsString( 'Restore post 31', $html, 'The rollback recipe.' );
		self::assertStringContainsString( 'Rollback failed', $html, 'The state is in words, not only colour.' );
	}

	public function test_the_changes_are_one_table_with_labelled_cells_that_can_stack(): void {
		$entry = $this->incident();

		$html = $this->html();

		self::assertSame( 1, preg_match( '/<table class="sw-rescue-table"[^>]*data-sw-rescue-table="attention"[^>]*>(.*?)<\/table>/s', $html, $table ) );
		self::assertMatchesRegularExpression( '/<caption class="screen-reader-text">[^<]+<\/caption>/', $table[1], 'The table has a name.' );
		foreach ( [ 'Change', 'When', 'Status', 'Actions' ] as $heading ) {
			self::assertMatchesRegularExpression( '/<th scope="col">' . preg_quote( $heading, '/' ) . '<\/th>/', $table[1] );
			self::assertStringContainsString( 'data-label="' . $heading . '"', $table[1], 'Each cell repeats its heading so a row reads alone at 400px.' );
		}
		self::assertStringContainsString( '<tr data-sw-rescue-row="' . $entry['id'] . '">', $table[1] );
		self::assertMatchesRegularExpression( '/<th scope="row" data-label="Change"><strong class="sw-rescue-object">/', $table[1], 'The object that changed is the row header.' );
	}

	public function test_the_change_set_is_named_by_its_short_id_and_can_be_copied_whole(): void {
		$entry = $this->incident();
		$short = self::short( $entry['id'] );

		$html = $this->html();

		self::assertSame( 1, preg_match( '/<details class="sw-rescue-details"><summary>Details<\/summary>(.*?)<\/dl>/s', $html, $details ), 'The ids are in the details of the change, not in front of it.' );
		self::assertStringContainsString( '<code class="sw-rescue-id">' . $short . '</code>', $details[1] );
		self::assertMatchesRegularExpression(
			'/<button type="button" class="sw-ui-btn sw-ui-btn--tertiary sw-ui-btn--xs" hidden data-sw-rescue-copy-text="' . preg_quote( $entry['id'], '/' ) . '">Copy<span class="sw-ui-visually-hidden"> change set id ' . $short . '<\/span><\/button>/',
			$details[1],
			'A script-only control that copies the full id; hidden until the script runs, named by what it copies.'
		);
	}

	public function test_the_object_and_what_was_done_come_first_and_the_ability_the_client_and_the_codes_are_in_the_details(): void {
		$_SERVER['HTTP_USER_AGENT'] = 'claude-code/1.4.2 (cli)';
		$entry = $this->incident();
		unset( $_SERVER['HTTP_USER_AGENT'] );

		$html = $this->html();

		self::assertSame( 1, preg_match( '/<th scope="row"[^>]*>(.*?)<\/th>/s', $html, $cell ) );
		$before = explode( '<details class="sw-rescue-details">', $cell[1] )[0];
		$after  = explode( '<details class="sw-rescue-details">', $cell[1] )[1];
		self::assertStringContainsString( 'Fixture', $before, 'The title of the post.' );
		self::assertStringContainsString( 'Changed by elementor v3 batch mutate', $before, 'What was done, in words.' );
		self::assertStringNotContainsString( 'stonewright/elementor-v3-batch-mutate', $before, 'The ability name is a detail.' );
		self::assertStringNotContainsString( 'claude-code', $before );
		self::assertStringNotContainsString( 'critical_error_page', $before, 'The code of the probe is a detail.' );
		self::assertStringNotContainsString( $entry['id'], $before );
		self::assertStringContainsString( '<code>stonewright/elementor-v3-batch-mutate</code>', $after );
		self::assertStringContainsString( '<code>claude-code/1.4.2', $after );
		self::assertStringContainsString( '<code>critical_error_page</code>', $after );
		self::assertStringContainsString( $entry['id'], $after );
	}

	public function test_the_summary_the_history_recorded_is_the_line_under_the_title(): void {
		$entry = $this->incident();
		$this->ledger_row_for( (string) $entry['id'] );
		$GLOBALS['wpdb']->tables[ $GLOBALS['wpdb']->prefix . 'stonewright_changes' ][0]['summary'] = 'Changed the heading of the page.';

		self::assertStringContainsString( '<span class="sw-rescue-sub">Changed the heading of the page.</span>', $this->html() );
	}

	public function test_a_status_badge_names_the_state_in_words(): void {
		$this->incident( 'rollback_failed' );
		$this->incident( 'incident', 32 );

		$html = $this->html();

		self::assertMatchesRegularExpression( '/<span class="sw-badge sw-rescue-badge--danger">Rollback failed<\/span>/', $html );
		self::assertMatchesRegularExpression( '/<span class="sw-badge sw-rescue-badge--danger">Fatal recorded<\/span>/', $html );
	}

	public function test_the_agent_client_is_named_when_it_is_known(): void {
		$_SERVER['HTTP_USER_AGENT'] = 'claude-code/1.4.2 (cli)';
		$this->incident();
		unset( $_SERVER['HTTP_USER_AGENT'] );

		self::assertStringContainsString( 'claude-code/1.4.2', $this->html() );
	}

	public function test_unconfirmed_changes_are_listed_in_the_same_table_with_a_way_to_check_them(): void {
		$entry = $this->incident( 'armed' );
		$GLOBALS['stonewright_test_filters']['stonewright_rescue_now'] = static fn (): int => time() + 4000;

		$html = $this->html();
		unset( $GLOBALS['stonewright_test_filters']['stonewright_rescue_now'] );

		self::assertStringContainsString( 'Not verified', $html );
		self::assertStringContainsString( '<tr data-sw-rescue-row="' . $entry['id'] . '">', $html );
		self::assertMatchesRegularExpression( '/<dt>Not verified<\/dt>\s*<dd>1<\/dd>/', $html );
		self::assertStringContainsString( 'name="action" value="stonewright_rescue_recheck"', $html );
		self::assertStringContainsString( 'Not verified means no health check has reported on the change yet. It does not mean the site is broken.', $html, 'What Not verified means, and what it does not.' );
		self::assertStringNotContainsString( 'blocks requests', $html, 'Nothing blames the host.' );
		self::assertStringNotContainsString( 'hosting', strtolower( $html ) );
	}

	public function test_recent_changes_are_listed_with_their_states(): void {
		$entry = $this->incident( 'verified' );

		$html = $this->html();

		self::assertStringContainsString( 'Recent changes', $html );
		self::assertSame( 1, preg_match( '/<table class="sw-rescue-table"[^>]*data-sw-rescue-table="recent"[^>]*>(.*?)<\/table>/s', $html, $table ) );
		self::assertStringContainsString( self::short( $entry['id'] ), $table[1] );
		self::assertStringContainsString( 'Verified', $table[1] );
	}

	public function test_a_change_is_never_listed_twice(): void {
		$entry = $this->incident();

		$html = $this->html();

		self::assertSame( 1, substr_count( $html, '<tr data-sw-rescue-row="' . $entry['id'] . '">' ) );
	}

	// -- Actions -----------------------------------------------------------------

	public function test_each_row_action_has_a_full_accessible_name_that_starts_with_its_visible_label(): void {
		$entry = $this->incident( 'incident' );
		$short = self::short( $entry['id'] );

		$html = $this->html();

		self::assertMatchesRegularExpression( '/<a class="sw-ui-btn sw-ui-btn--danger sw-ui-btn--sm" href="[^"]*page=stonewright-rescue&review=' . preg_quote( $entry['id'], '/' ) . '"[^>]*>Roll back<span class="sw-ui-visually-hidden"> change set ' . $short . '<\/span><\/a>/', $html );
		self::assertMatchesRegularExpression( '/<button type="submit" class="sw-ui-btn sw-ui-btn--sm"[^>]*>Check again<span class="sw-ui-visually-hidden"> change set ' . $short . '<\/span><\/button>/', $html );
	}

	public function test_the_first_press_of_roll_back_is_a_link_to_a_review_and_the_form_that_writes_is_not_a_row_control(): void {
		$entry = $this->incident( 'incident' );
		$hash  = md5( $entry['id'] );

		$html = $this->html();

		self::assertMatchesRegularExpression( '/<a class="sw-ui-btn sw-ui-btn--danger sw-ui-btn--sm" href="https:\/\/example\.test\/wp-admin\/admin\.php\?page=stonewright-rescue&review=' . preg_quote( $entry['id'], '/' ) . '"[^>]*data-sw-rescue-open="sw-rescue-dlg-' . $hash . '"/', $html );
		self::assertDoesNotMatchRegularExpression( '/<button[^>]*\sform="sw-rescue-form-/', $html, 'No button posts the form from the row: a press that works without script must not write.' );
		self::assertSame( 1, preg_match( '/<dialog[^>]*>(.*?)<\/dialog>/s', $html, $dialog ) );
		self::assertStringContainsString( 'action="https://example.test/wp-admin/admin-post.php"', $dialog[1] );
		self::assertStringContainsString( 'name="action" value="stonewright_rescue_rollback"', $dialog[1] );
		self::assertStringContainsString( 'name="incident_id" value="' . $entry['id'] . '"', $dialog[1] );
		self::assertStringContainsString( 'name="_stonewright_nonce"', $dialog[1] );
		self::assertStringContainsString( '<form method="post" action="https://example.test/wp-admin/admin-post.php" id="sw-rescue-form-' . $hash . '"', $dialog[1] );
	}

	// -- The review: the confirmation as a page, with no script ---------------------------------------

	public function test_the_review_is_the_confirmation_as_a_page_and_changes_nothing(): void {
		$entry = $this->incident( 'incident' );
		$short = self::short( $entry['id'] );
		$_GET  = [ 'review' => $entry['id'] ];

		$html = $this->html();

		self::assertStringContainsString( 'data-sw-rescue-probe="ok"', $html, 'It is still the page the health check looks for.' );
		self::assertSame( 1, preg_match( '/<section class="sw-rescue-review"[^>]*data-sw-rescue-review>(.*?)<\/section>/s', $html, $review ) );
		self::assertStringContainsString( '<h2 id="sw-rescue-review-title">Roll back change set ' . $short . '?</h2>', $review[1] );
		self::assertStringContainsString( 'name="action" value="stonewright_rescue_rollback"', $review[1] );
		self::assertStringContainsString( 'anything saved to it since is overwritten', $review[1] );
		self::assertStringNotContainsString( '<dialog', $html, 'The review is the page, not a dialog.' );
		self::assertStringNotContainsString( 'data-sw-rescue-table', $html, 'The list is not printed under it.' );
		self::assertMatchesRegularExpression( '/<a class="sw-ui-btn" href="https:\/\/example\.test\/wp-admin\/admin\.php\?page=stonewright-rescue" data-sw-rescue-cancel>Cancel<\/a>/', $review[1], 'Cancel is a link, so it works without script.' );
		self::assertMatchesRegularExpression( '/<button type="submit" class="sw-ui-btn sw-ui-btn--danger-solid"[^>]*data-sw-rescue-submit[^>]*>Roll back<\/button>/', $review[1] );
		self::assertDoesNotMatchRegularExpression( '/<button type="submit"[^>]*\sdisabled/', $review[1], 'Without script the confirm is not held back; the server checks.' );
		self::assertSame( 'broken body', $GLOBALS['stonewright_test_posts'][31]->post_content, 'Looking changed nothing.' );
		self::assertSame( 'incident', ChangeJournal::get( $entry['id'] )['state'] );
		self::assertSame( [], $this->receipts(), 'No receipt: nothing ran.' );
	}

	public function test_the_review_of_a_change_that_cannot_be_rolled_back_says_so_and_offers_a_way_back(): void {
		$entry = ChangeJournal::arm( [ 'ability' => 'stonewright/custom-code-provider', 'resource_type' => 'custom_code', 'resource_key' => 'wpcode:9', 'recipe' => [ 'type' => 'none', 'ref' => '' ] ] );
		ChangeJournal::settle( $entry['id'], 'rollback_failed' );
		$gone = $this->html_for( [ 'review' => 'cs-ffffffffffffffffffffffff' ] );
		$none = $this->html_for( [ 'review' => $entry['id'] ] );

		foreach ( [ $gone, $none ] as $html ) {
			self::assertStringContainsString( 'Nothing to roll back here', $html );
			self::assertStringNotContainsString( 'name="action" value="stonewright_rescue_rollback"', $html );
			self::assertMatchesRegularExpression( '/<a class="sw-ui-btn" href="[^"]*page=stonewright-rescue">Back to Rescue<\/a>/', $html );
		}
	}

	public function test_production_safe_review_carries_the_token_and_the_phrase_that_the_server_checks(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		$entry = $this->incident( 'incident' );

		$html = $this->html_for( [ 'review' => $entry['id'] ] );

		self::assertSame( 1, preg_match( '/name="confirmation_token" value="(swc_[^"]+)"/', $html, $match ) );
		self::assertTrue( ConfirmationToken::verify( html_entity_decode( $match[1] ), 'stonewright/rescue-rollback', [ 'incident_id' => $entry['id'] ] ) );
		self::assertMatchesRegularExpression( '/<input type="text" class="sw-ui-input" id="sw-rescue-phrase-[^"]+" name="confirm_phrase"[^>]*data-sw-rescue-phrase="ROLL BACK"/', $html );
	}

	/** @return list<array<string, mixed>> The receipts of the page's own rollbacks and re-checks. */
	private function receipts(): array {
		return array_values(
			array_filter(
				array_map( static fn ( array $row ): array => $row['data'], $GLOBALS['stonewright_test_wpdb_inserts'] ),
				static fn ( array $row ): bool => 'stonewright/rescue-rollback' === ( $row['ability_name'] ?? '' )
			)
		);
	}

	/** @param array<string, string> $get */
	private function html_for( array $get ): string {
		$_GET = $get;
		try {
			return $this->html();
		} finally {
			$_GET = [];
		}
	}

	public function test_a_failed_rollback_offers_a_retry_named_as_one(): void {
		$entry = $this->incident( 'rollback_failed' );
		$short = self::short( $entry['id'] );

		$html = $this->html();

		self::assertMatchesRegularExpression( '/<a class="sw-ui-btn sw-ui-btn--danger sw-ui-btn--sm"[^>]*>Try again<span class="sw-ui-visually-hidden"> to roll back change set ' . $short . '<\/span><\/a>/', $html );
		self::assertStringContainsString( 'Last attempt: failed (snapshot_missing)', $html, 'The failure is shown on its own row.' );
	}

	public function test_a_change_without_a_recipe_cannot_be_rolled_back_from_the_page_but_can_be_re_checked(): void {
		$entry = ChangeJournal::arm( [ 'ability' => 'stonewright/custom-code-provider', 'resource_type' => 'custom_code', 'resource_key' => 'wpcode:9', 'recipe' => [ 'type' => 'none', 'ref' => '' ] ] );
		ChangeJournal::settle( $entry['id'], 'rollback_failed' );

		$html = $this->html();

		self::assertStringContainsString( 'No automatic rollback is available', $html );
		self::assertStringNotContainsString( 'name="action" value="stonewright_rescue_rollback"', $html );
		self::assertStringNotContainsString( '<dialog', $html );
		self::assertStringContainsString( 'name="action" value="stonewright_rescue_recheck"', $html );
	}

	// -- The confirmation dialog -------------------------------------------------

	public function test_the_dialog_names_the_change_set_shows_what_will_happen_and_focuses_cancel_first(): void {
		$entry = $this->incident( 'incident' );
		$short = self::short( $entry['id'] );
		$hash  = md5( $entry['id'] );

		$html = $this->html();

		self::assertSame( 1, preg_match( '/<dialog class="sw-rescue-dialog" id="sw-rescue-dlg-' . $hash . '" aria-labelledby="sw-rescue-dlg-' . $hash . '-title" data-sw-rescue-dialog>(.*?)<\/dialog>/s', $html, $dialog ) );
		self::assertStringContainsString( '<h2 id="sw-rescue-dlg-' . $hash . '-title">Roll back change set ' . $short . '?</h2>', $dialog[1] );
		// A key-value preview of what is touched and how it comes back.
		self::assertMatchesRegularExpression( '/<dt>What changed<\/dt>\s*<dd>Post 31<\/dd>/', $dialog[1], 'The dialog names the item and not its title: the title is in the row.' );
		self::assertMatchesRegularExpression( '/<dt>Changed by<\/dt>\s*<dd><code>stonewright\/elementor-v3-batch-mutate<\/code><\/dd>/', $dialog[1] );
		self::assertMatchesRegularExpression( '/<dt>Restores<\/dt>\s*<dd>Restore post 31/', $dialog[1] );
		self::assertStringContainsString( 'anything saved to it since is overwritten', $dialog[1], 'The consequence, in one sentence.' );
		// Cancel comes first, holds the initial focus, and the destructive button is the last control.
		$cancel = strpos( $dialog[1], 'data-sw-rescue-cancel' );
		$submit = strpos( $dialog[1], 'data-sw-rescue-submit' );
		self::assertIsInt( $cancel );
		self::assertIsInt( $submit );
		self::assertLessThan( $submit, $cancel );
		self::assertMatchesRegularExpression( '/<a class="sw-ui-btn" href="[^"]*page=stonewright-rescue"[^>]*data-sw-rescue-cancel[^>]*\sautofocus[^>]*>Cancel<\/a>/', $dialog[1], 'Cancel is a link to the page, which the script turns into closing the dialog.' );
		self::assertMatchesRegularExpression( '/<button type="submit" class="sw-ui-btn sw-ui-btn--danger-solid"[^>]*data-sw-rescue-submit[^>]*>Roll back<\/button>/', $dialog[1] );
		self::assertStringContainsString( 'data-sw-busy-label="Rolling back and checking the site..."', $dialog[1] );
	}

	public function test_outside_production_safe_mode_there_is_no_typed_phrase_and_no_token(): void {
		$this->incident( 'incident' );

		$html = $this->html();

		self::assertStringNotContainsString( 'confirmation_token', $html );
		self::assertStringNotContainsString( 'data-sw-rescue-phrase', $html );
		self::assertStringNotContainsString( 'sw-rescue-callout--info', $html );
	}

	public function test_production_safe_mode_asks_for_a_typed_phrase_and_binds_a_token_to_the_change_set(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		$entry = $this->incident( 'incident' );
		$hash  = md5( $entry['id'] );

		$html = $this->html();

		self::assertMatchesRegularExpression( '/<label for="sw-rescue-phrase-' . $hash . '">Type ROLL BACK to confirm<\/label>/', $html );
		self::assertMatchesRegularExpression( '/<input type="text" class="sw-ui-input" id="sw-rescue-phrase-' . $hash . '" name="confirm_phrase"[^>]*autocomplete="off"[^>]*data-sw-rescue-phrase="ROLL BACK"/', $html );
		self::assertSame( 1, preg_match( '/<dialog[^>]*>(.*?)<\/dialog>/s', $html, $dialog ) );
		self::assertSame( 1, preg_match( '/name="confirmation_token" value="(swc_[^"]+)"/', $dialog[1], $match ) );
		self::assertTrue( ConfirmationToken::verify( html_entity_decode( $match[1] ), 'stonewright/rescue-rollback', [ 'incident_id' => $entry['id'] ] ), 'The token is bound to this ability and this change set.' );
		// The re-check form carries its own token, bound to that action.
		self::assertSame( 1, preg_match( '/name="action" value="stonewright_rescue_recheck" \/><input type="hidden" name="incident_id"[^>]*\/><input type="hidden" name="return_to"[^>]*\/><input type="hidden" name="_stonewright_nonce"[^>]*\/><input type="hidden" name="confirmation_token" value="(swc_[^"]+)"/', $html, $recheck ) );
		self::assertTrue( ConfirmationToken::verify( html_entity_decode( $recheck[1] ), 'stonewright/rescue-rollback', [ 'incident_id' => $entry['id'], 'action' => 'recheck' ] ) );
		self::assertFalse( ConfirmationToken::verify( html_entity_decode( $recheck[1] ), 'stonewright/rescue-rollback', [ 'incident_id' => $entry['id'] ] ), 'A re-check token cannot roll anything back.' );
	}

	public function test_production_safe_mode_adds_an_info_callout_about_the_confirmation(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';

		$html = $this->html();

		self::assertSame( 1, preg_match( '/<div class="sw-rescue-callout sw-rescue-callout--info"[^>]*>(.*?)<\/div>/s', $html, $match ) );
		self::assertStringContainsString( 'Production-safe mode is on', $match[1] );
		self::assertStringContainsString( 'confirmation token', $match[1] );
	}

	public function test_a_probe_request_gets_the_page_without_storing_confirmation_tokens(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		$this->incident( 'incident' );
		$path  = '/wp-admin/admin.php';
		$nonce = '0123456789abcdef';
		$_SERVER['REQUEST_METHOD']           = 'GET';
		$_SERVER['REQUEST_URI']              = $path . '?page=stonewright-rescue&sw_probe=' . $nonce;
		$_GET['sw_probe']                    = $nonce;
		$_SERVER['HTTP_X_STONEWRIGHT_PROBE'] = (string) ProbeToken::issue( 7, $path, $nonce );
		ProbeToken::set_login_handler( static function ( int $user_id ): void {} );
		ProbeToken::authenticate_request();
		$stored = count( $GLOBALS['stonewright_test_transients'] );

		$html = $this->html();

		self::assertTrue( ProbeToken::is_probe_request() );
		self::assertStringContainsString( 'data-sw-rescue-probe="ok"', $html, 'The page still answers the health check.' );
		self::assertStringNotContainsString( 'confirmation_token', $html );
		self::assertCount( $stored, $GLOBALS['stonewright_test_transients'], 'Nobody reads this response, so nothing is stored for it.' );
	}

	public function test_the_agent_prompt_is_in_a_disclosure_with_a_label_and_a_script_only_copy_button(): void {
		$entry = $this->incident();

		$html = $this->html();

		self::assertSame( 1, preg_match( '/<details class="sw-rescue-prompt">\s*<summary>Prompt for your agent<\/summary>(.*?)<\/details>/s', $html, $details ) );
		self::assertSame( 1, preg_match( '/<textarea[^>]*id="(sw-rescue-prompt-[^"]+)"[^>]*\sreadonly[^>]*>(.*?)<\/textarea>/s', $details[1], $match ), 'A read-only prompt box.' );
		self::assertStringContainsString( $entry['id'], $match[2] );
		self::assertStringContainsString( 'stonewright-rescue-rollback', $match[2] );
		self::assertStringContainsString( 'stonewright-rescue-status', $match[2] );
		self::assertStringContainsString( 'for="' . $match[1] . '"', $details[1], 'The box has a label.' );
		self::assertMatchesRegularExpression( '/<button type="button"[^>]*\shidden[^>]*data-sw-rescue-copy="' . preg_quote( $match[1], '/' ) . '"[^>]*>Copy prompt for your agent<\/button>/', $details[1] );
	}

	public function test_the_copy_prompt_names_the_incident_and_the_ability_to_call(): void {
		$entry = $this->incident();

		$prompt = RescuePage::prompt_for( $entry );

		self::assertStringContainsString( $entry['id'], $prompt );
		self::assertStringContainsString( 'stonewright-rescue-status', $prompt );
		self::assertStringContainsString( 'stonewright-rescue-rollback', $prompt );
		self::assertStringContainsString( 'site_status healthy', $prompt );
	}

	// -- States ------------------------------------------------------------------

	public function test_a_problem_with_the_journal_file_is_said_out_loud(): void {
		file_put_contents( $this->uploads . '/stonewright-state', 'in the way' );
		ChangeJournal::reset_for_tests();
		ChangeJournal::arm( [ 'ability' => 'stonewright/x-y', 'resource_type' => 'option', 'resource_key' => 'blogname' ] );

		$html = $this->html();

		self::assertStringContainsString( 'journal file', $html );
		self::assertMatchesRegularExpression( '/<div class="sw-rescue-notice sw-rescue-notice--warn" role="status">/', $html );
	}

	public function test_nothing_the_journal_holds_can_break_out_of_the_markup(): void {
		$entry = ChangeJournal::arm( [ 'ability' => 'stonewright/x-y', 'resource_type' => 'option', 'resource_key' => '"><script>alert(1)</script>' ] );
		ChangeJournal::settle( $entry['id'], 'rollback_failed' );

		$html = $this->html();

		self::assertStringNotContainsString( '<script>alert(1)', $html );
		self::assertStringContainsString( '&lt;script&gt;alert(1)', $html );
	}

	public function test_it_uses_no_inline_event_handlers_no_inline_styles_and_no_duplicate_ids(): void {
		$this->incident( 'incident' );
		$this->incident( 'rollback_failed', 32 );
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';

		$html = $this->html();

		self::assertDoesNotMatchRegularExpression( '/\son[a-z]+=/i', $html );
		self::assertStringNotContainsString( 'style="', $html );
		preg_match_all( '/\sid="([^"]+)"/', $html, $ids );
		self::assertSame( [], array_keys( array_filter( array_count_values( $ids[1] ), static fn ( int $n ): bool => $n > 1 ) ), 'Every id is unique, so two change sets never share a label.' );
	}

	public function test_every_control_has_a_name(): void {
		$this->incident( 'incident' );
		RescuePage::set_safe_mode_resolver( static fn ( int $user_id ): ?string => 'https://example.test/x' );

		$html = $this->html();

		preg_match_all( '/<button\b[^>]*>(.*?)<\/button>/s', $html, $buttons );
		self::assertNotEmpty( $buttons[0] );
		foreach ( $buttons[0] as $index => $button ) {
			self::assertTrue(
				'' !== trim( strip_tags( $buttons[1][ $index ] ) ) || str_contains( $button, 'aria-label=' ),
				'A button without a name: ' . $button
			);
		}
	}

	// -- The result of an action -------------------------------------------------

	public function test_a_successful_rollback_shows_a_status_notice_with_a_receipt_and_a_link_to_the_audit_log(): void {
		$_GET = [ 'rescue' => 'rolled_back', 'incident' => 'cs-0123456789abcdef01234567' ];

		$html = $this->html();

		self::assertSame( 1, preg_match( '/<div class="sw-rescue-notice sw-rescue-notice--ok" role="status" data-sw-rescue-result>(.*?)<\/div>/s', $html, $match ) );
		self::assertStringContainsString( 'rolled back', $match[1] );
		self::assertStringContainsString( 'Receipt', $match[1] );
		self::assertStringContainsString( '<code class="sw-rescue-id">cs-0123456789abcdef01234567</code>', $match[1] );
		self::assertMatchesRegularExpression( '/<button type="button"[^>]*\shidden[^>]*data-sw-rescue-copy-text="cs-0123456789abcdef01234567"[^>]*>Copy<span class="sw-ui-visually-hidden"> receipt id<\/span><\/button>/', $match[1] );
		self::assertMatchesRegularExpression( '/<a href="[^"]*page=stonewright-audit-log[^"]*ability=stonewright%2Frescue-rollback[^"]*">View in Audit log<\/a>/', $match[1] );
	}

	public function test_a_rollback_that_fails_is_announced_as_an_alert(): void {
		$_GET = [ 'rescue' => 'rollback_failed', 'incident' => 'cs-abc' ];

		self::assertMatchesRegularExpression( '/<div class="sw-rescue-notice sw-rescue-notice--danger" role="alert" data-sw-rescue-result>/', $this->html() );
	}

	public function test_a_rollback_that_leaves_the_site_failing_is_a_warning(): void {
		$_GET = [ 'rescue' => 'rolled_back_still_failing', 'incident' => 'cs-abc' ];

		self::assertMatchesRegularExpression( '/<div class="sw-rescue-notice sw-rescue-notice--warn" role="status" data-sw-rescue-result>/', $this->html() );
	}

	public function test_an_unknown_outcome_shows_nothing_and_nothing_in_it_is_trusted(): void {
		$_GET = [ 'rescue' => '<script>', 'incident' => '<b>x</b>' ];
		$html = $this->html();
		self::assertStringNotContainsString( '<script>', $html );
		self::assertStringNotContainsString( 'data-sw-rescue-result', $html );

		$_GET = [ 'rescue' => 'rolled_back', 'incident' => '"><img src=x>' ];
		$html = $this->html();
		self::assertStringNotContainsString( '<img src=x>', $html );
		self::assertStringNotContainsString( 'Receipt', $html, 'A bad id is dropped rather than echoed.' );
	}

	// -- Age, a newer change, and a rollback that is already running -------------------------------

	public function test_each_change_set_says_how_long_ago_it_was_made(): void {
		$GLOBALS['stonewright_test_filters']['stonewright_rescue_now'] = static fn (): int => time() - 10800;
		$this->incident( 'rollback_failed', 31 );
		unset( $GLOBALS['stonewright_test_filters']['stonewright_rescue_now'] );

		self::assertMatchesRegularExpression( '/<span class="sw-rescue-sub">Changed 3 hours ago<\/span>/', $this->html() );
	}

	public function test_a_change_made_a_moment_ago_says_so(): void {
		$this->incident();

		self::assertStringContainsString( 'Changed just now', $this->html() );
	}

	public function test_the_confirmation_warns_when_a_newer_change_to_the_same_item_exists(): void {
		$older = $this->incident( 'rollback_failed', 31 );
		$newer = ChangeJournal::arm( [ 'ability' => 'stonewright/design-apply-to-post', 'resource_type' => 'post', 'resource_key' => '31', 'scope' => 'post' ] );
		$hash  = md5( $older['id'] );

		$html = $this->html();

		self::assertSame( 1, preg_match( '/<dialog[^>]*id="sw-rescue-dlg-' . $hash . '"[^>]*>(.*?)<\/dialog>/s', $html, $dialog ) );
		self::assertMatchesRegularExpression( '/<p class="sw-rescue-warning" role="note">[^<]*newer change[^<]*<code class="sw-rescue-id">' . preg_quote( substr( $newer['id'], 3, 8 ), '/' ) . '<\/code>/', $dialog[1] );
	}

	public function test_a_confirmation_with_nothing_newer_has_no_warning(): void {
		$this->incident();

		self::assertStringNotContainsString( 'sw-rescue-warning', $this->html() );
	}

	public function test_a_change_set_that_is_being_rolled_back_offers_no_second_click(): void {
		$entry = $this->incident();
		ChangeJournal::claim( $entry['id'], 'ability' );

		$html = $this->html();

		self::assertStringContainsString( 'A rollback of this change set is running.', $html );
		self::assertStringNotContainsString( 'data-sw-rescue-open', $html );
		self::assertStringNotContainsString( '<dialog', $html );
	}

	public function test_a_second_roll_back_request_for_the_same_change_set_is_turned_away_and_changes_nothing(): void {
		$entry = $this->incident();
		ChangeJournal::claim( $entry['id'], 'ability' );
		$_POST = [ '_stonewright_nonce' => 'x', 'incident_id' => $entry['id'] ];

		$result = RescuePage::process_rollback_request();

		self::assertSame( 'in_progress', $result['code'] );
		self::assertSame( 'broken body', $GLOBALS['stonewright_test_posts'][31]->post_content );
		$_GET = [ 'rescue' => 'in_progress', 'incident' => $entry['id'] ];
		self::assertStringContainsString( 'already running', $this->html() );
	}

	// -- Undo of a verified change -----------------------------------------------------------------

	/** A settled change to a sandbox file, whose way back runs without touching a file. */
	private function verified_code_change(): array {
		$entry = ChangeJournal::arm(
			[
				'ability'       => 'stonewright/sandbox-activate',
				'resource_type' => 'sandbox',
				'resource_key'  => 'example-snippet.php',
				'recipe'        => [ 'type' => 'sandbox_file', 'ref' => 'example-snippet.php' ],
			]
		);
		ChangeJournal::settle( $entry['id'], 'verified' );
		return (array) ChangeJournal::get( $entry['id'] );
	}

	public function test_a_verified_change_in_the_history_can_be_rolled_back_from_the_page(): void {
		$entry = $this->incident( 'verified' );

		$html = $this->html();

		self::assertSame( 1, preg_match( '/<table class="sw-rescue-table"[^>]*data-sw-rescue-table="recent"[^>]*>(.*?)<\/table>/s', $html, $table ) );
		self::assertStringContainsString( self::short( $entry['id'] ), $table[1] );
		self::assertStringContainsString( 'name="action" value="stonewright_rescue_rollback"', $table[1] );
		self::assertStringContainsString( 'name="incident_id" value="' . $entry['id'] . '"', $table[1] );
		self::assertStringContainsString( 'review=' . $entry['id'], $table[1], 'The press is a link to the review.' );
		self::assertStringContainsString( 'Roll back<span class="sw-ui-visually-hidden"> change set ' . self::short( $entry['id'] ) . '</span>', $table[1] );
		self::assertStringContainsString( '<dialog', $table[1] );
		self::assertStringNotContainsString( 'stonewright_rescue_recheck', $table[1], 'A verified change has nothing to check again.' );
		self::assertSame( 1, substr_count( $html, 'data-sw-rescue-dialog' ), 'One dialog per change.' );
	}

	public function test_every_change_the_journal_keeps_is_in_the_history(): void {
		for ( $number = 1; $number <= 14; ++$number ) {
			$entry = ChangeJournal::arm( [ 'ability' => 'stonewright/content-update-page', 'resource_type' => 'post', 'resource_key' => (string) ( 700 + $number ), 'recipe' => [ 'type' => 'none', 'ref' => '' ] ] );
			ChangeJournal::settle( $entry['id'], 'verified' );
		}

		$html = $this->html();

		self::assertSame( 14, substr_count( $html, '<tr data-sw-rescue-row=' ), 'The history is not cut at twelve; it holds what the journal holds.' );
	}

	public function test_a_verified_change_without_a_recipe_says_so_and_offers_no_button(): void {
		$entry = ChangeJournal::arm( [ 'ability' => 'stonewright/content-update-page', 'resource_type' => 'post', 'resource_key' => '9', 'recipe' => [ 'type' => 'none', 'ref' => '' ] ] );
		ChangeJournal::settle( $entry['id'], 'verified' );

		$html = $this->html();

		self::assertStringContainsString( 'No automatic rollback is available for this change.', $html );
		self::assertStringNotContainsString( 'data-sw-rescue-open', $html );
	}

	public function test_a_change_that_was_rolled_back_says_by_whom_and_offers_no_second_rollback(): void {
		$entry = $this->incident( 'verified' );
		$_POST = [ '_stonewright_nonce' => 'x', 'incident_id' => $entry['id'] ];
		RescuePage::process_rollback_request();
		$_POST = [];

		$html = $this->html();

		self::assertStringContainsString( 'Rolled back', $html );
		self::assertStringContainsString( 'Rolled back from this page by User 7', $html );
		self::assertStringNotContainsString( 'data-sw-rescue-open', $html );
		self::assertStringNotContainsString( '<dialog', $html );
	}

	public function test_the_dialog_of_a_verified_change_says_it_worked_and_names_what_is_restored(): void {
		$this->incident( 'verified' );

		$html = $this->html();

		self::assertStringContainsString( 'This change passed its health check.', $html );
		self::assertStringContainsString( 'Restore post 31 from the snapshot taken before the change.', $html );
	}

	public function test_a_verified_change_is_rolled_back_from_the_page_and_the_page_keeps_the_receipt(): void {
		$entry = $this->incident( 'verified' );
		$_POST = [ '_stonewright_nonce' => 'x', 'incident_id' => $entry['id'] ];

		$result = RescuePage::process_rollback_request();

		self::assertSame( 'rolled_back', $result['code'] );
		self::assertSame( 'original body', $GLOBALS['stonewright_test_posts'][31]->post_content );
		$after = ChangeJournal::get( $entry['id'] );
		self::assertSame( 'rolled_back', $after['state'] );
		self::assertSame( 'admin-page', $after['rollback']['by'] );
		self::assertSame( 7, $after['rollback']['user'] );
		self::assertSame( 'healthy', $after['rollback']['site'], 'The site is probed afterwards.' );
		$rows = array_values(
			array_filter(
				array_map( static fn ( array $row ): array => $row['data'], $GLOBALS['stonewright_test_wpdb_inserts'] ),
				static fn ( array $row ): bool => 'stonewright/rescue-rollback' === ( $row['ability_name'] ?? '' )
			)
		);
		self::assertCount( 1, $rows );
		self::assertSame( $entry['id'], $rows[0]['change_set_id'] );
		self::assertSame( 'succeeded', $rows[0]['rollback_status'] );
	}

	public function test_an_undo_that_breaks_the_site_is_put_back_and_the_page_says_so(): void {
		$entry = $this->incident( 'verified' );
		// The older state is the one that fails.
		$this->site( static fn (): string => 'original body' === $GLOBALS['stonewright_test_posts'][31]->post_content ? 'broken' : 'healthy' );
		$_POST = [ '_stonewright_nonce' => 'x', 'incident_id' => $entry['id'] ];

		$result = RescuePage::process_rollback_request();
		$_POST  = [];

		self::assertSame( 'undo_reverted', $result['code'] );
		self::assertSame( 'broken body', $GLOBALS['stonewright_test_posts'][31]->post_content, 'The state from before the undo is back.' );
		$after = ChangeJournal::get( $entry['id'] );
		self::assertSame( 'verified', $after['state'] );
		self::assertSame( 'undo_reverted', $after['note'] );
		$rows = array_values(
			array_filter(
				array_map( static fn ( array $row ): array => $row['data'], $GLOBALS['stonewright_test_wpdb_inserts'] ),
				static fn ( array $row ): bool => 'stonewright/rescue-rollback' === ( $row['ability_name'] ?? '' )
			)
		);
		self::assertCount( 1, $rows, 'The receipt is written.' );
		self::assertSame( 'reverted', $rows[0]['rollback_status'] );
		self::assertSame( 'stonewright_rescue_undo_reverted', $rows[0]['error_code'] );
		$_GET = [ 'rescue' => 'undo_reverted', 'incident' => $entry['id'] ];
		$html = $this->html();
		self::assertStringContainsString( 'The undo would have broken the site, so it was put back.', $html );
		self::assertStringContainsString( 'An undo was tried and put back because the site stopped loading', $html );
		self::assertStringContainsString( 'data-sw-rescue-open', $html, 'The change is still verified and can be undone again.' );
	}

	public function test_an_undo_that_cannot_save_the_current_state_first_is_refused_on_the_page(): void {
		$entry = $this->incident( 'verified' );
		unset( $GLOBALS['stonewright_test_posts'][31] );
		$_POST = [ '_stonewright_nonce' => 'x', 'incident_id' => $entry['id'] ];

		$result = RescuePage::process_rollback_request();
		$_POST  = [];

		self::assertSame( 'undo_refused', $result['code'] );
		self::assertSame( 'verified', ChangeJournal::get( $entry['id'] )['state'] );
		$_GET = [ 'rescue' => 'undo_refused', 'incident' => $entry['id'] ];
		self::assertStringContainsString( 'no way back', $this->html() );
	}

	public function test_a_verified_code_change_is_rolled_back_from_the_page_because_an_administrator_asked(): void {
		$entry = $this->verified_code_change();
		$_POST = [ '_stonewright_nonce' => 'x', 'incident_id' => $entry['id'] ];

		$result = RescuePage::process_rollback_request();

		self::assertSame( 'rolled_back', $result['code'] );
		$after = ChangeJournal::get( $entry['id'] );
		self::assertSame( 'rolled_back', $after['state'] );
		self::assertSame( 'admin-page', $after['rollback']['by'] );
	}

	public function test_a_second_roll_back_of_a_verified_change_does_not_run_again(): void {
		$entry = $this->incident( 'verified' );
		$_POST = [ '_stonewright_nonce' => 'x', 'incident_id' => $entry['id'] ];
		self::assertSame( 'rolled_back', RescuePage::process_rollback_request()['code'] );
		$GLOBALS['stonewright_test_posts'][31]->post_content = 'edited after the undo';

		$again = RescuePage::process_rollback_request();

		self::assertSame( 'not_open', $again['code'] );
		self::assertSame( 'edited after the undo', $GLOBALS['stonewright_test_posts'][31]->post_content );
	}

	public function test_a_verified_change_that_is_being_rolled_back_offers_no_second_click(): void {
		$entry = $this->incident( 'verified' );
		ChangeJournal::claim( $entry['id'], 'ability' );
		$_POST = [ '_stonewright_nonce' => 'x', 'incident_id' => $entry['id'] ];

		self::assertStringContainsString( 'A rollback of this change set is running.', $this->html() );
		self::assertSame( 'in_progress', RescuePage::process_rollback_request()['code'] );
		self::assertSame( 'broken body', $GLOBALS['stonewright_test_posts'][31]->post_content );
	}

	public function test_production_safe_mode_binds_a_token_to_a_verified_change_and_refuses_without_it(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		$entry = $this->incident( 'verified' );

		$html = $this->html();
		self::assertStringContainsString( 'data-sw-rescue-phrase="ROLL BACK"', $html );
		self::assertStringContainsString( 'name="confirmation_token"', $html );

		$_POST = [ '_stonewright_nonce' => 'x', 'incident_id' => $entry['id'], 'confirm_phrase' => 'ROLL BACK' ];
		self::assertSame( 'confirmation_required', RescuePage::process_rollback_request()['code'] );
		self::assertSame( 'broken body', $GLOBALS['stonewright_test_posts'][31]->post_content );
		self::assertSame( 'verified', ChangeJournal::get( $entry['id'] )['state'] );
	}

	public function test_the_confirmation_of_a_verified_change_warns_about_a_newer_change_to_the_same_item(): void {
		$entry = $this->incident( 'verified' );
		$newer = ChangeJournal::arm( [ 'ability' => 'stonewright/content-update-page', 'resource_type' => 'post', 'resource_key' => '31', 'recipe' => [ 'type' => 'none', 'ref' => '' ] ] );
		ChangeJournal::settle( $newer['id'], 'verified' );

		$html = $this->html();

		self::assertStringContainsString( 'sw-rescue-warning', $html );
		self::assertStringContainsString( self::short( $newer['id'] ), $html );
		self::assertNotSame( '', $entry['id'] );
	}

	// -- Safe mode ---------------------------------------------------------------

	public function test_the_safe_mode_button_appears_only_when_a_safe_mode_exists(): void {
		RescuePage::set_safe_mode_resolver( false );
		self::assertStringNotContainsString( 'stonewright_rescue_safe_mode', $this->html() );

		RescuePage::set_safe_mode_resolver( static fn ( int $user_id ): ?string => 'https://example.test/wp-admin/?sw_rescue=abc' );

		$html = $this->html();
		self::assertStringContainsString( 'name="action" value="stonewright_rescue_safe_mode"', $html );
		self::assertStringContainsString( 'Open in safe mode', $html );
	}

	public function test_without_a_helper_the_safe_mode_button_is_not_offered_and_the_page_says_why(): void {
		RescuePage::set_safe_mode_resolver( null );
		MuRuntime::load();
		RescueInstaller::remove();

		$html = $this->html();

		self::assertStringNotContainsString( 'stonewright_rescue_safe_mode', $html );
		self::assertStringNotContainsString( 'Open in safe mode', $html );
		self::assertStringContainsString( 'data-sw-rescue-helper="missing"', $html );
		self::assertStringContainsString( 'Not installed', $html );
		self::assertStringContainsString( 'Safe mode is not available', $html );
	}

	public function test_with_a_healthy_helper_the_page_shows_its_state_and_offers_safe_mode(): void {
		RescuePage::set_safe_mode_resolver( null );
		MuRuntime::load();
		RescueInstaller::install();

		try {
			$html = $this->html();
		} finally {
			RescueInstaller::remove();
		}

		self::assertStringContainsString( 'data-sw-rescue-helper="installed"', $html );
		self::assertStringContainsString( 'Rescue helper', $html );
		self::assertStringContainsString( 'Installed', $html );
		self::assertStringContainsString( 'name="action" value="stonewright_rescue_safe_mode"', $html );
		self::assertStringNotContainsString( 'Safe mode is not available', $html );
	}

	public function test_by_default_the_link_comes_from_the_shared_safe_boot_contract(): void {
		RescuePage::set_safe_mode_resolver( null );
		self::assertTrue( method_exists( 'Stonewright\WpMcp\Security\RescueKeys', 'safe_boot_url' ), 'The shared contract: RescueKeys::safe_boot_url( int ): ?string.' );
		$_POST['_stonewright_nonce'] = 'x';

		$result = RescuePage::process_safe_mode_request();

		// The helper is not installed in the unit environment: no link, and the page says so.
		self::assertNull( $result['url'] );
		self::assertSame( 'safe_mode_unavailable', $result['code'] );
	}

	public function test_opening_safe_mode_issues_the_link_only_when_asked(): void {
		$calls = 0;
		RescuePage::set_safe_mode_resolver(
			static function ( int $user_id ) use ( &$calls ): ?string {
				++$calls;
				return 7 === $user_id ? 'https://example.test/wp-admin/?sw_rescue=abc' : null;
			}
		);

		$this->html();
		self::assertSame( 0, $calls, 'Viewing the page never issues a key.' );

		$_POST['_stonewright_nonce'] = 'x';
		$result = RescuePage::process_safe_mode_request();

		self::assertSame( 1, $calls );
		self::assertSame( 'https://example.test/wp-admin/?sw_rescue=abc', $result['url'] );
	}

	public function test_a_site_without_a_safe_mode_link_says_so(): void {
		RescuePage::set_safe_mode_resolver( static fn ( int $user_id ): ?string => null );
		$_POST['_stonewright_nonce'] = 'x';

		$result = RescuePage::process_safe_mode_request();

		self::assertNull( $result['url'] );
		self::assertSame( 'safe_mode_unavailable', $result['code'] );
	}

	// -- Handlers ----------------------------------------------------------------

	public function test_rolling_back_from_the_page_restores_the_change_and_records_who_did_it(): void {
		$entry = $this->incident();
		$_POST = [ '_stonewright_nonce' => 'x', 'incident_id' => $entry['id'] ];

		$result = RescuePage::process_rollback_request();

		self::assertSame( 'rolled_back', $result['code'] );
		self::assertSame( $entry['id'], $result['incident_id'] );
		self::assertSame( 'original body', $GLOBALS['stonewright_test_posts'][31]->post_content );
		self::assertSame( 'admin-page', ChangeJournal::get( $entry['id'] )['rollback']['by'] );
		$rows = array_values(
			array_filter(
				array_map( static fn ( array $row ): array => $row['data'], $GLOBALS['stonewright_test_wpdb_inserts'] ),
				static fn ( array $row ): bool => 'stonewright/rescue-rollback' === ( $row['ability_name'] ?? '' )
			)
		);
		self::assertCount( 1, $rows, 'The page audits the outcome.' );
		self::assertSame( 'succeeded', $rows[0]['rollback_status'] );
	}

	public function test_a_bad_nonce_is_refused_and_nothing_changes(): void {
		$entry = $this->incident();
		$_POST = [ '_stonewright_nonce' => '', 'incident_id' => $entry['id'] ];

		$this->expectException( \RuntimeException::class );
		try {
			RescuePage::process_rollback_request();
		} finally {
			self::assertSame( 'broken body', $GLOBALS['stonewright_test_posts'][31]->post_content );
		}
	}

	public function test_a_user_who_cannot_manage_options_is_refused(): void {
		$entry = $this->incident();
		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => false ];
		$_POST = [ '_stonewright_nonce' => 'x', 'incident_id' => $entry['id'] ];

		$this->expectException( \RuntimeException::class );
		try {
			RescuePage::process_rollback_request();
		} finally {
			self::assertSame( 'broken body', $GLOBALS['stonewright_test_posts'][31]->post_content );
		}
	}

	public function test_production_safe_mode_refuses_a_rollback_without_its_token(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		$entry = $this->incident();
		$_POST = [ '_stonewright_nonce' => 'x', 'incident_id' => $entry['id'], 'confirm_phrase' => 'ROLL BACK' ];

		$result = RescuePage::process_rollback_request();

		self::assertSame( 'confirmation_required', $result['code'] );
		self::assertSame( 'broken body', $GLOBALS['stonewright_test_posts'][31]->post_content );
	}

	public function test_production_safe_mode_refuses_a_missing_or_wrong_phrase_on_the_server_and_runs_nothing(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		$entry = $this->incident();
		$token = ConfirmationToken::issue( 'stonewright/rescue-rollback', [ 'incident_id' => $entry['id'] ] );

		foreach ( [ [], [ 'confirm_phrase' => 'roll back' ], [ 'confirm_phrase' => 'ROLL BACK NOW' ] ] as $extra ) {
			$_POST = array_merge( [ '_stonewright_nonce' => 'x', 'incident_id' => $entry['id'], 'confirmation_token' => $token ], $extra );
			self::assertSame( 'confirmation_phrase', RescuePage::process_rollback_request()['code'] );
		}
		self::assertSame( 'broken body', $GLOBALS['stonewright_test_posts'][31]->post_content );
		self::assertSame( 'rollback_failed', ChangeJournal::get( $entry['id'] )['state'] );
		self::assertSame( [], $this->receipts(), 'A refused confirmation is not a run and has no receipt.' );

		$_POST = [ '_stonewright_nonce' => 'x', 'incident_id' => $entry['id'], 'confirmation_token' => $token, 'confirm_phrase' => ' ROLL BACK ' ];
		self::assertSame( 'rolled_back', RescuePage::process_rollback_request()['code'], 'The token was not spent by the refusals.' );
	}

	public function test_production_safe_mode_accepts_the_token_the_page_issued(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		$entry = $this->incident();
		$_POST = [
			'_stonewright_nonce' => 'x',
			'incident_id'        => $entry['id'],
			'confirmation_token' => ConfirmationToken::issue( 'stonewright/rescue-rollback', [ 'incident_id' => $entry['id'] ] ),
			'confirm_phrase'     => 'ROLL BACK',
		];

		$result = RescuePage::process_rollback_request();

		self::assertSame( 'rolled_back', $result['code'] );
	}

	// -- A rollback over later edits needs the box ticked ---------------------------------------------

	public function test_an_ordinary_rollback_over_a_newer_change_is_refused_until_the_box_is_ticked(): void {
		$entry = $this->incident( 'verified' );
		$newer = ChangeJournal::arm( [ 'ability' => 'stonewright/content-update-page', 'resource_type' => 'post', 'resource_key' => '31', 'recipe' => [ 'type' => 'none', 'ref' => '' ] ] );
		ChangeJournal::settle( $newer['id'], 'verified' );
		$html = $this->html_for( [ 'review' => $entry['id'] ] );

		self::assertMatchesRegularExpression( '/<input type="checkbox" id="sw-rescue-review-drift" name="force_drift" value="1" required/', $html, 'The review asks the person to say they know.' );

		$_POST = [ '_stonewright_nonce' => 'x', 'incident_id' => $entry['id'] ];
		self::assertSame( 'drift', RescuePage::process_rollback_request()['code'] );
		self::assertSame( 'broken body', $GLOBALS['stonewright_test_posts'][31]->post_content, 'The later edit is kept.' );
		self::assertSame( [], $this->receipts() );

		$_POST['force_drift'] = '1';
		self::assertSame( 'rolled_back', RescuePage::process_rollback_request()['code'] );
		self::assertSame( 'original body', $GLOBALS['stonewright_test_posts'][31]->post_content );
	}

	public function test_the_box_is_shown_when_the_engine_says_a_confirmation_is_needed_and_not_otherwise(): void {
		$with = $this->incident( 'verified', 31 );
		$none = $this->incident( 'verified', 32 );
		$newer = ChangeJournal::arm( [ 'ability' => 'stonewright/content-update-page', 'resource_type' => 'post', 'resource_key' => '31', 'recipe' => [ 'type' => 'none', 'ref' => '' ] ] );
		ChangeJournal::settle( $newer['id'], 'verified' );

		self::assertStringContainsString( 'name="force_drift"', $this->html_for( [ 'review' => $with['id'] ] ), 'A later change to the item: the engine asks.' );
		self::assertStringNotContainsString( 'name="force_drift"', $this->html_for( [ 'review' => $none['id'] ] ), 'Nothing later: it does not.' );
	}

	public function test_the_refusals_that_ran_nothing_come_back_to_the_review_and_the_others_to_the_page(): void {
		$where = static fn ( string $code ): string => RescuePage::outcome_url( [ 'code' => $code, 'incident_id' => 'cs-abc' ] );

		foreach ( [ 'confirmation_phrase', 'confirmation_invalid', 'confirmation_required', 'drift', 'changed_since_preview' ] as $code ) {
			self::assertStringContainsString( 'review=cs-abc', $where( $code ), $code );
		}
		foreach ( [ 'rolled_back', 'rollback_failed', 'in_progress', 'not_found' ] as $code ) {
			self::assertStringNotContainsString( 'review=', $where( $code ), $code );
		}
	}

	// -- What a health check said ----------------------------------------------------------------------

	public function test_the_kinds_of_a_health_check_are_told_apart_and_only_one_of_them_says_the_site_failed(): void {
		$leg = static fn ( string $status, string $reason ): array => [ 'status' => 'failed' === $status ? 'failed' : 'unavailable', 'coverage' => 'none', 'legs' => [ [ 'leg' => 'home', 'status' => $status, 'http' => 0, 'reason' => $reason ] ] ];

		self::assertSame( 'healthy', RescuePage::verification_kind( [ 'status' => 'passed', 'legs' => [] ] ) );
		self::assertSame( 'site_failed', RescuePage::verification_kind( [ 'status' => 'failed', 'legs' => [ [ 'leg' => 'home', 'status' => 'failed', 'http' => 500, 'reason' => 'http_500' ] ] ] ) );
		self::assertSame( 'site_failed', RescuePage::verification_kind( $leg( 'failed', 'critical_error_page' ) ), 'A failed leg is the site failing, whatever the total says.' );
		self::assertSame( 'not_started', RescuePage::verification_kind( $leg( 'unavailable', 'token_unavailable' ) ) );
		self::assertSame( 'not_started', RescuePage::verification_kind( [ 'status' => 'unavailable', 'unavailable_reason' => 'token_unavailable' ] ) );
		foreach ( [ 'no_response', 'redirect', 'http_403', 'http_404', 'login_required', 'unexpected_body', 'budget', 'degraded_no_response', 'a_code_nobody_has_met' ] as $reason ) {
			self::assertSame( 'unreachable', RescuePage::verification_kind( $leg( 'unavailable', $reason ) ), $reason . ' is a check with no verdict, never a failed site.' );
		}
		self::assertSame( 'unknown', RescuePage::verification_kind( [] ) );
		self::assertSame( 'unknown', RescuePage::verification_kind( [ 'status' => 'unavailable', 'legs' => [] ] ) );
		self::assertSame( 'check_lost', RescuePage::verification_kind( [ 'status' => 'failed', 'coverage' => 'partial', 'legs' => [ [ 'leg' => 'admin', 'status' => 'failed', 'http' => 0, 'reason' => 'degraded_no_response' ] ] ] ), 'A check that passed before and does not answer now is not a failed site.' );
	}

	/** @return array<string, mixed> An unverified entry with the probe the journal would hold. */
	private function unverified( array $probe ): array {
		$entry = ChangeJournal::arm( [ 'ability' => 'stonewright/content-update-page', 'resource_type' => 'post', 'resource_key' => '31', 'recipe' => [ 'type' => 'none', 'ref' => '' ] ] );
		ChangeJournal::record_probe( $entry['id'], $probe );
		$GLOBALS['stonewright_test_filters']['stonewright_rescue_now'] = static fn (): int => time() + 4000;
		return $entry;
	}

	public function test_a_change_whose_check_could_not_start_says_so_and_does_not_say_the_site_is_broken(): void {
		$this->post( 31, 'x' );
		$this->unverified( [ 'status' => 'unavailable', 'coverage' => 'none', 'legs' => [ [ 'leg' => 'home', 'status' => 'unavailable', 'http' => 0, 'reason' => 'token_unavailable', 'ms' => 0 ] ], 'checked_at' => time() ] );

		$html = $this->html();
		unset( $GLOBALS['stonewright_test_filters']['stonewright_rescue_now'] );

		self::assertMatchesRegularExpression( '/data-sw-rescue-verification="not_started">The health check could not start, so this change has not been checked\. That says nothing about the site\.<\/span>/', $html );
		self::assertSame( 1, preg_match( '/<th scope="row"[^>]*>(.*?)<details/s', $html, $cell ) );
		self::assertStringNotContainsString( 'token_unavailable', $cell[1], 'The technical code is in the details.' );
		self::assertStringContainsString( '<code>token_unavailable</code>', $html );
		self::assertStringNotContainsString( 'broken', strtolower( $cell[1] ) );
		self::assertStringNotContainsString( 'host', strtolower( $html ) );
	}

	public function test_a_change_whose_check_got_no_answer_says_the_result_is_unknown_and_the_site_may_be_fine(): void {
		$this->post( 31, 'x' );
		$this->unverified( [ 'status' => 'unavailable', 'coverage' => 'none', 'legs' => [ [ 'leg' => 'home', 'status' => 'unavailable', 'http' => 0, 'reason' => 'no_response', 'ms' => 10 ] ], 'checked_at' => time() ] );

		$html = $this->html();
		unset( $GLOBALS['stonewright_test_filters']['stonewright_rescue_now'] );

		self::assertMatchesRegularExpression( '/data-sw-rescue-verification="unreachable">The health check got no answer it could judge, so the result is unknown\. The site may be fine\.<\/span>/', $html );
		self::assertStringContainsString( '<code>no_response</code>', $html );
	}

	public function test_a_change_with_no_result_at_all_says_none_was_recorded_yet(): void {
		$this->post( 31, 'x' );
		ChangeJournal::arm( [ 'ability' => 'stonewright/content-update-page', 'resource_type' => 'post', 'resource_key' => '31', 'recipe' => [ 'type' => 'none', 'ref' => '' ] ] );
		$GLOBALS['stonewright_test_filters']['stonewright_rescue_now'] = static fn (): int => time() + 4000;

		$html = $this->html();
		unset( $GLOBALS['stonewright_test_filters']['stonewright_rescue_now'] );

		self::assertStringContainsString( 'No health check has reported on this change yet. That says nothing about the site.', $html );
	}

	public function test_a_site_that_failed_says_so_with_the_words_for_it(): void {
		$entry = $this->incident( 'incident' );
		$html  = $this->html();

		self::assertStringContainsString( 'A fatal error was recorded after this change.', $html );
		self::assertStringContainsString( $entry['id'], $html );
		$entry = $this->incident( 'rollback_failed', 32 );
		self::assertStringContainsString( 'The site failed after this change and the automatic rollback did not finish.', $this->html() );
	}

	public function test_a_check_again_with_no_verdict_is_not_reported_as_a_site_that_failed(): void {
		$entry = $this->incident( 'rollback_failed' );
		HealthProbe::set_transport( static fn ( string $url, array $args ): array => [ 'response' => [ 'code' => 0 ], 'body' => '', 'headers' => [] ] );
		$_POST = [ '_stonewright_nonce' => 'x', 'incident_id' => $entry['id'] ];

		$result = RescuePage::process_recheck_request();

		self::assertSame( 'rechecked_unreachable', $result['code'] );
		self::assertSame( 'rollback_failed', ChangeJournal::get( $entry['id'] )['state'], 'The incident stays open.' );
		$_GET = [ 'rescue' => 'rechecked_unreachable', 'incident' => $entry['id'] ];
		$html = $this->html();
		self::assertStringContainsString( 'no answer it could judge', $html );
		self::assertStringContainsString( 'The site may be fine', $html );
		self::assertStringNotContainsString( 'found the site failing', $html );
	}

	public function test_every_outcome_message_is_neutral_about_the_host_and_the_site_when_the_check_gave_no_verdict(): void {
		foreach ( [ 'rolled_back_unverified', 'rechecked_open', 'rechecked_not_started', 'rechecked_unreachable' ] as $code ) {
			$_GET = [ 'rescue' => $code, 'incident' => 'cs-abc' ];
			$text = strtolower( $this->html() );
			self::assertStringContainsString( 'data-sw-rescue-result', $text, $code );
			self::assertStringNotContainsString( 'host', $text, $code );
			self::assertStringNotContainsString( 'blocks requests', $text, $code );
			self::assertStringNotContainsString( 'site is broken', $text, $code );
		}
	}

	public function test_a_rollback_that_fails_is_reported_and_leaves_the_incident_open(): void {
		$entry = $this->incident();
		delete_post_meta( 31, '_stonewright_backups' );
		$_POST = [ '_stonewright_nonce' => 'x', 'incident_id' => $entry['id'] ];

		$result = RescuePage::process_rollback_request();

		self::assertSame( 'rollback_failed', $result['code'] );
		self::assertSame( 'rollback_failed', ChangeJournal::get( $entry['id'] )['state'] );
	}

	public function test_a_site_that_still_fails_after_the_rollback_is_reported_as_such(): void {
		$entry = $this->incident();
		$this->site( static fn (): string => 'broken' );
		$_POST = [ '_stonewright_nonce' => 'x', 'incident_id' => $entry['id'] ];

		self::assertSame( 'rolled_back_still_failing', RescuePage::process_rollback_request()['code'] );
	}

	public function test_re_checking_closes_the_incident_when_the_site_loads(): void {
		$entry = $this->incident();
		$_POST = [ '_stonewright_nonce' => 'x', 'incident_id' => $entry['id'] ];

		$result = RescuePage::process_recheck_request();

		self::assertSame( 'rechecked_resolved', $result['code'] );
		self::assertSame( 'verified', ChangeJournal::get( $entry['id'] )['state'] );
	}

	public function test_re_checking_a_site_that_still_fails_keeps_the_incident_open(): void {
		$entry = $this->incident();
		$this->site( static fn (): string => 'broken' );
		$_POST = [ '_stonewright_nonce' => 'x', 'incident_id' => $entry['id'] ];

		self::assertSame( 'rechecked_site_failed', RescuePage::process_recheck_request()['code'] );
	}

	// -- Assets ------------------------------------------------------------------

	private static function plugin_dir(): string {
		return dirname( __DIR__, 3 );
	}

	private static function css(): string {
		return (string) file_get_contents( self::plugin_dir() . '/assets/admin/pages/rescue.css' );
	}

	private static function js(): string {
		return (string) file_get_contents( self::plugin_dir() . '/assets/admin/pages/rescue.js' );
	}

	public function test_the_assets_live_under_the_pages_folder(): void {
		self::assertFileExists( self::plugin_dir() . '/assets/admin/pages/rescue.css' );
		self::assertFileExists( self::plugin_dir() . '/assets/admin/pages/rescue.js' );
		self::assertFileDoesNotExist( self::plugin_dir() . '/assets/admin/rescue.css' );
		self::assertFileDoesNotExist( self::plugin_dir() . '/assets/admin/rescue.js' );
	}

	public function test_the_stylesheet_stays_small_and_uses_tokens_only(): void {
		$raw = self::css();
		$css = (string) preg_replace( '~/\*.*?\*/~s', '', $raw );

		self::assertLessThan( 6144, strlen( $raw ), 'A page stylesheet stays small.' );
		self::assertStringContainsString( 'var(--sw-', $css );
		self::assertDoesNotMatchRegularExpression( '/#[0-9a-fA-F]{3,8}\b/', $css, 'No raw colours.' );
		self::assertDoesNotMatchRegularExpression( '/\brgba?\s*\(|\bhsla?\s*\(/', $css, 'No colour functions.' );
		self::assertStringNotContainsString( '!important', $css );
		self::assertStringNotContainsString( 'transition: all', $css );
		self::assertDoesNotMatchRegularExpression( '/border-(left|right):\s*[2-9]px/', $css, 'No coloured side stripes.' );
		self::assertStringNotContainsString( 'prefers-color-scheme', $css, 'No dark mode.' );
		self::assertDoesNotMatchRegularExpression( '/font-size:\s*\d/', $css, 'Font sizes come from tokens.' );
		self::assertDoesNotMatchRegularExpression( '/border-radius:\s*\d/', $css, 'Radii come from tokens.' );
	}

	public function test_the_stylesheet_stacks_the_table_and_leaves_the_size_of_every_control_to_the_layer(): void {
		$css = self::css();

		self::assertStringContainsString( '@media (max-width: 782px)', $css );
		self::assertStringContainsString( 'content: attr(data-label)', $css, 'Cells repeat their heading when stacked.' );
		self::assertDoesNotMatchRegularExpression( '/(?:button|input|select|\.sw-btn|-btn)[^{]*\{[^}]*(?:min-|max-)?height\s*:/', $css, 'The layer sizes every control, at 44px on a narrow screen too.' );
		self::assertDoesNotMatchRegularExpression( '/\.sw-btn|\.sw-rescue-solid/', $css, 'No button system of its own.' );
		self::assertMatchesRegularExpression( '/\.sw-rescue-page \.sw-ui-actions > form \{ display: contents; \}/', $css, 'A form in an action row is not a box of its own: its button is a member of the row.' );
		self::assertStringContainsString( 'overflow-wrap: anywhere', $css, 'Long ids wrap at 400px.' );
		self::assertStringContainsString( '::backdrop', $css );
		self::assertMatchesRegularExpression( '/\.sw-rescue-page \[hidden\][^{]*\{\s*display:\s*none/', $css, 'The hidden attribute must beat the button display rule.' );
	}

	public function test_the_script_only_enhances_and_keeps_the_page_working_without_it(): void {
		$js = self::js();

		foreach ( [ 'showModal', 'data-sw-rescue-open', 'data-sw-rescue-cancel', 'data-sw-rescue-phrase', 'data-sw-rescue-copy', 'data-sw-rescue-copy-text', 'data-sw-rescue-form', 'navigator.clipboard', 'execCommand', "'close'" ] as $needle ) {
			self::assertStringContainsString( $needle, $js, $needle );
		}
		self::assertStringContainsString( "typeof dialog.showModal !== 'function'", $js, 'Without dialog support the link is followed, to the review.' );
		self::assertStringContainsString( 'initPhrases', $js, 'The typed phrase holds the button back on the review as well as in the dialog.' );
		self::assertMatchesRegularExpression( '/event\.preventDefault\(\);\s*dialog\.close\(\);/', $js, 'Cancel is a link: closing the dialog in place stops the navigation.' );
		self::assertDoesNotMatchRegularExpression( '/\.submit\(\)|form\.requestSubmit|dispatchEvent\( new Event\( [\'"]submit/', $js, 'The script never submits a form by itself.' );
		self::assertStringContainsString( 'pageshow', $js, 'Back and forward must not leave a form stuck.' );
		foreach ( [ 'eval(', 'innerHTML', 'setInterval', 'setTimeout( poll', 'window.confirm', 'window.alert', 'XMLHttpRequest', 'fetch(' ] as $forbidden ) {
			self::assertStringNotContainsString( $forbidden, $js, $forbidden );
		}
	}

	// -- Links to the Changes page -----------------------------------------------

	/** A ledger row with the id of a journal entry, as the writer of a change records it. */
	private function ledger_row_for( string $change_id ): void {
		$db = new LedgerWpdb();
		$db->unique[ $db->prefix . 'stonewright_changes' ] = [ 'change_id' ];
		$GLOBALS['wpdb'] = $db;
		ChangeLedger::reset_schema_health_cache_for_tests();
		$row = ChangeLedger::record( [ 'change_id' => $change_id, 'ability' => 'stonewright/elementor-v3-batch-mutate', 'family' => 'elementor', 'resource_type' => 'post', 'resource_id' => '31' ] );
		self::assertIsArray( $row, is_wp_error( $row ) ? $row->get_error_message() : '' );
	}

	public function test_the_history_is_one_tab_away_in_the_tab_bar_and_the_header_has_no_second_button_for_it(): void {
		$html = $this->html();

		self::assertMatchesRegularExpression( '#<a class="sw-ui-hubnav__link" href="[^"]*page=stonewright-changes&tab=history">History</a>#', $html );
		self::assertStringNotContainsString( 'View changes', $html );
		self::assertStringNotContainsString( 'sw-ui-page-header__aside', $html );
	}

	public function test_a_journal_row_with_a_ledger_row_links_to_its_diff_and_a_row_without_one_does_not(): void {
		$with    = $this->incident( 'incident', 31 );
		$without = $this->incident( 'rollback_failed', 32 );
		$this->ledger_row_for( (string) $with['id'] );

		$html = $this->html();

		self::assertSame( 1, substr_count( $html, '>View diff<' ), 'Only the row that has a ledger row links.' );
		self::assertMatchesRegularExpression( '/<a class="sw-ui-btn sw-ui-btn--sm" href="[^"]*page=stonewright-changes&change=' . preg_quote( (string) $with['id'], '/' ) . '">View diff<span class="sw-ui-visually-hidden"> of change set ' . self::short( (string) $with['id'] ) . '<\/span><\/a>/', $html );
		self::assertStringNotContainsString( 'change=' . $without['id'], $html );
	}

	public function test_the_rescue_page_still_renders_when_the_ledger_cannot_be_read(): void {
		$this->incident( 'incident', 31 );
		$GLOBALS['wpdb'] = new \stdClass();

		$html = $this->html();

		self::assertStringContainsString( 'Roll back', $html );
		self::assertStringNotContainsString( '>View diff<', $html );
		self::assertStringContainsString( 'data-sw-rescue-probe="ok"', $html );
		self::assertStringContainsString( 'Recent changes only Rescue keeps', $html );
	}

	// -- Action rows ---------------------------------------------------------------------------------------

	/** The first row of actions of a table row, as its controls in order. @return list<string> */
	private static function row_controls( string $html, string $id ): array {
		self::assertSame( 1, preg_match( '/<tr data-sw-rescue-row="' . preg_quote( $id, '/' ) . '">(.*?)<\/tr>/s', $html, $row ), 'The row is there.' );
		self::assertSame( 1, preg_match( '/<td data-label="Actions">(.*)<\/td>/s', $row[1], $cell ) );
		self::assertSame( 1, preg_match( '/<div class="sw-ui-actions" data-sw-actions>(.*?)<\/div>(?=<p class="sw-rescue-note">|<dialog|$)/s', $cell[1], $group ), 'The actions are one row of the layer.' );
		preg_match_all( '/<(?:a|button) [^>]*>[^<]*(?:<span[^>]*>[^<]*<\/span>)?<\/(?:a|button)>/', $group[1], $controls );
		return array_map( static fn ( string $control ): string => trim( (string) preg_replace( '/\s+/', ' ', strip_tags( $control ) ) ), $controls[0] );
	}

	public function test_a_not_verified_row_has_its_three_actions_in_one_row_in_one_order(): void {
		$entry = $this->incident( 'armed' );
		$GLOBALS['stonewright_test_filters']['stonewright_rescue_now'] = static fn (): int => time() + 4000;
		$this->ledger_row_for( (string) $entry['id'] );
		$html = $this->html();
		unset( $GLOBALS['stonewright_test_filters']['stonewright_rescue_now'] );
		$short = self::short( $entry['id'] );

		self::assertSame(
			[ 'Roll back change set ' . $short, 'Check again change set ' . $short, 'View diff of change set ' . $short ],
			self::row_controls( $html, $entry['id'] ),
			'Roll back, Check again and View diff share one row: the same height, one gap, no stacking.'
		);
		// Every control of the row is a button of the layer, so the size tokens decide its height.
		self::assertDoesNotMatchRegularExpression( '/class="sw-btn/', $html, 'No control of the older button system.' );
		self::assertSame( 1, preg_match( '/<td data-label="Actions">.*?<\/td>/s', $html, $cell ) );
		self::assertSame( 1, substr_count( $cell[0], 'class="sw-ui-actions"' ), 'One group per row.' );
	}

	public function test_a_note_about_a_missing_action_is_under_the_row_never_in_it(): void {
		$entry = ChangeJournal::arm( [ 'ability' => 'stonewright/custom-code-provider', 'resource_type' => 'custom_code', 'resource_key' => 'wpcode:9', 'recipe' => [ 'type' => 'none', 'ref' => '' ] ] );
		ChangeJournal::settle( $entry['id'], 'rollback_failed' );

		$html = $this->html();

		self::assertSame( 1, preg_match( '/<td data-label="Actions">(.*?)<\/td>/s', $html, $cell ) );
		self::assertMatchesRegularExpression( '/<\/div><p class="sw-rescue-note">No automatic rollback is available for this change\. Undo it by hand, then check the site again\.<\/p>$/', $cell[1], 'The note follows the row.' );
		self::assertStringNotContainsString( 'sw-rescue-note', (string) preg_replace( '/<\/div><p class="sw-rescue-note">.*$/s', '</div>', $cell[1] ), 'And nothing of it is inside.' );
	}

	public function test_open_in_safe_mode_is_in_the_header_as_a_row_with_the_help_under_it_and_described_by_it(): void {
		RescuePage::set_safe_mode_resolver( static fn ( int $user_id ): ?string => 'https://example.test/x' );

		$html = $this->html();

		self::assertSame( 1, preg_match( '/<div class="sw-ui-page-header__aside">(.*?)<\/form><\/div><\/header>/s', $html, $aside ), 'Where it always was: the header.' );
		self::assertStringContainsString( 'class="sw-rescue-header-action"', $aside[1] );
		$button = strpos( $aside[1], '<div class="sw-ui-actions" data-sw-actions><button type="submit" class="sw-ui-btn" aria-describedby="sw-rescue-safe-help">Open in safe mode</button></div>' );
		$help   = strpos( $aside[1], '<p class="sw-rescue-note" id="sw-rescue-safe-help">Loads WordPress with only Stonewright and a default theme. The link works once.</p>' );
		self::assertIsInt( $button );
		self::assertIsInt( $help );
		self::assertLessThan( $help, $button, 'The help is under the row and described by the button, so a longer help moves nothing.' );
		self::assertStringContainsString( 'name="return_to" value="rescue"', $aside[1] );
		self::assertStringNotContainsString( 'sw-rescue-panel', $html );
		self::assertStringNotContainsString( 'View changes', $html, 'The tab bar is the way to History.' );
	}

	// -- The history and the fallback -------------------------------------------------------------------

	/** A ledger row for a change, with a before image, so that it is one the history can undo. */
	private function undoable_row( string $change_id, string $family = 'elementor' ): void {
		$db = new LedgerWpdb();
		$db->unique[ $db->prefix . 'stonewright_changes' ] = [ 'change_id' ];
		$GLOBALS['wpdb'] = $db;
		ChangeLedger::reset_schema_health_cache_for_tests();
		$row = ChangeLedger::record( [ 'change_id' => $change_id, 'ability' => 'stonewright/elementor-v3-batch-mutate', 'family' => $family, 'resource_type' => 'post', 'resource_id' => '31', 'before' => [ 'post_content' => 'x' ] ] );
		self::assertIsArray( $row, is_wp_error( $row ) ? $row->get_error_message() : '' );
	}

	public function test_a_recent_change_the_history_can_undo_is_on_the_history_tab_and_not_listed_twice(): void {
		$owned = $this->incident( 'verified', 31 );
		$this->undoable_row( (string) $owned['id'] );
		RollbackFamilies::register( new FakeFamilyHandler( 'elementor' ) );
		try {
			$html = $this->html();
		} finally {
			RollbackFamilies::reset_for_tests();
		}

		self::assertStringNotContainsString( '<tr data-sw-rescue-row="' . $owned['id'] . '">', $html );
		self::assertStringContainsString( '1 recent change is on the History tab, with its diff and Undo.', $html );
		self::assertMatchesRegularExpression( '#<a href="[^"]*page=stonewright-changes">Open History</a>#', $html );
		self::assertStringContainsString( 'Every other recent change is in History, or none has been journaled yet.', $html );
	}

	public function test_a_recent_change_the_history_cannot_undo_stays_here_with_its_rollback(): void {
		$no_row     = $this->incident( 'verified', 31 );
		$no_handler = $this->incident( 'verified', 32 );
		$this->undoable_row( (string) $no_handler['id'], 'family_without_a_handler' );

		$html = $this->html();

		self::assertSame( 1, substr_count( $html, '<tr data-sw-rescue-row="' . $no_row['id'] . '">' ), 'No row in the history: the journal is the only record.' );
		self::assertSame( 1, substr_count( $html, '<tr data-sw-rescue-row="' . $no_handler['id'] . '">' ), 'A row nothing can restore: the rescue recipe is the only way back.' );
		self::assertSame( 2, substr_count( $html, 'data-sw-rescue-dialog' ), 'Every recoverable action is kept.' );
		self::assertStringNotContainsString( 'on the History tab', $html );
	}

	public function test_when_the_ledger_cannot_be_read_every_recent_change_stays_here(): void {
		$one = $this->incident( 'verified', 31 );
		$two = $this->incident( 'verified', 32 );
		$GLOBALS['wpdb'] = new \stdClass();

		$html = $this->html();

		self::assertSame( 1, substr_count( $html, '<tr data-sw-rescue-row="' . $one['id'] . '">' ) );
		self::assertSame( 1, substr_count( $html, '<tr data-sw-rescue-row="' . $two['id'] . '">' ) );
		self::assertSame( 2, substr_count( $html, 'data-sw-rescue-dialog' ) );
	}

	public function test_a_ledger_that_throws_on_every_question_leaves_every_row_and_every_action(): void {
		$one   = $this->incident( 'verified', 31 );
		$two   = $this->incident( 'verified', 32 );
		$three = $this->incident( 'verified', 33 );
		$GLOBALS['wpdb'] = new class() extends LedgerWpdb {
			public function get_row( $query = null, $output = OBJECT, $y = 0 ) {
				throw new \RuntimeException( 'ledger down' );
			}
		};

		$html = $this->html();

		foreach ( [ $one, $two, $three ] as $entry ) {
			self::assertSame( 1, substr_count( $html, '<tr data-sw-rescue-row="' . $entry['id'] . '">' ) );
		}
		self::assertSame( 3, substr_count( $html, 'data-sw-rescue-dialog' ) );
	}

	private static function remove_tree( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) ?: [] as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			is_dir( $path ) ? self::remove_tree( $path ) : @unlink( $path );
		}
		@rmdir( $dir );
	}
}
