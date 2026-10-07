<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\Pages\SandboxLibraryPage;
use Stonewright\WpMcp\Admin\SandboxPage;
use Stonewright\WpMcp\Sandbox\SandboxFiles;
use Stonewright\WpMcp\Security\ConfirmationToken;

/**
 * The Library tab of the Custom code hub, built from the shared UI layer: one filter toolbar instead of nested
 * tabs, a table, and the edit, diff and rollback views as cards. Every form keeps the fields, nonce and
 * production-safe confirmation token its handler reads.
 *
 * @covers \Stonewright\WpMcp\Admin\Pages\SandboxLibraryPage
 */
final class SandboxLibraryPageRenderTest extends TestCase {

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

	/** The embedded Library tab inside the hub. @param array<string, string> $get */
	private static function html( array $get = [] ): string {
		$_GET = array_merge( [ 'page' => 'stonewright-sandbox', 'tab' => 'library' ], $get );
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

	private static function assert_clean( string $html, \DOMXPath $xpath ): void {
		foreach ( [ 'nav-tab', 'class="button', 'class="notice', 'notice notice-', 'sw-badge', 'sw-btn', 'is-dismissible', 'wp-list-table', 'widefat', 'stonewright-library-filters', 'sw-actions', 'data-confirm' ] as $legacy ) {
			self::assertStringNotContainsString( $legacy, $html, $legacy );
		}
		self::assertDoesNotMatchRegularExpression( '/\sstyle=/', $html, 'No inline style attribute.' );
		$ids = [];
		foreach ( $xpath->query( '//*[@id]' ) ?: [] as $node ) {
			\assert( $node instanceof \DOMElement );
			$ids[] = $node->getAttribute( 'id' );
		}
		self::assertSame( [], array_keys( array_filter( array_count_values( $ids ), static fn ( int $count ): bool => $count > 1 ) ), 'Duplicate ids.' );
	}

	// ---------------------------------------------------------------------------------------------
	// The toolbar replaces the nested tabs
	// ---------------------------------------------------------------------------------------------

	public function test_the_kind_is_one_control_in_one_toolbar_not_a_second_row_of_tabs(): void {
		[ , $xpath ] = self::dom( [ 'library_tab' => 'widgets' ] );

		self::assertSame( 1, $xpath->query( '//form[@method="get"]//*[contains(@class,"sw-ui-segmented")]' )->length );
		$radios = $xpath->query( '//input[@type="radio"][@name="library_tab"]' );
		self::assertNotFalse( $radios );
		self::assertSame( [ 'snippets', 'widgets', 'plugins' ], array_map( static fn ( \DOMNode $node ): string => $node instanceof \DOMElement ? $node->getAttribute( 'value' ) : '', iterator_to_array( $radios ) ) );
		self::assertSame( 1, $xpath->query( '//input[@type="radio"][@name="library_tab"][@checked]' )->length );
		self::assertSame( 'widgets', $xpath->query( '//input[@type="radio"][@name="library_tab"][@checked]' )->item( 0 )?->getAttribute( 'value' ) );
		self::assertSame( 1, $xpath->query( '//*[@role="radiogroup"][@aria-labelledby or @aria-label]' )->length );
		self::assertSame( 0, $xpath->query( '//nav[contains(@class,"nav-tab-wrapper")]' )->length );
	}

	public function test_the_filter_form_keeps_the_page_and_tab_and_has_labelled_selects_and_a_secondary_submit(): void {
		[ , $xpath ] = self::dom();

		self::assertSame( 1, $xpath->query( '//form[@method="get"]//input[@type="hidden"][@name="page"][@value="stonewright-sandbox"]' )->length );
		self::assertSame( 1, $xpath->query( '//form[@method="get"]//input[@type="hidden"][@name="tab"][@value="library"]' )->length );
		foreach ( [ 'category', 'status' ] as $field ) {
			$select = $xpath->query( '//form[@method="get"]//select[@name="' . $field . '"]' )->item( 0 );
			self::assertInstanceOf( \DOMElement::class, $select, $field );
			self::assertSame( 1, $xpath->query( '//label[@for="' . $select->getAttribute( 'id' ) . '"]' )->length, $field . ' needs a label' );
			self::assertStringContainsString( 'sw-ui-select', $select->getAttribute( 'class' ) );
		}
		$submit = $xpath->query( '//form[@method="get"]//button[@type="submit"]' )->item( 0 );
		self::assertInstanceOf( \DOMElement::class, $submit );
		self::assertStringNotContainsString( 'sw-ui-btn--primary', $submit->getAttribute( 'class' ) );
		self::assertSame( 1, $xpath->query( '//*[contains(@class,"sw-ui-toolbar")]' )->length );
	}

	public function test_the_filters_in_the_address_are_selected_in_the_form(): void {
		[ , $xpath ] = self::dom( [ 'category' => 'widget', 'status' => 'active' ] );

		self::assertSame( 'widget', $xpath->query( '//select[@name="category"]/option[@selected]' )->item( 0 )?->getAttribute( 'value' ) );
		self::assertSame( 'active', $xpath->query( '//select[@name="status"]/option[@selected]' )->item( 0 )?->getAttribute( 'value' ) );
	}

	// ---------------------------------------------------------------------------------------------
	// The table
	// ---------------------------------------------------------------------------------------------

	public function test_files_are_a_table_with_a_status_word_and_named_actions(): void {
		SandboxFiles::write( 'route-test.php', "<?php\n// route test\n" );
		[ , $xpath ] = self::dom();
		$html        = self::html();

		self::assertSame( 1, $xpath->query( '//table[contains(@class,"sw-ui-table--stack")]' )->length );
		self::assertSame( 1, $xpath->query( '//tbody/tr' )->length );
		self::assertMatchesRegularExpression( '/sw-ui-badge--warn[^>]*>[^<]*Draft</', $html );
		foreach ( [ 'View', 'Edit', 'Diff', 'Activate', 'Delete' ] as $verb ) {
			self::assertGreaterThanOrEqual( 1, $xpath->query( '//tr//*[(self::a or self::button) and contains(normalize-space(.),"' . $verb . ' route-test.php")]' )->length, $verb );
		}
		self::assertStringContainsString( 'page=stonewright-sandbox&tab=library&library_tab=snippets&action=edit&file=route-test.php', $html );
		self::assertStringContainsString( 'page=stonewright-sandbox&tab=library&library_tab=snippets&action=diff&file=route-test.php', $html );
		self::assertSame( 0, $xpath->query( '//*[contains(@class,"sw-ui-btn--primary")]' )->length, 'A list has no primary action.' );
		self::assert_clean( $html, $xpath );
	}

	public function test_view_opens_the_raw_file_in_a_new_tab_with_its_own_nonce(): void {
		SandboxFiles::write( 'route-test.php', "<?php\n// route test\n" );
		[ , $xpath ] = self::dom();

		$view = $xpath->query( '//a[contains(@href,"action=stonewright_sandbox_view")]' )->item( 0 );
		self::assertInstanceOf( \DOMElement::class, $view );
		self::assertStringContainsString( 'file=route-test.php', $view->getAttribute( 'href' ) );
		self::assertStringContainsString( '_wpnonce=' . wp_create_nonce( 'stonewright_sandbox_view_route-test.php' ), $view->getAttribute( 'href' ) );
		self::assertSame( '_blank', $view->getAttribute( 'target' ) );
		self::assertStringContainsString( 'noopener', $view->getAttribute( 'rel' ) );
	}

	public function test_the_row_forms_post_what_the_library_handler_reads_and_return_to_the_same_tab(): void {
		SandboxFiles::write( 'route-test.php', "<?php\n// route test\n" );
		[ , $xpath ] = self::dom( [ 'library_tab' => 'snippets' ] );

		foreach ( [ 'activate', 'delete' ] as $action ) {
			$form = $xpath->query( '//form[.//input[@name="stonewright_lib_action"][@value="' . $action . '"]]' )->item( 0 );
			self::assertInstanceOf( \DOMElement::class, $form, $action );
			self::assertSame( 'post', $form->getAttribute( 'method' ) );
			self::assertSame( 1, $xpath->query( './/input[@name="action"][@value="stonewright_sandbox_lib_action"]', $form )->length );
			self::assertSame( 1, $xpath->query( './/input[@name="stonewright_filename"][@value="route-test.php"]', $form )->length );
			self::assertSame( 1, $xpath->query( './/input[@name="stonewright_return_tab"][@value="library"]', $form )->length );
			self::assertSame( 1, $xpath->query( './/input[@name="stonewright_library_tab"][@value="snippets"]', $form )->length );
			self::assertSame( 1, $xpath->query( './/input[@name="_stonewright_lib_nonce"]', $form )->length );
			self::assertSame( 0, $xpath->query( './/*[@id="_stonewright_lib_nonce"]', $form )->length, 'No id on a nonce field.' );
		}
	}

	public function test_delete_asks_first_in_a_dialog_that_cancels_by_default(): void {
		SandboxFiles::write( 'route-test.php', "<?php\n// route test\n" );
		[ , $xpath ] = self::dom();

		$dialog = $xpath->query( '//dialog[contains(@class,"sw-ui-dialog")]' )->item( 0 );
		self::assertInstanceOf( \DOMElement::class, $dialog );
		self::assertStringContainsString( 'Delete route-test.php?', $dialog->textContent );
		self::assertSame( 1, $xpath->query( './/button[@autofocus][@data-sw-ui-dialog-close]', $dialog )->length );
		$confirm = $xpath->query( './/button[contains(@class,"sw-ui-btn--danger-solid")][@type="submit"][@form]', $dialog )->item( 0 );
		self::assertInstanceOf( \DOMElement::class, $confirm );
		self::assertSame( 1, $xpath->query( '//form[@id="' . $confirm->getAttribute( 'form' ) . '"]//input[@name="stonewright_lib_action"][@value="delete"]' )->length );
	}

	public function test_production_safe_embeds_one_exact_token_in_activate_and_in_delete_and_none_in_development(): void {
		SandboxFiles::write( 'route-test.php', "<?php\n// route test\n" );

		[ , $development ] = self::dom();
		self::assertSame( 0, $development->query( '//input[@name="stonewright_confirmation_token"]' )->length );

		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		[ , $xpath ]                                             = self::dom();
		foreach ( [ 'activate' => 'stonewright/sandbox-activate', 'delete' => 'stonewright/sandbox-delete' ] as $action => $ability ) {
			$token = $xpath->query( '//form[.//input[@name="stonewright_lib_action"][@value="' . $action . '"]]//input[@name="stonewright_confirmation_token"]' )->item( 0 );
			self::assertInstanceOf( \DOMElement::class, $token, $action );
			$verified = ConfirmationToken::verify_or_error( $token->getAttribute( 'value' ), $ability, [ 'name' => 'route-test.php' ] );
			self::assertNotInstanceOf( \WP_Error::class, $verified, 'The token is bound to this action and this file.' );
		}
	}

	public function test_a_file_that_is_not_a_draft_has_no_activate_button(): void {
		SandboxFiles::write( 'live-one.php', "<?php\n// live\n" );
		SandboxFiles::activate( 'live-one.php' );
		$html = self::html();

		self::assertStringNotContainsString( 'name="stonewright_lib_action" value="activate"', $html );
		self::assertMatchesRegularExpression( '/sw-ui-badge--ok[^>]*>[^<]*Active</', $html );
	}

	public function test_without_edit_plugins_the_row_offers_view_only(): void {
		SandboxFiles::write( 'route-test.php', "<?php\n// route test\n" );
		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true ];
		$html                                  = self::html();

		self::assertStringContainsString( 'action=stonewright_sandbox_view', $html );
		self::assertStringNotContainsString( 'action=edit', $html );
		self::assertStringNotContainsString( 'stonewright_lib_action', $html );
	}

