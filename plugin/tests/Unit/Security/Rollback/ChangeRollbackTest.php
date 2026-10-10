<?php
/**
 * The rollback engine: the dry run, the gates, drift, the claim, the probe and the rows a rollback writes.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security\Rollback;

use Stonewright\WpMcp\Security\Adapters\PostAdapter;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\ChangeJournal;
use Stonewright\WpMcp\Security\ChangeLedger;
use Stonewright\WpMcp\Security\ChangeRollback;
use Stonewright\WpMcp\Security\ConfirmationToken;
use Stonewright\WpMcp\Security\RescueRollback;
use Stonewright\WpMcp\Security\Rollback\RollbackClaim;
use Stonewright\WpMcp\Security\Rollback\RollbackFamilies;
use Stonewright\WpMcp\Tests\Unit\Security\Adapters\PostLedgerWpdb;

/**
 * @covers \Stonewright\WpMcp\Security\ChangeRollback
 * @covers \Stonewright\WpMcp\Security\Rollback\RollbackFamilies
 * @covers \Stonewright\WpMcp\Security\Rollback\RollbackClaim
 * @covers \Stonewright\WpMcp\Security\Rollback\PostRollbackFamily
 */
final class ChangeRollbackTest extends RollbackTestCase {

	/** A user row recorded for a family this branch has no adapter for. */
	private function fake_change( FakeFamilyHandler $handler ): string {
		$handler->state = [ 'display_name' => 'After' ];
		$row            = ChangeLedger::record( [ 'ability' => 'stonewright/users-update', 'family' => 'user', 'resource_type' => 'user', 'resource_id' => '5', 'before' => [ 'fields' => [ 'display_name' => 'Before' ] ] ] );
		self::assertIsArray( $row );
		ChangeLedger::settle( $row['change_id'], [ 'status' => 'verified', 'after' => [ 'fields' => [ 'display_name' => 'After' ] ] ] );
		return $row['change_id'];
	}

	// ---- the dry run ------------------------------------------------------------------------------

	public function test_a_dry_run_returns_the_diff_of_the_undo_and_writes_nothing(): void {
		$id     = $this->changed_page();
		$rows   = ChangeLedger::count();
		$blobs  = $this->blobs();
		$before = $GLOBALS['stonewright_test_posts'];

		$plan = ChangeRollback::run( $id, [ 'dry_run' => true ] );

		self::assertIsArray( $plan, $plan instanceof \WP_Error ? $plan->get_error_message() : '' );
		self::assertTrue( $plan['ok'] );
		self::assertTrue( $plan['dry_run'] );
		self::assertSame( $id, $plan['change_id'] );
		self::assertSame( 'rollback', $plan['kind'] );
		self::assertSame( 'post', $plan['family'] );
		self::assertSame( 'ledger', $plan['path'] );
		self::assertSame( 'ok', $plan['diff']['status'] );
		self::assertTrue( $plan['diff']['changed'] );
		$shown = (string) wp_json_encode( $plan['diff'] );
		self::assertStringContainsString( 'Changed body', $shown, 'The diff starts from what is live now.' );
		self::assertStringContainsString( 'Original body', $shown, 'The diff ends at the before image.' );
		self::assertFalse( $plan['drift'] );
		self::assertSame( [], $plan['newer_changes'] );
		self::assertSame( [], $plan['warnings'] );
		self::assertFalse( $plan['approval_required'] );
		self::assertSame( 64, strlen( $plan['current_sha256'] ), 'The caller can pass it back as expected_current_sha256.' );
		self::assertNotSame( '', $plan['would_apply'] );

		self::assertEquals( $before, $GLOBALS['stonewright_test_posts'], 'A dry run changes no post.' );
		self::assertSame( $rows, ChangeLedger::count(), 'A dry run adds no ledger row.' );
		self::assertSame( $blobs, $this->blobs(), 'A dry run stores no image.' );
	}

	public function test_a_dry_run_needs_no_confirmation_token_in_production_safe_mode_but_says_one_is_needed(): void {
		$id = $this->changed_page();
		$this->production_safe();

		$plan = ChangeRollback::run( $id, [ 'dry_run' => true ] );

		self::assertIsArray( $plan );
		self::assertTrue( $plan['confirmation_required'] );
	}

	// ---- a run: the rows, the statuses, the restored state ---------------------------------------

