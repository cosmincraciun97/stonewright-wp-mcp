<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Gutenberg;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Gutenberg\BlocksBatchMutate;
use Stonewright\WpMcp\Gutenberg\Finalizer\BlockQueue;
use Stonewright\WpMcp\Security\ConfirmationToken;

/**
 * @covers \Stonewright\WpMcp\Abilities\Gutenberg\BlocksBatchMutate
 */
final class BlocksBatchMutateTest extends TestCase {

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
				'attributes'      => [
					'className' => [ 'type' => 'string' ],
					'level'     => [ 'type' => 'integer', 'enum' => [ 1, 2, 3, 4, 5, 6 ] ],
				],
				'render_callback' => static fn(): string => '',
				'is_dynamic'      => true,
			],
			'core/group'     => (object) [
				'attributes'      => [ 'className' => [ 'type' => 'string' ] ],
				'render_callback' => static fn(): string => '',
				'is_dynamic'      => true,
			],
			'vendor/alpha'   => (object) [
				'attributes' => [ 'title' => [ 'type' => 'string' ] ],
			],
			'vendor/beta'    => (object) [
				'attributes' => [ 'title' => [ 'type' => 'string' ] ],
			],
			'vendor/gamma'   => (object) [
				'attributes' => [ 'title' => [ 'type' => 'string' ] ],
			],
		];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_posts']                = [];
		$GLOBALS['stonewright_test_post_meta_calls']      = [];
		$GLOBALS['stonewright_test_options']              = [];
		$GLOBALS['stonewright_test_user_caps']            = [];
		$GLOBALS['stonewright_test_user_logged_in']       = false;
		$GLOBALS['stonewright_test_wp_update_post_return'] = null;
		unset( $GLOBALS['stonewright_test_registered_blocks'] );
	}

	public function test_dry_run_compiles_operations_without_snapshot_or_write(): void {
		$before = (string) $GLOBALS['stonewright_test_posts'][801]->post_content;
		$result = ( new BlocksBatchMutate() )->execute(
			[
				'post_id'    => 801,
				'dry_run'    => true,
				'operations' => [
					[
						'action' => 'insert',
						'block'  => [ 'blockName' => 'core/heading', 'innerHTML' => '<h2>After</h2>' ],
					],
				],
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertTrue( $result['dry_run'] );
		self::assertSame( 'planned', $result['verification_status'] );
		self::assertSame( '', $result['snapshot_id'] );
		self::assertSame( '', $result['readback_hash'] );
		self::assertSame( $before, $GLOBALS['stonewright_test_posts'][801]->post_content );
		self::assertSame( 'gutenberg', $result['write_receipt']['architecture'] );
		self::assertTrue( $result['preview_omitted'] );
		self::assertArrayNotHasKey( 'preview', $result );
		self::assertSame( 2, $result['preview_summary']['block_count'] );
	}

	public function test_dry_run_returns_full_preview_only_when_explicitly_requested(): void {
		$result = ( new BlocksBatchMutate() )->execute(
			[
				'post_id'      => 801,
				'dry_run'      => true,
				'include_full' => true,
				'operations'   => [
					[
						'action' => 'insert',
						'block'  => [ 'blockName' => 'core/heading', 'innerHTML' => '<h2>After</h2>' ],
					],
				],
			]
		);

		self::assertIsArray( $result );
		self::assertFalse( $result['preview_omitted'] );
		self::assertCount( 2, $result['preview'] );
	}

	public function test_explicit_full_preview_fails_closed_above_bounded_limit(): void {
		$result = ( new BlocksBatchMutate() )->execute(
			[
				'post_id'           => 801,
				'dry_run'           => true,
				'include_full'      => true,
				'max_preview_blocks' => 1,
				'operations'        => [
					[
						'action' => 'insert',
						'block'  => [ 'blockName' => 'core/heading', 'innerHTML' => '<h2>After</h2>' ],
					],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_full_preview_limit_exceeded', $result->get_error_code() );
	}

	public function test_write_snapshots_and_returns_verified_receipt(): void {
		$result = ( new BlocksBatchMutate() )->execute(
			[
				'post_id'               => 801,
				'expected_content_hash' => $this->current_hash(),
				'change_set_id'          => 'change-blocks-1',
				'operations'             => [
					[
						'action' => 'update',
						'path'   => [ 0 ],
						'attrs'  => [ 'className' => 'updated' ],
					],
				],
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( 'verified', $result['verification_status'] );
		self::assertNotSame( '', $result['snapshot_id'] );
		self::assertSame( $result['after_hash'], $result['readback_hash'] );
		self::assertSame( 'change-blocks-1', $result['write_receipt']['change_set_id'] );
		self::assertSame( 'not_needed', $result['write_receipt']['rollback_status'] );
		self::assertStringContainsString( 'updated', (string) $GLOBALS['stonewright_test_posts'][801]->post_content );
	}

	public function test_write_requires_expected_content_hash(): void {
		$result = ( new BlocksBatchMutate() )->execute(
			[
				'post_id'    => 801,
				'operations' => [ [ 'action' => 'update', 'path' => [ 0 ], 'innerHTML' => '<p>new</p>' ] ],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_missing_expected_content_hash', $result->get_error_code() );
	}

	public function test_expected_hash_conflict_blocks_write(): void {
		$result = ( new BlocksBatchMutate() )->execute(
			[
				'post_id'                => 801,
				'expected_content_hash'  => hash( 'sha256', 'different content' ),
				'operations'             => [ [ 'action' => 'update', 'path' => [ 0 ], 'innerHTML' => '<p>no</p>' ] ],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_content_conflict', $result->get_error_code() );
		self::assertSame( '<!-- wp:paragraph --><p>Before</p><!-- /wp:paragraph -->', $GLOBALS['stonewright_test_posts'][801]->post_content );
	}

	public function test_remove_requires_confirmation_in_production_safe_mode(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		$args = [
			'post_id'               => 801,
			'expected_content_hash' => $this->current_hash(),
			'operations'            => [ [ 'action' => 'remove', 'path' => [ 0 ] ] ],
		];

		$blocked = ( new BlocksBatchMutate() )->execute( $args );
		self::assertInstanceOf( \WP_Error::class, $blocked );
		self::assertSame( 'stonewright_confirmation_required', $blocked->get_error_code() );

		$args['confirmation_token'] = ConfirmationToken::issue( 'stonewright/blocks-batch-mutate', $args );
		$allowed = ( new BlocksBatchMutate() )->execute( $args );
		self::assertIsArray( $allowed );
		self::assertTrue( $allowed['ok'] );
	}

	public function test_failed_write_restores_the_single_snapshot(): void {
		$GLOBALS['stonewright_test_wp_update_post_return'] = new \WP_Error( 'database_failure', 'Database write failed.' );
		$result = ( new BlocksBatchMutate() )->execute(
			[
				'post_id'               => 801,
				'expected_content_hash' => $this->current_hash(),
				'operations'            => [ [ 'action' => 'update', 'path' => [ 0 ], 'innerHTML' => '<p>new</p>' ] ],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'database_failure', $result->get_error_code() );
		$data = (array) $result->get_error_data();
		self::assertSame( 'succeeded', $data['rollback_status'] );
		self::assertSame( $this->current_hash(), $data['rollback_readback_hash'] );
		self::assertTrue( $data['write_receipt']['rollback_attempted'] );
		self::assertSame( 'write.persist', $data['write_receipt']['root_error_path'] );
	}

	/** @dataProvider invalidAnchorProvider */
	public function test_insert_rejects_missing_anchors( string $key ): void {
		$result = ( new BlocksBatchMutate() )->execute(
			[
				'post_id'    => 801,
				'dry_run'    => true,
				'operations' => [
					[
						'action' => 'insert',
						$key     => [ 99 ],
						'block'  => [ 'blockName' => 'core/paragraph', 'innerHTML' => '<p>new</p>' ],
					],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_batch_operation_failed', $result->get_error_code() );
		self::assertSame( 'stonewright_invalid_anchor', $result->get_error_data()['root_error_code'] );
	}

	/** @return array<string,array{string}> */
	public static function invalidAnchorProvider(): array {
		return [
			'before' => [ 'before_path' ],
			'after'  => [ 'after_path' ],
		];
	}

	public function test_insert_rejects_ambiguous_anchors(): void {
		$result = ( new BlocksBatchMutate() )->execute(
			[
				'post_id'    => 801,
				'dry_run'    => true,
				'operations' => [
					[
						'action'      => 'insert',
						'before_path' => [ 0 ],
						'after_path'  => [ 0 ],
						'block'       => [ 'blockName' => 'core/paragraph', 'innerHTML' => '<p>new</p>' ],
					],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_ambiguous_anchor', $result->get_error_data()['root_error_code'] );
	}

	public function test_insert_and_nested_update_preserve_interleaved_inner_content(): void {
		$wrapper_open  = '<div class="wp-block-group">';
		$wrapper_close = '</div>';
		$result = ( new BlocksBatchMutate() )->execute(
			[
				'post_id'      => 801,
				'dry_run'      => true,
				'include_full' => true,
				'operations'   => [
					[
						'action' => 'insert',
						'block'  => [
							'blockName'    => 'core/group',
							'innerHTML'    => $wrapper_open . $wrapper_close,
							'innerContent' => [ $wrapper_open, null, $wrapper_close ],
							'innerBlocks'  => [
								[ 'blockName' => 'core/paragraph', 'innerHTML' => '<p>Nested</p>' ],
							],
						],
					],
					[
						'action' => 'update',
						'path'   => [ 1, 0 ],
						'attrs'  => [ 'className' => 'nested-updated' ],
					],
					[
						'action'   => 'insert',
						'path'     => [ 1 ],
						'position' => 1,
						'block'    => [ 'blockName' => 'core/paragraph', 'innerHTML' => '<p>Second</p>' ],
					],
				],
			]
		);

		self::assertIsArray( $result );
		self::assertSame( [ $wrapper_open, null, null, $wrapper_close ], $result['preview'][1]['innerContent'] );
		self::assertCount( 2, $result['preview'][1]['innerBlocks'] );
		self::assertSame( 'nested-updated', $result['preview'][1]['innerBlocks'][0]['attrs']['className'] );
	}

	public function test_nested_parent_inner_html_replacement_fails_closed(): void {
		$wrapper_open  = '<div class="wp-block-group">';
		$wrapper_close = '</div>';
		$result = ( new BlocksBatchMutate() )->execute(
			[
				'post_id'    => 801,
				'dry_run'    => true,
				'operations' => [
					[
						'action' => 'insert',
						'block'  => [
							'blockName'    => 'core/group',
							'innerHTML'    => $wrapper_open . $wrapper_close,
							'innerContent' => [ $wrapper_open, null, $wrapper_close ],
							'innerBlocks'  => [ [ 'blockName' => 'core/paragraph', 'innerHTML' => '<p>Nested</p>' ] ],
						],
					],
					[ 'action' => 'update', 'path' => [ 1 ], 'innerHTML' => '<div>unsafe</div>' ],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_unsafe_nested_inner_html', $result->get_error_data()['root_error_code'] );
	}

	public function test_nested_move_and_remove_keep_wrapper_placeholders_aligned(): void {
		$wrapper_open  = '<div class="wp-block-group">';
		$wrapper_close = '</div>';
		$result = ( new BlocksBatchMutate() )->execute(
			[
				'post_id'      => 801,
				'dry_run'      => true,
				'include_full' => true,
				'operations'   => [
					[
						'action' => 'insert',
						'block'  => [
							'blockName'    => 'core/group',
							'innerHTML'    => $wrapper_open . $wrapper_close,
							'innerContent' => [ $wrapper_open, null, null, null, $wrapper_close ],
							'innerBlocks'  => [
								[ 'blockName' => 'core/paragraph', 'attrs' => [ 'className' => 'a' ], 'innerHTML' => '<p>A</p>' ],
								[ 'blockName' => 'core/paragraph', 'attrs' => [ 'className' => 'b' ], 'innerHTML' => '<p>B</p>' ],
								[ 'blockName' => 'core/paragraph', 'attrs' => [ 'className' => 'c' ], 'innerHTML' => '<p>C</p>' ],
							],
						],
					],
					[ 'action' => 'move', 'path' => [ 1, 2 ], 'position' => 0 ],
					[ 'action' => 'remove', 'path' => [ 1, 1 ] ],
				],
			]
		);

		self::assertIsArray( $result );
		self::assertSame( [ $wrapper_open, null, null, $wrapper_close ], $result['preview'][1]['innerContent'] );
		self::assertSame(
			[ 'c', 'b' ],
			array_map( static fn ( array $block ): string => (string) $block['attrs']['className'], $result['preview'][1]['innerBlocks'] )
		);
	}

	public function test_registered_block_schema_rejects_invalid_attribute_type(): void {
		$result = ( new BlocksBatchMutate() )->execute(
			[
				'post_id'    => 801,
				'dry_run'    => true,
				'operations' => [ [ 'action' => 'update', 'path' => [ 0 ], 'attrs' => [ 'className' => 17 ] ] ],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_invalid_block_attributes', $result->get_error_data()['root_error_code'] );
	}

	public function test_registered_block_schema_rejects_undeclared_attribute(): void {
		$result = ( new BlocksBatchMutate() )->execute(
			[
				'post_id'    => 801,
				'dry_run'    => true,
				'operations' => [ [ 'action' => 'update', 'path' => [ 0 ], 'attrs' => [ 'undeclared' => true ] ] ],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_unknown_block_attributes', $result->get_error_data()['root_error_code'] );
		self::assertSame( [ 'undeclared' ], $result->get_error_data()['items'][0]['error']['data']['offending_keys'] );
	}

	public function test_three_finalizer_ops_queue_three_items_not_a_single_insert(): void {
		$result = ( new BlocksBatchMutate() )->execute(
			[
				'post_id'               => 801,
				'expected_content_hash' => $this->current_hash(),
				'operations'            => [
					[
						'action'   => 'insert',
						'path'     => [],
						'position' => 0,
						'block'    => [ 'blockName' => 'vendor/alpha', 'attrs' => [ 'title' => 'A' ] ],
					],
					[
						'action'   => 'insert',
						'path'     => [],
						'position' => 1,
						'block'    => [ 'blockName' => 'vendor/beta', 'attrs' => [ 'title' => 'B' ] ],
					],
					[
						'action'   => 'insert',
						'path'     => [],
						'position' => 2,
						'block'    => [ 'blockName' => 'vendor/gamma', 'attrs' => [ 'title' => 'C' ] ],
					],
				],
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['queued'] );

		$queued_names = [];
		foreach ( BlockQueue::list() as $item ) {
			if ( 801 !== (int) $item['post_id'] ) {
				continue;
			}
			$record = BlockQueue::get( (string) $item['id'] );
			self::assertIsArray( $record );
			if ( isset( $record['operations'] ) && is_array( $record['operations'] ) ) {
				foreach ( $record['operations'] as $operation ) {
					$queued_names[] = (string) ( $operation['block_spec']['name'] ?? '' );
					self::assertSame( 'insert', (string) ( $operation['action'] ?? '' ) );
					self::assertArrayHasKey( 'position', $operation );
				}
				continue;
			}
			$queued_names[] = (string) ( $record['block_spec']['name'] ?? '' );
			self::assertSame( 'insert', (string) ( $record['action'] ?? '' ) );
		}

		self::assertSame( [ 'vendor/alpha', 'vendor/beta', 'vendor/gamma' ], $queued_names );
		foreach ( BlockQueue::list() as $item ) {
			if ( 801 !== (int) $item['post_id'] ) {
				continue;
			}
			$record = BlockQueue::get( (string) $item['id'] );
			self::assertIsArray( $record );
			if ( isset( $record['operations'] ) && is_array( $record['operations'] ) ) {
				continue;
			}
			self::assertNotNull( $record['position'] );
		}
		self::assertSame( '<!-- wp:paragraph --><p>Before</p><!-- /wp:paragraph -->', $GLOBALS['stonewright_test_posts'][801]->post_content );
	}

	public function test_mixed_finalizer_and_native_ops_reject_closed(): void {
		$before = (string) $GLOBALS['stonewright_test_posts'][801]->post_content;
		$result = ( new BlocksBatchMutate() )->execute(
			[
				'post_id'               => 801,
				'expected_content_hash' => $this->current_hash(),
				'operations'            => [
					[
						'action'   => 'insert',
						'path'     => [],
						'position' => 1,
						'block'    => [ 'blockName' => 'vendor/alpha', 'attrs' => [ 'title' => 'A' ] ],
					],
					[
						'action' => 'update',
						'path'   => [ 0 ],
						'attrs'  => [ 'className' => 'native' ],
					],
					[
						'action'   => 'move',
						'path'     => [ 0 ],
						'position' => 1,
					],
					[
						'action' => 'remove',
						'path'   => [ 0 ],
					],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_mixed_finalizer_batch', $result->get_error_code() );
		self::assertSame( 400, (int) ( $result->get_error_data()['status'] ?? 0 ) );
		self::assertSame( $before, $GLOBALS['stonewright_test_posts'][801]->post_content );
		self::assertSame( [], BlockQueue::list() );
	}

	public function test_finalizer_insert_then_update_resolves_path_against_working_tree(): void {
		$GLOBALS['stonewright_test_registered_blocks']['core/paragraph'] = (object) [
			'attributes' => [ 'className' => [ 'type' => 'string' ] ],
		];

		$result = ( new BlocksBatchMutate() )->execute(
			[
				'post_id'               => 801,
				'expected_content_hash' => $this->current_hash(),
				'operations'            => [
					[
						'action'   => 'insert',
						'path'     => [],
						'position' => 0,
						'block'    => [ 'blockName' => 'vendor/alpha', 'attrs' => [ 'title' => 'Inserted' ] ],
					],
					[
						'action' => 'update',
						'path'   => [ 1 ],
						'attrs'  => [ 'className' => 'shifted-original' ],
					],
				],
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['queued'] );

		$queued = [];
		foreach ( BlockQueue::list() as $item ) {
			if ( 801 !== (int) $item['post_id'] ) {
				continue;
			}
			$record = BlockQueue::get( (string) $item['id'] );
			self::assertIsArray( $record );
			$queued[] = $record;
		}

		self::assertCount( 2, $queued );
		self::assertSame( 'insert', $queued[0]['action'] );
		self::assertSame( 0, $queued[0]['position'] );
		self::assertSame( 'vendor/alpha', $queued[0]['block_spec']['name'] );
		self::assertSame( 'update', $queued[1]['action'] );
		self::assertSame( [ 1 ], $queued[1]['path'] );
		self::assertSame( 'core/paragraph', $queued[1]['block_spec']['name'] );
		self::assertSame( 'shifted-original', $queued[1]['block_spec']['attributes']['className'] );
		self::assertSame( '<!-- wp:paragraph --><p>Before</p><!-- /wp:paragraph -->', $GLOBALS['stonewright_test_posts'][801]->post_content );
	}

	public function test_unregistered_inserted_block_is_rejected(): void {
		$result = ( new BlocksBatchMutate() )->execute(
			[
				'post_id'    => 801,
				'dry_run'    => true,
				'operations' => [ [ 'action' => 'insert', 'block' => [ 'blockName' => 'vendor/missing', 'innerHTML' => '<p>new</p>' ] ] ],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_block_not_registered', $result->get_error_code() );
		self::assertSame( '<!-- wp:paragraph --><p>Before</p><!-- /wp:paragraph -->', $GLOBALS['stonewright_test_posts'][801]->post_content );
	}

	public function test_immediate_compare_and_swap_recheck_blocks_a_racing_write(): void {
		$initial = '<!-- wp:paragraph --><p>Before</p><!-- /wp:paragraph -->';
		$changed = '<!-- wp:paragraph --><p>Concurrent</p><!-- /wp:paragraph -->';
		$post = new class( $initial, $changed ) {
			public int $ID = 801;
			public string $post_type = 'page';
			public string $post_status = 'draft';
			public string $post_title = 'Racing block batch target';
			public string $post_excerpt = '';
			public array $meta = [];
			public array $writes = [];
			private int $reads = 0;

			public function __construct( private string $initial, private string $changed ) {}

			public function __get( string $name ): mixed {
				if ( 'post_content' === $name ) {
					++$this->reads;
					return $this->reads >= 4 ? $this->changed : $this->initial;
				}
				return null;
			}

			public function __set( string $name, mixed $value ): void {
				if ( 'post_content' === $name ) {
					$this->writes[] = (string) $value;
				}
			}
		};
		$GLOBALS['stonewright_test_posts'][801] = $post;

		$result = ( new BlocksBatchMutate() )->execute(
			[
				'post_id'               => 801,
				'expected_content_hash' => hash( 'sha256', $initial ),
				'operations'            => [ [ 'action' => 'update', 'path' => [ 0 ], 'innerHTML' => '<p>new</p>' ] ],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_content_conflict', $result->get_error_code() );
		self::assertSame( 'write.cas_recheck', $result->get_error_data()['write_receipt']['root_error_path'] );
		self::assertSame( [], $post->writes );
	}

	public function test_rollback_is_failed_when_restore_readback_does_not_match_snapshot(): void {
		$initial = '<!-- wp:paragraph --><p>Before</p><!-- /wp:paragraph -->';
		$corrupt = '<!-- wp:paragraph --><p>Corrupt</p><!-- /wp:paragraph -->';
		$post = new class( $initial, $corrupt ) {
			public int $ID = 801;
			public string $post_type = 'page';
			public string $post_status = 'draft';
			public string $post_title = 'Rollback block batch target';
			public string $post_excerpt = '';
			public array $meta = [];
			private int $reads = 0;

			public function __construct( private string $initial, private string $corrupt ) {}

			public function __get( string $name ): mixed {
				if ( 'post_content' === $name ) {
					++$this->reads;
					return $this->reads >= 5 ? $this->corrupt : $this->initial;
				}
				return null;
			}

			public function __set( string $name, mixed $value ): void {}
		};
		$GLOBALS['stonewright_test_posts'][801] = $post;
		$GLOBALS['stonewright_test_wp_update_post_return'] = new \WP_Error( 'database_failure', 'Database write failed.' );

		$result = ( new BlocksBatchMutate() )->execute(
			[
				'post_id'               => 801,
				'expected_content_hash' => hash( 'sha256', $initial ),
				'operations'            => [ [ 'action' => 'update', 'path' => [ 0 ], 'innerHTML' => '<p>new</p>' ] ],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		$data = (array) $result->get_error_data();
		self::assertFalse( $data['restored'] );
		self::assertSame( 'failed', $data['rollback_status'] );
		self::assertSame( hash( 'sha256', $corrupt ), $data['rollback_readback_hash'] );
	}

	private function current_hash(): string {
		return hash( 'sha256', (string) $GLOBALS['stonewright_test_posts'][801]->post_content );
	}
}
