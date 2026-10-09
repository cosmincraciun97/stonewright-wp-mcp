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
use Stonewright\WpMcp\Security\ConfirmationToken;
use Stonewright\WpMcp\Security\HealthProbe;
use Stonewright\WpMcp\Security\ProbeToken;

/**
 * Stonewright > Rescue: the page that still works when the site does not.
 *
 * @covers \Stonewright\WpMcp\Admin\RescuePage
 */
final class RescuePageTest extends TestCase {

	private string $uploads;

	/** @var callable():string */
	private $site_state;

	protected function setUp(): void {
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
		RescuePage::set_safe_mode_resolver( false );
		$this->site( static fn (): string => 'healthy' );
	}

	protected function tearDown(): void {
		HealthProbe::set_transport( null );
		RescuePage::set_safe_mode_resolver( null );
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
		self::assertSame( ConfigurationPage::SLUG, $page['parent'] );
		self::assertSame( 'manage_options', $page['capability'] );
		self::assertSame( 'Rescue', $page['menu_title'] );
		self::assertSame( [ RescuePage::class, 'render' ], $page['callback'] );
		foreach ( [ 'admin_post_stonewright_rescue_rollback', 'admin_post_stonewright_rescue_recheck', 'admin_post_stonewright_rescue_safe_mode', 'admin_enqueue_scripts' ] as $hook ) {
			self::assertArrayHasKey( $hook, $GLOBALS['stonewright_test_actions'], $hook . ' must be registered.' );
		}
	}

	public function test_rescue_registers_itself_in_the_activity_hub_through_the_menu_registry(): void {
		MenuRegistry::reset_for_tests();
		RescuePage::register();
		self::assertNull( MenuRegistry::entry( 'stonewright-rescue' ), 'The entry is registered on init, where labels can be translated.' );
		do_action( 'init' );

		self::assertSame( 'Rescue', AdminShell::pages()['stonewright-rescue'] );
		self::assertSame( 'activity', MenuRegistry::hub_for( 'stonewright-rescue' ) );
		self::assertSame( [ 'stonewright-audit-log', 'stonewright-rescue' ], array_column( MenuRegistry::hub_entries( 'activity' ), 'slug' ) );
		$entry = MenuRegistry::entry( 'stonewright-rescue' );
		self::assertSame( 'manage_options', $entry['capability'] ?? '' );
		self::assertTrue( $entry['beta'] ?? false, 'Rescue carries the EXP marker in the band and the sidebar.' );
		self::assertStringContainsString( 'Roll back a change that stopped the site from loading', $entry['lede'] ?? '' );
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
		self::assertMatchesRegularExpression( '/<a class="sw-ui-band__link sw-ui-band__link--exp" href="[^"]*page=stonewright-rescue" aria-current="page" data-sw-ui-tip="This feature is experimental\.">Rescue<span class="sw-ui-band__exp" aria-hidden="true">EXP<\/span>/', $html, 'Rescue is the current link of the band, in the Activity group, with the EXP marker.' );
		self::assertStringContainsString( '<span class="sw-ui-band__label" aria-hidden="true">Activity</span>', $html );
		self::assertStringNotContainsString( 'sw-ui-hubnav', $html, 'Rescue has no tabs of its own.' );
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
		foreach ( [ 'Change set', 'When', 'What changed', 'Status', 'Actions' ] as $heading ) {
			self::assertMatchesRegularExpression( '/<th scope="col">' . preg_quote( $heading, '/' ) . '<\/th>/', $table[1] );
			self::assertStringContainsString( 'data-label="' . $heading . '"', $table[1], 'Each cell repeats its heading so a row reads alone at 400px.' );
		}
		self::assertStringContainsString( '<tr data-sw-rescue-row="' . $entry['id'] . '">', $table[1] );
		self::assertMatchesRegularExpression( '/<th scope="row" data-label="Change set">/', $table[1], 'The id is the row header.' );
	}

