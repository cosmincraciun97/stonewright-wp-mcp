<?php
/**
 * Elementor V3 batch-mutate returns a ChangeSetV1 built from its write receipt.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ElementorV3;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\BatchMutate;
use Stonewright\WpMcp\Elementor\Schema\WidgetSchemaRepository;
use Stonewright\WpMcp\Security\ChangeSet;
use Stonewright\WpMcp\Security\ConfirmationToken;
use Stonewright\WpMcp\Security\IncidentStore;
use Stonewright\WpMcp\Tests\Unit\Security\ChangeSetAssertions;

require_once dirname( __DIR__ ) . '/Security/ChangeSetAssertions.php';

/**
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\BatchMutate
 */
final class BatchMutateChangeSetTest extends TestCase {
	use ChangeSetAssertions;

	protected function setUp(): void {
		WidgetSchemaRepository::reset_request_cache();
		$GLOBALS['stonewright_test_posts'] = [
			501 => (object) [
				'ID'           => 501,
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Batch target',
				'post_content' => '',
				'post_excerpt' => '',
				'meta'         => [
					'_elementor_data'      => '[{"id":"root","elType":"container","settings":{"container_type":"flex"},"elements":[]}]',
					'_elementor_edit_mode' => 'builder',
					'_elementor_version'   => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.0.0',
				],
			],
		];
		$GLOBALS['stonewright_test_post_meta_calls']  = [];
		$GLOBALS['stonewright_test_wpdb_inserts']     = [];
		$GLOBALS['stonewright_test_options']          = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_user_caps']        = [ 'edit_post' => true, 'edit_posts' => true ];
		$GLOBALS['stonewright_test_user_logged_in']   = true;
		$GLOBALS['stonewright_test_current_user_id']  = 1;
		$GLOBALS['stonewright_test_transients']       = [];
		IncidentStore::reset_for_tests();
	}

	protected function tearDown(): void {
		WidgetSchemaRepository::reset_request_cache();
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_transients']      = [];
		IncidentStore::reset_for_tests();
	}

	/** @return list<array<string, mixed>> */
	private static function three_operations(): array {
		return [
			[ 'action' => 'add_container', 'op_id' => 'inner', 'parent_id' => 'root', 'settings' => [ 'layout' => 'flex', 'direction' => 'column' ] ],
			[ 'action' => 'add_widget', 'op_id' => 'headline', 'parent_ref' => 'inner', 'widget_type' => 'heading', 'settings' => [ 'title' => 'Before' ] ],
			[ 'action' => 'update_element', 'element_ref' => 'headline', 'settings' => [ 'title' => 'After' ] ],
		];
	}

	/** @return array<string, mixed> The last audit row recorded. */
	private function last_row(): array {
		$rows = $GLOBALS['stonewright_test_wpdb_inserts'];
		self::assertNotEmpty( $rows );
		return end( $rows )['data'];
	}

