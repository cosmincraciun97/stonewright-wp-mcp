<?php
/**
 * Elementor V4 update-node returns a ChangeSetV1 built from the Elementor write receipt.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV4\UpdateNode;
use Stonewright\WpMcp\Elementor\V4\AtomicSchemaRepository;
use Stonewright\WpMcp\Elementor\V4\V4FeatureGate;
use Stonewright\WpMcp\Security\ChangeSet;
use Stonewright\WpMcp\Security\IncidentStore;
use Stonewright\WpMcp\Tests\Unit\Security\ChangeSetAssertions;

require_once dirname( __DIR__ ) . '/Security/ChangeSetAssertions.php';

/**
 * @covers \Stonewright\WpMcp\Abilities\ElementorV4\UpdateNode
 */
final class V4UpdateNodeChangeSetTest extends TestCase {
	use ChangeSetAssertions;

	private const POST = 9201;

	protected function setUp(): void {
		AtomicSchemaRepository::invalidate();
		$GLOBALS['stonewright_test_options'] = [
			'stonewright_mode'                => 'development',
			'stonewright_elementor_v4_atomic' => true,
		];
		V4FeatureGate::set_atomic_module_present_for_tests( true );
		$GLOBALS['stonewright_test_user_caps']       = [ 'edit_post' => true, 'edit_posts' => true ];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		IncidentStore::reset_for_tests();

		$tree = [
			[
				'id'              => 'a000001',
				'version'         => '0.0',
				'elType'          => 'e-div-block',
				'isInner'         => false,
				'settings'        => [],
				'editor_settings' => [],
				'interactions'    => [],
				'styles'          => [],
				'elements'        => [
					[
						'id'              => 'heading1',
						'version'         => '0.0',
						'elType'          => 'widget',
						'widgetType'      => 'e-heading',
						'isInner'         => false,
						'settings'        => [
							'title' => [ '$$type' => 'html-v3', 'value' => [ 'content' => [ '$$type' => 'string', 'value' => 'Hello' ], 'children' => [] ] ],
							'tag'   => [ '$$type' => 'string', 'value' => 'h2' ],
						],
						'editor_settings' => [],
						'interactions'    => [],
						'styles'          => [],
						'elements'        => [],
					],
				],
			],
		];
		$GLOBALS['stonewright_test_posts'] = [
			self::POST => (object) [
				'ID'           => self::POST,
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'V4 pure',
				'post_content' => '',
				'post_excerpt' => '',
				'meta'         => [
					'_elementor_data'      => wp_json_encode( $tree ),
					'_elementor_edit_mode' => 'builder',
					'_elementor_version'   => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.0.0',
				],
			],
		];
	}

	protected function tearDown(): void {
		V4FeatureGate::set_atomic_module_present_for_tests( null );
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		AtomicSchemaRepository::invalidate();
		IncidentStore::reset_for_tests();
	}

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private static function arguments( array $overrides = [] ): array {
		return array_merge(
			[
				'post_id'    => self::POST,
				'element_id' => 'heading1',
				'settings'   => [ 'tag' => [ '$$type' => 'heading-level', 'value' => 'h3' ] ],
				'mode'       => 'merge',
			],
			$overrides
		);
	}

	/** @return array<string, mixed> */
	private function last_row(): array {
		$rows = $GLOBALS['stonewright_test_wpdb_inserts'];
		self::assertNotEmpty( $rows );
		return end( $rows )['data'];
	}

	public function test_a_verified_node_write_reports_the_planned_change_hashes_and_snapshot(): void {
		$result = ( new UpdateNode() )->execute( self::arguments() );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$change_set = $result['change_set'];
		self::assertValidChangeSet( $change_set );
		self::assertSame( $result['write_receipt']['change_set_id'], $change_set['change_set_id'] );
		self::assertSame( [ [ 'kind' => 'element', 'ref' => 'heading1', 'action' => 'update_settings', 'index' => 0 ] ], $change_set['planned'] );
		self::assertSame( $change_set['planned'], $change_set['applied'] );
		self::assertSame( [], $change_set['missing'] );
		self::assertSame( [], $change_set['unexpected'] );
		self::assertSame( $result['write_receipt']['before_hash'], $change_set['before_hash'] );
		self::assertSame( $result['write_receipt']['readback_hash'], $change_set['after_hash'] );
		self::assertNotSame( $change_set['before_hash'], $change_set['after_hash'] );
		self::assertSame( 'verified', $change_set['verification']['status'] );
		self::assertSame( 'v4', $change_set['verification']['evidence']['architecture'] );
		self::assertTrue( $change_set['rollback_available'] );
		self::assertSame( [ 'kind' => 'post_snapshot', 'ref' => $result['snapshot_id'], 'target' => (string) self::POST ], $change_set['rollback_recipe_ref'] );
		self::assertSame( 'mode_policy', $change_set['approval_reason'] );
		self::assertSame( $change_set['change_set_id'], $this->last_row()['change_set_id'] );
	}

