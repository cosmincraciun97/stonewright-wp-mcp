<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ElementorV3;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\BatchMutate;

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
		];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_options'] = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_user_caps'] = [ 'edit_post' => true, 'edit_posts' => true ];
		$GLOBALS['stonewright_test_user_logged_in'] = true;
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_posts'] = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_options'] = [];
		$GLOBALS['stonewright_test_user_caps'] = [];
		$GLOBALS['stonewright_test_user_logged_in'] = false;
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

	public function test_batch_normalizes_container_settings_on_add_and_update(): void {
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
							'_flex_size'   => 'grow',
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
			self::assertArrayNotHasKey( 'flex_wrap', $settings );
			self::assertArrayNotHasKey( '_flex_size', $settings );
			self::assertArrayNotHasKey( '_flex_grow', $settings );
			self::assertArrayNotHasKey( '_flex_shrink', $settings );
		}
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
}
