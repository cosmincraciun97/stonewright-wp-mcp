<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ElementorV3;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\BuildTree;
use Stonewright\WpMcp\Abilities\ElementorV3\PageDigest;
use Stonewright\WpMcp\Elementor\Schema\SettingsKeyAliases;
use Stonewright\WpMcp\Core\AbilityRegistry;

/**
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\PageDigest
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\BuildTree
 * @covers \Stonewright\WpMcp\Elementor\Schema\SettingsKeyAliases
 */
final class PageDigestBuildTreeTest extends TestCase {

	private int $post_id = 9201;

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps'] = [
			'edit_posts' => true,
			'edit_pages' => true,
			'edit_post'  => true,
		];
		$GLOBALS['stonewright_test_user_logged_in'] = true;
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		$GLOBALS['stonewright_test_options'] = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_posts'][ $this->post_id ] = (object) [
			'ID'           => $this->post_id,
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_title'   => 'Digest Page',
			'post_content' => '',
			'post_excerpt' => '',
			'post_name'    => 'digest-page',
			'meta'         => [
				'_elementor_edit_mode' => 'builder',
				'_elementor_data'      => wp_json_encode(
					[
						[
							'id'       => 'sec001',
							'elType'   => 'container',
							'settings' => [],
							'elements' => [
								[
									'id'         => 'wid001',
									'elType'     => 'widget',
									'widgetType' => 'heading',
									'settings'   => [ 'title' => 'Hello world from digest' ],
									'elements'   => [],
								],
							],
						],
					]
				),
			],
		];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_posts'] = [];
		$GLOBALS['stonewright_test_user_caps'] = [];
	}

	public function test_registry_has_new_abilities(): void {
		self::assertInstanceOf( PageDigest::class, AbilityRegistry::ability_by_name( 'stonewright/elementor-page-digest' ) );
		self::assertInstanceOf( BuildTree::class, AbilityRegistry::ability_by_name( 'stonewright/elementor-build-tree' ) );
	}

	public function test_settings_key_aliases_normalize_justify_content(): void {
		$result = SettingsKeyAliases::normalize(
			[
				'justify_content' => 'center',
				'bg_color'        => '#fff',
			]
		);
		self::assertSame( 'center', $result['settings']['flex_justify_content'] );
		self::assertArrayNotHasKey( 'justify_content', $result['settings'] );
		self::assertSame( '#fff', $result['settings']['background_color'] );
		self::assertNotEmpty( $result['applied'] );
	}

	public function test_page_digest_returns_compact_outline(): void {
		$result = ( new PageDigest() )->execute( [ 'post_id' => $this->post_id ] );
		self::assertIsArray( $result );
		self::assertSame( $this->post_id, $result['post_id'] );
		self::assertTrue( $result['active'] );
		self::assertGreaterThanOrEqual( 2, $result['counts']['total'] );
		self::assertNotEmpty( $result['outline'] );
		self::assertArrayHasKey( 'index_path', $result['outline'][0] );
		self::assertStringContainsString( 'Hello', (string) ( $result['outline'][1]['heading'] ?? $result['outline'][0]['heading'] ?? '' ) );
		self::assertLessThan( 800, (int) $result['estimated_tokens'] );
	}

	public function test_build_tree_dry_run_and_path_error(): void {
		$ability = new BuildTree();
		$ok      = $ability->execute(
			[
				'post_id' => $this->post_id,
				'dry_run' => true,
				'tree'    => [
					[
						'id'       => 'a1b2c3d',
						'elType'   => 'container',
						'settings' => [ 'justify_content' => 'center' ],
						'elements' => [],
					],
				],
			]
		);
		self::assertIsArray( $ok );
		self::assertTrue( $ok['ok'] );
		self::assertTrue( $ok['dry_run'] );
		self::assertGreaterThanOrEqual( 1, (int) $ok['aliases_applied'] );

		$err = $ability->execute(
			[
				'post_id' => $this->post_id,
				'dry_run' => true,
				'tree'    => [
					[
						'elType' => 'widget',
						// missing widgetType
						'settings' => [],
						'elements' => [],
					],
				],
			]
		);
		self::assertInstanceOf( \WP_Error::class, $err );
		self::assertStringContainsString( 'tree[0]', $err->get_error_message() );
	}

	public function test_build_tree_writes_with_snapshot(): void {
		$ability = new BuildTree();
		$result  = $ability->execute(
			[
				'post_id' => $this->post_id,
				'tree'    => [
					[
						'id'       => 'root001',
						'elType'   => 'container',
						'settings' => [],
						'elements' => [
							[
								'id'         => 'head001',
								'elType'     => 'widget',
								'widgetType' => 'heading',
								'settings'   => [ 'title' => 'Built' ],
								'elements'   => [],
							],
						],
					],
				],
			]
		);
		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertNotSame( '', $result['snapshot_id'] );
		self::assertGreaterThanOrEqual( 2, (int) $result['element_count'] );
	}
}