	public function test_a_verified_write_reports_what_it_planned_applied_and_hashed(): void {
		$result = ( new BatchMutate() )->execute( [ 'post_id' => 501, 'operations' => self::three_operations() ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$change_set = $result['change_set'];
		self::assertValidChangeSet( $change_set );

		self::assertSame( $result['write_receipt']['change_set_id'], $change_set['change_set_id'], 'One identifier: the receipt, the response and the change set agree.' );
		self::assertSame( $result['change_set_id'], $change_set['change_set_id'] );
		self::assertSame( [ 'add_container', 'add_widget', 'update_element' ], array_column( $change_set['planned'], 'action' ) );
		self::assertSame( [ $result['refs']['inner'], $result['refs']['headline'], $result['refs']['headline'] ], array_column( $change_set['planned'], 'ref' ) );
		self::assertSame( [ 0, 1, 2 ], array_column( $change_set['planned'], 'index' ) );
		self::assertSame( $change_set['planned'], $change_set['applied'], 'Every planned change was confirmed by the readback.' );
		self::assertCount( $result['applied'], $change_set['applied'], 'The applied list agrees with the applied count.' );
		self::assertSame( [], $change_set['missing'] );
		self::assertSame( [], $change_set['unexpected'] );
		self::assertSame( $result['before_hash'], $change_set['before_hash'] );
		self::assertSame( $result['readback_hash'], $change_set['after_hash'] );
		self::assertNotSame( $change_set['before_hash'], $change_set['after_hash'] );
		self::assertSame( 'verified', $change_set['verification']['status'] );
		self::assertSame( 'readback_hash', $change_set['verification']['evidence']['method'] );
		self::assertSame( $result['write_receipt']['planned_hash'], $change_set['verification']['evidence']['expected_hash'] );
		self::assertSame( $result['readback_hash'], $change_set['verification']['evidence']['observed_hash'] );
		self::assertTrue( $change_set['rollback_available'] );
		self::assertSame( [ 'kind' => 'post_snapshot', 'ref' => $result['snapshot_id'], 'target' => '501' ], $change_set['rollback_recipe_ref'] );
		self::assertNull( $change_set['repair_of'] );
		self::assertSame( 'mode_policy', $change_set['approval_reason'] );
	}

	public function test_the_audit_row_carries_the_change_set_id_of_the_write(): void {
		$result = ( new BatchMutate() )->execute( [ 'post_id' => 501, 'operations' => self::three_operations() ] );

		self::assertIsArray( $result );
		self::assertSame( $result['change_set']['change_set_id'], $this->last_row()['change_set_id'] );
		self::assertSame( '', $this->last_row()['incident_id'], 'A successful write belongs to no incident.' );
	}

	public function test_a_dry_run_plans_without_claiming_an_effect(): void {
		$result = ( new BatchMutate() )->execute( [ 'post_id' => 501, 'dry_run' => true, 'operations' => self::three_operations() ] );

		self::assertIsArray( $result );
		$change_set = $result['change_set'];
		self::assertValidChangeSet( $change_set );
		self::assertCount( 3, $change_set['planned'] );
		self::assertSame( [], $change_set['applied'] );
		self::assertSame( [], $change_set['missing'] );
		self::assertSame( 'unverified', $change_set['verification']['status'] );
		self::assertSame( 'dry_run', $change_set['verification']['evidence']['outcome'] );
		self::assertSame( $result['before_hash'], $change_set['before_hash'] );
		self::assertSame( $result['after_hash'], $change_set['after_hash'], 'A dry run reports the hash the write would produce.' );
		self::assertFalse( $change_set['rollback_available'] );
		self::assertNull( $change_set['rollback_recipe_ref'] );
		self::assertNull( $change_set['approval_reason'] );
	}

	public function test_an_unchanged_request_is_planned_but_neither_applied_nor_missing(): void {
		$result = ( new BatchMutate() )->execute(
			[
				'post_id'    => 501,
				'operations' => [ [ 'action' => 'update_element', 'element_id' => 'root', 'settings' => [ 'container_type' => 'flex' ] ] ],
			]
		);

		self::assertIsArray( $result );
		$change_set = $result['change_set'];
		self::assertValidChangeSet( $change_set );
		self::assertCount( 1, $change_set['planned'] );
		self::assertSame( [], $change_set['applied'] );
		self::assertSame( [], $change_set['missing'] );
		self::assertSame( 'unverified', $change_set['verification']['status'] );
		self::assertSame( 'unchanged', $change_set['verification']['evidence']['outcome'] );
		self::assertSame( $change_set['before_hash'], $change_set['after_hash'] );
		self::assertSame( '', $this->last_row()['change_set_id'], 'A no-op write keeps its audit row free of a change set id.' );
		self::assertSame( [], $GLOBALS['wpdb']->incident_rows );
	}

	public function test_a_mixed_batch_applies_only_the_operations_that_change_state(): void {
		$result = ( new BatchMutate() )->execute(
			[
				'post_id'    => 501,
				'operations' => [
					[ 'action' => 'update_element', 'element_id' => 'root', 'settings' => [ 'container_type' => 'flex' ] ],
					[ 'action' => 'add_widget', 'parent_id' => 'root', 'widget_type' => 'heading', 'settings' => [ 'title' => 'Only real delta' ] ],
				],
			]
		);

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$change_set = $result['change_set'];
		self::assertValidChangeSet( $change_set );
		self::assertCount( 2, $change_set['planned'] );
		self::assertCount( 1, $change_set['applied'] );
		self::assertSame( 'add_widget', $change_set['applied'][0]['action'] );
		self::assertSame( $result['applied'], count( $change_set['applied'] ) );
		self::assertSame( [], $change_set['missing'] );
	}

	public function test_a_validation_failure_applies_nothing_and_misses_everything_planned(): void {
		$result = ( new BatchMutate() )->execute(
			[
				'post_id'    => 501,
				'operations' => [
					[ 'action' => 'add_widget', 'parent_id' => 'root', 'widget_type' => 'heading', 'settings' => [ 'title' => 'Fine' ] ],
					[ 'action' => 'update_element', 'element_id' => 'no-such-element', 'settings' => [ 'title' => 'Lost' ] ],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		$change_set = $result->get_error_data()['change_set'];
		self::assertValidChangeSet( $change_set );
		self::assertSame( 'failed', $change_set['verification']['status'] );
		self::assertCount( 2, $change_set['planned'] );
		self::assertSame( [], $change_set['applied'] );
		self::assertSame( $change_set['planned'], $change_set['missing'] );
		self::assertSame( $result->get_error_data()['write_receipt']['change_set_id'], $change_set['change_set_id'] );
		self::assertSame( '', $change_set['after_hash'], 'Nothing was written, so there is no state after the write.' );
		self::assertFalse( $change_set['rollback_available'] );
		self::assertNull( $change_set['approval_reason'] );
		self::assertNotSame( '', $change_set['verification']['evidence']['root_error_code'] );
		self::assertSame( $change_set['change_set_id'], $this->last_row()['change_set_id'] );
	}

	public function test_a_write_stopped_by_the_production_safe_gate_is_planned_and_not_applied(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';

		$result = ( new BatchMutate() )->execute( [ 'post_id' => 501, 'operations' => [ [ 'action' => 'remove_element', 'element_id' => 'root' ] ] ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_confirmation_required', $result->get_error_code() );
		$change_set = $result->get_error_data()['change_set'];
		self::assertValidChangeSet( $change_set );
		self::assertSame( 'unverified', $change_set['verification']['status'], 'A gate that stops a write is not a failed verification.' );
		self::assertSame( 'not_applied', $change_set['verification']['evidence']['outcome'] );
		self::assertSame( [ 'remove_element' ], array_column( $change_set['planned'], 'action' ) );
		self::assertSame( [], $change_set['applied'] );
		self::assertSame( $change_set['planned'], $change_set['missing'] );
		self::assertNull( $change_set['approval_reason'] );
	}

	public function test_a_write_under_production_safe_names_the_confirmation_token_as_its_approval(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		$arguments = [
			'post_id'    => 501,
			'operations' => [ [ 'action' => 'add_widget', 'parent_id' => 'root', 'widget_type' => 'heading', 'settings' => [ 'title' => 'Hello' ] ] ],
		];
		$token                           = ConfirmationToken::issue( 'stonewright/elementor-v3-batch-mutate', $arguments );
		$arguments['confirmation_token'] = $token;

		$result = ( new BatchMutate() )->execute( $arguments );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertSame( 'confirmation_token', $result['change_set']['approval_reason'] );
		self::assertStringNotContainsString( $token, (string) wp_json_encode( $result['change_set'] ) );
	}

	public function test_a_repair_passes_repair_of_through_to_the_change_set_and_the_audit_row(): void {
		$result = ( new BatchMutate() )->execute(
			[
				'post_id'       => 501,
				'repair_of'     => 'cs-failed-earlier',
				'supersedes'    => 'cs-replaced',
				'operations'    => self::three_operations(),
			]
		);

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertSame( 'cs-failed-earlier', $result['change_set']['repair_of'] );
		self::assertSame( 'cs-replaced', $result['change_set']['supersedes'] );
		self::assertValidChangeSet( $result['change_set'] );
		self::assertSame( 'cs-failed-earlier', $this->last_row()['repair_of'] );
	}

	public function test_the_ability_declares_the_lineage_inputs_and_the_change_set_output(): void {
		$ability = new BatchMutate();

		foreach ( ChangeSet::input_properties() as $name => $schema ) {
			self::assertSame( $schema, $ability->input_schema()['properties'][ $name ] );
		}
		self::assertSame( ChangeSet::output_property(), $ability->output_schema()['properties']['change_set'] );
	}

	public function test_a_verified_repair_with_the_same_resource_resolves_the_incident_of_the_failed_change(): void {
		$failed = ( new BatchMutate() )->execute(
			[
				'post_id'       => 501,
				'change_set_id' => 'cs-broken',
				'operations'    => [ [ 'action' => 'update_element', 'element_id' => 'no-such-element', 'settings' => [ 'title' => 'Lost' ] ] ],
			]
		);
		self::assertInstanceOf( \WP_Error::class, $failed );
		self::assertSame( 'cs-broken', $failed->get_error_data()['change_set']['change_set_id'] );
		$incidents = IncidentStore::recent();
		self::assertCount( 1, $incidents );
		self::assertNotSame( 'resolved', $incidents[0]['state'] );

		$repair = ( new BatchMutate() )->execute(
			[
				'post_id'       => 501,
				'change_set_id' => 'cs-fixed',
				'repair_of'     => 'cs-broken',
				'operations'    => [ [ 'action' => 'add_widget', 'parent_id' => 'root', 'widget_type' => 'heading', 'settings' => [ 'title' => 'Fixed' ] ] ],
			]
		);

		self::assertIsArray( $repair, $repair instanceof \WP_Error ? $repair->get_error_message() : '' );
		self::assertSame( 'verified', $repair['change_set']['verification']['status'] );
		self::assertSame( 'resolved', IncidentStore::recent()[0]['state'] );
		self::assertSame( $this->last_row()['event_id'], IncidentStore::recent()[0]['resolution_event_id'] );
		self::assertSame( '', $this->last_row()['incident_id'] );
	}
}
