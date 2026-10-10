<?php
/**
 * stonewright/change-rollback: undo or redo one change from the ledger. The engine owns every gate; the ability passes
 * the call to it and never verifies the confirmation token itself.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Abilities\Security;

use Stonewright\WpMcp\Abilities\Security\ChangeRollback as ChangeRollbackAbility;
use Stonewright\WpMcp\Core\AbilityAnnotations;
use Stonewright\WpMcp\Security\ChangeLedger;
use Stonewright\WpMcp\Security\ChangeRollback;
use Stonewright\WpMcp\Security\ConfirmationToken;

/**
 * @covers \Stonewright\WpMcp\Abilities\Security\ChangeRollback
 * @covers \Stonewright\WpMcp\Security\ChangeRollback
 */
final class ChangeRollbackAbilityTest extends ChangeHistoryTestCase {

	private function ability(): ChangeRollbackAbility {
		return new ChangeRollbackAbility();
	}

	/** @param array<string, mixed> $args */
	private function run_ability( string $id, array $args = [] ): mixed {
		return $this->ability()->execute( array_merge( [ 'change_id' => $id ], $args ) );
	}

	// ---- the ability ---------------------------------------------------------------------------------------------

	public function test_it_is_a_destructive_write_ability_for_administrators(): void {
		$ability = $this->ability();

		self::assertSame( 'stonewright/change-rollback', $ability->name() );
		self::assertSame( 'security', $ability->category() );
		self::assertTrue( $ability->permission_callback( [ 'change_id' => 'cs-x' ] ) );
		$this->as_user_without_manage_options();
		self::assertFalse( $ability->permission_callback( [ 'change_id' => 'cs-x' ] ) );
		$hints = AbilityAnnotations::for_ability( $ability );
		self::assertFalse( $hints['readonly'] );
		self::assertTrue( $hints['destructive'] );
		self::assertFalse( $hints['idempotent'] );
	}

	public function test_the_input_schema_is_strict_and_has_the_six_inputs(): void {
		$schema = $this->ability()->input_schema();

		self::assertFalse( $schema['additionalProperties'] );
		self::assertSame( [ 'change_id' ], $schema['required'] );
		self::assertSame( [ 'change_id', 'dry_run', 'force_drift', 'expected_current_sha256', 'permanent', 'confirmation_token' ], array_keys( $schema['properties'] ) );
		self::assertSame( '^cs-[a-f0-9]{24}$', $schema['properties']['change_id']['pattern'] );
		self::assertSame( 'boolean', $schema['properties']['dry_run']['type'] );
		self::assertSame( 'boolean', $schema['properties']['force_drift']['type'] );
		self::assertSame( 'boolean', $schema['properties']['permanent']['type'] );
		self::assertSame( '^[a-fA-F0-9]{32,64}$', $schema['properties']['expected_current_sha256']['pattern'] );
		self::assertSame( 'string', $schema['properties']['confirmation_token']['type'] );
	}

	public function test_the_output_schema_covers_a_run_a_dry_run_and_the_arguments_of_a_token(): void {
		$schema = $this->ability()->output_schema();

		self::assertSame( [ 'ok', 'change_id' ], $schema['required'] );
		foreach ( [ 'dry_run', 'kind', 'path', 'rollback_status', 'rollback_change_id', 'resource_id', 'state', 'site_status', 'verification_status', 'drift', 'drift_forced', 'requires_force', 'approval_required', 'approval_url', 'confirmation_required', 'confirmation_args', 'newer_changes', 'warnings', 'limits', 'would_apply', 'diff', 'receipt', 'restorable_again' ] as $key ) {
			self::assertArrayHasKey( $key, $schema['properties'], $key );
		}
	}

	public function test_a_person_without_manage_options_changes_nothing_even_when_the_ability_is_called_directly(): void {
		$id = $this->page_change( 31, 'Original body', 'Changed body' );
		$this->as_user_without_manage_options();

		$result = $this->run_ability( $id );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_change_forbidden', $result->get_error_code() );
		self::assertSame( 'Changed body', $this->post_field( 'post_content' ) );
	}