	public function test_the_change_set_is_named_by_its_short_id_and_can_be_copied_whole(): void {
		$entry = $this->incident();
		$short = self::short( $entry['id'] );

		$html = $this->html();

		self::assertStringContainsString( '<code class="sw-rescue-id">' . $short . '</code>', $html );
		self::assertMatchesRegularExpression(
			'/<button type="button"[^>]*\shidden[^>]*data-sw-rescue-copy-text="' . preg_quote( $entry['id'], '/' ) . '"[^>]*aria-label="Copy change set id ' . $short . '"[^>]*>Copy<\/button>/',
			$html,
			'A script-only control that copies the full id; hidden until the script runs.'
		);
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
		self::assertStringContainsString( 'blocks requests from the site to itself', $html, 'Why a change can stay unverified.' );
	}

	public function test_recent_changes_are_listed_with_their_states_and_without_actions(): void {
		$entry = $this->incident( 'verified' );

		$html = $this->html();

		self::assertStringContainsString( 'Recent changes', $html );
		self::assertSame( 1, preg_match( '/<table class="sw-rescue-table"[^>]*data-sw-rescue-table="recent"[^>]*>(.*?)<\/table>/s', $html, $table ) );
		self::assertStringContainsString( self::short( $entry['id'] ), $table[1] );
		self::assertStringContainsString( 'Verified', $table[1] );
		self::assertStringNotContainsString( '<form', $table[1], 'The history only reports.' );
		self::assertStringNotContainsString( 'name="action"', $html, 'Nothing to act on.' );
		self::assertStringNotContainsString( '<dialog', $html );
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

		self::assertMatchesRegularExpression( '/<button[^>]*aria-label="Roll back change set ' . $short . '"[^>]*>Roll back<\/button>/', $html );
		self::assertMatchesRegularExpression( '/<button[^>]*aria-label="Check change set ' . $short . ' again"[^>]*>Check again<\/button>/', $html );
	}

	public function test_a_roll_back_form_carries_a_nonce_and_the_incident_and_needs_no_script(): void {
		$entry = $this->incident( 'incident' );
		$hash  = md5( $entry['id'] );

		$html = $this->html();

		self::assertStringContainsString( 'action="https://example.test/wp-admin/admin-post.php"', $html );
		self::assertStringContainsString( 'name="action" value="stonewright_rescue_rollback"', $html );
		self::assertStringContainsString( 'name="incident_id" value="' . $entry['id'] . '"', $html );
		self::assertStringContainsString( 'name="_stonewright_nonce"', $html );
		// The row button submits the very form that the dialog shows, so it works with no script.
		self::assertMatchesRegularExpression( '/<button type="submit" form="sw-rescue-form-' . $hash . '"[^>]*data-sw-rescue-open="sw-rescue-dlg-' . $hash . '"/', $html );
		self::assertStringContainsString( '<form method="post" action="https://example.test/wp-admin/admin-post.php" id="sw-rescue-form-' . $hash . '"', $html );
	}

