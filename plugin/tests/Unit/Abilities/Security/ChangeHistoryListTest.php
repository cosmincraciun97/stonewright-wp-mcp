<?php
/**
 * stonewright/change-history-list: short rows of the change ledger, filtered and paged.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Abilities\Security;

use Stonewright\WpMcp\Abilities\Security\ChangeHistoryList;
use Stonewright\WpMcp\Core\AbilityAnnotations;
use Stonewright\WpMcp\Security\ChangeRollback;

/**
 * @covers \Stonewright\WpMcp\Abilities\Security\ChangeHistoryList
 * @covers \Stonewright\WpMcp\Support\ChangeHistoryView
 */
final class ChangeHistoryListTest extends ChangeHistoryTestCase {

	private function ability(): ChangeHistoryList {
		return new ChangeHistoryList();
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	private function list( array $args = [] ): array {
		return $this->ok_result( $this->ability()->execute( $args ) );
	}

	/** Give the rows of the ledger the dates 1 Mar, 2 Mar ... of 2026, oldest first. */
	private function date_rows(): void {
		$table = $this->db->prefix . 'stonewright_changes';
		foreach ( array_keys( $this->db->tables[ $table ] ) as $index => $key ) {
			$this->db->tables[ $table ][ $key ]['created_at'] = sprintf( '2026-03-%02d 10:00:00', $index + 1 );
		}
	}

	// ---- the ability ---------------------------------------------------------------------------------------------

	public function test_it_is_a_read_only_security_ability_for_administrators(): void {
		$ability = $this->ability();

		self::assertSame( 'stonewright/change-history-list', $ability->name() );
		self::assertSame( 'security', $ability->category() );
		self::assertTrue( $ability->permission_callback( [] ) );
		$this->as_user_without_manage_options();
		self::assertFalse( $ability->permission_callback( [] ) );
		self::assertSame( [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ], array_intersect_key( AbilityAnnotations::for_ability( $ability ), [ 'readonly' => 1, 'destructive' => 1, 'idempotent' => 1 ] ) );
	}

	public function test_the_input_schema_is_strict_and_names_every_filter_of_the_page_and_the_paging(): void {
		$schema = $this->ability()->input_schema();

		self::assertFalse( $schema['additionalProperties'] );
		self::assertSame( [ 'family', 'resource', 'ability', 'actor', 'status', 'from', 'to', 'restorable', 'kind', 'page', 'per_page' ], array_keys( $schema['properties'] ) );
		self::assertContains( 'elementor', $schema['properties']['family']['enum'] );
		self::assertContains( 'woocommerce', $schema['properties']['family']['enum'] );
		self::assertSame( [ 'verified', 'rolled_back', 'incident', 'failed', 'unchecked' ], $schema['properties']['status']['enum'] );
		self::assertSame( [ 'change', 'rollback', 'redo', 'restore_point' ], $schema['properties']['kind']['enum'] );
		self::assertSame( 'boolean', $schema['properties']['restorable']['type'] );
		self::assertSame( [ 1, 100, 25 ], [ $schema['properties']['per_page']['minimum'], $schema['properties']['per_page']['maximum'], $schema['properties']['per_page']['default'] ] );
		self::assertSame( 1, $schema['properties']['page']['minimum'] );
		self::assertArrayNotHasKey( 'required', $schema );
	}

	public function test_the_output_schema_lists_the_short_row(): void {
		$schema = $this->ability()->output_schema();
		$row    = $schema['properties']['items']['items']['properties'];

		foreach ( [ 'change_id', 'time', 'kind', 'family', 'resource_type', 'resource_label', 'ability', 'actor', 'actor_name', 'status', 'summary', 'restorable', 'restorable_reason', 'parent_id', 'children' ] as $key ) {
			self::assertArrayHasKey( $key, $row, $key );
		}
		self::assertSame( [ 'ok', 'items', 'total', 'page', 'per_page', 'pages' ], $schema['required'] );
		self::assertArrayNotHasKey( 'before_ref', $row );
		self::assertArrayNotHasKey( 'after_ref', $row );
	}

	// ---- rows ----------------------------------------------------------------------------------------------------

	public function test_a_row_is_short_and_has_no_stored_content(): void {
		$id = $this->page_change( 31, 'Original body', 'Changed body', 'Home page' );

		$result = $this->list();

		self::assertSame( 1, $result['total'] );
		$row = $result['items'][0];
		self::assertSame( $id, $row['change_id'] );
		self::assertSame( [ 'change', 'post', 'post', 'Home page', 'stonewright/content-update-page', 'verified', true, '' ], [ $row['kind'], $row['family'], $row['resource_type'], $row['resource_label'], $row['ability'], $row['status'], $row['restorable'], $row['restorable_reason'] ] );
		self::assertSame( [ 7, 'user-7', '', 0 ], [ $row['actor'], $row['actor_name'], $row['parent_id'], $row['children'] ] );
		self::assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $row['time'] );
		self::assertSame( 'string', gettype( $row['summary'] ) );
		$json = (string) wp_json_encode( $result );
		foreach ( [ 'before_ref', 'after_ref', 'before_sha256', 'after_sha256', 'Original body', 'Changed body', '.gz' ] as $needle ) {
			self::assertStringNotContainsString( $needle, $json, 'The list holds no ' . $needle );
		}
	}

