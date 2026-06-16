<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ElementorV3;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\GetPageStructure;

/**
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\GetPageStructure
 */
final class GetPageStructureTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_posts'] = [
			701 => (object) [
				'ID'           => 701,
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Structure target',
				'post_content' => '',
				'post_excerpt' => '',
				'meta'         => [
					'_elementor_data'      => wp_json_encode(
						[
							[
								'id'       => 'root',
								'elType'   => 'container',
								'settings' => [
									'_title'         => 'Hero section',
									'container_type' => 'flex',
								],
								'elements' => [
									[
										'id'         => 'headline',
										'elType'     => 'widget',
										'widgetType' => 'heading',
										'settings'   => [
											'title'      => 'Fast native Elementor',
											'header_size' => 'h1',
										],
										'elements'   => [],
									],
									[
										'id'         => 'body',
										'elType'     => 'widget',
										'widgetType' => 'text-editor',
										'settings'   => [
											'editor' => '<p>Summary should strip tags.</p>',
										],
										'elements'   => [],
									],
								],
							],
						]
					),
					'_elementor_edit_mode' => 'builder',
					'_elementor_version'   => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.0.0',
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

	public function test_summary_mode_returns_compact_outline_by_default(): void {
		$result = ( new GetPageStructure() )->execute(
			[
				'post_id'     => 701,
				'maxElements' => 2,
			]
		);

		self::assertIsArray( $result );
		self::assertSame( 'summary', $result['response_mode'] );
		self::assertSame( 3, $result['count'] );
		self::assertSame( 2, $result['returned_count'] );
		self::assertTrue( $result['truncated'] );
		self::assertTrue( $result['tree_omitted'] );
		self::assertArrayNotHasKey( 'tree', $result );

		self::assertSame(
			[
				'id'            => 'root',
				'parent_id'     => null,
				'path'          => '0',
				'depth'         => 0,
				'elType'        => 'container',
				'widgetType'    => '',
				'label'         => 'Hero section',
				'settings_keys' => [ '_title', 'container_type' ],
				'child_count'   => 2,
			],
			$result['outline'][0]
		);
		self::assertSame( 'headline', $result['outline'][1]['id'] );
		self::assertSame( 'root', $result['outline'][1]['parent_id'] );
		self::assertSame( '0.0', $result['outline'][1]['path'] );
	}

	public function test_full_mode_preserves_raw_tree(): void {
		$result = ( new GetPageStructure() )->execute(
			[
				'post_id'      => 701,
				'responseMode' => 'full',
			]
		);

		self::assertIsArray( $result );
		self::assertSame( 'full', $result['response_mode'] );
		self::assertSame( 3, $result['count'] );
		self::assertArrayHasKey( 'tree', $result );
		self::assertSame( 'root', $result['tree'][0]['id'] );
		self::assertSame( 'heading', $result['tree'][0]['elements'][0]['widgetType'] );
	}
}