	// ---------------------------------------------------------------------------------------------
	// States
	// ---------------------------------------------------------------------------------------------

	public function test_an_empty_library_explains_itself_and_does_not_offer_to_clear_filters_it_does_not_have(): void {
		$html = self::html();

		self::assertStringContainsString( 'No library files yet', $html );
		self::assertStringContainsString( 'Sandbox files that agents write appear here', $html );
		self::assertStringNotContainsString( 'Clear filters', $html );
		self::assertStringNotContainsString( '<table', $html );
	}

	public function test_a_filter_that_matches_nothing_says_so_and_offers_a_way_out(): void {
		SandboxFiles::write( 'route-test.php', "<?php\n// route test\n" );
		[ , $xpath ] = self::dom( [ 'status' => 'active' ] );

		self::assertStringContainsString( 'No files match', self::html( [ 'status' => 'active' ] ) );
		self::assertSame( 1, $xpath->query( '//a[normalize-space(.)="Clear filters"][contains(@href,"page=stonewright-sandbox")][not(contains(@href,"status="))]' )->length );
		self::assertSame( 0, $xpath->query( '//table' )->length );
	}

	public function test_the_widgets_view_lists_installed_elementor_widgets_with_one_create_action_each(): void {
		[ , $xpath ] = self::dom( [ 'library_tab' => 'widgets' ] );
		$html        = self::html( [ 'library_tab' => 'widgets' ] );

		// The test double of Elementor registers one widget (no empty state: that one is checked in the browser).
		self::assertStringContainsString( 'Installed Elementor widgets', $html );
		self::assertStringContainsString( 'Contract Widget', $html );
		$form = $xpath->query( '//form[.//input[@name="action"][@value="stonewright_sandbox_widget_project"]]' )->item( 0 );
		self::assertInstanceOf( \DOMElement::class, $form );
		self::assertSame( 1, $xpath->query( './/input[@name="_stonewright_widget_nonce"]', $form )->length );
		self::assertSame( 1, $xpath->query( './/input[@name="stonewright_library_tab"][@value="widgets"]', $form )->length );
		self::assertSame( 0, $xpath->query( './/*[@id="_stonewright_widget_nonce"]', $form )->length );
		self::assertStringContainsString( 'Create widget project', $html );
	}