	// ---- a dry run -----------------------------------------------------------------------------------------------

	public function test_a_dry_run_returns_the_plan_and_writes_nothing(): void {
		$id = $this->page_change( 31, 'Original body', 'Changed body' );
		$before = ChangeLedger::count();

		$result = $this->ok_result( $this->run_ability( $id, [ 'dry_run' => true ] ) );

		self::assertTrue( $result['dry_run'] );
		self::assertSame( [ $id, 'rollback', 'ledger', false, false ], [ $result['change_id'], $result['kind'], $result['path'], $result['drift'], $result['approval_required'] ] );
		self::assertSame( 'Changed body', $this->post_field( 'post_content' ) );
		self::assertSame( $before, ChangeLedger::count(), 'No row was written.' );
		self::assertCount( 1, $this->audit_rows(), 'One audit row, the engine\'s own.' );
		self::assertArrayNotHasKey( 'confirmation_args', $result, 'No token is needed outside production-safe mode.' );
	}

	public function test_a_dry_run_carries_a_short_diff_and_never_the_lines_or_an_image(): void {
		$id = $this->page_change( 31, 'Original body', 'Changed body' );

		$result = $this->ok_result( $this->run_ability( $id, [ 'dry_run' => true ] ) );

		self::assertTrue( $result['diff']['changed'] );
		self::assertSame( 'ok', $result['diff']['status'] );
		self::assertSame( [ 'id', 'title', 'summary' ], array_keys( $result['diff']['sections'][0] ) );
		$json = (string) wp_json_encode( $result );
		self::assertStringNotContainsString( '"hunks"', $json );
		self::assertStringNotContainsString( 'Changed body', $json );
		self::assertStringNotContainsString( 'Original body', $json );
	}

	public function test_a_dry_run_in_production_safe_mode_returns_the_arguments_a_token_must_be_issued_for(): void {
		$id = $this->page_change( 31, 'Original body', 'Changed body' );
		$this->production_safe();

		$result = $this->ok_result( $this->run_ability( $id, [ 'dry_run' => true, 'force_drift' => true ] ) );

		self::assertTrue( $result['confirmation_required'] );
		self::assertSame( ChangeRollback::confirmation_args( $id, [ 'force_drift' => true ] ), $result['confirmation_args'] );
		self::assertSame( [ 'change_id', 'force_drift', 'expected_current_sha256', 'permanent' ], array_keys( $result['confirmation_args'] ) );
	}

	// ---- a run ---------------------------------------------------------------------------------------------------

	public function test_a_run_puts_the_page_back_and_a_second_run_on_the_rollback_redoes_it(): void {
		$id = $this->page_change( 31, 'Original body', 'Changed body' );

		$undo = $this->ok_result( $this->run_ability( $id ) );

		self::assertSame( 'Original body', $this->post_field( 'post_content' ) );
		self::assertSame( [ 'rollback', 'ledger', 'succeeded', 'healthy' ], [ $undo['kind'], $undo['path'], $undo['rollback_status'], $undo['site_status'] ] );
		self::assertTrue( $undo['restorable_again'] );
		$rollback = $this->row( $undo['rollback_change_id'] );
		self::assertSame( [ 'rollback', $id, 'stonewright/change-rollback', 7 ], [ $rollback['kind'], $rollback['parent_id'], $rollback['ability'], $rollback['actor'] ] );

		$redo = $this->ok_result( $this->run_ability( $undo['rollback_change_id'] ) );

		self::assertSame( 'redo', $redo['kind'] );
		self::assertSame( 'Changed body', $this->post_field( 'post_content' ) );
	}

