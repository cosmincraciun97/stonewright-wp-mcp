<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV4\UpdateNode;

/**
 * @covers \Stonewright\WpMcp\Abilities\ElementorV4\UpdateNode
 */
final class V4UpdateNodeTest extends TestCase {

	private const POST_V4    = 9101;
	private const POST_V3    = 9102;
	private const POST_MIXED = 9103;

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options'] = [
			'stonewright_mode'               => 'development',
			'stonewright_elementor_v4_atomic' => true,
		];
		$GLOBALS['stonewright_test_user_caps']      = [ 'edit_post' => true, 'edit_posts' => true ];
		$GLOBALS['stonewright_test_user_logged_in'] = true;
		$GLOBALS['stonewright_test_post_meta_calls'] = [];

		$heading_settings = [
			'title' => [
				'$$type' => 'html-v3',
				'value'  => [
					'content'  => [ '$$type' => 'string', 'value' => 'Hello' ],
					'children' => [],
				],
			],
			'tag'   => [ '$$type' => 'string', 'value' => 'h2' ],
		];

		$v4_tree = [
			[
				'id'              => 'a000001',
				'version'         => '0.0',
				'elType'          => 'e-div-block',
				'isInner'         => false,
				'settings'        => [],
				'editor_settings' => [],
				'interactions'    => [],
				'styles'          => [],
				'elements'        => [
					[
						'id'              => 'heading1',
						'version'         => '0.0',
						'elType'          => 'widget',
						'widgetType'      => 'e-heading',
						'isInner'         => false,
						'settings'        => $heading_settings,
						'editor_settings' => [],
						'interactions'    => [],
						'styles'          => [],
						'elements'        => [],
					],
				],
			],
		];

		$v3_tree = [
			[
				'id'       => 'root',
				'elType'   => 'container',
				'settings' => [ 'container_type' => 'flex' ],
				'elements' => [
					[
						'id'         => 'w1',
						'elType'     => 'widget',
						'widgetType' => 'heading',
						'settings'   => [ 'title' => 'Classic' ],
						'elements'   => [],
					],
				],
			],
		];

		$mixed_path = dirname( __DIR__, 2 ) . '/fixtures/elementor-v4/mixed-v3-v4.json';
		$mixed_tree = json_decode( (string) file_get_contents( $mixed_path ), true );
		self::assertIsArray( $mixed_tree );

		// Seed atomic heading settings so merge has a real envelope to preserve.
		$mixed_tree[0]['elements'][0]['settings'] = $heading_settings;

		$GLOBALS['stonewright_test_posts'] = [
			self::POST_V4    => $this->post( self::POST_V4, 'V4 pure', $v4_tree ),
			self::POST_V3    => $this->post( self::POST_V3, 'V3 pure', $v3_tree ),
			self::POST_MIXED => $this->post( self::POST_MIXED, 'V4 mixed', $mixed_tree ),
		];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
	}

	public function test_dry_run_returns_planned_settings_without_writing(): void {
		$before = (string) $GLOBALS['stonewright_test_posts'][ self::POST_V4 ]->meta['_elementor_data'];

		$result = ( new UpdateNode() )->execute(
			[
				'post_id'    => self::POST_V4,
				'element_id' => 'heading1',
				'settings'   => [
					'tag' => [ '$$type' => 'string', 'value' => 'h1' ],
				],
				'mode'       => 'merge',
				'dry_run'    => true,
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['dry_run'] );
		self::assertSame( '', $result['snapshot_id'] );
		self::assertSame( 'v4', $result['architecture'] );
		self::assertSame( 'h1', $result['settings']['tag']['value'] );
		// Existing title preserved in planned merge.
		self::assertSame( 'Hello', $result['settings']['title']['value']['content']['value'] );

		$after = (string) $GLOBALS['stonewright_test_posts'][ self::POST_V4 ]->meta['_elementor_data'];
		self::assertSame( $before, $after, 'dry_run must not mutate _elementor_data' );
	}

	public function test_write_path_snapshots_and_updates_settings(): void {
		$result = ( new UpdateNode() )->execute(
			[
				'post_id'    => self::POST_V4,
				'element_id' => 'heading1',
				'settings'   => [
					'tag' => [ '$$type' => 'string', 'value' => 'h3' ],
				],
				'mode'       => 'merge',
				'dry_run'    => false,
			]
		);

		self::assertIsArray( $result );
		self::assertFalse( $result['dry_run'] );
		self::assertNotSame( '', $result['snapshot_id'] );
		self::assertSame( 'heading1', $result['element_id'] );
		self::assertSame( 'h3', $result['settings']['tag']['value'] );

		$post = $GLOBALS['stonewright_test_posts'][ self::POST_V4 ];
		$tree = json_decode( stripslashes( (string) $post->meta['_elementor_data'] ), true );
		self::assertIsArray( $tree );
		self::assertSame( 'h3', $tree[0]['elements'][0]['settings']['tag']['value'] );
		self::assertSame( 'Hello', $tree[0]['elements'][0]['settings']['title']['value']['content']['value'] );
		// elType / widgetType untouched.
		self::assertSame( 'widget', $tree[0]['elements'][0]['elType'] );
		self::assertSame( 'e-heading', $tree[0]['elements'][0]['widgetType'] );
	}

	public function test_unknown_node_id_returns_structured_error_with_live_ids(): void {
		$result = ( new UpdateNode() )->execute(
			[
				'post_id'    => self::POST_V4,
				'element_id' => 'missing-id',
				'settings'   => [
					'tag' => [ '$$type' => 'string', 'value' => 'h1' ],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_element_not_found', $result->get_error_code() );
		$data = $result->get_error_data();
		self::assertIsArray( $data );
		self::assertArrayHasKey( 'live_atomic_ids', $data );
		self::assertContains( 'heading1', $data['live_atomic_ids'] );
		self::assertContains( 'a000001', $data['live_atomic_ids'] );
	}

	public function test_pure_v3_document_returns_v4_architecture_mismatch(): void {
		$result = ( new UpdateNode() )->execute(
			[
				'post_id'    => self::POST_V3,
				'element_id' => 'w1',
				'settings'   => [
					'title' => [ '$$type' => 'string', 'value' => 'nope' ],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_v4_architecture_mismatch', $result->get_error_code() );
		$data = $result->get_error_data();
		self::assertIsArray( $data );
		self::assertSame( 'v3', $data['architecture'] );
	}

	public function test_non_atomic_target_in_mixed_tree_rejected(): void {
		$result = ( new UpdateNode() )->execute(
			[
				'post_id'    => self::POST_MIXED,
				'element_id' => 'legacy1',
				'settings'   => [
					'foo' => [ '$$type' => 'string', 'value' => 'bar' ],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_non_atomic_target', $result->get_error_code() );
	}

	public function test_known_schema_key_requires_typed_envelope(): void {
		$result = ( new UpdateNode() )->execute(
			[
				'post_id'    => self::POST_V4,
				'element_id' => 'heading1',
				'settings'   => [
					'title' => 'plain string is invalid for atomic',
				],
				'dry_run'    => true,
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_invalid_settings_envelope', $result->get_error_code() );
	}

	public function test_known_schema_key_with_envelope_accepted(): void {
		$result = ( new UpdateNode() )->execute(
			[
				'post_id'    => self::POST_V4,
				'element_id' => 'heading1',
				'settings'   => [
					'title' => [
						'$$type' => 'html-v3',
						'value'  => [
							'content'  => [ '$$type' => 'string', 'value' => 'Updated' ],
							'children' => [],
						],
					],
				],
				'dry_run'    => true,
			]
		);

		self::assertIsArray( $result );
		self::assertSame( 'Updated', $result['settings']['title']['value']['content']['value'] );
	}

	public function test_unknown_new_key_without_envelope_rejected(): void {
		$result = ( new UpdateNode() )->execute(
			[
				'post_id'    => self::POST_V4,
				'element_id' => 'heading1',
				'settings'   => [
					'not_a_real_prop' => 'no envelope',
				],
				'dry_run'    => true,
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_unknown_settings_key', $result->get_error_code() );
	}

	public function test_permission_callback_uses_edit_post_not_return_true(): void {
		$ability = new UpdateNode();
		$source  = file_get_contents( (string) ( new \ReflectionClass( UpdateNode::class ) )->getFileName() );
		self::assertIsString( $source );
		self::assertStringContainsString( 'Permissions::edit_post', $source );
		self::assertStringNotContainsString( '__return_true', $source );

		$ok = $ability->permission_callback(
			[
				'post_id' => self::POST_V4,
				'dry_run' => true,
			]
		);
		self::assertTrue( $ok );
	}

	/**
	 * @param array<int, array<string, mixed>> $tree
	 */
	private function post( int $id, string $title, array $tree ): object {
		return (object) [
			'ID'           => $id,
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_title'   => $title,
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