	public function test_a_run_restores_the_before_image_and_writes_a_rollback_row_under_the_change(): void {
		$id = $this->changed_page();

		$result = ChangeRollback::run( $id, [ 'by' => 'ability' ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertTrue( $result['ok'] );
		self::assertSame( 'Original body', $this->post_field( 'post_content' ) );
		self::assertSame( 'rollback', $result['kind'] );
		self::assertSame( 'ledger', $result['path'] );
		self::assertSame( 'healthy', $result['site_status'] );
		self::assertSame( 'verified', $result['verification_status'] );
		self::assertTrue( $result['restorable_again'] );

		$rollback = $this->row( $result['rollback_change_id'] );
		self::assertSame( 'rollback', $rollback['kind'] );
		self::assertSame( $id, $rollback['parent_id'] );
		self::assertSame( 'post', $rollback['family'] );
		self::assertSame( (string) self::POST, $rollback['resource_id'] );
		self::assertSame( 'verified', $rollback['status'] );
		self::assertTrue( $rollback['restorable'] );
		self::assertSame( 7, $rollback['actor'] );
		self::assertSame( 'Changed body', ChangeLedger::read_image( $rollback['change_id'], 'before' )['post']['post_content'], 'Its before image is the live state before the rollback.' );
		self::assertSame( 'Original body', ChangeLedger::read_image( $rollback['change_id'], 'after' )['post']['post_content'] );
		self::assertSame( 'rolled_back_by', $this->row( $id )['status'] );
	}

	public function test_a_redo_is_a_rollback_of_the_rollback_row_and_restores_the_after_image(): void {
		$id       = $this->changed_page();
		$rollback = ChangeRollback::run( $id );
		self::assertIsArray( $rollback );

		$redo = ChangeRollback::run( $rollback['rollback_change_id'] );

		self::assertIsArray( $redo, $redo instanceof \WP_Error ? $redo->get_error_message() : '' );
		self::assertSame( 'redo', $redo['kind'] );
		self::assertSame( 'Changed body', $this->post_field( 'post_content' ) );
		$row = $this->row( $redo['rollback_change_id'] );
		self::assertSame( 'redo', $row['kind'] );
		self::assertSame( $rollback['rollback_change_id'], $row['parent_id'] );
		self::assertSame( 'rolled_back_by', $this->row( $rollback['rollback_change_id'] )['status'] );
		self::assertSame( 'verified', $this->row( $id )['status'], 'The change is in effect again.' );
	}

	public function test_a_redo_can_be_undone_again(): void {
		$id    = $this->changed_page();
		$one   = ChangeRollback::run( $id );
		$two   = ChangeRollback::run( $one['rollback_change_id'] );
		$three = ChangeRollback::run( $two['rollback_change_id'] );

		self::assertIsArray( $three, $three instanceof \WP_Error ? $three->get_error_message() : '' );
		self::assertSame( 'rollback', $three['kind'] );
		self::assertSame( 'Original body', $this->post_field( 'post_content' ) );
		self::assertSame( 'rolled_back_by', $this->row( $id )['status'] );
	}

	public function test_a_change_that_was_rolled_back_cannot_be_rolled_back_twice_and_names_its_rollback(): void {
		$id     = $this->changed_page();
		$result = ChangeRollback::run( $id );
		self::assertIsArray( $result );

		$again = $this->assert_refused( ChangeRollback::run( $id ), 'stonewright_change_already_rolled_back' );

		self::assertSame( $result['rollback_change_id'], $again->get_error_data()['rollback_change_id'] );
	}

	public function test_a_creation_is_undone_to_the_trash_and_redone_by_restoring_the_post(): void {
		$this->make_post( 55, [ 'post_title' => 'Brand new', 'post_content' => 'New body' ] );
		$created = PostAdapter::record_create( 'stonewright/content-create-page', 55 );
		PostAdapter::settle( $created, PostAdapter::capture_after( 55 ), 'verified' );

		$undo = ChangeRollback::run( $created );

		self::assertIsArray( $undo, $undo instanceof \WP_Error ? $undo->get_error_message() : '' );
		self::assertSame( 'trash', $this->post_field( 'post_status', 55 ) );
		$redo = ChangeRollback::run( $undo['rollback_change_id'] );
		self::assertIsArray( $redo, $redo instanceof \WP_Error ? $redo->get_error_message() : '' );
		self::assertSame( 'publish', $this->post_field( 'post_status', 55 ), 'The redo takes the post out of the trash.' );
	}

	// ---- refusals ----------------------------------------------------------------------------------

	public function test_a_row_that_is_not_restorable_is_refused_with_its_reason(): void {
		$row = ChangeLedger::record( [ 'ability' => 'stonewright/content-update-page', 'family' => 'post', 'resource_type' => 'post', 'resource_id' => '31', 'before' => [ 'v' => 1 ], 'restorable' => false, 'restorable_reason' => 'too_large' ] );
		self::assertIsArray( $row );
		ChangeLedger::settle( $row['change_id'], [ 'status' => 'verified' ] );

		$error = $this->assert_refused( ChangeRollback::run( $row['change_id'] ), 'stonewright_change_not_restorable' );

		self::assertSame( 'too_large', $error->get_error_data()['reason'] );
		self::assertStringContainsString( 'too_large', $error->get_error_message() );
	}

	public function test_a_dry_run_of_a_row_that_is_not_restorable_is_refused_too(): void {
		$row = ChangeLedger::record( [ 'ability' => 'stonewright/content-update-page', 'family' => 'post', 'resource_type' => 'post', 'resource_id' => '31', 'before' => [ 'v' => 1 ], 'restorable' => false, 'restorable_reason' => 'masked_secret' ] );
		self::assertIsArray( $row );

		$this->assert_refused( ChangeRollback::run( $row['change_id'], [ 'dry_run' => true ] ), 'stonewright_change_not_restorable' );
	}

	public function test_an_id_that_is_not_a_change_id_or_is_unknown_is_refused(): void {
		$this->assert_refused( ChangeRollback::run( '../../etc/passwd' ), 'stonewright_change_invalid_id' );
		$this->assert_refused( ChangeRollback::run( 'cs-' . str_repeat( 'a', 24 ) ), 'stonewright_change_not_found' );
	}

	public function test_a_restore_point_row_cannot_be_rolled_back(): void {
		$row = ChangeLedger::record( [ 'ability' => 'stonewright/change-restore-point-create', 'family' => 'other', 'resource_type' => 'restore_point', 'resource_id' => 'task-1', 'kind' => 'restore_point', 'before' => null ] );
		self::assertIsArray( $row );

		$this->assert_refused( ChangeRollback::run( $row['change_id'] ), 'stonewright_change_kind_unsupported' );
	}

	public function test_a_family_without_a_handler_is_refused_and_says_which(): void {
		$row = ChangeLedger::record( [ 'ability' => 'stonewright/settings-admin-write', 'family' => 'other', 'resource_type' => 'admin_setting', 'resource_id' => 'mode', 'before' => [ 'price' => '5' ] ] );
		self::assertIsArray( $row );
		ChangeLedger::settle( $row['change_id'], [ 'status' => 'verified', 'after' => [ 'price' => '6' ] ] );

		$error = $this->assert_refused( ChangeRollback::run( $row['change_id'] ), 'stonewright_change_family_unsupported' );

		self::assertSame( 'other', $error->get_error_data()['family'] );
	}

	public function test_a_resource_that_already_equals_the_before_image_is_refused_as_already_restored(): void {
		$id = $this->changed_page();
		$this->edit_post( 'post_content', 'Original body' );

		$error = $this->assert_refused( ChangeRollback::run( $id, [ 'force_drift' => true ] ), 'stonewright_change_already_restored' );

		self::assertSame( [ $id ], array_column( $this->ledger_rows(), 'change_id' ), 'Nothing was recorded.' );
		self::assertSame( 409, $error->get_error_data()['status'] );
	}

	public function test_a_post_that_no_longer_exists_fails_cleanly_and_the_change_stays_in_effect(): void {
		$id = $this->changed_page();
		unset( $GLOBALS['stonewright_test_posts'][ self::POST ] );

		$error = ChangeRollback::run( $id, [ 'force_drift' => true ] );

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'stonewright_change_rollback_failed', $error->get_error_code() );
		self::assertNotSame( 'rolled_back_by', $this->row( $id )['status'] );
	}

	// ---- permission --------------------------------------------------------------------------------

	public function test_without_manage_options_nothing_is_read_or_written_and_the_refusal_is_audited(): void {
		$id = $this->changed_page();
		$this->as_user_without_manage_options();

		$error = $this->assert_refused( ChangeRollback::run( $id ), 'stonewright_change_forbidden' );

		self::assertSame( 403, $error->get_error_data()['status'] );
		self::assertSame( 'Changed body', $this->post_field( 'post_content' ) );
		self::assertSame( 1, ChangeLedger::count() );
		$audit = $this->audit_rows();
		self::assertCount( 1, $audit );
		self::assertSame( 'blocked', $audit[0]['result_status'] );
	}

	public function test_a_dry_run_needs_manage_options_too(): void {
		$id = $this->changed_page();
		$this->as_user_without_manage_options();

		$this->assert_refused( ChangeRollback::run( $id, [ 'dry_run' => true ] ), 'stonewright_change_forbidden' );
	}

	// ---- production-safe ---------------------------------------------------------------------------

	public function test_in_production_safe_mode_a_run_needs_a_confirmation_token(): void {
		$id = $this->changed_page();
		$this->production_safe();

		$this->assert_refused( ChangeRollback::run( $id ), 'stonewright_confirmation_required' );

		self::assertSame( 'Changed body', $this->post_field( 'post_content' ) );
		self::assertSame( 1, ChangeLedger::count() );
	}

	public function test_a_token_for_another_change_or_other_options_is_refused_and_its_own_token_works(): void {
		$id    = $this->changed_page();
		$other = $this->post_change( fn () => $this->edit_post( 'post_title', 'Other title' ) );
		$this->production_safe();

		$for_other = ConfirmationToken::issue( ChangeRollback::ABILITY, ChangeRollback::confirmation_args( $other, [] ) );
		$this->assert_refused( ChangeRollback::run( $id, [ 'confirmation_token' => $for_other, 'force_drift' => true ] ), 'stonewright_confirmation_args_mismatch' );

		$plain = ConfirmationToken::issue( ChangeRollback::ABILITY, ChangeRollback::confirmation_args( $id, [] ) );
		$this->assert_refused( ChangeRollback::run( $id, [ 'confirmation_token' => $plain, 'force_drift' => true ] ), 'stonewright_confirmation_args_mismatch' );

		self::assertSame( 'Changed body', $this->post_field( 'post_content' ), 'Nothing ran.' );

		$forced = ConfirmationToken::issue( ChangeRollback::ABILITY, ChangeRollback::confirmation_args( $id, [ 'force_drift' => true ] ) );
		$result = ChangeRollback::run( $id, [ 'confirmation_token' => $forced, 'force_drift' => true ] );
		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
	}

	public function test_a_token_is_bound_to_the_preview_the_person_saw(): void {
		$id = $this->changed_page();
		$this->production_safe();
		$plan = ChangeRollback::run( $id, [ 'dry_run' => true ] );
		self::assertIsArray( $plan );
		$token = ConfirmationToken::issue( ChangeRollback::ABILITY, ChangeRollback::confirmation_args( $id, [ 'expected_current_sha256' => $plan['current_sha256'] ] ) );

		$this->assert_refused( ChangeRollback::run( $id, [ 'confirmation_token' => $token ] ), 'stonewright_confirmation_args_mismatch' );

		$token  = ConfirmationToken::issue( ChangeRollback::ABILITY, ChangeRollback::confirmation_args( $id, [ 'expected_current_sha256' => $plan['current_sha256'] ] ) );
		$result = ChangeRollback::run( $id, [ 'confirmation_token' => $token, 'expected_current_sha256' => $plan['current_sha256'] ] );
		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
	}

	public function test_a_token_is_not_used_up_by_a_refusal_that_comes_before_it(): void {
		$id = $this->changed_page();
		$this->edit_post( 'post_content', 'Edited by hand' );
		$this->production_safe();
		$token = ConfirmationToken::issue( ChangeRollback::ABILITY, ChangeRollback::confirmation_args( $id, [] ) );

		$this->assert_refused( ChangeRollback::run( $id, [ 'confirmation_token' => $token ] ), 'stonewright_change_drift' );

		$this->edit_post( 'post_content', 'Changed body' );
		$result = ChangeRollback::run( $id, [ 'confirmation_token' => $token ] );
		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
	}

	// ---- drift and newer changes ---------------------------------------------------------------------

	public function test_drift_is_refused_without_force_and_the_plan_warns(): void {
		$id = $this->changed_page();
		$this->edit_post( 'post_content', 'Edited by hand' );

		$plan = ChangeRollback::run( $id, [ 'dry_run' => true ] );
		self::assertIsArray( $plan );
		self::assertTrue( $plan['drift'] );
		self::assertTrue( $plan['requires_force'] );
		self::assertStringContainsString( 'changed since', implode( ' ', $plan['warnings'] ) );
		self::assertNotSame( $plan['expected_sha256'], $plan['current_sha256'] );

		$error = $this->assert_refused( ChangeRollback::run( $id ), 'stonewright_change_drift' );

		self::assertTrue( $error->get_error_data()['drift'] );
		self::assertSame( 'Edited by hand', $this->post_field( 'post_content' ), 'Nothing was written.' );
		self::assertSame( 1, ChangeLedger::count() );
	}

	public function test_force_drift_rolls_back_over_the_edit_and_keeps_the_edit_for_a_redo(): void {
		$id = $this->changed_page();
		$this->edit_post( 'post_content', 'Edited by hand' );

		$result = ChangeRollback::run( $id, [ 'force_drift' => true ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertSame( 'Original body', $this->post_field( 'post_content' ) );
		self::assertTrue( $result['drift_forced'] );
		self::assertSame( 'Edited by hand', ChangeLedger::read_image( $result['rollback_change_id'], 'before' )['post']['post_content'] );
		$redo = ChangeRollback::run( $result['rollback_change_id'] );
		self::assertIsArray( $redo );
		self::assertSame( 'Edited by hand', $this->post_field( 'post_content' ), 'The redo returns the edit, not the change.' );
	}

	public function test_a_state_that_was_never_settled_has_no_after_image_to_compare_and_needs_force(): void {
		$this->make_post( self::POST, [ 'post_content' => 'Original body' ] );
		$id = PostAdapter::record_before( 'stonewright/content-update-page', self::POST );
		$this->edit_post( 'post_content', 'Half written' );

		$this->assert_refused( ChangeRollback::run( $id ), 'stonewright_change_drift' );

		$result = ChangeRollback::run( $id, [ 'force_drift' => true ] );
		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertSame( 'Original body', $this->post_field( 'post_content' ) );
	}

	public function test_newer_changes_to_the_same_resource_are_listed_and_warned_about(): void {
		$first = $this->changed_page();
		$this->make_post( 99, [ 'post_content' => 'Elsewhere' ] );
		$second = $this->post_change( fn () => $this->edit_post( 'post_content', 'Third body' ) );
		$this->post_change( fn () => $this->edit_post( 'post_content', 'Elsewhere, changed', 99 ), 99 );

		$plan = ChangeRollback::run( $first, [ 'dry_run' => true ] );

		self::assertIsArray( $plan );
		self::assertSame( [ $second ], array_column( $plan['newer_changes'], 'change_id' ), 'A change to another post is not listed.' );
		self::assertSame( 'stonewright/content-update-page', $plan['newer_changes'][0]['ability'] );
		self::assertStringContainsString( 'newer change', strtolower( implode( ' ', $plan['warnings'] ) ) );
		self::assertTrue( $plan['drift'], 'The newer change also moved the live state.' );
	}

	public function test_a_newer_change_that_was_itself_rolled_back_is_not_a_warning(): void {
		$first  = $this->changed_page();
		$second = $this->post_change( fn () => $this->edit_post( 'post_content', 'Third body' ) );
		$undo   = ChangeRollback::run( $second );
		self::assertIsArray( $undo, $undo instanceof \WP_Error ? $undo->get_error_message() : '' );

		$plan = ChangeRollback::run( $first, [ 'dry_run' => true ] );

		self::assertIsArray( $plan );
		self::assertSame( [], array_column( $plan['newer_changes'], 'change_id' ), 'The rollback of the newer change left the resource as the first change made it.' );
		self::assertFalse( $plan['drift'] );
	}

	public function test_expected_current_sha256_refuses_a_state_that_is_not_the_one_previewed(): void {
		$id   = $this->changed_page();
		$plan = ChangeRollback::run( $id, [ 'dry_run' => true ] );
		self::assertIsArray( $plan );
		$this->edit_post( 'post_title', 'Retitled after the preview' );

		$this->assert_refused( ChangeRollback::run( $id, [ 'expected_current_sha256' => $plan['current_sha256'], 'force_drift' => true ] ), 'stonewright_change_changed_since_preview' );

		self::assertSame( 'Changed body', $this->post_field( 'post_content' ) );
	}

	public function test_expected_current_sha256_of_the_previewed_state_lets_the_run_through(): void {
		$id   = $this->changed_page();
		$plan = ChangeRollback::run( $id, [ 'dry_run' => true ] );
		self::assertIsArray( $plan );

		$result = ChangeRollback::run( $id, [ 'expected_current_sha256' => $plan['current_sha256'] ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
	}

	// ---- the claim ----------------------------------------------------------------------------------

	public function test_a_second_run_of_the_same_change_while_one_is_running_is_refused_and_the_first_runs_once(): void {
		$handler = new FakeFamilyHandler();
		RollbackFamilies::register( $handler );
		$id              = $this->fake_change( $handler );
		$inner           = null;
		$handler->during = function () use ( $id, &$inner ): void {
			$inner = ChangeRollback::run( $id );
		};

		$outer = ChangeRollback::run( $id );

		self::assertIsArray( $outer, $outer instanceof \WP_Error ? $outer->get_error_message() : '' );
		self::assertInstanceOf( \WP_Error::class, $inner );
		self::assertSame( 'stonewright_change_in_progress', $inner->get_error_code() );
		self::assertCount( 1, $handler->calls, 'The restore ran once.' );
		self::assertSame( [], $this->db->tables[ $this->db->prefix . 'options' ] ?? [], 'The claim is released.' );
	}

	public function test_a_claim_that_is_older_than_its_lifetime_does_not_block_a_run(): void {
		$id  = $this->changed_page();
		$row = $this->row( $id );
		$this->db->tables[ $this->db->prefix . 'options' ][] = [ 'option_name' => RollbackClaim::name_for( $row ), 'option_value' => (string) ( time() - 3600 ), 'autoload' => 'no' ];

		$result = ChangeRollback::run( $id );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
	}

	public function test_a_live_claim_on_the_resource_blocks_a_run_of_any_change_to_it(): void {
		$id  = $this->changed_page();
		$row = $this->row( $id );
		$this->db->tables[ $this->db->prefix . 'options' ][] = [ 'option_name' => RollbackClaim::name_for( $row ), 'option_value' => (string) time(), 'autoload' => 'no' ];

		$this->assert_refused( ChangeRollback::run( $id ), 'stonewright_change_in_progress' );

		self::assertSame( 'Changed body', $this->post_field( 'post_content' ) );
	}

	public function test_the_claim_is_released_after_a_failure(): void {
		$id = $this->changed_page();
		unset( $GLOBALS['stonewright_test_posts'][ self::POST ] );
		ChangeRollback::run( $id, [ 'force_drift' => true ] );

		self::assertSame( [], $this->db->tables[ $this->db->prefix . 'options' ] ?? [] );
	}

	// ---- the probe -----------------------------------------------------------------------------------

	public function test_a_probe_that_fails_after_the_restore_puts_the_after_state_back_and_reports_it(): void {
		$this->make_post( self::POST, [ 'post_content' => 'FATAL original layout' ] );
		$id = $this->post_change( fn () => $this->edit_post( 'post_content', 'Working layout' ) );
		$this->site( fn (): string => str_contains( $this->post_field( 'post_content' ), 'FATAL' ) ? 'broken' : 'healthy' );

		$error = ChangeRollback::run( $id );

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'stonewright_change_rollback_reverted', $error->get_error_code() );
		self::assertSame( 'Working layout', $this->post_field( 'post_content' ), 'The change that worked is back.' );
		$data = $error->get_error_data();
		self::assertSame( 'reverted', $data['rollback_status'] );
		self::assertSame( 'still_failing', $data['site_status'] );
		self::assertNotSame( '', $data['rollback_change_id'] );
		self::assertSame( 'verified', $this->row( $id )['status'], 'The change is still in effect.' );
		$rollback = $this->row( $data['rollback_change_id'] );
		self::assertSame( 'rolled_back_by', $rollback['status'], 'The rollback row says it was taken back.' );
		$children = ChangeLedger::children( $rollback['change_id'] );
		self::assertCount( 1, $children );
		self::assertSame( 'redo', $children[0]['kind'] );
		self::assertSame( 'verified', $children[0]['status'] );
	}

	public function test_a_site_check_that_could_not_run_keeps_the_rollback_and_says_so(): void {
		$id = $this->changed_page();
		$this->site( static fn (): string => 'unavailable' );

		$result = ChangeRollback::run( $id );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertSame( 'unknown', $result['site_status'] );
		self::assertSame( 'unverified', $result['verification_status'] );
		self::assertSame( 'probe_unavailable', $this->row( $result['rollback_change_id'] )['status'] );
		self::assertSame( 'Original body', $this->post_field( 'post_content' ) );
	}

	public function test_a_site_that_was_already_failing_before_the_restore_is_not_blamed_on_it(): void {
		$id = $this->changed_page();
		$this->site( static fn (): string => 'broken' );

		$result = ChangeRollback::run( $id );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertSame( 'Original body', $this->post_field( 'post_content' ) );
	}

	// ---- the journal link ------------------------------------------------------------------------------

	/**
	 * A post change that the journal also knows: armed with a snapshot recipe under the id of the ledger row.
	 *
	 * @return array{0:string,1:string} The change id, and the id of the snapshot.
	 */
	private function journaled_change( string $state ): array {
		$this->make_post( self::POST, [ 'post_content' => 'Original body' ] );
		$id       = PostAdapter::record_before( 'stonewright/content-update-page', self::POST );
		$snapshot = Backup::snapshot_post( self::POST );
		ChangeJournal::arm(
			[
				'change_set_id' => $id,
				'ability'       => 'stonewright/content-update-page',
				'resource_type' => 'post',
				'resource_key'  => (string) self::POST,
				'recipe'        => [ 'type' => 'post_snapshot', 'ref' => $snapshot ],
				'recipe_detail' => [ 'post_id' => self::POST, 'snapshot_id' => $snapshot ],
				'scope'         => 'post',
			]
		);
		$this->edit_post( 'post_content', 'Changed body' );
		PostAdapter::settle( $id, PostAdapter::capture_after( self::POST ), 'armed' );
		if ( 'armed' !== $state ) {
			ChangeJournal::settle( $id, $state );
		}
		return [ $id, $snapshot ];
	}

	public function test_an_open_incident_goes_through_the_journal_path_and_keeps_both_stores_consistent(): void {
		[ $id ] = $this->journaled_change( 'rollback_failed' );
		$this->edit_post( 'post_content', 'Broken by a fatal' );

		$result = ChangeRollback::run( $id );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertSame( 'journal', $result['path'] );
		self::assertSame( $id, $result['incident_id'], 'The journal path answers as the journal path does.' );
		self::assertSame( 'succeeded', $result['rollback_status'] );
		self::assertSame( 'Original body', $this->post_field( 'post_content' ), 'The incident needed no force: the site is failing.' );
		self::assertSame( 'rolled_back', ChangeJournal::get( $id )['state'] );
		self::assertSame( 'rolled_back_by', $this->row( $id )['status'] );
		$children = ChangeLedger::children( $id );
		self::assertCount( 1, $children, 'The history has the rollback row too.' );
		self::assertSame( 'rollback', $children[0]['kind'] );
		self::assertSame( $children[0]['change_id'], $result['rollback_change_id'] );
	}

	public function test_a_recent_verified_change_with_a_recipe_also_goes_through_the_journal_path(): void {
		[ $id ] = $this->journaled_change( 'verified' );

		$result = ChangeRollback::run( $id );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertSame( 'journal', $result['path'] );
		self::assertSame( 'rolled_back', ChangeJournal::get( $id )['state'] );
	}

	public function test_a_verified_change_the_journal_cannot_undo_uses_the_adapter_path_and_settles_the_journal_too(): void {
		[ $id ] = $this->journaled_change( 'verified' );
		ChangeJournal::attach_recipe( $id, [ 'type' => 'none', 'ref' => '' ] );

		$result = ChangeRollback::run( $id );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertSame( 'ledger', $result['path'] );
		self::assertSame( 'Original body', $this->post_field( 'post_content' ) );
		self::assertSame( 'rolled_back', ChangeJournal::get( $id )['state'], 'The journal learns that the change was undone.' );
		self::assertSame( 'rolled_back_by', $this->row( $id )['status'] );
	}

	public function test_the_rescue_path_for_an_incident_is_unchanged_when_called_directly(): void {
		[ $id ] = $this->journaled_change( 'rollback_failed' );
		$this->edit_post( 'post_content', 'Broken by a fatal' );
		$rows = ChangeLedger::count();

		$result = RescueRollback::run( $id, [ 'by' => 'admin-page', 'user_id' => 7 ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertSame( 'succeeded', $result['rollback_status'] );
		self::assertArrayNotHasKey( 'path', $result, 'The incident answer has the shape it always had.' );
		self::assertSame( 'Original body', $this->post_field( 'post_content' ) );
		self::assertSame( $rows, ChangeLedger::count(), 'The rescue path writes no ledger row of its own.' );
	}

	public function test_a_dry_run_of_an_open_incident_names_the_journal_path_and_asks_for_no_force(): void {
		[ $id ] = $this->journaled_change( 'armed' );
		$this->edit_post( 'post_content', 'Broken by a fatal' );

		$plan = ChangeRollback::run( $id, [ 'dry_run' => true ] );

		self::assertIsArray( $plan, $plan instanceof \WP_Error ? $plan->get_error_message() : '' );
		self::assertSame( 'journal', $plan['path'] );
		self::assertFalse( $plan['requires_force'], 'An incident is rolled back without force.' );
	}

	// ---- the registry -----------------------------------------------------------------------------------

	public function test_a_handler_registered_for_a_new_family_is_used_without_changing_the_engine(): void {
		$handler = new FakeFamilyHandler( 'user' );
		RollbackFamilies::register( $handler );
		$id = $this->fake_change( $handler );

		$plan = ChangeRollback::run( $id, [ 'dry_run' => true ] );
		self::assertIsArray( $plan, $plan instanceof \WP_Error ? $plan->get_error_message() : '' );
		self::assertSame( 'Writes the fields back.', $plan['would_apply'] );
		self::assertStringContainsString( 'Before', (string) wp_json_encode( $plan['diff'] ) );

		$result = ChangeRollback::run( $id );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertSame( [ 'display_name' => 'Before' ], $handler->state );
		$rollback = $this->row( $result['rollback_change_id'] );
		self::assertSame( 'user', $rollback['family'] );
		self::assertSame( [ 'fields' => [ 'display_name' => 'After' ] ], ChangeLedger::read_image( $rollback['change_id'], 'before' ) );

		$redo = ChangeRollback::run( $rollback['change_id'] );
		self::assertIsArray( $redo, $redo instanceof \WP_Error ? $redo->get_error_message() : '' );
		self::assertSame( [ 'display_name' => 'After' ], $handler->state );
	}

	public function test_a_later_registration_for_a_family_replaces_the_earlier_one(): void {
		$first  = new FakeFamilyHandler( 'user' );
		$second = new FakeFamilyHandler( 'user' );
		RollbackFamilies::register( $first );
		RollbackFamilies::register( $second );

		self::assertSame( $second, RollbackFamilies::handler_for( 'user' ) );
		self::assertNull( RollbackFamilies::handler_for( 'other' ) );
	}

	public function test_the_built_in_families_are_registered(): void {
		foreach ( [ 'post', 'elementor', 'gutenberg', 'fse', 'global_styles', 'option', 'menu', 'widget', 'theme_file', 'custom_code', 'sandbox' ] as $family ) {
			self::assertNotNull( RollbackFamilies::handler_for( $family ), $family );
		}
	}

	public function test_a_handler_that_fails_is_reported_and_recorded_and_the_change_stays_in_effect(): void {
		$handler         = new FakeFamilyHandler( 'user' );
		$handler->status = 'failed';
		RollbackFamilies::register( $handler );
		$id = $this->fake_change( $handler );

		$error = ChangeRollback::run( $id );

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'stonewright_change_rollback_failed', $error->get_error_code() );
		self::assertSame( 'fake_failure', $error->get_error_data()['detail'] );
		self::assertSame( 'verified', $this->row( $id )['status'] );
		$children = ChangeLedger::children( $id );
		self::assertCount( 1, $children );
		self::assertSame( 'failed', $children[0]['status'], 'The attempt is in the history.' );
	}

	public function test_a_handler_cannot_weaken_the_code_gate_for_a_code_family(): void {
		$handler = new FakeFamilyHandler( 'sandbox', false );
		RollbackFamilies::register( $handler );
		$row = ChangeLedger::record( [ 'ability' => 'stonewright/sandbox-write', 'family' => 'sandbox', 'resource_type' => 'sandbox_draft', 'resource_id' => 'demo.php', 'before' => [ 'fields' => [ 'a' => '1' ] ] ] );
		self::assertIsArray( $row );
		ChangeLedger::settle( $row['change_id'], [ 'status' => 'verified', 'after' => [ 'fields' => [ 'a' => '2' ] ] ] );
		$handler->state = [ 'a' => '2' ];

		$error = $this->assert_refused( ChangeRollback::run( $row['change_id'] ), 'stonewright_rescue_approval_required' );

		self::assertSame( [], $handler->calls );
		self::assertTrue( $error->get_error_data()['approval_required'] ?? false );
	}

	public function test_a_handler_may_ask_for_a_human_for_its_own_family(): void {
		$handler = new FakeFamilyHandler( 'user', true );
		RollbackFamilies::register( $handler );
		$id = $this->fake_change( $handler );

		$plan = ChangeRollback::run( $id, [ 'dry_run' => true ] );
		self::assertIsArray( $plan );
		self::assertTrue( $plan['approval_required'] );

		$this->assert_refused( ChangeRollback::run( $id ), 'stonewright_rescue_approval_required' );
		$done = ChangeRollback::run( $id, [ 'human_approved' => true ] );
		self::assertIsArray( $done, $done instanceof \WP_Error ? $done->get_error_message() : '' );
	}

	// ---- the audit -------------------------------------------------------------------------------------

	public function test_every_run_writes_one_audit_row_dry_runs_and_refusals_included(): void {
		$id = $this->changed_page();

		ChangeRollback::run( $id, [ 'dry_run' => true ] );
		ChangeRollback::run( 'cs-' . str_repeat( 'f', 24 ) );
		$result = ChangeRollback::run( $id, [ 'by' => 'admin-page' ] );
		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );

		$rows = $this->audit_rows();
		self::assertCount( 3, $rows );
		self::assertSame( [ 'ok', 'error', 'ok' ], array_column( $rows, 'result_status' ) );
		self::assertSame( $id, $rows[0]['change_set_id'] );
		self::assertSame( $result['rollback_change_id'], $rows[2]['change_set_id'], 'The receipt of a run carries the id of the new row.' );
		$args = $this->audit_args( $rows[2] );
		self::assertSame( $id, $args['change_id'] );
		self::assertSame( 'rollback', $args['action'] );
		self::assertSame( 'admin-page', $args['by'] );
		self::assertTrue( $this->audit_args( $rows[0] )['dry_run'] );
		self::assertSame( 'stonewright/change-rollback', $result['receipt']['ability'] );
		self::assertSame( $result['rollback_change_id'], $result['receipt']['change_set_id'] );
	}

	public function test_a_refusal_carries_the_receipt_and_no_audit_row_holds_an_image(): void {
		$id = $this->changed_page();
		$this->edit_post( 'post_content', 'SECRET-LOOKING-BODY-text' );

		$error = $this->assert_refused( ChangeRollback::run( $id ), 'stonewright_change_drift' );

		self::assertSame( $id, $error->get_error_data()['receipt']['change_set_id'] );
		$stored = (string) wp_json_encode( $this->audit_rows() );
		self::assertStringNotContainsString( 'SECRET-LOOKING-BODY-text', $stored );
		self::assertStringNotContainsString( 'Changed body', $stored );
	}

	public function test_an_audit_that_cannot_be_written_never_fails_the_run(): void {
		$id    = $this->changed_page();
		$audit = new class() extends PostLedgerWpdb {
			public function insert( $table, $data, $format = null ) {
				if ( str_contains( (string) $table, 'audit_log' ) ) {
					throw new \RuntimeException( 'audit down' );
				}
				return parent::insert( $table, $data, $format );
			}
		};
		$audit->tables   = $this->db->tables;
		$audit->unique   = $this->db->unique;
		$GLOBALS['wpdb'] = $audit;

		$result = ChangeRollback::run( $id );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertSame( 'Original body', $this->post_field( 'post_content' ) );
	}

	// ---- what the page needs --------------------------------------------------------------------------

	public function test_the_action_of_a_row_is_a_redo_only_for_a_rollback_row(): void {
		$id       = $this->changed_page();
		$rollback = ChangeRollback::run( $id );
		self::assertIsArray( $rollback, $rollback instanceof \WP_Error ? $rollback->get_error_message() : '' );

		self::assertSame( 'rollback', ChangeRollback::action_for( $this->row( $id ) ) );
		self::assertSame( 'redo', ChangeRollback::action_for( $this->row( $rollback['rollback_change_id'] ) ) );
	}

	public function test_the_rollback_to_redo_for_a_rolled_back_change_is_found(): void {
		$id       = $this->changed_page();
		$rollback = ChangeRollback::run( $id );
		self::assertIsArray( $rollback, $rollback instanceof \WP_Error ? $rollback->get_error_message() : '' );

		$target = ChangeRollback::redo_target( $this->row( $id ) );

		self::assertSame( $rollback['rollback_change_id'], $target['change_id'] ?? '' );
		self::assertNull( ChangeRollback::redo_target( $this->row( $rollback['rollback_change_id'] ) ) );
		ChangeRollback::run( $rollback['rollback_change_id'] );
		self::assertNull( ChangeRollback::redo_target( $this->row( $id ) ), 'The change is in effect again: nothing to redo.' );
	}
}