	public function test_a_rollback_is_linked_to_its_change_by_parent_id_and_the_change_counts_its_children(): void {
		$id   = $this->page_change( 31, 'Original body', 'Changed body' );
		$undo = ChangeRollback::run( $id );
		self::assertIsArray( $undo, $undo instanceof \WP_Error ? $undo->get_error_message() : '' );

		$items = [];
		foreach ( $this->list()['items'] as $row ) {
			$items[ $row['change_id'] ] = $row;
		}

		self::assertSame( 1, $items[ $id ]['children'] );
		self::assertSame( 'rolled_back_by', $items[ $id ]['status'] );
		self::assertSame( [ 'rollback', $id, 0 ], [ $items[ $undo['rollback_change_id'] ]['kind'], $items[ $undo['rollback_change_id'] ]['parent_id'], $items[ $undo['rollback_change_id'] ]['children'] ] );
	}

	public function test_a_row_that_cannot_be_restored_says_why(): void {
		$this->make_post( 31, [ 'post_content' => 'x' ] );
		$id = $this->post_change( fn () => $this->edit_post( 'post_content', 'y' ) );
		$this->db->tables[ $this->db->prefix . 'stonewright_changes' ][0]['restorable']        = 0;
		$this->db->tables[ $this->db->prefix . 'stonewright_changes' ][0]['restorable_reason'] = 'too_large';

		$row = $this->list( [ 'restorable' => false ] )['items'][0];

		self::assertSame( $id, $row['change_id'] );
		self::assertFalse( $row['restorable'] );
		self::assertSame( 'too_large', $row['restorable_reason'] );
	}

	public function test_a_long_summary_is_cut(): void {
		$this->page_change( 31, 'a', 'b' );
		$this->db->tables[ $this->db->prefix . 'stonewright_changes' ][0]['summary'] = str_repeat( 'long ', 200 );

		self::assertLessThanOrEqual( 300, mb_strlen( $this->list()['items'][0]['summary'] ) );
	}

	// ---- filters -------------------------------------------------------------------------------------------------

