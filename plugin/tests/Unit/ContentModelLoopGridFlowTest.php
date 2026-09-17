<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ContentModel\CptAcfLoopGridFlow;
use Stonewright\WpMcp\Elementor\Schema\WidgetSchemaRepository;

require_once dirname( __DIR__ ) . '/Unit/Elementor/Loop/SyntheticLoopWidgets.php';

/**
 * @covers \Stonewright\WpMcp\Abilities\ContentModel\CptAcfLoopGridFlow
 */
final class ContentModelLoopGridFlowTest extends TestCase {
	private object $original_elementor;

	protected function setUp(): void {
		$this->original_elementor = \Elementor\Plugin::$instance;
		$GLOBALS['stonewright_test_options']                = [ 'active_plugins' => [] ];
		$GLOBALS['stonewright_test_transients']             = [];
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
		unset( $GLOBALS['stonewright_test_loop_control_overrides'] );
		$fallback = $this->original_elementor->widgets_manager ?? new \stdClass();
		\Elementor\Plugin::$instance = (object) [
			'widgets_manager' => new class( $fallback ) {
				public function __construct( private object $fallback ) {
				}

				public function get_widget_types( ?string $name = null ): array|object|null {
					$loops = [
						'loop-carousel' => new \Stonewright\WpMcp\Tests\Unit\Elementor\Loop\SyntheticLoopCarouselWidget(),
						'loop-grid'     => new \Stonewright\WpMcp\Tests\Unit\Elementor\Loop\SyntheticLoopGridWidget(),
					];
					if ( null === $name ) {
						$base = method_exists( $this->fallback, 'get_widget_types' )
							? (array) $this->fallback->get_widget_types()
							: [];
						return array_merge( $base, $loops );
					}
					if ( isset( $loops[ $name ] ) ) {
						return $loops[ $name ];
					}
					return method_exists( $this->fallback, 'get_widget_types' )
						? $this->fallback->get_widget_types( $name )
						: null;
				}
			},
		];
		WidgetSchemaRepository::reset_request_cache();
	}

	protected function tearDown(): void {
		\Elementor\Plugin::$instance = $this->original_elementor;
		$GLOBALS['stonewright_test_options']                = [];
		$GLOBALS['stonewright_test_transients']             = [];
		$GLOBALS['stonewright_test_post_types']             = [];
		$GLOBALS['stonewright_test_posts']                  = [];
		$GLOBALS['stonewright_test_post_meta_calls']        = [];
		$GLOBALS['stonewright_test_inserted_posts']         = [];
		$GLOBALS['stonewright_test_user_logged_in']         = false;
		$GLOBALS['stonewright_test_user_caps']              = [];
		unset( $GLOBALS['stonewright_test_user_can_callback'], $GLOBALS['stonewright_test_loop_control_overrides'] );
		WidgetSchemaRepository::reset_request_cache();
	}

