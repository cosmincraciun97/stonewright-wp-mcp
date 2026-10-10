<?php
/**
 * stonewright/change-diff-get: the masked and capped diff of one change, and the summary of its undo plan.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Abilities\Security;

use Stonewright\WpMcp\Abilities\Security\ChangeDiffGet;
use Stonewright\WpMcp\Core\AbilityAnnotations;
use Stonewright\WpMcp\Security\ChangeLedger;
use Stonewright\WpMcp\Security\ChangeRollback;

/**
 * @covers \Stonewright\WpMcp\Abilities\Security\ChangeDiffGet
 * @covers \Stonewright\WpMcp\Support\ChangeHistoryView
 */
final class ChangeDiffGetTest extends ChangeHistoryTestCase {

	private function ability(): ChangeDiffGet {
		return new ChangeDiffGet();
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	private function get( string $id, array $args = [] ): array {
		return $this->ok_result( $this->ability()->execute( array_merge( [ 'change_id' => $id ], $args ) ) );
	}

	/** @return list<string> The text of every line in every hunk of the diff. */
	private function diff_lines( array $result ): array {
		$out = [];
		foreach ( $result['diff']['sections'] as $section ) {
			foreach ( $section['result']['hunks'] ?? [] as $hunk ) {
				foreach ( $hunk['lines'] as $line ) {
					$out[] = $line['op'] . ' ' . $line['text'];
				}
			}
		}
		return $out;
	}

	// ---- the ability ---------------------------------------------------------------------------------------------

	public function test_it_is_a_read_only_security_ability_for_administrators(): void {
		$ability = $this->ability();

		self::assertSame( 'stonewright/change-diff-get', $ability->name() );
		self::assertSame( 'security', $ability->category() );
		self::assertTrue( $ability->permission_callback( [] ) );
		$this->as_user_without_manage_options();
		self::assertFalse( $ability->permission_callback( [] ) );
		self::assertSame( [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ], array_intersect_key( AbilityAnnotations::for_ability( $ability ), [ 'readonly' => 1, 'destructive' => 1, 'idempotent' => 1 ] ) );
	}

	public function test_the_schemas_name_the_change_the_cap_and_the_plan(): void {
		$input  = $this->ability()->input_schema();
		$output = $this->ability()->output_schema();

		self::assertFalse( $input['additionalProperties'] );
		self::assertSame( [ 'change_id' ], $input['required'] );
		self::assertSame( [ 'change_id', 'max_lines' ], array_keys( $input['properties'] ) );
		self::assertSame( '^cs-[a-f0-9]{24}$', $input['properties']['change_id']['pattern'] );
		self::assertSame( [ 20, 2000, 400 ], [ $input['properties']['max_lines']['minimum'], $input['properties']['max_lines']['maximum'], $input['properties']['max_lines']['default'] ] );
		self::assertSame( [ 'ok', 'change', 'diff', 'plan' ], $output['required'] );
		foreach ( [ 'available', 'restorable', 'restorable_reason', 'kind', 'approval_required', 'approval_url', 'drift', 'drift_known', 'requires_force', 'already_restored', 'newer_changes', 'warnings', 'confirmation_required', 'would_apply', 'current_sha256', 'error_code', 'message' ] as $key ) {
			self::assertArrayHasKey( $key, $output['properties']['plan']['properties'], $key );
		}
	}

	public function test_a_person_without_manage_options_gets_nothing_even_when_the_ability_is_called_directly(): void {
		$id = $this->page_change( 31, 'Original body', 'Changed body' );
		$this->as_user_without_manage_options();

		$result = $this->ability()->execute( [ 'change_id' => $id ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_change_forbidden', $result->get_error_code() );
		self::assertStringNotContainsString( 'Changed body', (string) wp_json_encode( $result->get_error_data() ) );
	}

	// ---- the diff ------------------------------------------------------------------------------------------------

	public function test_the_diff_of_a_change_shows_its_lines_and_the_row_without_stored_content(): void {
		$id = $this->page_change( 31, 'Original body', 'Changed body', 'Home page' );

		$result = $this->get( $id );

		self::assertSame( 'ok', $result['diff']['status'] );
		self::assertTrue( $result['diff']['changed'] );
		self::assertSame( [ $id, 'post', 'Home page', 'verified' ], [ $result['change']['change_id'], $result['change']['family'], $result['change']['resource_label'], $result['change']['status'] ] );
		self::assertContains( 'del Original body', $this->diff_lines( $result ) );
		self::assertContains( 'add Changed body', $this->diff_lines( $result ) );
	}

	public function test_a_credential_in_an_image_is_masked_and_the_plan_says_the_change_cannot_be_undone(): void {
		$row = ChangeLedger::record( [ 'ability' => 'stonewright/settings-update', 'family' => 'option', 'resource_type' => 'option', 'resource_id' => 'example_mailer', 'before' => [ 'host' => 'a.example.test', 'password' => 'sentinel-OLD-12345678' ] ] );
		self::assertIsArray( $row );
		ChangeLedger::settle( $row['change_id'], [ 'status' => 'verified', 'after' => [ 'host' => 'b.example.test', 'password' => 'sentinel-NEW-87654321' ] ] );

		$result = $this->get( (string) $row['change_id'] );

		self::assertStringNotContainsString( 'sentinel-', (string) wp_json_encode( $result ) );
		self::assertTrue( $result['diff']['image_masked'] );
		self::assertFalse( $result['change']['restorable'] );
		self::assertSame( 'masked_secret', $result['change']['restorable_reason'] );
		self::assertFalse( $result['plan']['available'] );
		self::assertSame( 'stonewright_change_not_restorable', $result['plan']['error_code'] );
		self::assertFalse( $result['plan']['restorable'] );
	}

	public function test_a_diff_is_capped_by_max_lines_and_says_so(): void {
		$old = implode( "\n", array_map( static fn ( int $n ): string => 'line ' . $n, range( 1, 1500 ) ) );
		$new = implode( "\n", array_map( static fn ( int $n ): string => 'LINE ' . $n, range( 1, 1500 ) ) );
		$id  = $this->page_change( 31, $old, $new );

		$small = $this->get( $id, [ 'max_lines' => 40 ] );
		$full  = $this->get( $id );

		self::assertTrue( $small['diff']['truncated'] );
		self::assertLessThanOrEqual( 40, count( $this->diff_lines( $small ) ) );
		self::assertGreaterThan( 40, count( $this->diff_lines( $full ) ), 'The default cap shows more.' );
		self::assertLessThanOrEqual( 400, count( $this->diff_lines( $full ) ) );
	}

	public function test_no_stored_blob_name_hash_or_envelope_is_ever_returned(): void {
		$id = $this->page_change( 31, 'Original body', 'Changed body' );

		$json = (string) wp_json_encode( $this->get( $id ) );

		foreach ( [ 'before_ref', 'after_ref', 'before_sha256', 'after_sha256', '"kind":"data"', '{"v":1', '.gz' ] as $needle ) {
			self::assertStringNotContainsString( $needle, $json, $needle );
		}
		self::assertDoesNotMatchRegularExpression( '/\b[a-f0-9]{64}\b/', $json, 'No full hash, so no blob name.' );
	}

	// ---- the plan ------------------------------------------------------------------------------------------------

	public function test_the_plan_of_a_clean_change_can_be_run_without_force_or_a_person(): void {
		$id = $this->page_change( 31, 'Original body', 'Changed body' );

		$plan = $this->get( $id )['plan'];

		self::assertTrue( $plan['available'] );
		self::assertSame( [ true, 'rollback', false, false, false, false, false ], [ $plan['restorable'], $plan['kind'], $plan['approval_required'], $plan['drift'], $plan['requires_force'], $plan['already_restored'], $plan['confirmation_required'] ] );
		self::assertTrue( $plan['drift_known'] );
		self::assertSame( [], $plan['newer_changes'] );
		self::assertSame( [], $plan['warnings'] );
		self::assertNotSame( '', $plan['would_apply'] );
		self::assertSame( 32, strlen( $plan['current_sha256'] ), 'Only the start of the hash, as the Changes page shows it.' );
		self::assertArrayNotHasKey( 'diff', $plan, 'The diff is the diff key of the answer, not repeated in the plan.' );
	}

	public function test_a_later_edit_is_drift_and_a_later_change_is_listed(): void {
		$first = $this->page_change( 31, 'Original body', 'Changed body' );
		$this->edit_post( 'post_content', 'Edited by hand' );

		$plan = $this->get( $first )['plan'];
		self::assertTrue( $plan['drift'] );
		self::assertTrue( $plan['requires_force'] );
		self::assertNotSame( [], $plan['warnings'] );

		$second = $this->post_change( fn () => $this->edit_post( 'post_content', 'Changed again' ) );
		$plan   = $this->get( $first )['plan'];
		self::assertSame( [ $second ], array_column( $plan['newer_changes'], 'change_id' ) );
		self::assertSame( [ 'change_id', 'ability', 'since' ], array_keys( $plan['newer_changes'][0] ) );
	}

	public function test_a_change_to_code_needs_an_administrator_and_the_plan_names_the_page(): void {
		$id = $this->theme_change();

		$plan = $this->get( $id )['plan'];

		self::assertTrue( $plan['approval_required'] );
		self::assertStringContainsString( 'stonewright-changes', $plan['approval_url'] );
		self::assertStringContainsString( $id, $plan['approval_url'] );
		self::assertContains( 'add // version two', array_map( static fn ( string $line ): string => $line, $this->diff_lines( $this->get( $id ) ) ) );
	}

	public function test_the_plan_of_a_rollback_row_is_a_redo_and_that_of_a_rolled_back_change_points_at_it(): void {
		$id   = $this->page_change( 31, 'Original body', 'Changed body' );
		$undo = ChangeRollback::run( $id );
		self::assertIsArray( $undo, $undo instanceof \WP_Error ? $undo->get_error_message() : '' );

		$rollback = $this->get( $undo['rollback_change_id'] );
		self::assertSame( 'redo', $rollback['plan']['kind'] );
		self::assertTrue( $rollback['plan']['available'] );

		$change = $this->get( $id )['plan'];
		self::assertFalse( $change['available'] );
		self::assertSame( 'stonewright_change_already_rolled_back', $change['error_code'] );
		self::assertSame( $undo['rollback_change_id'], $change['redo_change_id'] );
	}

	public function test_the_plan_in_production_safe_mode_says_a_token_is_needed(): void {
		$id = $this->page_change( 31, 'Original body', 'Changed body' );
		$this->production_safe();

		self::assertTrue( $this->get( $id )['plan']['confirmation_required'] );
	}

	// ---- bad input -----------------------------------------------------------------------------------------------

	public function test_an_id_that_is_not_a_change_id_or_is_not_known_is_refused(): void {
		$this->page_change( 31, 'a', 'b' );

		$bad = $this->ability()->execute( [ 'change_id' => '../etc/passwd' ] );
		self::assertInstanceOf( \WP_Error::class, $bad );
		self::assertSame( 'stonewright_change_invalid_id', $bad->get_error_code() );

		$unknown = $this->ability()->execute( [ 'change_id' => 'cs-' . str_repeat( 'a', 24 ) ] );
		self::assertInstanceOf( \WP_Error::class, $unknown );
		self::assertSame( 'stonewright_change_not_found', $unknown->get_error_code() );
	}

	public function test_a_cap_outside_its_bounds_is_refused(): void {
		$id = $this->page_change( 31, 'a', 'b' );

		foreach ( [ 19, 2001, 'many' ] as $cap ) {
			$result = $this->ability()->execute( [ 'change_id' => $id, 'max_lines' => $cap ] );
			self::assertInstanceOf( \WP_Error::class, $result, (string) $cap );
			self::assertSame( 'stonewright_change_history_invalid', $result->get_error_code() );
		}
	}
}
