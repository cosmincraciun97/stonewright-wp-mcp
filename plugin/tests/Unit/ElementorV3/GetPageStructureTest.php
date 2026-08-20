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
			702 => (object) [
				'ID'           => 702,
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Private page title must not leak',
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
								'styles'   => [
									[
										'id'  => 's-root',
										'css' => '.hero{color:#111}',
									],
								],
								'elements' => [
									[
										'id'         => 'headline',
										'elType'     => 'widget',
										'widgetType' => 'heading',
										'settings'   => [
											'title'           => 'Fast native Elementor',
											'header_size'     => 'h1',
											'invented_secret' => 'drop-me',
											'file_bytes'      => str_repeat( 'A', 4000 ),
										],
										'styles'     => [
											[
												'__style_id' => 's-headline',
												'css'        => str_repeat( '.x{color:red}', 2000 ),
											],
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

	public function test_schema_accepts_include_content_and_subtree_root(): void {
		$schema = ( new GetPageStructure() )->input_schema();

		self::assertArrayHasKey( 'include_content', $schema['properties'] );
		self::assertSame( 'boolean', $schema['properties']['include_content']['type'] );
		self::assertFalse( $schema['properties']['include_content']['default'] );
		self::assertArrayHasKey( 'root_id', $schema['properties'] );
	}

	public function test_include_content_false_omits_css_and_setting_values(): void {
		$result = ( new GetPageStructure() )->execute(
			[
				'post_id'         => 702,
				'include_content' => false,
			]
		);

		self::assertIsArray( $result );
		self::assertArrayNotHasKey( 'nodes', $result );
		$encoded = (string) wp_json_encode( $result );
		self::assertStringNotContainsString( '.hero{color:#111}', $encoded );
		self::assertStringNotContainsString( 'drop-me', $encoded );
		self::assertStringNotContainsString( str_repeat( 'A', 32 ), $encoded );
		self::assertArrayNotHasKey( 'tree', $result );
	}

	public function test_include_content_true_returns_bounded_styles_and_allowlisted_settings(): void {
		$result = ( new GetPageStructure() )->execute(
			[
				'post_id'         => 702,
				'include_content' => true,
				'maxElements'     => 10,
			]
		);

		self::assertIsArray( $result );
		self::assertArrayNotHasKey( 'tree', $result );
		self::assertArrayHasKey( 'nodes', $result );
		$by_id = [];
		foreach ( (array) $result['nodes'] as $node ) {
			$by_id[ (string) ( $node['id'] ?? '' ) ] = $node;
		}
		self::assertArrayHasKey( 'headline', $by_id );
		self::assertSame( 'Fast native Elementor', $by_id['headline']['settings']['title'] );
		self::assertSame( 'h1', $by_id['headline']['settings']['header_size'] );
		self::assertArrayNotHasKey( 'invented_secret', $by_id['headline']['settings'] );
		self::assertArrayNotHasKey( 'file_bytes', $by_id['headline']['settings'] );
		self::assertSame(
			[
				'__style_id' => 's-root',
				'css'        => '.hero{color:#111}',
			],
			$by_id['root']['styles'][0]
		);
		self::assertSame( 's-headline', $by_id['headline']['styles'][0]['__style_id'] );
		self::assertArrayHasKey( 'css', $by_id['headline']['styles'][0] );
		self::assertLessThanOrEqual( 8192, strlen( (string) $by_id['headline']['styles'][0]['css'] ) );
		$encoded = (string) wp_json_encode( $result );
		self::assertStringNotContainsString( 'Private page title must not leak', $encoded );
		self::assertStringNotContainsString( str_repeat( 'A', 64 ), $encoded );
		self::assertLessThan( 80000, strlen( $encoded ) );
	}

	public function test_include_content_can_scope_to_root_id_subtree(): void {
		$result = ( new GetPageStructure() )->execute(
			[
				'post_id'         => 702,
				'include_content' => true,
				'root_id'         => 'headline',
			]
		);

		self::assertIsArray( $result );
		$ids = array_map(
			static fn( array $node ): string => (string) ( $node['id'] ?? '' ),
			(array) ( $result['nodes'] ?? [] )
		);
		self::assertSame( [ 'headline' ], $ids );
		self::assertSame( 1, $result['returned_count'] );
	}
}