	public function test_a_failed_rollback_offers_a_retry_named_as_one(): void {
		$entry = $this->incident( 'rollback_failed' );
		$short = self::short( $entry['id'] );

		$html = $this->html();

		self::assertMatchesRegularExpression( '/<button[^>]*aria-label="Roll back change set ' . $short . ' again"[^>]*>Try again<\/button>/', $html );
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
		self::assertMatchesRegularExpression( '/<dt>What changed<\/dt>\s*<dd>Post 31<\/dd>/', $dialog[1] );
		self::assertMatchesRegularExpression( '/<dt>Changed by<\/dt>\s*<dd><code>stonewright\/elementor-v3-batch-mutate<\/code><\/dd>/', $dialog[1] );
		self::assertMatchesRegularExpression( '/<dt>Restores<\/dt>\s*<dd>Restore post 31/', $dialog[1] );
		self::assertStringContainsString( 'anything saved to it since is overwritten', $dialog[1], 'The consequence, in one sentence.' );
		// Cancel comes first, holds the initial focus, and the destructive button is the last control.
		$cancel = strpos( $dialog[1], 'data-sw-rescue-cancel' );
		$submit = strpos( $dialog[1], 'data-sw-rescue-submit' );
		self::assertIsInt( $cancel );
		self::assertIsInt( $submit );
		self::assertLessThan( $submit, $cancel );
		self::assertMatchesRegularExpression( '/<button type="button"[^>]*\sautofocus[^>]*data-sw-rescue-cancel[^>]*>Cancel<\/button>/', $dialog[1] );
		self::assertMatchesRegularExpression( '/<button type="submit"[^>]*data-sw-rescue-submit[^>]*>Roll back<\/button>/', $dialog[1] );
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
		self::assertMatchesRegularExpression( '/<input type="text" id="sw-rescue-phrase-' . $hash . '" name="confirm_phrase"[^>]*autocomplete="off"[^>]*data-sw-rescue-phrase="ROLL BACK"/', $html );
		self::assertSame( 1, preg_match( '/<dialog[^>]*>(.*?)<\/dialog>/s', $html, $dialog ) );
		self::assertSame( 1, preg_match( '/name="confirmation_token" value="(swc_[^"]+)"/', $dialog[1], $match ) );
		self::assertTrue( ConfirmationToken::verify( html_entity_decode( $match[1] ), 'stonewright/rescue-rollback', [ 'incident_id' => $entry['id'] ] ), 'The token is bound to this ability and this change set.' );
		// The re-check form carries its own token, bound to that action.
		self::assertSame( 1, preg_match( '/name="action" value="stonewright_rescue_recheck" \/><input type="hidden" name="incident_id"[^>]*\/><input type="hidden" name="_stonewright_nonce"[^>]*\/><input type="hidden" name="confirmation_token" value="(swc_[^"]+)"/', $html, $recheck ) );
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
		self::assertMatchesRegularExpression( '/<button type="button"[^>]*\shidden[^>]*data-sw-rescue-copy-text="cs-0123456789abcdef01234567"[^>]*>Copy<\/button>/', $match[1] );
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
		$_POST = [ '_stonewright_nonce' => 'x', 'incident_id' => $entry['id'] ];

		$result = RescuePage::process_rollback_request();

		self::assertSame( 'confirmation_required', $result['code'] );
		self::assertSame( 'broken body', $GLOBALS['stonewright_test_posts'][31]->post_content );
	}

	public function test_production_safe_mode_accepts_the_token_the_page_issued(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		$entry = $this->incident();
		$_POST = [
			'_stonewright_nonce' => 'x',
			'incident_id'        => $entry['id'],
			'confirmation_token' => ConfirmationToken::issue( 'stonewright/rescue-rollback', [ 'incident_id' => $entry['id'] ] ),
		];

		$result = RescuePage::process_rollback_request();

		self::assertSame( 'rolled_back', $result['code'] );
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

		self::assertSame( 'rechecked_open', RescuePage::process_recheck_request()['code'] );
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

		self::assertLessThan( 4096, strlen( $raw ), 'A page stylesheet stays small.' );
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

	public function test_the_stylesheet_stacks_the_table_and_sizes_touch_targets_for_narrow_screens(): void {
		$css = self::css();

		self::assertStringContainsString( '@media (max-width: 782px)', $css );
		self::assertStringContainsString( 'content: attr(data-label)', $css, 'Cells repeat their heading when stacked.' );
		self::assertStringContainsString( 'min-height: 44px', $css, 'Touch targets on narrow screens.' );
		self::assertStringContainsString( 'overflow-wrap: anywhere', $css, 'Long ids wrap at 400px.' );
		self::assertStringContainsString( '::backdrop', $css );
		self::assertMatchesRegularExpression( '/\.sw-rescue-page \[hidden\][^{]*\{\s*display:\s*none/', $css, 'The hidden attribute must beat the button display rule.' );
	}

	public function test_the_script_only_enhances_and_keeps_the_page_working_without_it(): void {
		$js = self::js();

		foreach ( [ 'showModal', 'data-sw-rescue-open', 'data-sw-rescue-cancel', 'data-sw-rescue-phrase', 'data-sw-rescue-copy', 'data-sw-rescue-copy-text', 'data-sw-rescue-form', 'navigator.clipboard', 'execCommand', "'close'" ] as $needle ) {
			self::assertStringContainsString( $needle, $js, $needle );
		}
		self::assertStringContainsString( "typeof dialog.showModal !== 'function'", $js, 'Without dialog support the button posts the form directly.' );
		self::assertStringContainsString( 'pageshow', $js, 'Back and forward must not leave a form stuck.' );
		foreach ( [ 'eval(', 'innerHTML', 'setInterval', 'setTimeout( poll', 'window.confirm', 'window.alert', 'XMLHttpRequest', 'fetch(' ] as $forbidden ) {
			self::assertStringNotContainsString( $forbidden, $js, $forbidden );
		}
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