	public function test_the_ability_records_one_audit_row_that_says_the_call_came_through_an_ability(): void {
		$id = $this->page_change( 31, 'Original body', 'Changed body' );

		$this->ok_result( $this->run_ability( $id ) );

		$rows = $this->audit_rows();
		self::assertCount( 1, $rows, 'The engine writes the row; the ability writes no second one.' );
		self::assertSame( 'ability', $this->audit_args( $rows[0] )['by'] );
		self::assertSame( $id, $this->audit_args( $rows[0] )['change_id'] );
	}

	public function test_the_call_passes_the_actor_and_never_a_human_approval(): void {
		$id = $this->theme_change();

		$result = $this->run_ability( $id, [ 'human_approved' => true ] );

		// The schema forbids the key, and the ability would not pass it on if it were sent.
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_rescue_approval_required', $result->get_error_code(), 'An unknown key is dropped, and code still needs the administrator.' );
	}

	// ---- code ----------------------------------------------------------------------------------------------------

	public function test_the_undo_of_code_returns_the_approval_required_answer_as_it_is(): void {
		$id = $this->theme_change();

		$result = $this->run_ability( $id );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_rescue_approval_required', $result->get_error_code() );
		$data = $result->get_error_data();
		self::assertStringContainsString( 'stonewright-changes', (string) $data['approval_url'] );
		self::assertStringContainsString( 'Do not retry', $result->get_error_message() );
		self::assertSame( self::V2, (string) file_get_contents( $this->theme . '/functions.php' ), 'The file was not touched.' );
	}

	public function test_a_token_does_not_change_the_answer_for_code_and_is_not_used_up(): void {
		$id = $this->theme_change();
		$this->production_safe();
		$token = ConfirmationToken::issue( ChangeRollback::ABILITY, ChangeRollback::confirmation_args( $id, [] ) );

		$result = $this->run_ability( $id, [ 'confirmation_token' => $token ] );

		self::assertSame( 'stonewright_rescue_approval_required', $result->get_error_code() );
		self::assertTrue( ConfirmationToken::verify( $token, ChangeRollback::ABILITY, ChangeRollback::confirmation_args( $id, [] ) ), 'The refusal came before the token was used.' );
	}

	public function test_a_dry_run_of_code_still_returns_the_plan_with_the_approval_url(): void {
		$id = $this->theme_change();

		$result = $this->ok_result( $this->run_ability( $id, [ 'dry_run' => true ] ) );

		self::assertTrue( $result['approval_required'] );
		self::assertStringContainsString( 'stonewright-changes', $result['approval_url'] );
	}

	// ---- the token ------------------------------------------------------------------------------------------------