	public function test_creates_cpt_acf_fields_rows_loop_template_and_grid_widget_contract(): void {
		$this->include_registered_post_types_in_loop_schema();
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
							'subtitle' => 'Example ready',
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
		self::assertSame( 'featured_solution', $result['loop_grid_widget']['settings']['query_post_type'] );
		self::assertArrayNotHasKey( 'post_type', $result['loop_grid_widget']['settings'] );
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

	public function test_child_failure_propagates_failed_step_and_root_error_code(): void {
		$result = ( new CptAcfLoopGridFlow() )->execute(
			[
				'post_type'     => [
					'slug'     => 'speaker',
					'singular' => 'Speaker',
					'plural'   => 'Speakers',
				],
				'fields'        => [ [ 'name' => 'role', 'label' => 'Role', 'type' => 'text' ] ],
				'items'         => [ [ 'slug' => 'ana', 'title' => 'Ana' ] ],
				'loop_template' => [
					'title' => 'Broken card',
					'spec'  => [ 'not' => 'a-design-spec' ],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		$data = $result->get_error_data();
		self::assertSame( 'loop_template', $data['failed_step'] );
		self::assertNotSame( '', (string) ( $data['root_error_code'] ?? '' ) );
		self::assertSame( $data['root_error_code'], $result->get_error_code() );
		$steps = (array) ( $data['steps'] ?? [] );
		self::assertNotEmpty( $steps );
		$by_id = [];
		foreach ( $steps as $step ) {
			$by_id[ (string) ( $step['id'] ?? '' ) ] = $step;
		}
		self::assertSame( 'applied', $by_id['post_type']['status'] ?? null );
		self::assertSame( 'applied', $by_id['acf']['status'] ?? null );
		self::assertSame( 'applied', $by_id['content']['status'] ?? null );
		self::assertSame( 'failed', $by_id['loop_template']['status'] ?? null );
		self::assertArrayHasKey( 'rollback_status', $data );
		self::assertSame( 'not_attempted', $data['rollback_status'] );
		self::assertArrayHasKey( 'ok', $data );
		self::assertFalse( $data['ok'] );
		self::assertSame( 'speaker', $data['created_resources']['post_type'] ?? null );
		self::assertSame( [ 9100 ], $data['created_resources']['post_ids'] ?? null );
		self::assertStringContainsString( 'retry only the failed step', (string) ( $data['retry'] ?? '' ) );
		self::assertArrayNotHasKey( 'content', $data );
	}

	public function test_final_compile_fails_when_live_schema_still_omits_registered_cpt(): void {
		$GLOBALS['stonewright_test_wpdb_inserts'] = [];
		$result = ( new CptAcfLoopGridFlow() )->execute(
			[
				'post_type'     => [
					'slug'     => 'featured_solution',
					'singular' => 'Featured Solution',
					'plural'   => 'Featured Solutions',
				],
				'fields'        => [
					[ 'name' => 'subtitle', 'label' => 'Subtitle', 'type' => 'text' ],
				],
				'items'         => [
					[
						'slug'  => 'solar-roof',
						'title' => 'Solar Roof',
						'meta'  => [ 'subtitle' => 'Example ready' ],
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

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_loop_post_type_unsupported', $result->get_error_code() );
		self::assertNotTrue( is_array( $result ) && true === ( $result['ok'] ?? false ) );
		$data = $result->get_error_data();
		self::assertSame( 'loop_grid', $data['failed_step'] );
		self::assertSame( 'not_attempted', $data['rollback_status'] );
		$by_id = [];
		foreach ( (array) ( $data['steps'] ?? [] ) as $step ) {
			$by_id[ (string) ( $step['id'] ?? '' ) ] = $step;
		}
		self::assertSame( 'applied', $by_id['post_type']['status'] ?? null );
		self::assertSame( 'applied', $by_id['acf']['status'] ?? null );
		self::assertSame( 'applied', $by_id['content']['status'] ?? null );
		self::assertSame( 'applied', $by_id['loop_template']['status'] ?? null );
		self::assertSame( 'failed', $by_id['loop_grid']['status'] ?? null );
		self::assertSame( 'featured_solution', $data['created_resources']['post_type'] ?? null );
		self::assertNotEmpty( $data['created_resources']['post_ids'] ?? [] );
		self::assertGreaterThan( 0, (int) ( $data['created_resources']['template_id'] ?? 0 ) );
		self::assertArrayHasKey( 'featured_solution', $GLOBALS['stonewright_test_post_types'] );
		self::assertNotEmpty( $GLOBALS['stonewright_test_inserted_posts'] );
		self::assertStringContainsString( 'Do not recreate', (string) ( $data['retry'] ?? '' ) );
		self::assertStringNotContainsString( 'Solar Roof', wp_json_encode( $data ) );

		$row = $this->last_audit_row( 'stonewright/content-model-loop-grid-flow' );
		self::assertSame( 'error', $row['result_status'] ?? null );
		self::assertNotSame( 'SUCCESS', $row['outcome'] ?? null );
		self::assertStringNotContainsString( 'Solar Roof', (string) ( $row['sanitized_args'] ?? '' ) );
	}

	public function test_incompatible_loop_schema_does_not_replace_query_with_static_cards(): void {
		$GLOBALS['stonewright_test_loop_control_overrides']['loop-grid'] = [
			'template_id' => [ 'type' => 'query' ],
			'columns'     => [ 'type' => 'number', 'responsive' => true ],
		];
		WidgetSchemaRepository::reset_request_cache();

		$result = ( new CptAcfLoopGridFlow() )->execute(
			[
				'post_type' => [
					'slug'     => 'speaker',
					'singular' => 'Speaker',
					'plural'   => 'Speakers',
				],
				'fields'    => [ [ 'name' => 'role', 'label' => 'Role', 'type' => 'text' ] ],
				'items'     => [ [ 'slug' => 'ana', 'title' => 'Ana' ] ],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_loop_schema_incompatible', $result->get_error_code() );
		self::assertSame( 'loop_grid', $result->get_error_data()['failed_step'] );
		self::assertSame( [], $GLOBALS['stonewright_test_inserted_posts'] );
	}

	private function last_audit_row( string $ability ): array {
		$rows = array_reverse( $GLOBALS['stonewright_test_wpdb_inserts'] ?? [] );
		foreach ( $rows as $insert ) {
			$data = is_array( $insert['data'] ?? null ) ? $insert['data'] : [];
			if ( $ability === (string) ( $data['ability_name'] ?? '' ) ) {
				return $data;
			}
		}
		return [];
	}

	private function include_registered_post_types_in_loop_schema(): void {
		$GLOBALS['stonewright_test_loop_control_overrides']['*'] = static function ( array $controls, string $widget ): array {
			unset( $widget );
			foreach ( array_keys( $GLOBALS['stonewright_test_post_types'] ?? [] ) as $type ) {
				$type = sanitize_key( (string) $type );
				if ( '' === $type ) {
					continue;
				}
				if ( isset( $controls['query_post_type'] ) && is_array( $controls['query_post_type'] ) ) {
					$controls['query_post_type']['options']                 = is_array( $controls['query_post_type']['options'] ?? null )
						? $controls['query_post_type']['options']
						: [];
					$controls['query_post_type']['options'][ $type ] = $type;
				}
			}
			return $controls;
		};
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
