<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ElementorV4;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV4\RenderFromSpec;
use Stonewright\WpMcp\Elementor\V4\AtomicSchemaRepository;
use Stonewright\WpMcp\Elementor\V4\V4FeatureGate;

/** @covers \Stonewright\WpMcp\Abilities\ElementorV4\RenderFromSpec */
final class RenderFromSpecTest extends TestCase {

	private const POST_ID = 9120;

	protected function setUp(): void {
		AtomicSchemaRepository::invalidate();
		V4FeatureGate::set_atomic_module_present_for_tests( true );
		$GLOBALS['stonewright_test_options'] = [
			'stonewright_mode'                => 'development',
			'stonewright_elementor_v4_atomic' => true,
		];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_update_post_meta_returns'] = [ '_stonewright_backups' => false ];
		$GLOBALS['stonewright_test_posts'] = [
			self::POST_ID => (object) [
				'ID'           => self::POST_ID,
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Synthetic page',
				'post_content' => '',
				'post_excerpt' => '',
				'meta'         => [ '_elementor_data' => '[]' ],
			],
		];
	}

	protected function tearDown(): void {
		V4FeatureGate::set_atomic_module_present_for_tests( null );
		AtomicSchemaRepository::invalidate();
		$GLOBALS['stonewright_test_options'] = [];
		$GLOBALS['stonewright_test_posts'] = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		unset( $GLOBALS['stonewright_test_update_post_meta_returns'] );
	}

	public function test_failed_backup_snapshot_aborts_before_elementor_write(): void {
		$result = ( new RenderFromSpec() )->execute(
			[
				'post_id' => self::POST_ID,
				'dry_run' => false,
				'spec'    => [
					'page' => [ 'title' => 'Synthetic page' ],
					'sections' => [
						[
							'id' => 'hero',
							'blocks' => [ [ 'type' => 'heading', 'text' => 'Safe heading', 'level' => 2 ] ],
						],
					],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_backup_failed', $result->get_error_code() );
		self::assertNotContains( '_elementor_data', array_column( $GLOBALS['stonewright_test_post_meta_calls'], 'meta_key' ) );
	}

	public function test_dry_run_renders_the_spec_into_a_typed_atomic_tree_without_writing(): void {
		$result = ( new RenderFromSpec() )->execute(
			[
				'post_id' => self::POST_ID,
				'spec'    => [
					'page'     => [ 'title' => 'Synthetic page' ],
					'sections' => [
						[
							'id'        => 'hero',
							'direction' => 'column',
							'gap'       => '24px',
							'blocks'    => [
								[ 'type' => 'heading', 'text' => 'Safe heading', 'level' => 2 ],
								[ 'type' => 'paragraph', 'text' => 'Body copy.' ],
								[ 'type' => 'button', 'text' => 'Start', 'url' => 'https://example.com/start' ],
								[ 'type' => 'separator' ],
							],
						],
					],
				],
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertTrue( $result['dry_run'] );
		self::assertSame( '', $result['snapshot_id'] );
		self::assertSame( [], $result['errors'] );
		self::assertSame( [], $result['diagnostics'] );
		self::assertCount( 1, $result['atomic_tree'] );

		$section = $result['atomic_tree'][0];
		self::assertSame( 'e-flexbox', $section['elType'] );
		self::assertArrayNotHasKey( 'widgetType', $section );
		self::assertCount( 4, $section['elements'] );

		$style_id    = $section['settings']['classes']['value'][0];
		$style_props = $section['styles'][ $style_id ]['variants'][0]['props'];
		self::assertSame( [ '$$type' => 'string', 'value' => 'column' ], $style_props['flex-direction'] );
		self::assertSame( [ '$$type' => 'size', 'value' => [ 'unit' => 'px', 'size' => 24.0 ] ], $style_props['gap'] );

		[ $heading, $paragraph, $button, $divider ] = $section['elements'];
		self::assertSame( 'e-heading', $heading['widgetType'] );
		self::assertSame( 'html-v3', $heading['settings']['title']['$$type'] );
		self::assertSame( 'Safe heading', $heading['settings']['title']['value']['content']['value'] );
		self::assertSame( [ '$$type' => 'string', 'value' => 'h2' ], $heading['settings']['tag'] );
		self::assertSame( 'e-paragraph', $paragraph['widgetType'] );
		self::assertSame( 'e-button', $button['widgetType'] );
		self::assertSame( 'link', $button['settings']['link']['$$type'] );
		self::assertSame( 'https://example.com/start', $button['settings']['link']['value']['destination']['value'] );
		self::assertSame( 'e-divider', $divider['widgetType'] );

		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );
	}

	public function test_block_type_without_a_certified_atomic_schema_is_a_structured_error_and_writes_nothing(): void {
		$result = ( new RenderFromSpec() )->execute(
			[
				'post_id' => self::POST_ID,
				'dry_run' => false,
				'spec'    => [
					'page'     => [ 'title' => 'Synthetic page' ],
					'sections' => [
						[
							'id'     => 'hero',
							'blocks' => [
								[ 'type' => 'heading', 'text' => 'Safe heading', 'level' => 2 ],
								[ 'type' => 'list', 'items' => [ 'One' ] ],
							],
						],
					],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_v4_unknown_node', $result->get_error_code() );
		$data = $result->get_error_data();
		self::assertSame( [ 'sections', 0, 'children', 1 ], $data['path'] );
		self::assertSame( 'list', $data['received'] );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );
	}

	public function test_class_docblock_describes_the_validated_spec_to_atomic_renderer_pipeline(): void {
		$docblock = ( new \ReflectionClass( RenderFromSpec::class ) )->getDocComment();

		self::assertIsString( $docblock );
		self::assertStringContainsString( 'Validator::validate()', $docblock );
		self::assertStringContainsString( 'ElementorV4SpecRenderer', $docblock );
		self::assertStringContainsString( 'AtomicRenderer', $docblock );
		self::assertStringContainsString( '$$type', $docblock );
		self::assertStringContainsString( 'WP_Error', $docblock );
		self::assertStringNotContainsStringIgnoringCase( 'stub', $docblock );
		self::assertStringNotContainsStringIgnoringCase( 'placeholder', $docblock );
		self::assertStringNotContainsStringIgnoringCase( 'roadmap', $docblock );
	}
}
