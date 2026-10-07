<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\SandboxPage;
use Stonewright\WpMcp\Sandbox\SandboxFiles;

/**
 * The Custom code hub's own tabs (Drafts, Active, Crash recovery), built from the shared UI layer. The file
 * storage, the name rule and every handler gate stay as they were: these tests only look at what the page shows
 * and at the forms it posts.
 *
 * @covers \Stonewright\WpMcp\Admin\SandboxPage
 */
final class SandboxPageRenderTest extends TestCase {

	private string $sandbox_dir;
	private string $mu_dir;

	protected function setUp(): void {
		$this->sandbox_dir = WP_CONTENT_DIR . '/stonewright-sandbox';
		$this->mu_dir      = WP_CONTENT_DIR . '/mu-plugins';
		foreach ( [ $this->sandbox_dir, $this->mu_dir ] as $dir ) {
			if ( ! is_dir( $dir ) ) {
				mkdir( $dir, 0755, true );
			}
		}

		$GLOBALS['stonewright_test_user_caps']       = [ 'manage_options' => true, 'edit_plugins' => true ];
		$GLOBALS['stonewright_test_current_user_id'] = 7;
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_transients']      = [];
		$_GET                                        = [];
		$_POST                                       = [];
		$this->empty_test_dirs();
	}

	protected function tearDown(): void {
		$this->empty_test_dirs();
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_transients']      = [];
		$_GET                                        = [];
		$_POST                                       = [];
	}

	private function empty_test_dirs(): void {
		foreach ( [ $this->sandbox_dir, $this->mu_dir ] as $dir ) {
			foreach ( glob( $dir . '/*' ) ?: [] as $file ) {
				if ( is_file( $file ) ) {
					unlink( $file );
				}
			}
		}
	}

	/** @param array<string, string> $get */
	private static function html( array $get = [] ): string {
		$_GET = array_merge( [ 'page' => 'stonewright-sandbox' ], $get );
		ob_start();
		SandboxPage::render();

		return (string) ob_get_clean();
	}

