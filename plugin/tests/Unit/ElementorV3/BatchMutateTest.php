<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ElementorV3;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\BatchMutate;
use Stonewright\WpMcp\Elementor\Schema\ContainerSchemaRepository;
use Stonewright\WpMcp\Elementor\Schema\WidgetSchemaRepository;
use Stonewright\WpMcp\Elementor\Write\TreeHasher;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\BatchMutate
 */
final class BatchMutateTest extends TestCase {

	protected function setUp(): void {
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
			9049 => (object) [
				'ID'           => 9049,
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Mixed target',
				'post_content' => '',
				'post_excerpt' => '',
				'meta'         => [
					'_elementor_data'      => wp_json_encode(
						[
							[
								'id'       => 'mixed-root',
								'elType'   => 'container',
								'settings' => [ 'container_type' => 'flex' ],
								'elements' => [
									[
										'id'         => 'atomic-child',
										'elType'     => 'widget',
										'widgetType' => 'e-paragraph',
										'settings'   => [],
										'elements'   => [],
									],
									[
										'id'       => 'v3-child',
										'elType'   => 'container',
										'settings' => [ 'container_type' => 'flex' ],
										'elements' => [],
									],
								],
							],
						]
					),
					'_elementor_edit_mode' => 'builder',
				],
			],
		];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_options'] = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_user_caps'] = [ 'edit_post' => true, 'edit_posts' => true ];
		$GLOBALS['stonewright_test_user_logged_in'] = true;
		$GLOBALS['stonewright_test_transients'] = [];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_posts'] = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_options'] = [];
		$GLOBALS['stonewright_test_user_caps'] = [];
		$GLOBALS['stonewright_test_user_logged_in'] = false;
		$GLOBALS['stonewright_test_transients'] = [];
	}

	public function test_batch_adds_updates_and_writes_elementor_data_once(): void {
		self::assertTrue( class_exists( BatchMutate::class ), 'BatchMutate ability must exist.' );

		$result = ( new BatchMutate() )->execute(
			[
				'post_id'    => 501,
				'operations' => [
					[
						'action'    => 'add_container',
						'op_id'     => 'inner',
						'parent_id' => 'root',
						'settings'  => [ 'layout' => 'flex', 'direction' => 'column' ],
					],
					[
						'action'      => 'add_widget',
						'op_id'       => 'headline',
						'parent_ref'  => 'inner',
						'widget_type' => 'heading',
						'settings'    => [ 'title' => 'Before' ],
					],
					[
						'action'      => 'update_element',
						'element_ref' => 'headline',
						'settings'    => [ 'title' => 'After' ],
					],
				],
			]
		);

		self::assertIsArray( $result );
		self::assertSame( 3, $result['applied'] );
		self::assertSame( 0, $result['failed'] );
		self::assertSame( $result['refs']['headline'], $result['items'][1]['element_id'] );
		self::assertGreaterThanOrEqual( 0.0, $result['metrics']['elapsed_ms'] );

		$data_writes = array_values(
			array_filter(
				$GLOBALS['stonewright_test_post_meta_calls'],
				static fn ( array $call ): bool => '_elementor_data' === $call['meta_key']
			)
		);
		$backups = array_values(
			array_filter(
				$GLOBALS['stonewright_test_post_meta_calls'],
				static fn ( array $call ): bool => '_stonewright_backups' === $call['meta_key']
			)
		);

		self::assertCount( 1, $data_writes, 'Batch must persist Elementor data once.');
		self::assertCount( 1, $backups, 'Batch must snapshot once.');

		$post = $GLOBALS['stonewright_test_posts'][501];
		$tree = json_decode( stripslashes( (string) $post->meta['_elementor_data'] ), true );
		self::assertSame( 'After', $tree[0]['elements'][0]['elements'][0]['settings']['title'] );
	}

	public function test_dry_run_returns_preview_without_backup_or_write(): void {
		self::assertTrue( class_exists( BatchMutate::class ), 'BatchMutate ability must exist.' );

		$result = ( new BatchMutate() )->execute(
			[
				'post_id' => 501,
				'dry_run' => true,
				'operations' => [
					[
						'action'    => 'add_container',
						'op_id'     => 'inner',
						'parent_id' => 'root',
					],
				],
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['dry_run'] );
		self::assertSame( 1, $result['applied'] );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );
		self::assertArrayHasKey( 'preview', $result );
	}

	public function test_mixed_document_rejects_unparented_root_add(): void {
		$result = ( new BatchMutate() )->execute(
			[
				'post_id'   => 9049,
				'dry_run'   => true,
				'operations' => [
					[
						'action'      => 'add_widget',
						'widget_type' => 'heading',
						'settings'    => [ 'title' => 'Unsafe root add' ],
					],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_mixed_root_add_blocked', $result->get_error_code() );
	}

	public function test_mixed_document_allows_add_inside_v3_only_parent(): void {
		$result = ( new BatchMutate() )->execute(
			[
				'post_id'   => 9049,
				'dry_run'   => true,
				'operations' => [
					[
						'action'      => 'add_widget',
						'parent_id'   => 'v3-child',
						'widget_type' => 'heading',
						'settings'    => [ 'title' => 'Safe surgical add' ],
					],
				],
			]
		);

		self::assertIsArray( $result );
		self::assertSame( 1, $result['applied'] );
	}

	public function test_dry_run_collects_all_schema_failures_without_writing(): void {
		$result = ( new BatchMutate() )->execute(
			[
				'post_id'    => 501,
				'dry_run'    => true,
				'operations' => [
					[ 'action' => 'add_widget', 'parent_id' => 'root', 'widget_type' => 'heading', 'settings' => [ 'made_up_one' => 'x' ] ],
					[ 'action' => 'add_widget', 'parent_id' => 'root', 'widget_type' => 'heading', 'settings' => [ 'made_up_two' => 'y' ] ],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_batch_operation_failed', $result->get_error_code() );
		self::assertSame( 2, $result->get_error_data()['failed'] );
		self::assertCount( 2, $result->get_error_data()['items'] );
		self::assertCount( 2, $result->get_error_data()['schema_requests'] );
		self::assertTrue( $result->get_error_data()['write_blocked'] );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );
	}

	public function test_write_with_continue_policy_is_still_atomic_on_validation_failure(): void {
		$result = ( new BatchMutate() )->execute(
			[
				'post_id'      => 501,
				'stop_on_error' => false,
				'operations'   => [
					[ 'action' => 'add_container', 'parent_id' => 'root' ],
					[ 'action' => 'add_widget', 'parent_id' => 'root', 'widget_type' => 'heading', 'settings' => [ 'made_up' => 'x' ] ],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 1, $result->get_error_data()['applied'] );
		self::assertSame( 1, $result->get_error_data()['failed'] );
		self::assertTrue( $result->get_error_data()['write_blocked'] );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );
	}

	public function test_widget_typography_alias_is_normalized_with_a_compact_warning(): void {
		$result = ( new BatchMutate() )->execute(
			[
				'post_id'    => 501,
				'dry_run'    => true,
				'operations' => [
					[
						'action'      => 'add_widget',
						'parent_id'   => 'root',
						'widget_type' => 'heading',
						'settings'    => [
							'title'     => 'Aliased',
							'font_size' => [ 'size' => 18, 'unit' => 'px' ],
						],
					],
				],
			]
		);

		self::assertIsArray( $result );
		self::assertArrayHasKey( 'typography_font_size', $result['preview'][0]['elements'][0]['settings'] );
		self::assertSame( 'font_size', $result['items'][0]['normalization_warnings'][0]['alias'] );
	}

	public function test_batch_normalizes_aliases_and_preserves_native_flex_settings(): void {
		self::assertTrue( class_exists( BatchMutate::class ), 'BatchMutate ability must exist.' );

		$result = ( new BatchMutate() )->execute(
			[
				'post_id'    => 501,
				'dry_run'    => true,
				'operations' => [
					[
						'action'    => 'add_container',
						'op_id'     => 'inner',
						'parent_id' => 'root',
						'settings'  => [
							'layout'       => 'flex',
							'direction'    => 'row',
							'flex_wrap'    => 'wrap',
							'_flex_size'   => 'custom',
							'_flex_grow'   => '1',
							'_flex_shrink' => '0',
						],
					],
					[
						'action'     => 'update_element',
						'element_id' => 'root',
						'settings'   => [
							'direction'  => 'row',
							'flex_wrap'  => 'wrap',
							'_flex_size' => 'grow',
						],
					],
				],
			]
		);

		self::assertIsArray( $result );
		$root_settings  = $result['preview'][0]['settings'];
		$inner_settings = $result['preview'][0]['elements'][0]['settings'];

		foreach ( [ $root_settings, $inner_settings ] as $settings ) {
			self::assertSame( 'flex', $settings['container_type'] );
			self::assertArrayHasKey( 'flex_direction', $settings );
			self::assertSame( 'row', $settings['flex_direction'] );
			self::assertArrayNotHasKey( 'direction', $settings );
			self::assertSame( 'wrap', $settings['flex_wrap'] );
		}
		self::assertSame( 'grow', $root_settings['_flex_size'] );
		self::assertSame( 'custom', $inner_settings['_flex_size'] );
		self::assertSame( '1', $inner_settings['_flex_grow'] );
		self::assertSame( '0', $inner_settings['_flex_shrink'] );
	}

	public function test_batch_rejects_update_when_normalization_produces_no_change(): void {
		$result = ( new BatchMutate() )->execute(
			[
				'post_id'    => 501,
				'dry_run'    => true,
				'operations' => [
					[
						'action'     => 'update_element',
						'element_id' => 'root',
						'settings'   => [ 'container_type' => 'flex' ],
					],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_batch_operation_failed', $result->get_error_code() );
		self::assertSame( 'stonewright_no_effective_changes', $result->get_error_data()['cause_code'] );
	}

	public function test_remove_requires_confirmation_in_production_safe_mode(): void {
		self::assertTrue( class_exists( BatchMutate::class ), 'BatchMutate ability must exist.' );
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';

		$result = ( new BatchMutate() )->execute(
			[
				'post_id'    => 501,
				'operations' => [
					[
						'action'     => 'remove_element',
						'element_id' => 'root',
					],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_confirmation_required', $result->get_error_code() );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );
	}

	public function test_batch_rejects_atomic_widget_with_actionable_diagnostics(): void {
		$result = ( new BatchMutate() )->execute(
			[
				'post_id' => 501,
				'dry_run' => true,
				'operations' => [
					[
						'action'      => 'add_widget',
						'parent_id'   => 'root',
						'widget_type' => 'e-heading',
						'settings'    => [ 'text' => 'Salut' ],
					],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_batch_operation_failed', $result->get_error_code() );
		self::assertSame( 0, $result->get_error_data()['failed_index'] );
		self::assertSame( 'add_widget', $result->get_error_data()['failed_action'] );
		self::assertSame( 'stonewright_atomic_widget_in_v3_batch', $result->get_error_data()['cause_code'] );
		self::assertStringContainsString( 'V4', $result->get_error_data()['repair'] );
	}

	public function test_batch_accepts_compact_aliases_matching_skill_examples(): void {
		$result = ( new BatchMutate() )->execute(
			[
				'post_id'    => 501,
				'dry_run'    => true,
				'operations' => [
					[
						'type'     => 'container',
						'op_id'    => 'inner',
						'parent'   => 'root',
						'settings' => [ 'layout' => 'flex' ],
					],
					[
						'type'     => 'widget',
						'op_id'    => 'headline',
						'parent'   => '@inner',
						'widget'   => 'heading',
						'settings' => [ 'title' => 'Before' ],
					],
					[
						'type'     => 'update',
						'target'   => '@headline',
						'settings' => [ 'title' => 'After' ],
					],
				],
			]
		);

		self::assertIsArray( $result );
		self::assertSame( 3, $result['applied'] );
		self::assertSame( 0, $result['failed'] );
		self::assertSame( 'After', $result['preview'][0]['elements'][0]['elements'][0]['settings']['title'] );
	}

	public function test_remove_alias_requires_confirmation_in_production_safe_mode(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';

		$result = ( new BatchMutate() )->execute(
			[
				'post_id'    => 501,
				'operations' => [
					[
						'type'   => 'remove',
						'target' => 'root',
					],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_confirmation_required', $result->get_error_code() );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );
	}

	public function test_write_returns_matching_compiled_and_readback_hashes(): void {
		$result = ( new BatchMutate() )->execute(
			[
				'post_id'    => 501,
				'operations' => [ [ 'action' => 'add_container', 'parent_id' => 'root' ] ],
			]
		);

		self::assertIsArray( $result );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $result['before_hash'] );
		self::assertSame( $result['after_hash'], $result['readback_hash'] );
		self::assertSame( TreeHasher::hash( ElementorData::read( 501 ) ), $result['readback_hash'] );
	}

	public function test_expected_tree_hash_blocks_stale_plan_before_backup(): void {
		$result = ( new BatchMutate() )->execute(
			[
				'post_id'           => 501,
				'expected_tree_hash' => str_repeat( '0', 64 ),
				'operations'         => [ [ 'action' => 'add_container', 'parent_id' => 'root' ] ],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_tree_conflict', $result->get_error_code() );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );
	}

	public function test_idempotency_replays_same_write_and_rejects_changed_input(): void {
		$input = [
			'post_id'         => 501,
			'idempotency_key' => 'batch-create-inner',
			'operations'      => [ [ 'action' => 'add_container', 'parent_id' => 'root' ] ],
		];
		$first = ( new BatchMutate() )->execute( $input );
		self::assertIsArray( $first );
		$write_count = count( $GLOBALS['stonewright_test_post_meta_calls'] );

		$replay = ( new BatchMutate() )->execute( $input );
		self::assertIsArray( $replay );
		self::assertTrue( $replay['idempotent_replay'] );
		self::assertSame( $write_count, count( $GLOBALS['stonewright_test_post_meta_calls'] ) );

		$conflict = ( new BatchMutate() )->execute(
			array_replace(
				$input,
				[ 'operations' => [ [ 'action' => 'add_container', 'parent_id' => 'root', 'position' => 0 ] ] ]
			)
		);
		self::assertInstanceOf( \WP_Error::class, $conflict );
		self::assertSame( 'stonewright_idempotency_conflict', $conflict->get_error_code() );

		$policy_conflict = ( new BatchMutate() )->execute( array_replace( $input, [ 'stop_on_error' => false ] ) );
		self::assertInstanceOf( \WP_Error::class, $policy_conflict );
		self::assertSame( 'stonewright_idempotency_conflict', $policy_conflict->get_error_code() );
	}

	public function test_strict_evidence_requires_live_schema_hash_for_every_setting(): void {
		$missing = ( new BatchMutate() )->execute(
			[
				'post_id'         => 501,
				'dry_run'         => true,
				'require_evidence' => true,
				'operations'      => [
					[
						'action'      => 'add_widget',
						'parent_id'   => 'root',
						'widget_type' => 'heading',
						'settings'    => [ 'title' => 'Evidence' ],
					],
				],
			]
		);
		self::assertInstanceOf( \WP_Error::class, $missing );
		self::assertSame( 'stonewright_batch_operation_failed', $missing->get_error_code() );
		self::assertSame( 'stonewright_elementor_evidence_invalid', $missing->get_error_data()['items'][0]['error']['code'] );

		$schema = WidgetSchemaRepository::get( 'heading' );
		self::assertIsArray( $schema );
		$valid = ( new BatchMutate() )->execute(
			[
				'post_id'         => 501,
				'dry_run'         => true,
				'require_evidence' => true,
				'operations'      => [
					[
						'action'            => 'add_widget',
						'parent_id'         => 'root',
						'widget_type'       => 'heading',
						'settings'          => [ 'title' => 'Evidence' ],
						'settings_evidence' => [
							'title' => [
								'schema_hash'          => $schema['schema_hash'],
								'source'               => 'figma:node/hero-title',
								'confidence'           => 0.99,
								'responsive_scope'     => 'desktop',
								'requires_confirmation' => false,
							],
						],
					],
				],
			]
		);
		self::assertIsArray( $valid );
		self::assertCount( 1, $valid['items'][0]['evidence'] );
	}

	public function test_strict_container_evidence_uses_the_structural_schema_hash(): void {
		$schema = ContainerSchemaRepository::get();
		self::assertIsArray( $schema );
		$evidence = static fn(): array => [
			'schema_hash'          => $schema['schema_hash'],
			'source'               => 'figma:node/hero-container',
			'confidence'           => 0.99,
			'responsive_scope'     => 'desktop',
			'requires_confirmation' => false,
		];

		$result = ( new BatchMutate() )->execute(
			[
				'post_id'          => 501,
				'dry_run'          => true,
				'require_evidence' => true,
				'operations'       => [
					[
						'action'            => 'add_container',
						'parent_id'         => 'root',
						'settings'          => [ 'container_type' => 'flex', 'flex_direction' => 'row' ],
						'settings_evidence' => [ 'container_type' => $evidence(), 'flex_direction' => $evidence() ],
					],
				],
			]
		);

		self::assertIsArray( $result );
		self::assertCount( 2, $result['items'][0]['evidence'] );
	}

	public function test_v3_edit_allowed_when_touched_subtree_is_pure_v3_despite_atomic_elsewhere(): void {
		$this->seed_post(
			777,
			[
				[
					'id' => 'sect1', 'elType' => 'container', 'settings' => [], 'elements' => [
						[ 'id' => 'txt1', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Old' ], 'elements' => [] ],
					],
				],
				[ 'id' => 'atom1', 'elType' => 'widget', 'widgetType' => 'e-heading', 'settings' => [], 'elements' => [] ],
			]
		);

		$result = ( new BatchMutate() )->execute(
			[
				'post_id'    => 777,
				'operations' => [
					[ 'action' => 'update_element', 'element_id' => 'txt1', 'settings' => [ 'title' => 'New' ] ],
				],
			]
		);

		self::assertIsArray( $result );
	}

	public function test_v3_edit_still_blocked_when_operation_targets_atomic_node(): void {
		$this->seed_post(
			778,
			[ [ 'id' => 'atom1', 'elType' => 'widget', 'widgetType' => 'e-heading', 'settings' => [], 'elements' => [] ] ]
		);

		$result = ( new BatchMutate() )->execute(
			[
				'post_id'    => 778,
				'operations' => [
					[ 'action' => 'update_element', 'element_id' => 'atom1', 'settings' => [ 'title' => 'x' ] ],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_v3_architecture_mismatch', $result->get_error_code() );
	}

	public function test_mobile_update_preserves_and_hashes_non_target_breakpoints(): void {
		$this->seed_post(
			779,
			[
				[
					'id'         => 'heading1',
					'elType'     => 'widget',
					'widgetType' => 'heading',
					'settings'   => [
						'title'                       => 'Desktop title',
						'typography_font_size_tablet' => [ 'size' => 30, 'unit' => 'px' ],
						'typography_font_size_mobile' => [ 'size' => 22, 'unit' => 'px' ],
					],
					'elements'   => [],
				],
			]
		);

		$result = ( new BatchMutate() )->execute(
			[
				'post_id'    => 779,
				'dry_run'    => true,
				'operations' => [
					[
						'action'              => 'update_element',
						'element_id'          => 'heading1',
						'allowed_breakpoints' => [ 'mobile' ],
						'settings'            => [ 'typography_font_size_mobile' => [ 'size' => 18, 'unit' => 'px' ] ],
					],
				],
			]
		);

		self::assertIsArray( $result );
		self::assertSame( [ 'mobile' ], $result['items'][0]['allowed_breakpoints'] );
		self::assertSame( $result['items'][0]['non_target_before_hash'], $result['items'][0]['non_target_after_hash'] );
		self::assertSame( 'Desktop title', $result['preview'][0]['settings']['title'] );
		self::assertSame( 30, $result['preview'][0]['settings']['typography_font_size_tablet']['size'] );
		self::assertSame( 18, $result['preview'][0]['settings']['typography_font_size_mobile']['size'] );
	}

	public function test_mobile_scope_rejects_desktop_key_before_write(): void {
		$this->seed_post(
			780,
			[ [ 'id' => 'heading1', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Old' ], 'elements' => [] ] ]
		);

		$result = ( new BatchMutate() )->execute(
			[
				'post_id'    => 780,
				'operations' => [
					[
						'action'              => 'update_element',
						'element_id'          => 'heading1',
						'allowed_breakpoints' => [ 'mobile' ],
						'settings'            => [ 'title' => 'Desktop leak' ],
					],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_responsive_scope_violation', $result->get_error_data()['cause_code'] );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );
	}

	public function test_mobile_replace_rejects_non_target_deletion(): void {
		$this->seed_post(
			781,
			[
				[
					'id'         => 'heading1',
					'elType'     => 'widget',
					'widgetType' => 'heading',
					'settings'   => [
						'title'                       => 'Desktop title',
						'typography_font_size_mobile' => [ 'size' => 22, 'unit' => 'px' ],
					],
					'elements'   => [],
				],
			]
		);

		$result = ( new BatchMutate() )->execute(
			[
				'post_id'    => 781,
				'operations' => [
					[
						'action'              => 'update_element',
						'element_id'          => 'heading1',
						'allowed_breakpoints' => [ 'mobile' ],
						'mode'                => 'replace',
						'settings'            => [ 'typography_font_size_mobile' => [ 'size' => 18, 'unit' => 'px' ] ],
					],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_responsive_scope_violation', $result->get_error_data()['cause_code'] );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );
	}

	/** @param array<int, array<string, mixed>> $tree */
	private function seed_post( int $post_id, array $tree ): void {
		$GLOBALS['stonewright_test_posts'][ $post_id ] = (object) [
			'ID'           => $post_id,
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_title'   => 'Architecture target',
			'post_content' => '',
			'post_excerpt' => '',
			'meta'         => [
				'_elementor_data'      => wp_json_encode( $tree ),
				'_elementor_edit_mode' => 'builder',
				'_elementor_version'   => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.0.0',
			],
		];
	}
}
