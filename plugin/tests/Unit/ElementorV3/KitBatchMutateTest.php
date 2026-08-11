<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ElementorV3;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\KitBatchMutate;

/**
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\KitBatchMutate
 */
final class KitBatchMutateTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']         = [ 'elementor_active_kit' => 44 ];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_mode']                 = 'development';
		$GLOBALS['stonewright_test_posts']           = [
			44 => (object) [
				'ID'           => 44,
				'post_type'    => 'elementor_library',
				'post_status'  => 'publish',
				'post_title'   => 'Active Kit',
				'post_content' => '',
				'post_excerpt' => '',
				'post_parent'  => 0,
				'post_name'    => 'active-kit',
				'meta'         => [
					'_elementor_page_settings' => [
						'system_colors'      => [
							[ '_id' => 'primary', 'title' => 'Primary', 'color' => '#111111' ],
						],
						'custom_colors'      => [
							[ '_id' => 'brand', 'title' => 'Brand', 'color' => '#00aa00', 'extra_pro_key' => 'keep-me' ],
						],
						'system_typography'  => [
							[
								'_id'                    => 'primary',
								'title'                  => 'Primary',
								'typography_font_family' => 'Inter',
							],
						],
						'container_width'    => [ 'size' => 1200, 'unit' => 'px' ],
						'unknown_kit_flag'   => 'preserve-me',
						'page_title_selector'=> 'h1.entry-title',
					],
				],
			],
		];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		unset( $GLOBALS['stonewright_mode'] );
	}

	public function test_dry_run_plans_1140_container_and_montserrat_without_write(): void {
		$result = ( new KitBatchMutate() )->execute(
			[
				'dry_run'    => true,
				'operations' => [
					[
						'group'           => 'layout',
						'container_width' => 1140,
					],
					[
						'group'  => 'typography',
						'bucket' => 'system',
						'mode'   => 'merge',
						'fonts'  => [
							[
								'id'          => 'primary',
								'title'       => 'Primary Heading',
								'font_family' => 'Montserrat',
								'font_weight' => '700',
							],
							[
								'id'          => 'text',
								'title'       => 'Body',
								'font_family' => 'Montserrat',
								'font_weight' => '400',
							],
						],
					],
					[
						'group'  => 'colors',
						'bucket' => 'custom',
						'mode'   => 'merge',
						'colors' => [
							[ 'id' => 'brand', 'title' => 'Brand', 'color' => '#112233' ],
						],
					],
				],
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertTrue( $result['dry_run'] );
		self::assertSame( 0, $result['applied'] );
		self::assertSame( '', $result['snapshot_id'] );
		self::assertContains( 'container_width', $result['preview']['changed_keys'] );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );
	}

	public function test_apply_preserves_unknown_settings_and_entry_extras(): void {
		$result = ( new KitBatchMutate() )->execute(
			[
				'operations' => [
					[
						'group'   => 'layout',
						'setting' => 'container_width',
						'value'   => [ 'size' => 1140, 'unit' => 'px' ],
					],
					[
						'group'  => 'colors',
						'bucket' => 'custom',
						'mode'   => 'merge',
						'colors' => [
							[ 'id' => 'brand', 'title' => 'Brand', 'color' => '#112233' ],
						],
					],
				],
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertFalse( $result['dry_run'] );
		self::assertNotSame( '', $result['snapshot_id'] );
		self::assertSame( $result['after_hash'], $result['readback_hash'] );

		$settings = $GLOBALS['stonewright_test_posts'][44]->meta['_elementor_page_settings'];
		self::assertSame( 1140, $settings['container_width']['size'] );
		self::assertSame( 'preserve-me', $settings['unknown_kit_flag'] );
		self::assertSame( 'h1.entry-title', $settings['page_title_selector'] );
		self::assertSame( '#112233', $settings['custom_colors'][0]['color'] );
		self::assertSame( 'keep-me', $settings['custom_colors'][0]['extra_pro_key'] );
	}

	public function test_no_kit_errors(): void {
		$GLOBALS['stonewright_test_options'] = [];
		$result = ( new KitBatchMutate() )->execute(
			[
				'operations' => [
					[ 'group' => 'layout', 'container_width' => 1140 ],
				],
			]
		);
		self::assertInstanceOf( \WP_Error::class, $result );
	}
}