	/** @param array<string, string> $get @return array{0: \DOMDocument, 1: \DOMXPath} */
	private static function dom( array $get = [] ): array {
		$previous = libxml_use_internal_errors( true );
		$dom      = new \DOMDocument();
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . self::html( $get ) );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return [ $dom, new \DOMXPath( $dom ) ];
	}

	private static function assert_no_legacy_markup( string $html ): void {
		foreach ( [ 'stonewright-card', 'stonewright-empty-state', 'stonewright-toolbar', 'stonewright-panel', 'class="button', 'class="notice', 'notice notice-', 'sw-badge', 'sw-callout', 'sw-btn', 'is-dismissible', 'form-table', 'wp-list-table', 'widefat', 'data-stonewright-toggle-target', 'data-confirm' ] as $legacy ) {
			self::assertStringNotContainsString( $legacy, $html, $legacy );
		}
		self::assertDoesNotMatchRegularExpression( '/\sstyle=/', $html, 'No inline style attribute.' );
	}

	private static function assert_unique_ids( \DOMXPath $xpath ): void {
		$ids = [];
		foreach ( $xpath->query( '//*[@id]' ) ?: [] as $node ) {
			\assert( $node instanceof \DOMElement );
			$ids[] = $node->getAttribute( 'id' );
		}
		self::assertSame( [], array_keys( array_filter( array_count_values( $ids ), static fn ( int $count ): bool => $count > 1 ) ), 'Duplicate ids.' );
	}

	// ---------------------------------------------------------------------------------------------
	// Drafts
	// ---------------------------------------------------------------------------------------------

	public function test_no_files_is_a_first_run_state_that_says_what_this_is_and_what_to_do(): void {
		[ , $xpath ] = self::dom();
		$html        = self::html();

		self::assertStringContainsString( 'No sandbox files yet', $html );
		self::assertStringContainsString( 'Sandbox files are PHP drafts that agents write and you review. Nothing runs until you activate a file.', $html );
		self::assertSame( 0, $xpath->query( '//table' )->length );
		self::assertSame( 1, $xpath->query( '//a[contains(@class,"sw-ui-btn--primary")][contains(@href,"new=1")]' )->length, 'One primary action: create the first file.' );
		self::assertSame( 1, $xpath->query( '//*[contains(@class,"sw-ui-btn--primary")]' )->length );
		self::assertStringContainsString( 'sw-ui-page', $html );
		self::assertStringContainsString( 'sw-ui-empty--first-run', $html );
		self::assert_no_legacy_markup( $html );
		self::assert_unique_ids( $xpath );
	}

	public function test_the_page_explains_where_drafts_live_in_one_callout(): void {
		self::assertStringContainsString( 'wp-content/stonewright-sandbox/', self::html() );
		self::assertSame( 1, substr_count( self::html(), 'sw-ui-callout--info' ) );
	}

	public function test_files_are_listed_in_a_table_with_a_status_word_a_size_and_a_time(): void {
		SandboxFiles::write( 'first-draft.php', "<?php\n// first\n" );
		SandboxFiles::write( 'live-one.php', "<?php\n// live\n" );
		SandboxFiles::activate( 'live-one.php' );
		[ , $xpath ] = self::dom();
		$html        = self::html();

		self::assertSame( 1, $xpath->query( '//table[contains(@class,"sw-ui-table--stack")]' )->length );
		self::assertSame( 1, $xpath->query( '//table/caption' )->length );
		self::assertSame( 2, $xpath->query( '//tbody/tr' )->length );
		self::assertStringContainsString( 'first-draft.php', $html );
		self::assertMatchesRegularExpression( '/sw-ui-badge--warn[^>]*>[^<]*Draft</', $html );
		self::assertMatchesRegularExpression( '/sw-ui-badge--ok[^>]*>[^<]*Active</', $html );
		self::assertGreaterThanOrEqual( 2, $xpath->query( '//tbody//time[@datetime]' )->length, 'A time element with a machine readable value.' );
		self::assert_no_legacy_markup( $html );
		self::assert_unique_ids( $xpath );
	}

	public function test_a_list_has_one_primary_button_and_it_creates_a_file(): void {
		SandboxFiles::write( 'first-draft.php', "<?php\n// first\n" );
		[ , $xpath ] = self::dom();

		self::assertSame( 1, $xpath->query( '//*[contains(@class,"sw-ui-btn--primary")]' )->length );
		self::assertSame( 1, $xpath->query( '//a[contains(@class,"sw-ui-btn--primary")][contains(@href,"new=1")]' )->length );
	}

	public function test_every_row_action_names_the_file_it_acts_on(): void {
		SandboxFiles::write( 'one-file.php', "<?php\n// one\n" );
		SandboxFiles::write( 'two-file.php', "<?php\n// two\n" );
		[ , $xpath ] = self::dom();

		foreach ( [ 'one-file.php', 'two-file.php' ] as $file ) {
			foreach ( [ 'Edit', 'Activate', 'Delete' ] as $verb ) {
				$found = $xpath->query( '//tr[contains(.,"' . $file . '")]//*[(self::a or self::button) and contains(normalize-space(.),"' . $verb . ' ' . $file . '")]' );
				self::assertNotFalse( $found );
				self::assertGreaterThanOrEqual( 1, $found->length, $verb . ' ' . $file );
			}
		}
	}

	public function test_a_draft_offers_edit_activate_and_delete_a_live_file_deactivate_and_disable(): void {
		SandboxFiles::write( 'only-draft.php', "<?php\n// d\n" );
		SandboxFiles::write( 'running.php', "<?php\n// r\n" );
		SandboxFiles::activate( 'running.php' );
		$html = self::html();

		self::assertSame( 1, preg_match_all( '/name="stonewright_file_action" value="activate"/', $html ) );
		self::assertSame( 1, preg_match_all( '/name="stonewright_file_action" value="deactivate"/', $html ) );
		self::assertSame( 1, preg_match_all( '/name="stonewright_file_action" value="disable"/', $html ) );
		self::assertSame( 2, preg_match_all( '/name="stonewright_file_action" value="delete"/', $html ) );
		self::assertSame( 0, preg_match_all( '/name="stonewright_file_action" value="enable"/', $html ) );
	}

	public function test_every_form_still_posts_the_action_the_file_and_the_nonce_the_handler_reads(): void {
		SandboxFiles::write( 'only-draft.php', "<?php\n// d\n" );
		[ , $xpath ] = self::dom();

		$forms = $xpath->query( '//form[.//input[@name="stonewright_file_action"]]' );
		self::assertNotFalse( $forms );
		self::assertGreaterThanOrEqual( 2, $forms->length );
		foreach ( $forms as $form ) {
			\assert( $form instanceof \DOMElement );
			self::assertSame( 'post', $form->getAttribute( 'method' ) );
			self::assertStringContainsString( 'admin-post.php', $form->getAttribute( 'action' ) );
			self::assertSame( 1, $xpath->query( './/input[@name="action"][@value="stonewright_sandbox_action"]', $form )->length );
			self::assertSame( 1, $xpath->query( './/input[@name="stonewright_filename"][@value="only-draft.php"]', $form )->length );
			self::assertSame( 1, $xpath->query( './/input[@name="_stonewright_nonce"]', $form )->length );
			self::assertSame( 0, $xpath->query( './/*[@id="_stonewright_nonce"]', $form )->length, 'No id on a nonce field: a list repeats it.' );
		}
	}

	public function test_deleting_asks_first_in_a_dialog_whose_confirm_button_is_not_primary(): void {
		SandboxFiles::write( 'only-draft.php', "<?php\n// d\n" );
		[ , $xpath ] = self::dom();

		$dialog = $xpath->query( '//dialog[contains(@class,"sw-ui-dialog")]' );
		self::assertNotFalse( $dialog );
		self::assertSame( 1, $dialog->length );
		$box = $dialog->item( 0 );
		\assert( $box instanceof \DOMElement );
		self::assertStringContainsString( 'Delete only-draft.php?', $box->textContent );
		self::assertStringContainsString( 'cannot be undone', $box->textContent );
		self::assertSame( 1, $xpath->query( './/button[@autofocus][@data-sw-ui-dialog-close]', $box )->length, 'Cancel takes focus.' );
		$confirm = $xpath->query( './/button[contains(@class,"sw-ui-btn--danger-solid")]', $box );
		self::assertSame( 1, $confirm->length );
		$button = $confirm->item( 0 );
		\assert( $button instanceof \DOMElement );
		self::assertSame( 'submit', $button->getAttribute( 'type' ) );
		self::assertNotSame( '', $button->getAttribute( 'form' ), 'It submits the row form from outside it.' );
		self::assertSame( 1, $xpath->query( '//form[@id="' . $button->getAttribute( 'form' ) . '"]//input[@name="stonewright_file_action"][@value="delete"]' )->length );
		self::assertSame( 0, $xpath->query( './/*[contains(@class,"sw-ui-btn--primary")]', $box )->length );

		// The row's own Delete button opens the dialog, and still submits the form when script is off.
		$opener = $xpath->query( '//button[@data-sw-ui-dialog-open="#' . $box->getAttribute( 'id' ) . '"]' );
		self::assertSame( 1, $opener->length );
		$opener_button = $opener->item( 0 );
		\assert( $opener_button instanceof \DOMElement );
		self::assertSame( 'submit', $opener_button->getAttribute( 'type' ) );
		self::assertStringContainsString( 'sw-ui-btn--danger', $opener_button->getAttribute( 'class' ) );
	}

	public function test_the_new_file_form_keeps_the_name_rule_the_server_applies(): void {
		[ , $xpath ] = self::dom( [ 'new' => '1' ] );

		$input = $xpath->query( '//input[@id="stonewright_new_filename"]' )->item( 0 );
		self::assertInstanceOf( \DOMElement::class, $input );
		self::assertSame( SandboxFiles::name_pattern(), $input->getAttribute( 'pattern' ) );
		self::assertTrue( $input->hasAttribute( 'required' ) );
		self::assertSame( 'stonewright_filename', $input->getAttribute( 'name' ) );
		self::assertSame( 1, $xpath->query( '//label[@for="stonewright_new_filename"]' )->length );
		self::assertNotSame( '', $input->getAttribute( 'aria-describedby' ), 'The rule is in the help text.' );
		self::assertSame( 1, $xpath->query( '//*[@id="' . $input->getAttribute( 'aria-describedby' ) . '"]' )->length );
		self::assertSame( 1, $xpath->query( '//label[@for="stonewright_new_contents"]' )->length );
		self::assertSame( 1, $xpath->query( '//textarea[@id="stonewright_new_contents"][@name="stonewright_contents"]' )->length );
		self::assertSame( 1, $xpath->query( '//form//input[@name="action"][@value="stonewright_sandbox_create"]' )->length );
		self::assertSame( 1, $xpath->query( '//form//input[@name="_stonewright_nonce"]' )->length );
	}

	public function test_the_new_file_view_has_one_primary_button_and_a_way_back(): void {
		[ , $xpath ] = self::dom( [ 'new' => '1' ] );

		self::assertSame( 1, $xpath->query( '//*[contains(@class,"sw-ui-btn--primary")]' )->length );
		self::assertSame( 1, $xpath->query( '//button[@type="submit"][contains(@class,"sw-ui-btn--primary")][contains(normalize-space(.),"Create file")]' )->length );
		self::assertGreaterThanOrEqual( 1, $xpath->query( '//a[normalize-space(.)="Cancel"][contains(@href,"page=stonewright-sandbox")]' )->length );
		self::assert_no_legacy_markup( self::html( [ 'new' => '1' ] ) );
	}

	public function test_editing_a_file_shows_its_facts_and_a_form_that_saves_it(): void {
		SandboxFiles::write( 'edit-me.php', "<?php\n// <b>bold</b> & more\n" );
		[ , $xpath ] = self::dom( [ 'edit' => 'edit-me.php' ] );
		$html        = self::html( [ 'edit' => 'edit-me.php' ] );

		self::assertSame( 1, $xpath->query( '//form//input[@name="action"][@value="stonewright_sandbox_save"]' )->length );
		self::assertSame( 1, $xpath->query( '//form//input[@name="stonewright_filename"][@value="edit-me.php"]' )->length );
		self::assertSame( 1, $xpath->query( '//form//input[@name="_stonewright_nonce"]' )->length );
		$area = $xpath->query( '//textarea[@id="stonewright_edit_contents"]' )->item( 0 );
		self::assertInstanceOf( \DOMElement::class, $area );
		self::assertStringContainsString( '<b>bold</b> & more', $area->textContent, 'The file is shown as text, never as markup.' );
		self::assertStringContainsString( 'sw-ui-textarea--code', $area->getAttribute( 'class' ) );
		self::assertSame( 1, $xpath->query( '//label[@for="stonewright_edit_contents"]' )->length );
		self::assertSame( 1, $xpath->query( '//button[@type="submit"][contains(@class,"sw-ui-btn--primary")]' )->length );
		self::assertSame( 1, $xpath->query( '//*[contains(@class,"sw-ui-btn--primary")]' )->length );
		self::assertStringContainsString( 'sw-ui-kv', $html );
		self::assert_no_legacy_markup( $html );
	}

	public function test_a_file_that_cannot_be_read_is_an_error_notice_with_a_way_back(): void {
		$html = self::html( [ 'edit' => 'missing-file.php' ] );

		self::assertStringContainsString( 'sw-ui-notice--danger', $html );
		self::assertStringContainsString( 'role="alert"', $html );
		self::assertStringNotContainsString( '<textarea id="stonewright_edit_contents"', $html );
		self::assertStringContainsString( 'page=stonewright-sandbox', $html );
	}

	public function test_a_name_the_server_would_refuse_never_opens_the_editor(): void {
		$html = self::html( [ 'edit' => '../wp-config.php' ] );

		self::assertStringNotContainsString( 'stonewright_edit_contents', $html );
	}

	public function test_a_finished_action_is_a_notice_that_stays(): void {
		$html = self::html( [ 'updated' => '1' ] );

		self::assertStringContainsString( 'sw-ui-notice--ok', $html );
		self::assertStringContainsString( 'Action completed successfully.', $html );
		self::assertStringNotContainsString( 'is-dismissible', $html );
	}

	public function test_a_refusal_is_announced_with_its_cause(): void {
		$GLOBALS['stonewright_test_transients']['stonewright_sandbox_error_7'] = 'Static guard blocked save: eval() is not allowed.';
		$html                                                                  = self::html( [ 'error' => '1' ] );

		self::assertStringContainsString( 'sw-ui-notice--danger', $html );
		self::assertStringContainsString( 'role="alert"', $html );
		self::assertStringContainsString( 'Static guard blocked save: eval() is not allowed.', $html );
		self::assertArrayNotHasKey( 'stonewright_sandbox_error_7', $GLOBALS['stonewright_test_transients'], 'The message is shown once.' );
	}

	public function test_a_long_file_name_and_error_wrap_inside_the_page(): void {
		$name = str_repeat( 'a-very-long-name-', 8 ) . 'x.php';
		SandboxFiles::write( $name, "<?php\n// long\n" );
		$html = self::html();

		self::assertStringContainsString( $name, $html );
		self::assertStringContainsString( 'sw-ui-table__primary', $html, 'The primary cell wraps long values.' );
	}

	// ---------------------------------------------------------------------------------------------
	// Active and Crash recovery
	// ---------------------------------------------------------------------------------------------

	public function test_active_with_nothing_running_says_what_active_means_and_where_to_start(): void {
		$html = self::html( [ 'tab' => 'mu-plugins' ] );

		self::assertStringContainsString( 'No active files', $html );
		self::assertStringContainsString( 'runs on every request', $html );
		self::assertStringContainsString( 'tab=drafts', $html );
		self::assertStringContainsString( 'sw-ui-empty', $html );
		self::assertStringNotContainsString( '<table', $html );
		self::assert_no_legacy_markup( $html );
	}

	public function test_active_lists_the_running_files_with_their_size(): void {
		SandboxFiles::write( 'active-visible.php', "<?php\n// active visible\n" );
		SandboxFiles::activate( 'active-visible.php' );
		[ , $xpath ] = self::dom( [ 'tab' => 'mu-plugins' ] );

		self::assertSame( 1, $xpath->query( '//table[contains(@class,"sw-ui-table--stack")]' )->length );
		self::assertSame( 1, $xpath->query( '//tbody/tr[contains(.,"active-visible.php")]' )->length );
		self::assertStringNotContainsString( 'No active files', self::html( [ 'tab' => 'mu-plugins' ] ) );
	}

	public function test_crash_recovery_with_no_crashes_says_what_the_page_will_show(): void {
		$html = self::html( [ 'tab' => 'crash-recovery' ] );

		self::assertStringContainsString( 'No crashes recorded', $html );
		self::assertStringContainsString( 'switches it off', $html );
		self::assertStringNotContainsString( '<table', $html );
		self::assert_no_legacy_markup( $html );
	}

	public function test_crash_recovery_lists_the_file_the_time_and_the_whole_error(): void {
		$GLOBALS['stonewright_test_options']['stonewright_crash_log'] = [
			[ 'file' => 'broken-one.php', 'time' => 1790000000, 'error' => 'Uncaught Error: Call to undefined function example_missing() in /example/path/file.php:12' ],
		];
		[ , $xpath ] = self::dom( [ 'tab' => 'crash-recovery' ] );
		$html        = self::html( [ 'tab' => 'crash-recovery' ] );

		self::assertSame( 1, $xpath->query( '//tbody/tr' )->length );
		self::assertStringContainsString( 'broken-one.php', $html );
		self::assertStringContainsString( 'Call to undefined function example_missing()', $html );
		self::assertSame( 1, $xpath->query( '//tbody//time[@datetime]' )->length );
		self::assert_no_legacy_markup( $html );
	}

	public function test_the_old_audit_tab_points_at_the_audit_log_with_one_primary_link(): void {
		[ , $xpath ] = self::dom( [ 'tab' => 'audit' ] );
		$html        = self::html( [ 'tab' => 'audit' ] );

		self::assertStringContainsString( 'Audit Log moved', $html );
		// A heading, not bold text: the browser tests and screen readers look for it by role.
		self::assertSame( 1, $xpath->query( '//*[self::h2 or self::h3][normalize-space(.)="Audit Log moved"]' )->length );
		self::assertSame( 1, $xpath->query( '//a[contains(@class,"sw-ui-btn--primary")][contains(@href,"page=stonewright-audit-log")]' )->length );
		self::assert_no_legacy_markup( $html );
	}
}
