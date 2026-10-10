<?php
/**
 * Stonewright > Changes: the Undo and Redo flow. The dialog, the nonce, the permission, the token and the result.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use Stonewright\WpMcp\Admin\ChangesPage;
use Stonewright\WpMcp\Admin\ChangeUndo;
use Stonewright\WpMcp\Admin\MenuRegistry;
use Stonewright\WpMcp\Security\ChangeLedger;
use Stonewright\WpMcp\Security\ChangeRollback;
use Stonewright\WpMcp\Security\ConfirmationToken;
use Stonewright\WpMcp\Security\Rollback\RollbackFamilies;
use Stonewright\WpMcp\Tests\Unit\Security\Rollback\FakeFamilyHandler;
use Stonewright\WpMcp\Tests\Unit\Security\Rollback\RollbackTestCase;

/**
 * @covers \Stonewright\WpMcp\Admin\ChangeUndo
 * @covers \Stonewright\WpMcp\Admin\ChangeDetail
 * @covers \Stonewright\WpMcp\Admin\ChangesPage
 */
final class ChangeUndoTest extends RollbackTestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['stonewright_test_actions']          = [];
		$GLOBALS['stonewright_test_submenu_pages']    = [];
		$GLOBALS['stonewright_test_enqueued_styles']  = [];
		$GLOBALS['stonewright_test_enqueued_scripts'] = [];
		$GLOBALS['stonewright_test_users']            = [ (object) [ 'ID' => 7, 'user_login' => 'editor-one', 'display_name' => 'Editor One' ] ];
		$GLOBALS['stonewright_test_user_caps']        = [ 'manage_options' => true ];
		$_GET                                         = [];
		$_POST                                        = [];
		MenuRegistry::reset_for_tests();
	}

	protected function tearDown(): void {
		$_GET                                  = [];
		$_POST                                 = [];
		$GLOBALS['stonewright_test_users']     = [];
		$GLOBALS['stonewright_test_user_caps'] = [];
		unset( $GLOBALS['stonewright_test_last_redirect'] );
		MenuRegistry::reset_for_tests();
		parent::tearDown();
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

	/** The page with the drawer of one change open. */
	private function drawer( string $id, array $more = [] ): string {
		return $this->html( array_merge( [ 'change' => $id ], $more ) );
	}

	/** @param array<string, string> $post */
	private function post_request( array $post ): array {
		$_POST = $post;

		return ChangeUndo::process_request();
	}

	/** The fields the dialog posts, read from its own markup. */
	private function dialog_fields( string $html ): array {
		self::assertSame( 1, preg_match( '#<dialog id="sw-changes-undo-dialog".*?</dialog>#s', $html, $dialog ), 'The dialog is in the page.' );
		preg_match_all( '#<input type="hidden" name="([a-z0-9_]+)" value="([^"]*)"#', $dialog[0], $found, PREG_SET_ORDER );
		$fields = [];
		foreach ( $found as $field ) {
			$fields[ $field[1] ] = html_entity_decode( $field[2] );
		}

		return $fields;
	}

	// ---- registration ---------------------------------------------------------------------------------

	public function test_the_page_registers_the_one_post_action_of_the_undo(): void {
		ChangesPage::register();

		self::assertArrayHasKey( 'admin_post_stonewright_change_rollback', $GLOBALS['stonewright_test_actions'] );
		foreach ( array_keys( $GLOBALS['stonewright_test_actions'] ) as $hook ) {
			self::assertStringStartsNotWith( 'wp_ajax_', (string) $hook );
		}
		self::assertSame( 'stonewright_change_rollback', ChangeUndo::ACTION );
	}

	// ---- the button and the dialog ----------------------------------------------------------------------

	public function test_the_disabled_placeholder_is_gone_and_undo_opens_a_dialog_that_works_without_script(): void {
		$id   = $this->changed_page();
		$html = $this->drawer( $id );

		self::assertStringNotContainsString( 'Undo arrives with the rollback engine', $html );
		self::assertMatchesRegularExpression( '#<a class="sw-ui-btn[^"]*" href="https://example\.test/wp-admin/admin\.php\?page=stonewright-changes&change=' . $id . '&undo=1"[^>]*aria-haspopup="dialog"[^>]*data-sw-ui-dialog-open="\#sw-changes-undo-dialog"[^>]*>Undo this change</a>#', $html );
		self::assertMatchesRegularExpression( '#<dialog id="sw-changes-undo-dialog" class="sw-ui-dialog sw-changes-undo" aria-labelledby="sw-changes-undo-title"(?! open)#', $html, 'The dialog is closed until it is asked for.' );
	}

	public function test_the_address_with_undo_prints_the_dialog_open_for_a_browser_without_script(): void {
		$id   = $this->changed_page();
		$html = $this->drawer( $id, [ 'undo' => '1' ] );

		self::assertMatchesRegularExpression( '#<dialog id="sw-changes-undo-dialog" class="sw-ui-dialog sw-changes-undo" aria-labelledby="sw-changes-undo-title" open#', $html );
	}

	public function test_the_dialog_shows_the_dry_run_diff_what_it_restores_and_posts_to_admin_post_with_a_nonce(): void {
		$id   = $this->changed_page();
		$html = $this->drawer( $id );

		preg_match( '#<dialog id="sw-changes-undo-dialog".*?</dialog>#s', $html, $dialog );
		$dialog = $dialog[0] ?? '';
		self::assertMatchesRegularExpression( '#<form[^>]*method="post"[^>]*action="https://example\.test/wp-admin/admin-post\.php"#', $dialog );
		self::assertMatchesRegularExpression( '#<h2 class="sw-ui-dialog__title" id="sw-changes-undo-title">Undo change cs-[a-f0-9]{8}[a-f0-9]*…\?</h2>#', $dialog );
		self::assertStringContainsString( 'Original body', $dialog, 'The diff of the undo is in the dialog.' );
		self::assertStringContainsString( 'Changed body', $dialog );
		self::assertStringContainsString( 'data-sw-ui-diff=', $dialog );
		$fields = $this->dialog_fields( $html );
		self::assertSame( 'stonewright_change_rollback', $fields['action'] );
		self::assertSame( $id, $fields['change_id'] );
		self::assertSame( wp_create_nonce( 'stonewright_change_rollback' ), $fields['_stonewright_nonce'] );
		self::assertArrayNotHasKey( 'confirmation_token', $fields, 'Only production-safe mode asks for one.' );
		self::assertDoesNotMatchRegularExpression( '/\b[a-f0-9]{64}\b/', $html, 'A full hash is never printed.' );
		self::assertSame( 32, strlen( $fields['expected_current_sha256'] ) );
		self::assertStringNotContainsString( 'ROLL BACK', $dialog, 'No typed phrase outside production-safe mode.' );
		self::assertStringNotContainsString( 'force_drift', $dialog );
	}

	public function test_cancel_has_the_first_focus_and_the_confirm_button_names_the_action(): void {
		$id = $this->changed_page();
		preg_match( '#<dialog id="sw-changes-undo-dialog".*?</dialog>#s', $this->drawer( $id ), $dialog );
		$dialog = $dialog[0] ?? '';

		self::assertMatchesRegularExpression( '#<button type="button" class="sw-ui-btn"[^>]*data-sw-ui-dialog-close[^>]*autofocus[^>]*>Cancel</button>#', $dialog );
		self::assertMatchesRegularExpression( '#<button type="submit" class="sw-ui-btn sw-ui-btn--danger-solid"[^>]*>Undo this change</button>#', $dialog );
		self::assertLessThan( strpos( $dialog, 'type="submit"' ), strpos( $dialog, 'Cancel' ), 'Cancel comes before the destructive button.' );
	}

	public function test_a_state_that_drifted_shows_the_warning_and_needs_a_checked_box_to_go_on(): void {
		$id = $this->changed_page();
		$this->edit_post( 'post_content', 'Edited by hand' );
		$html = $this->drawer( $id );

		preg_match( '#<dialog id="sw-changes-undo-dialog".*?</dialog>#s', $html, $dialog );
		$dialog = $dialog[0] ?? '';
		self::assertStringContainsString( 'changed since', $dialog );
		self::assertMatchesRegularExpression( '#<input type="checkbox" id="[^"]+" name="force_drift" value="1" required#', $dialog );
		self::assertStringContainsString( 'Edited by hand', $dialog, 'The diff starts from what is live now.' );
	}

	public function test_newer_changes_are_named_in_the_dialog(): void {
		$first  = $this->changed_page();
		$second = $this->post_change( fn () => $this->edit_post( 'post_content', 'Third body' ) );
		$html   = $this->drawer( $first );

		preg_match( '#<dialog id="sw-changes-undo-dialog".*?</dialog>#s', $html, $dialog );
		self::assertStringContainsString( substr( $second, 3, 8 ), $dialog[0] ?? '' );
		self::assertStringContainsString( 'newer change', strtolower( $dialog[0] ?? '' ) );
	}

	public function test_production_safe_mode_adds_the_token_the_typed_phrase_and_a_disabled_confirm(): void {
		$id = $this->changed_page();
		$this->production_safe();
		$html   = $this->drawer( $id );
		$fields = $this->dialog_fields( $html );

		self::assertStringStartsWith( 'swc_', $fields['confirmation_token'] );
		preg_match( '#<dialog id="sw-changes-undo-dialog".*?</dialog>#s', $html, $dialog );
		$dialog = $dialog[0] ?? '';
		self::assertMatchesRegularExpression( '#<input class="sw-ui-input" type="text" id="[^"]+" name="confirm_phrase" value="" autocomplete="off" data-sw-ui-confirm-phrase="ROLL BACK"#', $dialog );
		self::assertMatchesRegularExpression( '#<button type="submit"[^>]*disabled[^>]*data-sw-ui-confirm-submit[^>]*>#', $dialog );
		self::assertArrayNotHasKey( 'confirmation_token_force', $fields );
	}

	public function test_a_drifted_state_in_production_safe_mode_gets_a_second_token_for_the_forced_run(): void {
		$id = $this->changed_page();
		$this->edit_post( 'post_content', 'Edited by hand' );
		$this->production_safe();

		$fields = $this->dialog_fields( $this->drawer( $id ) );

		self::assertStringStartsWith( 'swc_', $fields['confirmation_token'] );
		self::assertStringStartsWith( 'swc_', $fields['confirmation_token_force'] );
		self::assertNotSame( $fields['confirmation_token'], $fields['confirmation_token_force'] );
	}

	public function test_a_change_that_cannot_be_undone_has_no_dialog_and_says_why(): void {
		$row = ChangeLedger::record( [ 'ability' => 'stonewright/content-update-page', 'family' => 'post', 'resource_type' => 'post', 'resource_id' => '31', 'before' => [ 'v' => 1 ], 'restorable' => false, 'restorable_reason' => 'too_large' ] );
		self::assertIsArray( $row );
		ChangeLedger::settle( $row['change_id'], [ 'status' => 'verified' ] );

		$html = $this->drawer( $row['change_id'] );

		self::assertStringNotContainsString( 'sw-changes-undo-dialog', $html );
		self::assertStringContainsString( 'Undo is not available for this change', $html );
	}

	public function test_a_family_without_a_handler_has_no_undo(): void {
		$row = ChangeLedger::record( [ 'ability' => 'stonewright/woocommerce-update-product', 'family' => 'woocommerce', 'resource_type' => 'product', 'resource_id' => '9', 'before' => [ 'price' => '5' ] ] );
		self::assertIsArray( $row );
		ChangeLedger::settle( $row['change_id'], [ 'status' => 'verified', 'after' => [ 'price' => '6' ] ] );

		$html = $this->drawer( $row['change_id'] );

		self::assertStringNotContainsString( 'sw-changes-undo-dialog', $html );
		self::assertStringContainsString( 'Undo is not available for this change', $html );
	}

	public function test_the_list_without_a_drawer_prints_no_dialog_and_no_form(): void {
		$this->changed_page();
		$html = $this->html();

		self::assertStringNotContainsString( '<dialog', $html );
		self::assertStringNotContainsString( 'method="post"', $html );
	}

	public function test_a_code_change_tells_the_administrator_that_the_press_is_the_approval(): void {
		$handler = new FakeFamilyHandler( 'custom_code' );
		RollbackFamilies::register( $handler );
		$handler->state = [ 'a' => '2' ];
		$row            = ChangeLedger::record( [ 'ability' => 'stonewright/theme-custom-css', 'family' => 'custom_code', 'resource_type' => 'customizer_css', 'resource_id' => 'site-a', 'before' => [ 'fields' => [ 'a' => '1' ] ] ] );
		self::assertIsArray( $row );
		ChangeLedger::settle( $row['change_id'], [ 'status' => 'verified', 'after' => [ 'fields' => [ 'a' => '2' ] ] ] );

		$html = $this->drawer( $row['change_id'] );

		self::assertStringContainsString( 'This puts code back', $html );
		self::assertStringContainsString( 'approval', $html );
	}

	// ---- Redo ---------------------------------------------------------------------------------------------

	public function test_a_rolled_back_change_offers_redo_for_its_rollback(): void {
		$id       = $this->changed_page();
		$rollback = ChangeRollback::run( $id );
		self::assertIsArray( $rollback );

		$html   = $this->drawer( $id );
		$fields = $this->dialog_fields( $html );

		self::assertMatchesRegularExpression( '#data-sw-ui-dialog-open="\#sw-changes-undo-dialog"[^>]*>Redo this change</a>#', $html );
		self::assertSame( $rollback['rollback_change_id'], $fields['change_id'], 'The redo acts on the rollback row.' );
		self::assertMatchesRegularExpression( '#<h2 class="sw-ui-dialog__title" id="sw-changes-undo-title">Redo change cs-[a-f0-9]{8}[a-f0-9]*…\?</h2>#', $html );
		self::assertStringNotContainsString( '>Undo this change<', $html );
	}

	public function test_a_rollback_row_offers_redo_and_a_row_that_was_taken_back_offers_nothing(): void {
		$id   = $this->changed_page();
		$one  = ChangeRollback::run( $id );
		self::assertIsArray( $one );
		self::assertStringContainsString( 'Redo this change', $this->drawer( $one['rollback_change_id'] ) );

		$two = ChangeRollback::run( $one['rollback_change_id'] );
		self::assertIsArray( $two );
		$html = $this->drawer( $one['rollback_change_id'] );

		self::assertStringNotContainsString( 'sw-changes-undo-dialog', $html );
		self::assertStringNotContainsString( 'Redo this change', $html );
		self::assertStringContainsString( 'Undo this change', $this->drawer( $id ), 'The change is in effect again and can be undone.' );
	}

	// ---- escaping -----------------------------------------------------------------------------------------

	public function test_hostile_text_in_a_summary_a_title_or_a_diff_never_reaches_the_dialog_as_markup(): void {
		$this->make_post( self::POST, [ 'post_title' => '<script>alert("before")</script>', 'post_content' => '<img src=x onerror=alert(1)>' ] );
		$id = $this->post_change( fn () => $this->edit_post( 'post_content', '<script>alert("after")</script>' ) );
		$GLOBALS['wpdb']->update( $GLOBALS['wpdb']->prefix . 'stonewright_changes', [ 'summary' => '<script>alert("s")</script>words' ], [ 'change_id' => $id ] );

		$html = $this->drawer( $id, [ 'undo' => '1' ] );

		self::assertStringNotContainsString( '<script', $html );
		self::assertStringNotContainsString( '<img src=x', $html );
		self::assertStringContainsString( '&lt;script&gt;alert(&quot;after&quot;)&lt;/script&gt;', str_replace( '&#039;', '"', $html ) );
	}

	// ---- the post -----------------------------------------------------------------------------------------

	/** @return array<string, string> What the dialog of a change posts. */
	private function posted( string $id, array $more = [] ): array {
		$fields = $this->dialog_fields( $this->drawer( $id ) );

		return array_merge( [ 'action' => $fields['action'], 'change_id' => $fields['change_id'], '_stonewright_nonce' => $fields['_stonewright_nonce'], 'expected_current_sha256' => $fields['expected_current_sha256'] ], $more );
	}

	public function test_a_post_without_a_valid_nonce_or_without_manage_options_ends_the_request_and_runs_nothing(): void {
		$id   = $this->changed_page();
		$post = $this->posted( $id );

		$GLOBALS['stonewright_test_nonce_invalid'] = true;
		try {
			$this->post_request( $post );
			self::fail( 'A bad nonce must end the request.' );
		} catch ( \RuntimeException $stopped ) {
			self::assertStringContainsString( 'Forbidden', $stopped->getMessage() );
		}
		unset( $GLOBALS['stonewright_test_nonce_invalid'] );

		$this->as_user_without_manage_options();
		$GLOBALS['stonewright_test_user_caps'] = [];
		try {
			$this->post_request( $post );
			self::fail( 'Without manage_options the request must end.' );
		} catch ( \RuntimeException $stopped ) {
			self::assertStringContainsString( 'Forbidden', $stopped->getMessage() );
		}

		self::assertSame( 'Changed body', $this->post_field( 'post_content' ) );
		self::assertSame( [], $this->audit_rows(), 'A request that ends at the gate is not a run.' );
	}

	public function test_a_post_without_a_nonce_field_ends_the_request(): void {
		$id   = $this->changed_page();
		$post = $this->posted( $id );
		unset( $post['_stonewright_nonce'] );

		$this->expectException( \RuntimeException::class );
		$this->post_request( $post );
	}

	public function test_a_valid_post_undoes_the_change_and_the_result_links_to_the_new_row(): void {
		$id      = $this->changed_page();
		$outcome = $this->post_request( $this->posted( $id ) );

		self::assertSame( 'undone', $outcome['code'] );
		self::assertSame( $id, $outcome['change_id'] );
		self::assertTrue( ChangeLedger::is_valid_id( $outcome['new_id'] ) );
		self::assertSame( 'Original body', $this->post_field( 'post_content' ) );
		self::assertSame( 'rollback', $this->row( $outcome['new_id'] )['kind'] );
		$audit = $this->audit_rows();
		self::assertCount( 1, $audit );
		self::assertSame( 'admin-page', $this->audit_args( $audit[0] )['by'] );

		$url = ChangeUndo::redirect_url( $outcome );
		self::assertStringContainsString( 'page=stonewright-changes', $url );
		self::assertStringContainsString( 'change=' . $outcome['new_id'], $url );
		self::assertStringContainsString( 'undone=undone', $url );
		self::assertStringContainsString( 'from=' . $id, $url );
	}

	public function test_the_page_shows_the_result_with_a_link_to_the_new_row_and_the_receipt(): void {
		$id      = $this->changed_page();
		$outcome = $this->post_request( $this->posted( $id ) );

		$html = $this->html( [ 'change' => $outcome['new_id'], 'undone' => 'undone', 'from' => $id ] );

		self::assertMatchesRegularExpression( '#<div class="sw-ui-notice sw-ui-notice--ok"[^>]*role="status"#', $html );
		self::assertStringContainsString( 'The change was undone', $html );
		self::assertMatchesRegularExpression( '#<a class="sw-ui-link" href="[^"]*change=' . $outcome['new_id'] . '[^"]*">View the new change</a>#', $html );
		self::assertMatchesRegularExpression( '#<a class="sw-ui-link" href="[^"]*page=stonewright-audit-log[^"]*change_set_id=' . $outcome['new_id'] . '[^"]*ability=stonewright%2Fchange-rollback[^"]*"#', str_replace( '&amp;', '&', $html ) );
		self::assertStringContainsString( $outcome['new_id'], $html );
	}

	public function test_an_unknown_result_code_or_a_hostile_from_parameter_prints_nothing(): void {
		$id   = $this->changed_page();
		$html = $this->html( [ 'undone' => '<script>', 'from' => '"><img src=x>', 'change' => $id ] );

		self::assertStringNotContainsString( '<script', $html );
		self::assertStringNotContainsString( '<img src=x', $html );
		self::assertStringNotContainsString( 'The change was undone', $html );
	}

	public function test_a_post_for_an_unknown_change_or_a_bad_id_is_not_found(): void {
		$id   = $this->changed_page();
		$post = $this->posted( $id );

		self::assertSame( 'not_found', $this->post_request( array_merge( $post, [ 'change_id' => 'cs-' . str_repeat( 'a', 24 ) ] ) )['code'] );
		self::assertSame( 'not_found', $this->post_request( array_merge( $post, [ 'change_id' => '../../x' ] ) )['code'] );
		self::assertSame( 'Changed body', $this->post_field( 'post_content' ) );
	}

	public function test_a_post_after_the_state_drifted_is_refused_unless_the_box_was_checked(): void {
		$id   = $this->changed_page();
		$post = $this->posted( $id );
		$this->edit_post( 'post_content', 'Edited by hand' );

		$refused = $this->post_request( $post );
		self::assertSame( 'drift', $refused['code'] );
		self::assertSame( 'Edited by hand', $this->post_field( 'post_content' ) );

		$post    = $this->posted( $id, [ 'force_drift' => '1' ] );
		$allowed = $this->post_request( $post );
		self::assertSame( 'undone', $allowed['code'] );
		self::assertSame( 'Original body', $this->post_field( 'post_content' ) );
	}

	public function test_a_post_after_the_state_changed_since_the_dialog_was_drawn_is_refused(): void {
		$id   = $this->changed_page();
		$post = $this->posted( $id );
		$this->edit_post( 'post_title', 'Retitled while the dialog was open' );

		self::assertSame( 'changed_since_preview', $this->post_request( array_merge( $post, [ 'force_drift' => '1' ] ) )['code'] );
		self::assertSame( 'Changed body', $this->post_field( 'post_content' ) );
	}

	public function test_a_redo_is_posted_for_the_rollback_row_and_restores_the_change(): void {
		$id   = $this->changed_page();
		$one  = ChangeRollback::run( $id );
		self::assertIsArray( $one );
		$post = $this->posted( $id );

		$outcome = $this->post_request( $post );

		self::assertSame( 'redone', $outcome['code'] );
		self::assertSame( 'Changed body', $this->post_field( 'post_content' ) );
		self::assertSame( 'redo', $this->row( $outcome['new_id'] )['kind'] );
	}

	public function test_production_safe_mode_needs_the_token_the_dialog_issued_and_binds_it_to_the_change(): void {
		$id = $this->changed_page();
		$this->production_safe();
		$fields = $this->dialog_fields( $this->drawer( $id ) );
		$post   = $this->posted( $id );

		self::assertSame( 'confirmation_required', $this->post_request( $post )['code'] );
		self::assertSame( 'Changed body', $this->post_field( 'post_content' ) );

		$this->make_post( 99, [ 'post_content' => 'Elsewhere' ] );
		$other = $this->post_change( fn () => $this->edit_post( 'post_content', 'Elsewhere, changed', 99 ), 99 );
		$bad   = ConfirmationToken::issue( ChangeRollback::ABILITY, ChangeRollback::confirmation_args( $other, [] ) );
		self::assertSame( 'confirmation_invalid', $this->post_request( array_merge( $post, [ 'confirmation_token' => $bad ] ) )['code'] );
		self::assertSame( 'Changed body', $this->post_field( 'post_content' ) );

		$fresh = $this->dialog_fields( $this->drawer( $id ) );
		$ok    = $this->post_request( array_merge( $this->posted( $id ), [ 'confirmation_token' => $fresh['confirmation_token'] ] ) );
		self::assertSame( 'undone', $ok['code'], 'The token the dialog issued for this change is accepted.' );
		self::assertNotSame( $fields['confirmation_token'], $fresh['confirmation_token'] );
	}

	public function test_production_safe_mode_runs_with_the_token_of_the_dialog(): void {
		$id = $this->changed_page();
		$this->production_safe();
		$fields = $this->dialog_fields( $this->drawer( $id ) );

		$outcome = $this->post_request( $this->posted( $id, [ 'confirmation_token' => $fields['confirmation_token'] ] ) );

		self::assertSame( 'undone', $outcome['code'] );
		self::assertSame( 'Original body', $this->post_field( 'post_content' ) );
	}

	public function test_the_forced_run_uses_the_forced_token(): void {
		$id = $this->changed_page();
		$this->edit_post( 'post_content', 'Edited by hand' );
		$this->production_safe();
		$fields = $this->dialog_fields( $this->drawer( $id ) );
		$post   = $this->posted( $id, [ 'force_drift' => '1', 'confirmation_token' => $fields['confirmation_token'] ] );

		self::assertSame( 'confirmation_invalid', $this->post_request( $post )['code'], 'The plain token does not cover the forced run.' );

		$fields = $this->dialog_fields( $this->drawer( $id ) );
		$post   = $this->posted( $id, [ 'force_drift' => '1', 'confirmation_token' => $fields['confirmation_token'], 'confirmation_token_force' => $fields['confirmation_token_force'] ] );
		self::assertSame( 'undone', $this->post_request( $post )['code'] );
	}

	public function test_the_page_passes_human_approved_for_code_and_an_agent_cannot(): void {
		$handler = new FakeFamilyHandler( 'custom_code' );
		RollbackFamilies::register( $handler );
		$handler->state = [ 'a' => '2' ];
		$row            = ChangeLedger::record( [ 'ability' => 'stonewright/theme-custom-css', 'family' => 'custom_code', 'resource_type' => 'customizer_css', 'resource_id' => 'site-a', 'before' => [ 'fields' => [ 'a' => '1' ] ] ] );
		self::assertIsArray( $row );
		ChangeLedger::settle( $row['change_id'], [ 'status' => 'verified', 'after' => [ 'fields' => [ 'a' => '2' ] ] ] );
		$id = (string) $row['change_id'];

		$agent = ChangeRollback::run( $id, [ 'by' => 'ability' ] );
		self::assertInstanceOf( \WP_Error::class, $agent );
		self::assertSame( 'stonewright_rescue_approval_required', $agent->get_error_code() );
		self::assertSame( [], $handler->calls );

		$outcome = $this->post_request( $this->posted( $id ) );

		self::assertSame( 'undone', $outcome['code'] );
		self::assertSame( [ 'a' => '1' ], $handler->state );
	}

	public function test_a_failure_of_the_run_is_reported_with_its_own_code(): void {
		$id = $this->changed_page();
		$this->make_post( self::POST, [ 'post_content' => 'FATAL original layout' ] );
		$id = $this->post_change( fn () => $this->edit_post( 'post_content', 'Working layout' ) );
		$this->site( fn (): string => str_contains( $this->post_field( 'post_content' ), 'FATAL' ) ? 'broken' : 'healthy' );

		$outcome = $this->post_request( $this->posted( $id ) );

		self::assertSame( 'reverted', $outcome['code'] );
		self::assertSame( 'Working layout', $this->post_field( 'post_content' ) );
		$html = $this->html( [ 'change' => $id, 'undone' => 'reverted', 'from' => $id ] );
		self::assertMatchesRegularExpression( '#sw-ui-notice--danger#', $html );
		self::assertStringContainsString( 'was put back', $html );
	}

	// ---- assets ---------------------------------------------------------------------------------------------

	public function test_the_script_opens_the_dialog_through_the_layer_and_forgets_the_undo_address(): void {
		$js = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/admin/pages/changes.js' );

		self::assertStringContainsString( 'sw-changes-undo-dialog', $js );
		self::assertStringContainsString( "[ 'change', 'view', 'undo', 'undone', 'from' ]", $js, 'Closing the drawer takes every argument of the page out of the address.' );
		self::assertStringContainsString( "dropParams( [ 'undo' ] )", $js, 'Closing the dialog takes undo out of the address.' );
		self::assertDoesNotMatchRegularExpression( '/fetch\(|XMLHttpRequest|innerHTML/', $js );
	}

	public function test_the_dialog_fits_a_400_pixel_screen(): void {
		$css = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/admin/pages/changes.css' );

		self::assertMatchesRegularExpression( '/\.sw-changes-page \.sw-ui-dialog\.sw-changes-undo \{[^}]*width: min\(/', $css );
		self::assertMatchesRegularExpression( '/\.sw-changes-undo[^{]*\{[^}]*max-width: calc\(100vw - /', $css );
		self::assertDoesNotMatchRegularExpression( '/overflow-x:\s*(?:auto|scroll)/', $css );
	}
}
