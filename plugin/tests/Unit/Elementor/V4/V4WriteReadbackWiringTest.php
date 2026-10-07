<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\V4;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV4\RenderFromSpec;
use Stonewright\WpMcp\Abilities\ElementorV4\UpdateNode;
use Stonewright\WpMcp\Elementor\V4\AtomicSchemaRepository;
use Stonewright\WpMcp\Elementor\V4\V4FeatureGate;

/**
 * The Stonewright V4 writers return the mandatory nested readback result.
 *
 * @covers \Stonewright\WpMcp\Abilities\ElementorV4\UpdateNode
 * @covers \Stonewright\WpMcp\Abilities\ElementorV4\RenderFromSpec
 */
final class V4WriteReadbackWiringTest extends TestCase {

	private const POST = 9501;

	protected function setUp(): void {
		AtomicSchemaRepository::invalidate();
		V4FeatureGate::set_atomic_module_present_for_tests( true );
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development', 'stonewright_elementor_v4_atomic' => true ];
		$GLOBALS['stonewright_test_user_caps']       = [ 'edit_post' => true, 'edit_posts' => true ];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$tree = [
			[ 'id' => 'a000001', 'version' => '0.0', 'elType' => 'e-div-block', 'isInner' => false, 'settings' => [], 'editor_settings' => [], 'interactions' => [], 'styles' => [], 'elements' => [
				[ 'id' => 'heading1', 'version' => '0.0', 'elType' => 'widget', 'widgetType' => 'e-heading', 'isInner' => false, 'settings' => [ 'tag' => [ '$$type' => 'string', 'value' => 'h2' ] ], 'editor_settings' => [], 'interactions' => [], 'styles' => [], 'elements' => [] ],
			] ],
		];
		$GLOBALS['stonewright_test_posts'] = [
			self::POST => (object) [ 'ID' => self::POST, 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'V4', 'post_content' => '', 'post_excerpt' => '', 'meta' => [ '_elementor_data' => wp_json_encode( $tree ), '_elementor_edit_mode' => 'builder' ] ],
		];
	}

	protected function tearDown(): void {
		V4FeatureGate::set_atomic_module_present_for_tests( null );
		AtomicSchemaRepository::invalidate();
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
		$GLOBALS['stonewright_test_current_user_id'] = 0;
	}

	public function test_update_node_returns_a_verified_nested_readback_of_the_whole_document(): void {
		$result = ( new UpdateNode() )->execute( [ 'post_id' => self::POST, 'element_id' => 'heading1', 'settings' => [ 'tag' => [ '$$type' => 'heading-level', 'value' => 'h3' ] ] ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertSame( [ 'verified' => true, 'method' => 'document_tree', 'checked' => 2, 'context' => 'update_node' ], $result['readback'] );
		self::assertArrayHasKey( 'readback', ( new UpdateNode() )->output_schema()['properties'] );
	}

	public function test_render_from_spec_returns_a_verified_nested_readback(): void {
		$result = ( new RenderFromSpec() )->execute(
			[
				'post_id' => self::POST,
				'dry_run' => false,
				'spec'    => [
					'page'     => [ 'title' => 'Synthetic page' ],
					'sections' => [ [ 'id' => 'hero', 'blocks' => [ [ 'type' => 'heading', 'text' => 'Safe heading', 'level' => 2 ] ] ] ],
				],
			]
		);

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertTrue( $result['readback']['verified'] );
		self::assertSame( 'render_from_spec', $result['readback']['context'] );
		self::assertGreaterThanOrEqual( 3, $result['readback']['checked'], 'the existing document and every rendered node are compared' );
		self::assertArrayHasKey( 'readback', ( new RenderFromSpec() )->output_schema()['properties'] );
	}
}
