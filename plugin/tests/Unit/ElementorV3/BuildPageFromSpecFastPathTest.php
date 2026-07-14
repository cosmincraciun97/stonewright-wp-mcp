<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ElementorV3;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\BuildPageFromSpec;
use Stonewright\WpMcp\Elementor\Renderer\Section;

/**
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\BuildPageFromSpec
 */
final class BuildPageFromSpecFastPathTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_posts'] = [
			777 => (object) [
				'ID'           => 777,
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Spec target',
				'post_content' => '',
				'post_excerpt' => '',
				'meta'         => [
					'_elementor_data'      => '[{"id":"keep","elType":"container","settings":[],"elements":[]}]',
					'_elementor_edit_mode' => 'builder',
					'_elementor_version'   => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.0.0',
				],
			],
		];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_options'] = [ 'stonewright_mode' => 'development' ];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_posts'] = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_options'] = [];
	}

	public function test_dry_run_renders_metrics_without_snapshot_or_write(): void {
		$result = ( new BuildPageFromSpec() )->execute(
			[
				'post_id' => 777,
				'dry_run' => true,
				'spec'    => self::spec( 'Dry Run' ),
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['dry_run'] );
		self::assertSame( 2, $result['elements'] );
		self::assertArrayHasKey( 'preview', $result );
		self::assertGreaterThanOrEqual( 0.0, $result['metrics']['elapsed_ms'] );
		self::assertGreaterThanOrEqual( 0.0, $result['metrics']['render_ms'] );
		self::assertSame( 0.0, $result['metrics']['write_ms'] );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );
	}

	public function test_strict_visual_spec_requires_design_evidence_before_render(): void {
		$spec = self::spec( 'Verified heading' );
		$spec['style_policy'] = 'strict';

		$result = ( new BuildPageFromSpec() )->execute(
			[
				'post_id' => 777,
				'dry_run' => true,
				'spec'    => $spec,
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_design_evidence_invalid', $result->get_error_code() );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );
	}

	public function test_append_mode_keeps_existing_top_level_elements(): void {
		$result = ( new BuildPageFromSpec() )->execute(
			[
				'post_id' => 777,
				'mode'    => 'append',
				'spec'    => self::spec( 'Append' ),
			]
		);

		self::assertIsArray( $result );

		$post = $GLOBALS['stonewright_test_posts'][777];
		$tree = json_decode( stripslashes( (string) $post->meta['_elementor_data'] ), true );
		self::assertSame( 'keep', $tree[0]['id'] );
		self::assertSame( Section::stable_id( 's0' ), $tree[1]['id'] );
	}

	public function test_replace_section_mode_replaces_matching_sections_only(): void {
		$section_id = Section::stable_id( 's0' );
		$GLOBALS['stonewright_test_posts'][777]->meta['_elementor_data'] = wp_json_encode(
			[
				[ 'id' => 'keep', 'elType' => 'container', 'settings' => [], 'elements' => [] ],
				[
					'id'       => $section_id,
					'elType'   => 'container',
					'settings' => [],
					'elements' => [
						[
							'id'         => 'old',
							'elType'     => 'widget',
							'widgetType' => 'heading',
							'settings'   => [ 'title' => 'Old' ],
							'elements'   => [],
						],
					],
				],
			],
			JSON_UNESCAPED_SLASHES
		);

		$result = ( new BuildPageFromSpec() )->execute(
			[
				'post_id' => 777,
				'mode'    => 'replace_section',
				'spec'    => self::spec( 'New' ),
			]
		);

		self::assertIsArray( $result );

		$post = $GLOBALS['stonewright_test_posts'][777];
		$tree = json_decode( stripslashes( (string) $post->meta['_elementor_data'] ), true );
		self::assertSame( 'keep', $tree[0]['id'] );
		self::assertSame( $section_id, $tree[1]['id'] );
		self::assertSame( 'New', $tree[1]['elements'][0]['settings']['title'] );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function spec( string $title ): array {
		return [
			'version'  => '1.0.0',
			'page'     => [ 'title' => 'Fast Path' ],
			'sections' => [
				[
					'id'     => 'hero',
					'blocks' => [
						[ 'type' => 'heading', 'text' => $title, 'level' => 1 ],
					],
				],
			],
		];
	}
}