	public function test_a_message_from_the_last_action_is_a_notice_that_stays_and_is_shown_once(): void {
		$GLOBALS['stonewright_test_transients']['stonewright_sandbox_lib_notice_7'] = [ 'type' => 'success', 'message' => 'Action completed successfully.' ];
		$html                                                                       = self::html();

		self::assertStringContainsString( 'sw-ui-notice--ok', $html );
		self::assertStringContainsString( 'Action completed successfully.', $html );
		self::assertArrayNotHasKey( 'stonewright_sandbox_lib_notice_7', $GLOBALS['stonewright_test_transients'] );

		$GLOBALS['stonewright_test_transients']['stonewright_sandbox_lib_notice_7'] = [ 'type' => 'error', 'message' => 'Static guard blocked save: eval() is not allowed.' ];
		$html                                                                       = self::html();
		self::assertStringContainsString( 'sw-ui-notice--danger', $html );
		self::assertStringContainsString( 'role="alert"', $html );
		self::assertStringContainsString( 'Static guard blocked save', $html );
	}

	// ---------------------------------------------------------------------------------------------
	// Edit, diff and rollback
	// ---------------------------------------------------------------------------------------------

	public function test_editing_posts_the_hash_it_rendered_and_has_one_primary_button(): void {
		SandboxFiles::write( 'route-test.php', "<?php\n// route test\n" );
		[ , $xpath ] = self::dom( [ 'action' => 'edit', 'file' => 'route-test.php', 'library_tab' => 'snippets' ] );
		$html        = self::html( [ 'action' => 'edit', 'file' => 'route-test.php', 'library_tab' => 'snippets' ] );

		$form = $xpath->query( '//form[.//input[@name="stonewright_lib_action"][@value="edit"]]' )->item( 0 );
		self::assertInstanceOf( \DOMElement::class, $form );
		self::assertSame( 1, $xpath->query( './/input[@name="content_hash_at_render"]', $form )->length );
		self::assertSame( substr( hash( 'sha256', "<?php\n// route test\n" ), 0, 16 ), $xpath->query( './/input[@name="content_hash_at_render"]', $form )->item( 0 )?->getAttribute( 'value' ) );
		self::assertSame( 1, $xpath->query( './/input[@name="_stonewright_lib_nonce"]', $form )->length );
		self::assertSame( 1, $xpath->query( './/input[@name="stonewright_return_tab"][@value="library"]', $form )->length );
		self::assertSame( 1, $xpath->query( './/label[@for="sw-code-lib-contents"]', $form )->length );
		$area = $xpath->query( './/textarea[@id="sw-code-lib-contents"][@name="stonewright_content"]', $form )->item( 0 );
		self::assertInstanceOf( \DOMElement::class, $area );
		self::assertStringContainsString( 'sw-ui-textarea--code', $area->getAttribute( 'class' ) );
		self::assertSame( 1, $xpath->query( '//*[contains(@class,"sw-ui-btn--primary")]' )->length );
		self::assertGreaterThanOrEqual( 1, $xpath->query( '//a[normalize-space(.)="Cancel"]' )->length );
		self::assert_clean( $html, $xpath );
	}

