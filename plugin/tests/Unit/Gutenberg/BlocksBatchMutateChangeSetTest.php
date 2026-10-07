<?php
/**
 * Gutenberg blocks-batch-mutate returns a ChangeSetV1 built from its write receipt.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Gutenberg;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Gutenberg\BlocksBatchMutate;
use Stonewright\WpMcp\Security\ChangeSet;
use Stonewright\WpMcp\Security\IncidentStore;
use Stonewright\WpMcp\Tests\Unit\Security\ChangeSetAssertions;

require_once dirname( __DIR__ ) . '/Security/ChangeSetAssertions.php';

/**
 * @covers \Stonewright\WpMcp\Abilities\Gutenberg\BlocksBatchMutate
 */
final class BlocksBatchMutateChangeSetTest extends TestCase {
	use ChangeSetAssertions;

	protected function setUp(): void {
		$GLOBALS['stonewright_test_posts'] = [
			801 => (object) [
				'ID'           => 801,
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Block batch target',
				'post_content' => '<!-- wp:paragraph --><p>Before</p><!-- /wp:paragraph -->',
				'post_excerpt' => '',
				'meta'         => [],
			],
		];
		$GLOBALS['stonewright_test_post_meta_calls']       = [];
		$GLOBALS['stonewright_test_wpdb_inserts']          = [];
		$GLOBALS['stonewright_test_options']               = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_user_caps']             = [ 'edit_post' => true, 'edit_posts' => true ];
		$GLOBALS['stonewright_test_user_logged_in']        = true;
		$GLOBALS['stonewright_test_current_user_id']       = 42;
		$GLOBALS['stonewright_test_wp_update_post_return'] = null;
		$GLOBALS['stonewright_test_registered_blocks']     = [
			'core/paragraph' => (object) [
				'attributes'      => [ 'className' => [ 'type' => 'string' ] ],
				'render_callback' => static fn(): string => '',
				'is_dynamic'      => true,
			],
			'core/heading'   => (object) [
				'attributes'      => [ 'className' => [ 'type' => 'string' ], 'level' => [ 'type' => 'integer', 'enum' => [ 1, 2, 3, 4, 5, 6 ] ] ],
				'render_callback' => static fn(): string => '',
				'is_dynamic'      => true,
			],
			'vendor/alpha'   => (object) [
				'attributes' => [ 'title' => [ 'type' => 'string' ] ],
			],
		];
		IncidentStore::reset_for_tests();
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_posts']                 = [];
		$GLOBALS['stonewright_test_post_meta_calls']       = [];
		$GLOBALS['stonewright_test_wpdb_inserts']          = [];
		$GLOBALS['stonewright_test_options']               = [];
		$GLOBALS['stonewright_test_user_caps']             = [];
		$GLOBALS['stonewright_test_user_logged_in']        = false;
		$GLOBALS['stonewright_test_current_user_id']       = 0;
		$GLOBALS['stonewright_test_wp_update_post_return'] = null;
		unset( $GLOBALS['stonewright_test_registered_blocks'] );
		IncidentStore::reset_for_tests();
	}

