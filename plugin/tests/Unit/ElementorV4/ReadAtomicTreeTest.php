<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ElementorV4;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV4\ReadAtomicTree;

/**
 * @covers \Stonewright\WpMcp\Abilities\ElementorV4\ReadAtomicTree
 */
final class ReadAtomicTreeTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']['stonewright_elementor_v4_atomic'] = true;
		$GLOBALS['stonewright_test_posts'] = [
			802 => (object) [
				'ID'           => 802,
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'V4 atomic tree',
				'post_content' => '',
				'post_excerpt' => '',
				'meta'         => [
					'_elementor_data'      => wp_json_encode(
						[
							[
								'id'       => 'legacy1',
								'elType'   => 'container',
								'settings' => [],
								'elements' => [
									[
										'id'         => 'atomic1',
										'elType'     => 'widget',
										'widgetType' => 'e-heading',
										'settings'   => [ 'title' => 'Atomic heading' ],
										'elements'   => [],
									],
								],
							],
							[
								'id'       => 'atomic2',
								'elType'   => 'e-flexbox',
								'settings' => [ '_title' => 'Flex shell' ],
								'elements' => [
									[
										'id'         => 'atomic3',
										'elType'     => 'widget',
										'widgetType' => 'e-paragraph',
										'settings'   => [ 'editor' => '<p>Body copy</p>' ],
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
		unset( $GLOBALS['stonewright_test_options']['stonewright_elementor_v4_atomic'] );
	}

	public function test_summary_mode_returns_outline_without_atomic_tree(): void {
		$result = ( new ReadAtomicTree() )->execute(
			[
				'post_id'   => 802,
				'max_nodes' => 2,
			]
		);

		self::assertIsArray( $result );
		self::assertSame( 'summary', $result['response_mode'] );
		self::assertArrayNotHasKey( 'atomic_tree', $result );
		self::assertTrue( $result['tree_omitted'] );
		self::assertTrue( $result['truncated'] );
		self::assertSame( 2, $result['returned_count'] );
		self::assertCount( 2, $result['outline'] );
		self::assertGreaterThan( 2, $result['count'] );
		self::assertSame( 'mixed', $result['architecture'] );
		self::assertArrayHasKey( 'atomic_count', $result );
		self::assertArrayHasKey( 'non_atomic_count', $result );
		self::assertStringContainsString( 'responseMode', (string) $result['full_mode_hint'] );
		// AtomicTreeInspector lifts atomic1 out of the non-atomic legacy container first.
		self::assertSame( 'atomic1', $result['outline'][0]['id'] );
		self::assertSame( 'e-heading', $result['outline'][0]['widgetType'] );
		self::assertSame( 'Atomic heading', $result['outline'][0]['label'] );
		self::assertSame( 'atomic2', $result['outline'][1]['id'] );
		self::assertSame( 'e-flexbox', $result['outline'][1]['elType'] );
	}

	public function test_full_mode_returns_atomic_tree(): void {
		$result = ( new ReadAtomicTree() )->execute(
			[
				'post_id'      => 802,
				'responseMode' => 'full',
			]
		);

		self::assertIsArray( $result );
		self::assertSame( 'full', $result['response_mode'] );
		self::assertArrayHasKey( 'atomic_tree', $result );
		self::assertIsArray( $result['atomic_tree'] );
		self::assertNotEmpty( $result['atomic_tree'] );
		self::assertArrayNotHasKey( 'outline', $result );
	}

	public function test_default_max_nodes_is_two_hundred(): void {
		$schema = ( new ReadAtomicTree() )->input_schema();
		self::assertSame( 200, $schema['properties']['max_nodes']['default'] );
		self::assertSame( 500, $schema['properties']['max_nodes']['maximum'] );
		self::assertSame( 'summary', $schema['properties']['responseMode']['default'] );
	}
}