	public function test_editing_in_production_safe_mode_says_so_and_embeds_the_token_bound_to_the_content(): void {
		SandboxFiles::write( 'route-test.php', "<?php\n// route test\n" );
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		[ , $xpath ]                                             = self::dom( [ 'action' => 'edit', 'file' => 'route-test.php' ] );

		self::assertStringContainsString( 'Production-safe mode is active', self::html( [ 'action' => 'edit', 'file' => 'route-test.php' ] ) );
		self::assertStringContainsString( 'expires in 5 minutes', self::html( [ 'action' => 'edit', 'file' => 'route-test.php' ] ) );
		$token = $xpath->query( '//input[@name="stonewright_confirmation_token"]' )->item( 0 );
		self::assertInstanceOf( \DOMElement::class, $token );
		self::assertNotInstanceOf(
			\WP_Error::class,
			ConfirmationToken::verify_or_error( $token->getAttribute( 'value' ), 'stonewright/sandbox-edit', [ 'name' => 'route-test.php', 'content_hash' => substr( hash( 'sha256', "<?php\n// route test\n" ), 0, 16 ) ] )
		);
	}

	public function test_editing_lists_the_backups_with_one_rollback_link_each(): void {
		SandboxFiles::write( 'route-test.php', "<?php\n// one\n" );
		SandboxFiles::write( 'route-test.php', "<?php\n// two\n" );
		[ , $xpath ] = self::dom( [ 'action' => 'edit', 'file' => 'route-test.php' ] );

		$versions = SandboxFiles::backup_versions( 'route-test.php' );
		self::assertNotEmpty( $versions );
		self::assertSame( count( $versions ), $xpath->query( '//table//a[contains(@href,"action=rollback")]' )->length );
		self::assertSame( 1, $xpath->query( '//table/caption[contains(.,"ackup")]' )->length );
		self::assertGreaterThanOrEqual( 1, $xpath->query( '//table//time[@datetime]' )->length );
	}

