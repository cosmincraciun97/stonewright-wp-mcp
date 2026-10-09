<?php
// SPDX-License-Identifier: GPL-2.0-or-later
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ElementorV3;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\GetElement;

/**
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\GetElement
 */
final class GetElementTest extends TestCase {

	protected function setUp(): void {
		$tree = [
			[
				'id'       => 'top',
				'elType'   => 'container',
				'settings' => [],
				'elements' => [
					[
						'id'         => 'first',
						'elType'     => 'widget',
						'widgetType' => 'heading',
						'settings'   => [ 'title' => 'One' ],
						'elements'   => [],
					],
					[
						'id'       => 'inner',
						'elType'   => 'container',
						'settings' => [],
						'elements' => [
							[
								'id'         => 'deep',
								'elType'     => 'widget',
								'widgetType' => 'heading',
								'settings'   => [ 'title' => 'Deep' ],
								'elements'   => [],
							],
						],
					],
				],
			],
			[
				'id'       => 'second-top',
				'elType'   => 'container',
				'settings' => [],
				'elements' => [],
			],
		];

		$GLOBALS['stonewright_test_posts'] = [
			941 => (object) [
				'ID'           => 941,
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Get element target',
				'post_content' => '',
				'post_excerpt' => '',
				'meta'         => [
					'_elementor_data'      => (string) wp_json_encode( $tree ),
					'_elementor_edit_mode' => 'builder',
				],
			],
		];
		$GLOBALS['stonewright_test_user_caps']      = [ 'edit_post' => true, 'edit_posts' => true ];
		$GLOBALS['stonewright_test_user_logged_in'] = true;
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_posts']          = [];
		$GLOBALS['stonewright_test_user_caps']      = [];
		$GLOBALS['stonewright_test_user_logged_in'] = false;
	}

	public function test_returns_a_nested_element_without_warnings(): void {
		$result = ( new GetElement() )->execute( [ 'post_id' => 941, 'element_id' => 'first' ] );

		self::assertIsArray( $result );
		self::assertSame( [ 0, 0 ], $result['path'] );
		self::assertSame( 'first', $result['element']['id'] );
		self::assertSame( 'One', $result['element']['settings']['title'] );
	}

	public function test_returns_a_deeply_nested_element(): void {
		$result = ( new GetElement() )->execute( [ 'post_id' => 941, 'element_id' => 'deep' ] );

		self::assertIsArray( $result );
		self::assertSame( [ 0, 1, 0 ], $result['path'] );
		self::assertSame( 'deep', $result['element']['id'] );
		self::assertSame( 'Deep', $result['element']['settings']['title'] );
	}

	public function test_returns_top_level_elements_and_reports_missing_ones(): void {
		$top = ( new GetElement() )->execute( [ 'post_id' => 941, 'element_id' => 'second-top' ] );
		self::assertIsArray( $top );
		self::assertSame( [ 1 ], $top['path'] );
		self::assertSame( 'second-top', $top['element']['id'] );

		$missing = ( new GetElement() )->execute( [ 'post_id' => 941, 'element_id' => 'nope' ] );
		self::assertInstanceOf( \WP_Error::class, $missing );
		self::assertSame( 'stonewright_not_found', $missing->get_error_code() );
	}
}
