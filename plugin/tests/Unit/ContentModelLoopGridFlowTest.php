<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ContentModel\CptAcfLoopGridFlow;

/**
 * @covers \Stonewright\WpMcp\Abilities\ContentModel\CptAcfLoopGridFlow
 */
final class ContentModelLoopGridFlowTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']                = [];
		$GLOBALS['stonewright_test_post_types']             = [];
		$GLOBALS['stonewright_test_posts']                  = [];
		$GLOBALS['stonewright_test_post_meta_calls']        = [];
		$GLOBALS['stonewright_test_inserted_posts']         = [];
		$GLOBALS['stonewright_test_next_post_id']           = 9100;
		$GLOBALS['stonewright_test_user_logged_in']         = true;
		$GLOBALS['stonewright_test_user_caps']              = [ 'edit_posts' => true, 'publish_posts' => true ];
		$GLOBALS['stonewright_test_user_can_callback']      = static function ( string $cap ): bool {
			return in_array( $cap, [ 'edit_posts', 'publish_posts', 'edit_post_meta', 'edit_post' ], true );
		};
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options']                = [];
		$GLOBALS['stonewright_test_post_types']             = [];
		$GLOBALS['stonewright_test_posts']                  = [];
		$GLOBALS['stonewright_test_post_meta_calls']        = [];
		$GLOBALS['stonewright_test_inserted_posts']         = [];
		$GLOBALS['stonewright_test_user_logged_in']         = false;
		$GLOBALS['stonewright_test_user_caps']              = [];
		unset( $GLOBALS['stonewright_test_user_can_callback'] );
	}

	public function test_creates_cpt_acf_fields_rows_loop_template_and_grid_widget_contract(): void {
		$result = ( new CptAcfLoopGridFlow() )->execute(
			[
				'post_type'     => [
					'slug'     => 'featured_solution',
					'singular' => 'Featured Solution',
					'plural'   => 'Featured Solutions',
				],
				'fields'        => [
					[ 'name' => 'subtitle', 'label' => 'Subtitle', 'type' => 'text' ],
					[ 'name' => 'cta_url', 'label' => 'CTA URL', 'type' => 'url' ],
				],
				'items'         => [
					[
						'slug'   => 'solar-roof',
						'title'  => 'Solar Roof',
						'status' => 'publish',
						'meta'   => [
							'subtitle' => 'nZEB ready',
							'cta_url'  => '/solutions/solar-roof',
						],
					],
				],
				'loop_template' => [
					'title'        => 'Featured Solution Card',
					'link_to_post' => true,
					'spec'         => self::card_spec(),
				],
				'grid'          => [
					'columns'        => 3,
					'posts_per_page' => 6,
				],
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( 'featured_solution', $result['post_type']['slug'] );
		self::assertTrue( $result['post_type']['registered_runtime'] );
		self::assertSame( 'featured_solution', $GLOBALS['stonewright_test_options']['cptui_post_types']['featured_solution']['name'] ?? null );
		self::assertSame( 2, $result['acf']['field_count'] );
		self::assertSame( 'group_stonewright_featured_solution', $result['acf']['field_group_key'] );
		self::assertSame( 1, $result['content']['created'] );
		self::assertSame( 9101, $result['loop_template']['template_id'] );
		self::assertSame( 'loop-item', $GLOBALS['stonewright_test_posts'][9101]->meta['_elementor_template_type'] ?? null );
		self::assertSame( 'loop-grid', $result['loop_grid_widget']['widgetType'] );
		self::assertSame( 9101, $result['loop_grid_widget']['settings']['template_id'] );
		self::assertSame( 'featured_solution', $result['loop_grid_widget']['settings']['post_type'] );
		self::assertSame( 'stonewright/elementor-v3-batch-mutate', $result['next_required_call']['ability'] );
		self::assertContains( 'Use loop_grid_widget as the widget settings payload for the target Elementor archive/listing section.', $result['repair_hints'] );
	}

	public function test_dry_run_returns_flow_plan_without_writes(): void {
		$result = ( new CptAcfLoopGridFlow() )->execute(
			[
				'post_type' => [
					'slug'     => 'speaker',
					'singular' => 'Speaker',
					'plural'   => 'Speakers',
				],
				'fields'    => [ [ 'name' => 'role', 'label' => 'Role', 'type' => 'text' ] ],
				'items'     => [ [ 'slug' => 'ana', 'title' => 'Ana' ] ],
				'dry_run'   => true,
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['dry_run'] );
		self::assertFalse( $result['ok'] );
		self::assertSame( [], $GLOBALS['stonewright_test_inserted_posts'] );
		self::assertSame( [], $GLOBALS['stonewright_test_post_types'] );
		self::assertSame( 'stonewright/content-model-loop-grid-flow', $result['next_required_call']['ability'] );
	}

	private static function card_spec(): array {
		return [
			'page'     => [ 'title' => 'Card' ],
			'sections' => [
				[
					'id'     => 'card',
					'blocks' => [
						[ 'type' => 'heading', 'text' => 'Dynamic title' ],
						[ 'type' => 'paragraph', 'text' => 'Dynamic subtitle' ],
					],
				],
			],
		];
	}
}