	public function test_a_file_that_is_too_large_to_edit_is_an_error_with_a_way_back_and_no_contents(): void {
		file_put_contents( $this->sandbox_dir . '/big-file.draft', '<?php // SENTINEL' . str_repeat( 'X', 262200 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$html = self::html( [ 'action' => 'edit', 'file' => 'big-file.php' ] );

		self::assertStringContainsString( 'too large', strtolower( $html ) );
		self::assertStringContainsString( 'sw-ui-notice--danger', $html );
		self::assertStringNotContainsString( 'SENTINEL', $html );
		self::assertStringNotContainsString( '<textarea', $html );
	}

	public function test_the_diff_view_shows_both_versions_line_by_line_and_says_when_there_is_no_pending_one(): void {
		SandboxFiles::write( 'route-test.php', "<?php\n// route test\n" );
		[ , $xpath ] = self::dom( [ 'action' => 'diff', 'file' => 'route-test.php' ] );
		$html        = self::html( [ 'action' => 'diff', 'file' => 'route-test.php' ] );

		self::assertStringContainsString( 'No pending version found', $html );
		self::assertSame( 1, $xpath->query( '//pre[contains(@class,"sw-ui-code__body")]' )->length );
		self::assertStringContainsString( '// route test', $html );
		self::assertGreaterThanOrEqual( 1, $xpath->query( '//a[normalize-space(.)="Back to library"]' )->length );
		self::assert_clean( $html, $xpath );
	}

	public function test_the_diff_view_marks_a_changed_line_with_words_and_signs_not_colour(): void {
		SandboxFiles::write( 'route-test.php', "<?php\n// current\n" );
		file_put_contents( $this->sandbox_dir . '/route-test.pending.draft', "<?php\n// pending\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$html = self::html( [ 'action' => 'diff', 'file' => 'route-test.php' ] );

		self::assertStringContainsString( '- // current', $html );
		self::assertStringContainsString( '+ // pending', $html );
		self::assertStringContainsString( 'Current draft', $html );
		self::assertStringContainsString( 'Pending version', $html );
	}

	public function test_the_rollback_view_asks_once_and_carries_its_token_in_production_safe_mode(): void {
		SandboxFiles::write( 'route-test.php', "<?php\n// one\n" );
		SandboxFiles::write( 'route-test.php', "<?php\n// two\n" );
		$ts                                                      = SandboxFiles::backup_versions( 'route-test.php' )[0]['timestamp'];
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		[ , $xpath ]                                             = self::dom( [ 'action' => 'rollback', 'file' => 'route-test.php', 'to' => (string) $ts ] );

		$form = $xpath->query( '//form[.//input[@name="stonewright_lib_action"][@value="rollback"]]' )->item( 0 );
		self::assertInstanceOf( \DOMElement::class, $form );
		self::assertSame( (string) $ts, $xpath->query( './/input[@name="stonewright_rollback_ts"]', $form )->item( 0 )?->getAttribute( 'value' ) );
		self::assertSame( 1, $xpath->query( './/input[@name="_stonewright_lib_nonce"]', $form )->length );
		$token = $xpath->query( './/input[@name="stonewright_confirmation_token"]', $form )->item( 0 );
		self::assertInstanceOf( \DOMElement::class, $token );
		self::assertNotInstanceOf( \WP_Error::class, ConfirmationToken::verify_or_error( $token->getAttribute( 'value' ), 'stonewright/sandbox-rollback', [ 'name' => 'route-test.php', 'to' => $ts ] ) );
		self::assertSame( 1, $xpath->query( '//*[contains(@class,"sw-ui-btn--primary")]' )->length );
		self::assertStringContainsString( 'replace the current draft', self::html( [ 'action' => 'rollback', 'file' => 'route-test.php', 'to' => (string) $ts ] ) );
	}

	public function test_a_rollback_without_a_valid_time_is_an_error_and_has_no_form(): void {
		$html = self::html( [ 'action' => 'rollback', 'file' => 'route-test.php', 'to' => '0' ] );

		self::assertStringContainsString( 'Invalid backup timestamp', $html );
		self::assertStringNotContainsString( 'stonewright_rollback_ts', $html );
	}

	public function test_a_path_that_leaves_the_sandbox_never_reaches_a_view(): void {
		$html = self::html( [ 'action' => 'edit', 'file' => '../wp-config.php' ] );

		self::assertStringNotContainsString( 'name="stonewright_content"', $html );
		self::assertStringContainsString( 'sw-ui-notice--danger', $html );
	}

	// ---------------------------------------------------------------------------------------------
	// The legacy address
	// ---------------------------------------------------------------------------------------------

	public function test_the_old_direct_address_prints_the_same_content_inside_the_shell_with_one_heading(): void {
		SandboxFiles::write( 'route-test.php', "<?php\n// route test\n" );
		$_GET = [ 'page' => 'stonewright-sandbox-library' ];
		ob_start();
		SandboxLibraryPage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'sw-shell', $html );
		self::assertSame( 1, substr_count( $html, '<h1' ) );
		self::assertStringContainsString( 'route-test.php', $html );
		self::assertStringContainsString( 'name="page" value="stonewright-sandbox-library"', $html );
	}

	public function test_without_manage_options_the_library_refuses_with_a_403(): void {
		$GLOBALS['stonewright_test_user_caps'] = [];

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'You do not have permission to view this page.' );
		SandboxLibraryPage::render();
	}
}
