<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ElementorV3;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\BatchMutate;
use Stonewright\WpMcp\Elementor\Schema\ContainerSchemaRepository;
use Stonewright\WpMcp\Elementor\Schema\ResponsiveScope;
use Stonewright\WpMcp\Elementor\Schema\WidgetSchemaRepository;
use Stonewright\WpMcp\Elementor\Write\PostWriteLock;
use Stonewright\WpMcp\Elementor\Write\TreeHasher;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\BatchMutate
 */
final class BatchMutateTest extends TestCase {
	private object $original_elementor;

	protected function setUp(): void {
		$this->original_elementor = \Elementor\Plugin::$instance;
		$base_manager = $this->original_elementor->widgets_manager;
		\Elementor\Plugin::$instance = (object) array_merge(
			(array) $this->original_elementor,
			[
				'widgets_manager' => new class( $base_manager ) {
					public function __construct( private object $base ) {
					}
					public function get_widget_types( ?string $name = null ): array|object|null {
						if ( 'form' === $name ) {
							return new BatchFormWidgetForTest();
						}
						if ( 'posts' === $name ) {
							return new BatchFixedControlWidgetForTest();
						}
						if ( null === $name ) {
							$widgets = (array) $this->base->get_widget_types();
							$widgets['form'] = new BatchFormWidgetForTest();
							$widgets['posts'] = new BatchFixedControlWidgetForTest();
							return $widgets;
						}
						return $this->base->get_widget_types( $name );
					}
				},
			]
		);
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
			502 => (object) [
				'ID'           => 502,
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Form batch target',
				'post_content' => '',
				'post_excerpt' => '',
				'meta'         => [
					'_elementor_data' => wp_json_encode(
						[
							[
								'id' => 'form-root', 'elType' => 'container', 'settings' => [ 'container_type' => 'flex' ],
								'elements' => [
									[
										'id' => 'form-widget', 'elType' => 'widget', 'widgetType' => 'form', 'elements' => [],
										'settings' => [
											'form_fields' => [
												[ 'custom_id' => 'email', '_id' => 'row-a', 'field_label' => 'Email', 'field_type' => 'email', 'newsman_mapping' => 'subscriber_email' ],
												[ 'custom_id' => 'name', '_id' => 'row-b', 'field_label' => 'Name', 'field_type' => 'text' ],
											],
											'actions_after_submit' => [ 'email', 'newsman' ],
											'email_to' => 'team@example.test',
											'newsman_list' => 'list-1',
										],
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
		\Elementor\Plugin::$instance = $this->original_elementor;
		WidgetSchemaRepository::reset_request_cache();
		$GLOBALS['stonewright_test_posts'] = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_options'] = [];
		$GLOBALS['stonewright_test_user_caps'] = [];
		$GLOBALS['stonewright_test_user_logged_in'] = false;
		$GLOBALS['stonewright_test_transients'] = [];
		unset( $GLOBALS['stonewright_test_after_add_option'] );
		unset( $GLOBALS['stonewright_test_update_post_meta_return'] );
	}

	public function test_surgical_repeater_patch_preserves_newsman_actions_and_unknown_row_fields(): void {
		$result = ( new BatchMutate() )->execute(
			[
				'post_id' => 502,
				'dry_run' => true,
				'operations' => [
					[
						'action' => 'patch_repeater_row',
						'element_id' => 'form-widget',
						'repeater_key' => 'form_fields',
						'selector' => [ 'custom_id' => 'email' ],
						'row_patch' => [ 'field_label' => 'Business email' ],
					],
				],
			]
		);

		self::assertIsArray( $result );
		$item = $result['items'][0];
		self::assertSame( 'patch_repeater_row', $item['action'] );
		self::assertSame( $item['preservation']['unknown_fields_hash_before'], $item['preservation']['unknown_fields_hash_after'] );
		self::assertSame( $item['preservation']['actions_after_submit_hash_before'], $item['preservation']['actions_after_submit_hash_after'] );
		$settings = $result['preview'][0]['elements'][0]['settings'];
		self::assertSame( 'Business email', $settings['form_fields'][0]['field_label'] );
		self::assertSame( 'subscriber_email', $settings['form_fields'][0]['newsman_mapping'] );
		self::assertSame( [ 'email', 'newsman' ], $settings['actions_after_submit'] );
	}

	public function test_full_form_repeater_replace_requires_explicit_dry_run_and_bound_hash(): void {
		$operation = [
			'action' => 'update_element',
			'element_id' => 'form-widget',
			'settings' => [
				'form_fields' => [ [ 'custom_id' => 'email', '_id' => 'row-a', 'field_label' => 'Only email', 'field_type' => 'email' ] ],
			],
		];
		$blocked = ( new BatchMutate() )->execute( [ 'post_id' => 502, 'dry_run' => true, 'operations' => [ $operation ] ] );
		self::assertInstanceOf( \WP_Error::class, $blocked );
		self::assertSame( 'stonewright_third_party_replace_blocked', $blocked->get_error_data()['cause_code'] );

		$operation['allow_high_risk_replace'] = true;
		$planned = ( new BatchMutate() )->execute( [ 'post_id' => 502, 'dry_run' => true, 'operations' => [ $operation ] ] );
		self::assertIsArray( $planned );
		$hash = $planned['items'][0]['third_party_risk']['preservation_hash_before'];
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $hash );

		$operation['approved_preservation_hash'] = $hash;
		$applied = ( new BatchMutate() )->execute( [ 'post_id' => 502, 'operations' => [ $operation ] ] );
		self::assertIsArray( $applied );
		self::assertSame( 'verified', $applied['verification_status'] );
		self::assertTrue( $applied['items'][0]['unknown_setting_removal_approved'] );
		$stored = ElementorData::read( 502 );
		self::assertSame( 'Only email', $stored[0]['elements'][0]['settings']['form_fields'][0]['field_label'] );
		self::assertArrayNotHasKey( 'newsman_mapping', $stored[0]['elements'][0]['settings']['form_fields'][0] );
	}

	public function test_high_risk_apply_rejects_mismatched_preservation_hash(): void {
		$result = ( new BatchMutate() )->execute(
			[
				'post_id' => 502,
				'operations' => [
					[
						'action' => 'update_element',
						'element_id' => 'form-widget',
						'settings' => [ 'form_fields' => [ [ 'custom_id' => 'email', '_id' => 'row-a', 'field_label' => 'Only email', 'field_type' => 'email' ] ] ],
						'allow_high_risk_replace' => true,
						'approved_preservation_hash' => str_repeat( '0', 64 ),
					],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_third_party_replace_blocked', $result->get_error_data()['cause_code'] );
	}

	public function test_merge_cannot_enable_unknown_setting_removal(): void {
		$result = ( new BatchMutate() )->execute(
			[
				'post_id' => 502,
				'dry_run' => true,
				'operations' => [
					[
						'action' => 'update_element',
						'element_id' => 'form-widget',
						'settings' => [ 'email_to' => 'ops@example.test' ],
						'allow_high_risk_replace' => true,
						'approved_preservation_hash' => str_repeat( '0', 64 ),
					],
				],
			]
		);

		self::assertIsArray( $result );
		self::assertArrayNotHasKey( 'unknown_setting_removal_approved', $result['items'][0] );
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
		self::assertSame( 'stonewright/elementor-css-regenerate', $result['next_step']['tool'] );
		self::assertSame( 'stonewright/elementor-post-write-verify', $result['next_step']['then'] );
		self::assertTrue( (bool) ( $result['post_write']['element_cache']['deleted'] ?? false ) );

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

	public function test_write_returns_busy_without_mutation_when_page_is_locked(): void {
		PostWriteLock::acquire( 501, 'other-transaction', 30 );

		$result = ( new BatchMutate() )->execute(
			[
				'post_id'    => 501,
				'operations' => [
					[
						'action'      => 'add_widget',
						'parent_id'   => 'root',
						'widget_type' => 'heading',
						'settings'    => [ 'title' => 'Blocked write' ],
					],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_elementor_write_busy', $result->get_error_code() );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );
	}

	public function test_write_rechecks_page_after_acquiring_lock(): void {
		$intervening_tree = [
			[
				'id'       => 'root',
				'elType'   => 'container',
				'settings' => [ 'container_type' => 'flex' ],
				'elements' => [
					[
						'id'         => 'external',
						'elType'     => 'widget',
						'widgetType' => 'heading',
						'settings'   => [ 'title' => 'Intervening write' ],
						'elements'   => [],
					],
				],
			],
		];
		$GLOBALS['stonewright_test_after_add_option'] = static function () use ( $intervening_tree ): void {
			$GLOBALS['stonewright_test_posts'][501]->meta['_elementor_data'] = wp_json_encode( $intervening_tree );
		};

		$result = ( new BatchMutate() )->execute(
			[
				'post_id'    => 501,
				'operations' => [
					[
						'action'      => 'add_widget',
						'parent_id'   => 'root',
						'widget_type' => 'heading',
						'settings'    => [ 'title' => 'Stale batch' ],
					],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_elementor_revision_conflict', $result->get_error_code() );
		self::assertSame( TreeHasher::hash( $intervening_tree ), $result->get_error_data()['current_tree_hash'] );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );
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

	public function test_identical_valid_update_is_unchanged_without_rewrite(): void {
		$GLOBALS['wpdb']->incident_rows = [];
		$GLOBALS['stonewright_test_wpdb_inserts'] = [];

		$result = ( new BatchMutate() )->execute(
			[
				'post_id'    => 501,
				'operations' => [
					[
						'action'     => 'update_element',
						'element_id' => 'root',
						'settings'   => [ 'container_type' => 'flex' ],
					],
				],
			]
		);

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertTrue( $result['items'][0]['unchanged'] ?? false );
		self::assertSame( 0, $result['applied'] );
		self::assertSame( $result['before_hash'], $result['after_hash'] );
		self::assertSame( '', $result['change_set_id'] );
		self::assertSame( 'unchanged', $result['verification_status'] );
		self::assertSame( [], $result['post_write'] );
		self::assertSame( [], $result['next_step'] );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );
		self::assertSame( [], $GLOBALS['wpdb']->incident_rows );
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
		self::assertSame( 'stonewright_elementor_revision_conflict', $result->get_error_code() );
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

	public function test_missing_evidence_with_stop_on_error_returns_schema_requests(): void {
		$result = ( new BatchMutate() )->execute(
			[
				'post_id'          => 501,
				'dry_run'          => true,
				'stop_on_error'    => true,
				'require_evidence' => true,
				'operations'       => [
					[
						'action'      => 'add_widget',
						'parent_id'   => 'root',
						'widget_type' => 'heading',
						'settings'    => [ 'title' => 'Evidence' ],
					],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_batch_operation_failed', $result->get_error_code() );
		$data = $result->get_error_data();
		self::assertSame( 'stonewright_elementor_evidence_invalid', $data['cause_code'] );
		self::assertNotEmpty( $data['schema_requests'] );
		self::assertSame( 'stonewright/elementor-schema', $data['schema_requests'][0]['ability'] );
		self::assertSame( 'heading', $data['schema_requests'][0]['input']['widget_type'] ?? null );
		self::assertSame( 'summary', $data['schema_requests'][0]['input']['mode'] ?? null );
		self::assertSame( 'title', $data['schema_requests'][0]['input']['query'] ?? $data['schema_requests'][0]['setting'] ?? null );
	}

	public function test_direction_brief_provenance_is_accepted_for_token_derived_settings(): void {
		$GLOBALS['stonewright_test_options']['stonewright_active_design_direction_id'] = 1;
		$schema = WidgetSchemaRepository::get( 'heading' );
		self::assertIsArray( $schema );

		$result = ( new BatchMutate() )->execute(
			[
				'post_id'          => 501,
				'dry_run'          => true,
				'require_evidence' => true,
				'operations'       => [
					[
						'action'            => 'add_widget',
						'parent_id'         => 'root',
						'widget_type'       => 'heading',
						'settings'          => [
							'title'     => 'From direction',
							'font_size' => [ 'size' => 56, 'unit' => 'px' ],
						],
						'settings_evidence' => [
							'title' => [
								'schema_hash'           => $schema['schema_hash'],
								'source'                => 'figma:node/hero-title',
								'confidence'            => 0.99,
								'responsive_scope'      => 'desktop',
								'requires_confirmation' => false,
							],
							'typography_font_size' => [
								'source'                => 'direction-brief',
								'confidence'            => 0.95,
								'responsive_scope'      => 'desktop',
								'requires_confirmation' => false,
							],
						],
					],
				],
			]
		);

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertSame( 1, $result['applied'] );
	}

	public function test_operation_responsive_scope_authorizes_tablet_and_mobile_keys(): void {
		$result = ( new BatchMutate() )->execute(
			[
				'post_id'    => 501,
				'dry_run'    => true,
				'operations' => [
					[
						'action'           => 'add_container',
						'parent_id'        => 'root',
						'responsive_scope' => [ 'desktop', 'tablet', 'mobile' ],
						'settings'         => [
							'container_type' => 'flex',
							'padding'        => [
								'top' => '96', 'right' => '96', 'bottom' => '96', 'left' => '96', 'unit' => 'px', 'isLinked' => true,
							],
							'padding_tablet' => [
								'top' => '64', 'right' => '64', 'bottom' => '64', 'left' => '64', 'unit' => 'px', 'isLinked' => true,
							],
							'padding_mobile' => [
								'top' => '48', 'right' => '48', 'bottom' => '48', 'left' => '48', 'unit' => 'px', 'isLinked' => true,
							],
						],
					],
				],
			]
		);

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() . wp_json_encode( $result->get_error_data() ) : '' );
		self::assertSame( 1, $result['applied'] );
		$settings = $result['preview'][0]['elements'][0]['settings'];
		self::assertSame( '96', $settings['padding']['top'] );
		self::assertSame( '64', $settings['padding_tablet']['top'] );
		self::assertSame( '48', $settings['padding_mobile']['top'] );
		self::assertSame( [ 'desktop', 'tablet', 'mobile' ], $result['items'][0]['allowed_breakpoints'] );
	}

	public function test_batch_responsive_scope_authorizes_tablet_and_mobile_keys(): void {
		$result = ( new BatchMutate() )->execute(
			[
				'post_id'           => 501,
				'dry_run'           => true,
				'responsive_scope'  => [ 'desktop', 'tablet', 'mobile' ],
				'operations'        => [
					[
						'action'     => 'update_element',
						'element_id' => 'root',
						'settings'   => [
							'padding'        => [
								'top' => '96', 'right' => '96', 'bottom' => '96', 'left' => '96', 'unit' => 'px', 'isLinked' => true,
							],
							'padding_tablet' => [
								'top' => '64', 'right' => '64', 'bottom' => '64', 'left' => '64', 'unit' => 'px', 'isLinked' => true,
							],
							'padding_mobile' => [
								'top' => '48', 'right' => '48', 'bottom' => '48', 'left' => '48', 'unit' => 'px', 'isLinked' => true,
							],
						],
					],
				],
			]
		);

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() . wp_json_encode( $result->get_error_data() ) : '' );
		self::assertSame( [ 'desktop', 'tablet', 'mobile' ], $result['items'][0]['allowed_breakpoints'] );
		self::assertSame( '48', $result['preview'][0]['settings']['padding_mobile']['top'] );
	}

	public function test_desktop_default_scope_still_rejects_tablet_keys(): void {
		$result = ( new BatchMutate() )->execute(
			[
				'post_id'    => 501,
				'dry_run'    => true,
				'operations' => [
					[
						'action'     => 'update_element',
						'element_id' => 'root',
						'settings'   => [
							'padding_tablet' => [
								'top' => '64', 'right' => '64', 'bottom' => '64', 'left' => '64', 'unit' => 'px', 'isLinked' => true,
							],
						],
					],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_responsive_scope_violation', $result->get_error_data()['cause_code'] );
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
		self::assertSame( 'unsupported_responsive_control', $result->get_error_data()['cause_code'] );
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

	public function test_snapshot_persistence_failure_blocks_the_elementor_write(): void {
		$before = (string) $GLOBALS['stonewright_test_posts'][501]->meta['_elementor_data'];
		$GLOBALS['stonewright_test_update_post_meta_return'] = false;

		$result = ( new BatchMutate() )->execute(
			[
				'post_id'    => 501,
				'operations' => [
					[
						'action'     => 'update_element',
						'element_id' => 'root',
						'settings'   => [ 'container_type' => 'grid' ],
					],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_backup_failed', $result->get_error_code() );
		self::assertSame( 'backup.snapshot', $result->get_error_data()['write_receipt']['root_error_path'] );
		self::assertSame( $before, $GLOBALS['stonewright_test_posts'][501]->meta['_elementor_data'] );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'], 'No Elementor meta write may occur after an unpersisted snapshot.' );
	}

	public function test_mobile_padding_delta_preserves_every_existing_key_identically(): void {
		$existing = $this->legacy_container_settings();
		$this->seed_post( 901, [ $this->legacy_container( $existing ) ] );
		$padding_mobile = self::box( '8' );

		$result = ( new BatchMutate() )->execute(
			[
				'post_id'          => 901,
				'responsive_scope' => [ 'mobile' ],
				'operations'       => [
					[
						'action'     => 'update_element',
						'element_id' => 'hero',
						'settings'   => [ 'padding_mobile' => $padding_mobile ],
					],
				],
			]
		);

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() . wp_json_encode( $result->get_error_data() ) : '' );
		$tree     = json_decode( stripslashes( (string) $GLOBALS['stonewright_test_posts'][901]->meta['_elementor_data'] ), true );
		$settings = $tree[0]['settings'];
		foreach ( $existing as $key => $value ) {
			self::assertSame( $value, $settings[ $key ], $key );
		}
		self::assertSame( $padding_mobile, $settings['padding_mobile'] );
		self::assertSame( array_keys( $existing ), array_values( array_intersect( array_keys( $settings ), array_keys( $existing ) ) ) );
		self::assertSame( $result['items'][0]['non_target_before_hash'], $result['items'][0]['non_target_after_hash'] );
		self::assertSame(
			ResponsiveScope::hash_non_target_breakpoints( $existing, [ 'mobile' ] ),
			$result['items'][0]['non_target_before_hash']
		);
		self::assertSame(
			ResponsiveScope::hash_non_target_breakpoints( $settings, [ 'mobile' ] ),
			$result['items'][0]['non_target_after_hash']
		);
	}

	public function test_mobile_only_rejects_overflow_and_posts_per_page_without_widening_scope(): void {
		$this->seed_post(
			902,
			[
				[
					'id'         => 'loop',
					'elType'     => 'widget',
					'widgetType' => 'posts',
					'settings'   => [ 'posts_per_page' => 6 ],
					'elements'   => [],
				],
			]
		);

		foreach ( [ 'overflow' => 'hidden', 'posts_per_page' => 3 ] as $key => $value ) {
			$GLOBALS['stonewright_test_post_meta_calls'] = [];
			$result = ( new BatchMutate() )->execute(
				[
					'post_id'    => 902,
					'operations' => [
						[
							'action'              => 'update_element',
							'element_id'          => 'loop',
							'allowed_breakpoints' => [ 'mobile' ],
							'settings'            => [ $key => $value ],
						],
					],
				]
			);

			self::assertInstanceOf( \WP_Error::class, $result, $key );
			self::assertSame( 'unsupported_responsive_control', $result->get_error_data()['cause_code'], $key );
			self::assertSame( [ 'mobile' ], $result->get_error_data()['items'][0]['error']['data']['allowed_breakpoints'] ?? [] );
			self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'], $key );
		}
	}

	public function test_add_widget_and_visibility_leave_locked_ancestor_settings_byte_identical(): void {
		$existing = $this->legacy_container_settings();
		$this->seed_post( 903, [ $this->legacy_container( $existing ) ] );

		$result = ( new BatchMutate() )->execute(
			[
				'post_id'    => 903,
				'operations' => [
					[
						'action'      => 'add_widget',
						'op_id'       => 'loop',
						'parent_id'   => 'hero',
						'widget_type' => 'posts',
						'settings'    => [ 'posts_per_page' => 4 ],
					],
					[
						'action'      => 'update_element',
						'element_ref' => 'loop',
						'settings'    => [ 'hide_mobile' => 'hidden-mobile' ],
					],
				],
			]
		);

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() . wp_json_encode( $result->get_error_data() ) : '' );
		$tree = json_decode( stripslashes( (string) $GLOBALS['stonewright_test_posts'][903]->meta['_elementor_data'] ), true );
		foreach ( $existing as $key => $value ) {
			self::assertSame( $value, $tree[0]['settings'][ $key ], $key );
		}
		self::assertSame( 'hidden-mobile', $tree[0]['elements'][0]['settings']['hide_mobile'] );
		self::assertSame( 4, $tree[0]['elements'][0]['settings']['posts_per_page'] );
		$warnings = $result['items'][1]['normalization_warnings'] ?? [];
		self::assertNotContains( 'settings.boxed_width', array_column( $warnings, 'path' ) );
	}

	public function test_empty_default_lost_via_normalization_is_an_error_not_unchanged(): void {
		$result = ( new BatchMutate() )->execute(
			[
				'post_id'    => 501,
				'operations' => [
					[
						'action'     => 'update_element',
						'element_id' => 'root',
						'settings'   => [
							'flex_gap' => [
								'unit'  => 'px',
								'size'  => '',
								'sizes' => [],
							],
						],
					],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_elementor_setting_dropped', $result->get_error_data()['cause_code'] );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );
	}

	public function test_mixed_unchanged_and_real_operation_writes_only_the_delta(): void {
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$result = ( new BatchMutate() )->execute(
			[
				'post_id'    => 501,
				'operations' => [
					[
						'action'     => 'update_element',
						'element_id' => 'root',
						'settings'   => [ 'container_type' => 'flex' ],
					],
					[
						'action'      => 'add_widget',
						'parent_id'   => 'root',
						'widget_type' => 'heading',
						'settings'    => [ 'title' => 'Only real delta' ],
					],
				],
			]
		);

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertTrue( $result['items'][0]['unchanged'] ?? false );
		self::assertArrayNotHasKey( 'unchanged', $result['items'][1] );
		self::assertSame( 1, $result['applied'] );
		self::assertNotSame( '', $result['change_set_id'] );
		$data_writes = array_values(
			array_filter(
				$GLOBALS['stonewright_test_post_meta_calls'],
				static fn( array $call ): bool => '_elementor_data' === $call['meta_key']
			)
		);
		self::assertCount( 1, $data_writes );
		$tree = json_decode( stripslashes( (string) $GLOBALS['stonewright_test_posts'][501]->meta['_elementor_data'] ), true );
		self::assertSame( [ 'container_type' => 'flex' ], $tree[0]['settings'] );
		self::assertSame( 'Only real delta', $tree[0]['elements'][0]['settings']['title'] );
	}

	public function test_per_op_scope_overrides_batch_scope_and_same_layer_conflict_is_rejected(): void {
		$existing = $this->legacy_container_settings();
		$this->seed_post( 904, [ $this->legacy_container( $existing ) ] );

		$override = ( new BatchMutate() )->execute(
			[
				'post_id'          => 904,
				'dry_run'          => true,
				'responsive_scope' => [ 'desktop', 'tablet', 'mobile' ],
				'operations'       => [
					[
						'action'              => 'update_element',
						'element_id'          => 'hero',
						'allowed_breakpoints' => [ 'mobile' ],
						'settings'            => [
							'padding'        => self::box( '40' ),
							'padding_mobile' => self::box( '8' ),
						],
					],
				],
			]
		);
		self::assertInstanceOf( \WP_Error::class, $override );
		self::assertSame( 'stonewright_responsive_scope_violation', $override->get_error_data()['cause_code'] );

		$conflict = ( new BatchMutate() )->execute(
			[
				'post_id'    => 904,
				'dry_run'    => true,
				'operations' => [
					[
						'action'              => 'update_element',
						'element_id'          => 'hero',
						'allowed_breakpoints' => [ 'mobile' ],
						'responsive_scope'    => [ 'desktop', 'mobile' ],
						'settings'            => [ 'padding_mobile' => self::box( '8' ) ],
					],
				],
			]
		);
		self::assertInstanceOf( \WP_Error::class, $conflict );
		self::assertSame( 'stonewright_responsive_scope_conflict', $conflict->get_error_data()['cause_code'] ?? $conflict->get_error_code() );

		$batch_conflict = ( new BatchMutate() )->execute(
			[
				'post_id'             => 904,
				'dry_run'             => true,
				'allowed_breakpoints' => [ 'mobile' ],
				'responsive_scope'    => [ 'desktop', 'mobile' ],
				'operations'          => [
					[
						'action'     => 'update_element',
						'element_id' => 'hero',
						'settings'   => [ 'padding_mobile' => self::box( '8' ) ],
					],
				],
			]
		);
		self::assertInstanceOf( \WP_Error::class, $batch_conflict );
		self::assertSame( 'stonewright_responsive_scope_conflict', $batch_conflict->get_error_code() );
	}

	public function test_write_restores_meta_when_post_status_changes(): void {
		$before = (string) $GLOBALS['stonewright_test_posts'][501]->meta['_elementor_data'];
		$GLOBALS['stonewright_test_posts'][501]->post_status = 'draft';
		$GLOBALS['stonewright_test_status_flipped'] = false;
		add_filter(
			'update_post_metadata',
			static function ( $check, int $post_id, string $meta_key ) {
				if ( empty( $GLOBALS['stonewright_test_status_flipped'] ) && 501 === $post_id && '_elementor_data' === $meta_key ) {
					$GLOBALS['stonewright_test_status_flipped'] = true;
					$GLOBALS['stonewright_test_posts'][501]->post_status = 'publish';
				}
				return $check;
			},
			10,
			3
		);

		$result = ( new BatchMutate() )->execute(
			[
				'post_id'    => 501,
				'operations' => [
					[
						'action'     => 'update_element',
						'element_id' => 'root',
						'settings'   => [ 'container_type' => 'grid' ],
					],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_elementor_post_status_changed', $result->get_error_code() );
		self::assertSame( 'draft', $GLOBALS['stonewright_test_posts'][501]->post_status );
		self::assertSame( $before, $GLOBALS['stonewright_test_posts'][501]->meta['_elementor_data'] );
	}

	/** @return array<string, mixed> */
	private static function box( string $size ): array {
		return [
			'top' => $size, 'right' => $size, 'bottom' => $size, 'left' => $size, 'unit' => 'px', 'isLinked' => true,
		];
	}

	/** @return array<string, mixed> */
	private function legacy_container_settings(): array {
		return [
			'container_type'     => 'flex',
			'content_width'      => 'full',
			'padding'            => self::box( '24' ),
			'padding_tablet'     => self::box( '16' ),
			'boxed_width'        => [ 'unit' => 'px', 'size' => '', 'sizes' => [] ],
			'third_party_marker' => 'keep-me-exactly',
		];
	}

	/**
	 * @param array<string, mixed> $settings
	 * @return array<string, mixed>
	 */
	private function legacy_container( array $settings ): array {
		return [
			'id'       => 'hero',
			'elType'   => 'container',
			'settings' => $settings,
			'elements' => [],
		];
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

final class BatchFormWidgetForTest {
	public function get_title(): string {
		return 'Form';
	}

	/** @return list<string> */
	public function get_categories(): array {
		return [ 'pro-elements' ];
	}

	/** @return array<string,array<string,mixed>> */
	public function get_controls(): array {
		return [
			'form_fields' => [
				'type'   => 'repeater',
				'fields' => [
					'custom_id'  => [ 'type' => 'text' ],
					'field_label'=> [ 'type' => 'text' ],
					'field_type' => [ 'type' => 'select', 'options' => [ 'email' => 'Email', 'text' => 'Text' ] ],
				],
			],
			'actions_after_submit' => [ 'type' => 'select2', 'multiple' => true, 'options' => [ 'email' => 'Email', 'newsman' => 'Newsman' ] ],
			'email_to'             => [ 'type' => 'text' ],
			'newsman_list'         => [ 'type' => 'text' ],
		];
	}
}

final class BatchFixedControlWidgetForTest {
	public function get_title(): string {
		return 'Posts';
	}

	/** @return list<string> */
	public function get_categories(): array {
		return [ 'pro-elements' ];
	}

	/** @return array<string, array<string, mixed>> */
	public function get_controls(): array {
		return [
			'posts_per_page' => [ 'type' => 'number', 'responsive' => false ],
			'overflow'       => [
				'type'       => 'select',
				'responsive' => false,
				'options'    => [ 'default' => 'Default', 'hidden' => 'Hidden', 'auto' => 'Auto' ],
			],
			'hide_mobile'    => [ 'type' => 'switcher' ],
		];
	}
}