	public function test_the_filters_of_the_page_narrow_the_list(): void {
		$this->make_user( 7, [ 'user_login' => 'editor-seven' ] );
		$this->page_change( 31, 'a', 'b', 'Home page' );
		$this->theme_change();
		$this->page_change( 32, 'c', 'd', 'About page' );
		$this->date_rows();

		self::assertSame( 3, $this->list()['total'] );
		self::assertSame( [ 'theme_file' ], array_column( $this->list( [ 'family' => 'theme_file' ] )['items'], 'family' ) );
		self::assertSame( [ 'About page' ], array_column( $this->list( [ 'resource' => '32' ] )['items'], 'resource_label' ) );
		self::assertSame( 2, $this->list( [ 'ability' => 'stonewright/content-update-page' ] )['total'] );
		self::assertSame( 2, $this->list( [ 'ability' => 'content-update-page' ] )['total'], 'A bare ability name is taken as stonewright/<name>.' );
		self::assertSame( 3, $this->list( [ 'actor' => 7 ] )['total'] );
		self::assertSame( 3, $this->list( [ 'actor' => 'editor-seven' ] )['total'] );
		self::assertSame( 3, $this->list( [ 'status' => 'verified' ] )['total'] );
		self::assertSame( 0, $this->list( [ 'status' => 'rolled_back' ] )['total'] );
		self::assertSame( 2, $this->list( [ 'from' => '2026-03-02' ] )['total'] );
		self::assertSame( 2, $this->list( [ 'to' => '2026-03-02' ] )['total'] );
		self::assertSame( 1, $this->list( [ 'from' => '2026-03-02', 'to' => '2026-03-02' ] )['total'] );
		self::assertSame( 3, $this->list( [ 'restorable' => true ] )['total'] );
		self::assertSame( 0, $this->list( [ 'restorable' => false ] )['total'] );
		self::assertSame( 3, $this->list( [ 'kind' => 'change' ] )['total'] );
		self::assertSame( 0, $this->list( [ 'kind' => 'redo' ] )['total'] );
	}

	public function test_the_status_groups_of_the_page_match_every_status_they_stand_for(): void {
		$id = $this->page_change( 31, 'a', 'b' );
		$this->page_change( 32, 'c', 'd' );
		ChangeRollback::run( $id );

		self::assertSame( [ 'rolled_back_by' ], array_column( $this->list( [ 'status' => 'rolled_back' ] )['items'], 'status' ) );
		self::assertSame( 2, $this->list( [ 'status' => 'verified' ] )['total'], 'The other change and the rollback.' );
	}

	public function test_an_account_that_does_not_exist_matches_nothing_and_never_means_anyone(): void {
		$this->page_change( 31, 'a', 'b' );
		$GLOBALS['stonewright_test_missing_user_ids'] = [ 999 ];

		self::assertSame( 0, $this->list( [ 'actor' => 999 ] )['total'] );
		self::assertSame( [], $this->list( [ 'actor' => 999 ] )['items'] );
	}

	public function test_a_value_that_is_not_allowed_is_refused_and_never_widens_the_list(): void {
		$this->page_change( 31, 'a', 'b' );

		foreach ( [ [ 'family' => 'nonsense' ], [ 'status' => 'nonsense' ], [ 'kind' => 'nonsense' ], [ 'from' => 'yesterday' ], [ 'to' => '2026-13-45' ], [ 'per_page' => 0 ], [ 'per_page' => 101 ], [ 'page' => 0 ], [ 'ability' => 'Not An Ability!' ], [ 'restorable' => 'yes' ] ] as $args ) {
			$result = $this->ability()->execute( $args );
			self::assertInstanceOf( \WP_Error::class, $result, (string) wp_json_encode( $args ) );
			self::assertSame( 'stonewright_change_history_invalid', $result->get_error_code() );
		}
	}

	// ---- paging --------------------------------------------------------------------------------------------------

	public function test_the_list_is_paged_newest_first(): void {
		$ids = [];
		foreach ( [ 31, 32, 33, 34, 35 ] as $post ) {
			$ids[] = $this->page_change( $post, 'a', 'b' );
		}

		$first = $this->list( [ 'per_page' => 2 ] );
		self::assertSame( [ 5, 1, 2, 3 ], [ $first['total'], $first['page'], $first['per_page'], $first['pages'] ] );
		self::assertSame( [ $ids[4], $ids[3] ], array_column( $first['items'], 'change_id' ) );
		self::assertTrue( $first['has_more'] );

		$last = $this->list( [ 'per_page' => 2, 'page' => 3 ] );
		self::assertSame( [ $ids[0] ], array_column( $last['items'], 'change_id' ) );
		self::assertFalse( $last['has_more'] );

		self::assertSame( [], $this->list( [ 'per_page' => 2, 'page' => 9 ] )['items'], 'A page past the end is empty.' );
		self::assertSame( 25, $this->list()['per_page'] );
	}

	public function test_an_empty_ledger_is_an_empty_list(): void {
		$result = $this->list();

		self::assertSame( [ [], 0, 0 ], [ $result['items'], $result['total'], $result['pages'] ] );
		self::assertFalse( $result['has_more'] );
	}
}