	private function current_hash(): string {
		return hash( 'sha256', (string) $GLOBALS['stonewright_test_posts'][801]->post_content );
	}

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private function arguments( array $overrides = [] ): array {
		return array_merge(
			[
				'post_id'               => 801,
				'expected_content_hash' => $this->current_hash(),
				'change_set_id'         => 'change-blocks-1',
				'operations'            => [
					[ 'action' => 'update', 'path' => [ 0 ], 'attrs' => [ 'className' => 'updated' ] ],
					[ 'action' => 'insert', 'path' => [], 'position' => 1, 'block' => [ 'blockName' => 'core/heading', 'innerHTML' => '<h2>After</h2>' ] ],
				],
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

	public function test_a_verified_write_reports_what_it_planned_applied_and_hashed(): void {
		$result = ( new BlocksBatchMutate() )->execute( $this->arguments() );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$change_set = $result['change_set'];
		self::assertValidChangeSet( $change_set );
		self::assertSame( 'change-blocks-1', $change_set['change_set_id'] );
		self::assertSame( $result['write_receipt']['change_set_id'], $change_set['change_set_id'] );
		self::assertSame( [ 'update', 'insert' ], array_column( $change_set['planned'], 'action' ) );
		self::assertSame( [ 'block', 'block' ], array_column( $change_set['planned'], 'kind' ) );
		self::assertSame( [ '0', '1' ], array_column( $change_set['planned'], 'ref' ) );
		self::assertSame( $change_set['planned'], $change_set['applied'] );
		self::assertCount( $result['applied'], $change_set['applied'] );
		self::assertSame( [], $change_set['missing'] );
		self::assertSame( [], $change_set['unexpected'] );
		self::assertSame( $result['before_hash'], $change_set['before_hash'] );
		self::assertSame( $result['readback_hash'], $change_set['after_hash'] );
		self::assertNotSame( $change_set['before_hash'], $change_set['after_hash'] );
		self::assertSame( 'verified', $change_set['verification']['status'] );
		self::assertSame( $result['after_hash'], $change_set['verification']['evidence']['expected_hash'] );
		self::assertTrue( $change_set['rollback_available'] );
		self::assertSame( [ 'kind' => 'post_snapshot', 'ref' => $result['snapshot_id'], 'target' => '801' ], $change_set['rollback_recipe_ref'] );
		self::assertSame( 'mode_policy', $change_set['approval_reason'] );
		self::assertSame( 'change-blocks-1', $this->last_row()['change_set_id'] );
		self::assertSame( '', $this->last_row()['incident_id'] );
	}

	public function test_a_dry_run_only_plans(): void {
		$result = ( new BlocksBatchMutate() )->execute( $this->arguments( [ 'dry_run' => true ] ) );

		self::assertIsArray( $result );
		$change_set = $result['change_set'];
		self::assertValidChangeSet( $change_set );
		self::assertCount( 2, $change_set['planned'] );
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

	public function test_a_change_queued_for_the_browser_finalizer_is_not_claimed_as_applied(): void {
		$result = ( new BlocksBatchMutate() )->execute(
			$this->arguments(
				[
					'operations' => [ [ 'action' => 'insert', 'path' => [], 'position' => 1, 'block' => [ 'blockName' => 'vendor/alpha', 'attrs' => [ 'title' => 'A' ] ] ] ],
				]
			)
		);

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertTrue( $result['queued'] );
		$change_set = $result['change_set'];
		self::assertValidChangeSet( $change_set );
		self::assertCount( 1, $change_set['planned'] );
		self::assertSame( [], $change_set['applied'] );
		self::assertSame( [], $change_set['missing'] );
		self::assertSame( 'unverified', $change_set['verification']['status'] );
		self::assertSame( 'queued', $change_set['verification']['evidence']['outcome'] );
		self::assertFalse( $change_set['rollback_available'] );
		self::assertNull( $change_set['approval_reason'] );
	}

	public function test_a_failed_persistence_rolls_back_and_misses_every_planned_change(): void {
		$GLOBALS['stonewright_test_wp_update_post_return'] = new \WP_Error( 'database_failure', 'Database write failed.' );

		$result = ( new BlocksBatchMutate() )->execute( $this->arguments() );

		self::assertInstanceOf( \WP_Error::class, $result );
		$change_set = $result->get_error_data()['change_set'];
		self::assertValidChangeSet( $change_set );
		self::assertSame( 'change-blocks-1', $change_set['change_set_id'] );
		self::assertSame( 'failed', $change_set['verification']['status'] );
		self::assertSame( [], $change_set['applied'] );
		self::assertSame( $change_set['planned'], $change_set['missing'] );
		self::assertSame( 'succeeded', $change_set['verification']['evidence']['rollback_status'] );
		self::assertSame( 'database_failure', $change_set['verification']['evidence']['root_error_code'] );
		self::assertFalse( $change_set['rollback_available'], 'The snapshot was already restored.' );
		self::assertSame( 'mode_policy', $change_set['approval_reason'], 'The write ran before it was rolled back.' );
		self::assertSame( $this->current_hash(), $change_set['before_hash'] );
	}

	public function test_a_stale_plan_is_refused_before_anything_is_written(): void {
		$result = ( new BlocksBatchMutate() )->execute( $this->arguments( [ 'expected_content_hash' => hash( 'sha256', 'different content' ) ] ) );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_content_conflict', $result->get_error_code() );
		$change_set = $result->get_error_data()['change_set'];
		self::assertValidChangeSet( $change_set );
		self::assertSame( 'failed', $change_set['verification']['status'] );
		self::assertSame( $change_set['planned'], $change_set['missing'] );
		self::assertFalse( $change_set['rollback_available'] );
		self::assertNull( $change_set['approval_reason'] );
		self::assertSame( '', $change_set['after_hash'] );
	}

	public function test_a_repair_names_the_change_it_repairs(): void {
		$GLOBALS['stonewright_test_wp_update_post_return'] = new \WP_Error( 'database_failure', 'Database write failed.' );
		$failed = ( new BlocksBatchMutate() )->execute( $this->arguments() );
		self::assertInstanceOf( \WP_Error::class, $failed );
		$GLOBALS['stonewright_test_wp_update_post_return'] = null;

		$repair = ( new BlocksBatchMutate() )->execute( $this->arguments( [ 'change_set_id' => 'change-blocks-2', 'repair_of' => 'change-blocks-1' ] ) );

		self::assertIsArray( $repair, $repair instanceof \WP_Error ? $repair->get_error_message() : '' );
		self::assertSame( 'change-blocks-1', $repair['change_set']['repair_of'] );
		self::assertSame( 'verified', $repair['change_set']['verification']['status'] );
		self::assertSame( 'change-blocks-1', $this->last_row()['repair_of'] );
		self::assertSame( 'resolved', IncidentStore::recent()[0]['state'], 'The verified repair resolves the incident of the failed write.' );
	}

	public function test_the_ability_declares_the_lineage_inputs_and_the_change_set_output(): void {
		$ability = new BlocksBatchMutate();

		foreach ( ChangeSet::input_properties() as $name => $schema ) {
			self::assertSame( $schema, $ability->input_schema()['properties'][ $name ] );
		}
		self::assertSame( ChangeSet::output_property(), $ability->output_schema()['properties']['change_set'] );
	}
}
