<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ElementorV3;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\UpdateElement;

/**
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\UpdateElement
 */
final class UpdateElementTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_posts'] = [
			601 => (object) [
				'ID'           => 601,
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Update target',
				'post_content' => '',
				'post_excerpt' => '',
				'meta'         => [
					'_elementor_data'      => '[{"id":"root","elType":"container","settings":{"container_type":"flex","_flex_size":"grow"},"elements":[]}]',
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

	public function test_update_container_preserves_valid_native_flex_settings(): void {
		$result = ( new UpdateElement() )->execute(
			[
				'post_id'    => 601,
				'element_id' => 'root',
				'settings'   => [
					'direction'    => 'row',
					'flex_wrap'    => 'wrap',
					'_flex_size'   => 'custom',
					'_flex_grow'   => '1',
					'_flex_shrink' => '0',
				],
			]
		);

		self::assertIsArray( $result );

		$post     = $GLOBALS['stonewright_test_posts'][601];
		$tree     = json_decode( stripslashes( (string) $post->meta['_elementor_data'] ), true );
		$settings = $tree[0]['settings'];

		self::assertSame( 'flex', $settings['container_type'] );
		self::assertArrayHasKey( 'flex_direction', $settings );
		self::assertSame( 'row', $settings['flex_direction'] );
		self::assertArrayNotHasKey( 'direction', $settings );
		self::assertSame( 'wrap', $settings['flex_wrap'] );
		self::assertSame( 'custom', $settings['_flex_size'] );
		self::assertSame( '1', $settings['_flex_grow'] );
		self::assertSame( '0', $settings['_flex_shrink'] );
	}

	public function test_merge_patch_preserves_preexisting_unknown_pro_key(): void {
		$GLOBALS['stonewright_test_posts'][601]->meta['_elementor_data'] = '[{"id":"root","elType":"container","settings":{"container_type":"flex","pro_ribbon":"sale"},"elements":[]}]';

		$result = ( new UpdateElement() )->execute(
			[
				'post_id'    => 601,
				'element_id' => 'root',
				'mode'       => 'merge',
				'settings'   => [ 'flex_direction' => 'column' ],
			]
		);

		self::assertIsArray( $result );
		$post = $GLOBALS['stonewright_test_posts'][601];
		$tree = json_decode( stripslashes( (string) $post->meta['_elementor_data'] ), true );
		self::assertSame( 'column', $tree[0]['settings']['flex_direction'] );
		self::assertSame( 'sale', $tree[0]['settings']['pro_ribbon'] );
	}
}
