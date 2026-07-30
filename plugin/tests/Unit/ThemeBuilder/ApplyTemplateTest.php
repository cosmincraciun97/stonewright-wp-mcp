<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ThemeBuilder;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ThemeBuilder\ApplyTemplate;

/**
 * @covers \Stonewright\WpMcp\Abilities\ThemeBuilder\ApplyTemplate
 */
final class ApplyTemplateTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']                = [];
		$GLOBALS['stonewright_test_posts']                  = [];
		$GLOBALS['stonewright_test_post_meta_calls']        = [];
		$GLOBALS['stonewright_test_inserted_posts']         = [];
		$GLOBALS['stonewright_test_next_post_id']           = 8100;
		$GLOBALS['stonewright_test_user_logged_in']         = true;
		$GLOBALS['stonewright_test_user_caps']              = [ 'edit_posts' => true ];
		$GLOBALS['stonewright_test_user_can_callback']      = static fn( string $cap ): bool => in_array( $cap, [ 'edit_posts', 'edit_post' ], true );
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options']                = [];
		$GLOBALS['stonewright_test_posts']                  = [];
		$GLOBALS['stonewright_test_post_meta_calls']        = [];
		$GLOBALS['stonewright_test_inserted_posts']         = [];
		$GLOBALS['stonewright_test_user_logged_in']         = false;
		$GLOBALS['stonewright_test_user_caps']              = [];
		unset( $GLOBALS['stonewright_test_user_can_callback'] );
	}

	public function test_applies_template_spec_conditions_cache_and_verify_hint_in_one_call(): void {
		$result = ( new ApplyTemplate() )->execute(
			[
				'title'         => 'Single Exhibitor',
				'template_type' => 'single',
				'conditions'    => [
					[ 'type' => 'include', 'name' => 'singular', 'sub_name' => 'exhibitor' ],
				],
				'spec'          => self::minimal_spec(),
				'verify_url'    => 'https://example.test/exhibitors/acme',
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['applied'] );
		self::assertTrue( $result['created'] );
		self::assertSame( 'single', $result['template_type'] );
		self::assertSame( 8100, $result['template_id'] );
		self::assertSame( 'stonewright/theme-builder-apply-template', $result['next_required_call']['ability'] );
		self::assertSame( 'stonewright-theme-builder-apply-template', $result['next_required_call']['mcp_tool'] );
		self::assertSame( [], $result['repair_hints'] );
		self::assertSame( 200, $result['verify']['http_status'] );
		self::assertGreaterThan( 0, $result['element_count'] );

		$meta = $GLOBALS['stonewright_test_posts'][8100]->meta ?? [];
		self::assertSame( 'single', $meta['_elementor_template_type'] ?? null );
		self::assertSame( [ 'include/singular/exhibitor' ], $meta['_elementor_conditions'] ?? null );
		self::assertNotEmpty( $meta['_elementor_data'] ?? '' );

		$cache = $GLOBALS['stonewright_test_options']['elementor_pro_theme_builder_conditions'] ?? [];
		self::assertSame( [ 'include/singular/exhibitor' ], $cache['single'][8100] ?? null );
	}

	public function test_dry_run_returns_preview_without_creating_template(): void {
		$result = ( new ApplyTemplate() )->execute(
			[
				'title'         => 'Dry Run Header',
				'template_type' => 'header',
				'conditions'    => [ [ 'type' => 'include', 'name' => 'general' ] ],
				'spec'          => self::minimal_spec(),
				'dry_run'       => true,
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['dry_run'] );
		self::assertFalse( $result['applied'] );
		self::assertSame( 0, $result['template_id'] );
		self::assertSame( [], $GLOBALS['stonewright_test_inserted_posts'] );
		self::assertNotEmpty( $result['preview'] );
	}

	private static function minimal_spec(): array {
		return [
			'page'     => [ 'title' => 'Template' ],
			'sections' => [
				[
					'id'     => 'content',
					'blocks' => [
						[ 'type' => 'heading', 'text' => 'Exhibitor' ],
					],
				],
			],
		];
	}
}