	public function test_in_production_safe_mode_a_run_needs_a_token_and_the_token_is_verified_once(): void {
		$id = $this->page_change( 31, 'Original body', 'Changed body' );
		$this->production_safe();

		$missing = $this->run_ability( $id );
		self::assertSame( 'stonewright_confirmation_required', $missing->get_error_code() );
		self::assertSame( 'Changed body', $this->post_field( 'post_content' ) );

		$plan   = $this->ok_result( $this->run_ability( $id, [ 'dry_run' => true ] ) );
		$token  = ConfirmationToken::issue( ChangeRollback::ABILITY, $plan['confirmation_args'] );
		$result = $this->run_ability( $id, [ 'confirmation_token' => $token ] );

		// A token works once: had the ability verified it before the engine, the engine would find it used.
		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_code() . ': ' . $result->get_error_message() : '' );
		self::assertSame( 'Original body', $this->post_field( 'post_content' ) );
		$again = $this->run_ability( $result['rollback_change_id'], [ 'confirmation_token' => $token ] );
		self::assertInstanceOf( \WP_Error::class, $again, 'The token is spent, and bound to the first change.' );
		self::assertStringStartsWith( 'stonewright_confirmation_', $again->get_error_code() );
	}

	public function test_a_token_issued_for_other_arguments_is_refused(): void {
		$id = $this->page_change( 31, 'Original body', 'Changed body' );
		$this->production_safe();
		$token = ConfirmationToken::issue( ChangeRollback::ABILITY, ChangeRollback::confirmation_args( $id, [ 'force_drift' => true ] ) );

		$result = $this->run_ability( $id, [ 'confirmation_token' => $token ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_confirmation_args_mismatch', $result->get_error_code() );
		self::assertSame( 'Changed body', $this->post_field( 'post_content' ) );
	}

	// ---- drift ---------------------------------------------------------------------------------------------------

	public function test_drift_is_refused_and_force_drift_overwrites_it(): void {
		$id = $this->page_change( 31, 'Original body', 'Changed body' );
		$this->edit_post( 'post_content', 'Edited by hand' );

		$plan = $this->ok_result( $this->run_ability( $id, [ 'dry_run' => true ] ) );
		self::assertTrue( $plan['drift'] );
		self::assertTrue( $plan['requires_force'] );

		$refused = $this->run_ability( $id );
		self::assertInstanceOf( \WP_Error::class, $refused );
		self::assertSame( 'stonewright_change_drift', $refused->get_error_code() );
		self::assertSame( 'Edited by hand', $this->post_field( 'post_content' ) );

		$done = $this->ok_result( $this->run_ability( $id, [ 'force_drift' => true ] ) );
		self::assertTrue( $done['drift_forced'] );
		self::assertSame( 'Original body', $this->post_field( 'post_content' ) );
	}

	public function test_the_state_that_was_previewed_is_checked_before_anything_is_written(): void {
		$id   = $this->page_change( 31, 'Original body', 'Changed body' );
		$plan = $this->ok_result( $this->run_ability( $id, [ 'dry_run' => true ] ) );
		$this->edit_post( 'post_content', 'Edited by hand' );

		$result = $this->run_ability( $id, [ 'expected_current_sha256' => $plan['current_sha256'], 'force_drift' => true ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_change_changed_since_preview', $result->get_error_code() );
		self::assertSame( 'Edited by hand', $this->post_field( 'post_content' ) );
	}

	// ---- refusals --------------------------------------------------------------------------------------------------

	public function test_a_change_that_was_rolled_back_a_row_that_cannot_be_restored_and_a_bad_id_are_refused(): void {
		$id = $this->page_change( 31, 'Original body', 'Changed body' );
		$this->ok_result( $this->run_ability( $id ) );
		self::assertSame( 'stonewright_change_already_rolled_back', $this->run_ability( $id )->get_error_code() );

		$this->make_post( 32, [ 'post_content' => 'x' ] );
		$other = $this->post_change( fn () => $this->edit_post( 'post_content', 'y', 32 ), 32 );
		$this->db->tables[ $this->db->prefix . 'stonewright_changes' ][ count( $this->db->tables[ $this->db->prefix . 'stonewright_changes' ] ) - 1 ]['restorable']        = 0;
		$this->db->tables[ $this->db->prefix . 'stonewright_changes' ][ count( $this->db->tables[ $this->db->prefix . 'stonewright_changes' ] ) - 1 ]['restorable_reason'] = 'too_large';
		self::assertSame( 'stonewright_change_not_restorable', $this->run_ability( $other )->get_error_code() );

		self::assertSame( 'stonewright_change_invalid_id', $this->run_ability( '../x' )->get_error_code() );
		self::assertSame( 'stonewright_change_not_found', $this->run_ability( 'cs-' . str_repeat( 'b', 24 ) )->get_error_code() );
	}

	public function test_a_hash_that_is_not_a_hash_is_refused_before_the_engine_runs(): void {
		$id = $this->page_change( 31, 'Original body', 'Changed body' );

		$result = $this->run_ability( $id, [ 'expected_current_sha256' => 'not-a-hash' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_change_history_invalid', $result->get_error_code() );
		self::assertSame( 'Changed body', $this->post_field( 'post_content' ) );
		self::assertSame( [], $this->audit_rows() );
	}
}