	public function test_a_node_write_that_changes_nothing_is_unverified_and_not_applied(): void {
		$same_title = [ '$$type' => 'html-v3', 'value' => [ 'content' => [ '$$type' => 'string', 'value' => 'Hello' ], 'children' => [] ] ];
		$result     = ( new UpdateNode() )->execute( self::arguments( [ 'settings' => [ 'title' => $same_title ] ] ) );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$change_set = $result['change_set'];
		self::assertValidChangeSet( $change_set );
		self::assertSame( 'unchanged', $change_set['verification']['evidence']['outcome'] );
		self::assertSame( 'unverified', $change_set['verification']['status'] );
		self::assertSame( [], $change_set['applied'] );
		self::assertCount( 1, $change_set['planned'] );
		self::assertSame( $change_set['before_hash'], $change_set['after_hash'] );
	}

	public function test_a_dry_run_only_plans(): void {
		$result = ( new UpdateNode() )->execute( self::arguments( [ 'dry_run' => true ] ) );

		self::assertIsArray( $result );
		$change_set = $result['change_set'];
		self::assertValidChangeSet( $change_set );
		self::assertCount( 1, $change_set['planned'] );
		self::assertSame( [], $change_set['applied'] );
		self::assertSame( [], $change_set['missing'] );
		self::assertSame( 'unverified', $change_set['verification']['status'] );
		self::assertSame( 'dry_run', $change_set['verification']['evidence']['outcome'] );
		self::assertFalse( $change_set['rollback_available'] );
		self::assertNull( $change_set['approval_reason'] );
	}

	public function test_a_dry_run_and_a_write_of_different_settings_do_not_share_an_id(): void {
		$first  = ( new UpdateNode() )->execute( self::arguments( [ 'dry_run' => true ] ) );
		$second = ( new UpdateNode() )->execute( self::arguments( [ 'dry_run' => true, 'settings' => [ 'tag' => [ '$$type' => 'heading-level', 'value' => 'h4' ] ] ] ) );

		self::assertIsArray( $first );
		self::assertIsArray( $second );
		self::assertNotSame( $first['change_set']['change_set_id'], $second['change_set']['change_set_id'] );
	}

	public function test_a_failed_node_write_misses_the_planned_change(): void {
		$result = ( new UpdateNode() )->execute( self::arguments( [ 'element_id' => 'missing-id' ] ) );

		self::assertInstanceOf( \WP_Error::class, $result );
		$change_set = $result->get_error_data()['change_set'];
		self::assertValidChangeSet( $change_set );
		self::assertSame( 'failed', $change_set['verification']['status'] );
		self::assertSame( [], $change_set['applied'] );
		self::assertSame( $change_set['planned'], $change_set['missing'] );
		self::assertSame( 'stonewright_element_not_found', $change_set['verification']['evidence']['root_error_code'] );
		self::assertFalse( $change_set['rollback_available'] );
		self::assertNull( $change_set['approval_reason'] );
	}

	public function test_a_repair_names_the_change_it_repairs(): void {
		$failed = ( new UpdateNode() )->execute( self::arguments( [ 'element_id' => 'missing-id' ] ) );
		self::assertInstanceOf( \WP_Error::class, $failed );
		$failed_id = $failed->get_error_data()['change_set']['change_set_id'];

		$repair = ( new UpdateNode() )->execute( self::arguments( [ 'repair_of' => $failed_id ] ) );

		self::assertIsArray( $repair, $repair instanceof \WP_Error ? $repair->get_error_message() : '' );
		self::assertSame( $failed_id, $repair['change_set']['repair_of'] );
		self::assertValidChangeSet( $repair['change_set'] );
		self::assertSame( $failed_id, $this->last_row()['repair_of'] );
	}

	public function test_the_ability_declares_the_lineage_inputs_and_the_change_set_output(): void {
		$ability = new UpdateNode();

		foreach ( ChangeSet::input_properties() as $name => $schema ) {
			self::assertSame( $schema, $ability->input_schema()['properties'][ $name ] );
		}
		self::assertSame( ChangeSet::output_property(), $ability->output_schema()['properties']['change_set'] );
	}
}
