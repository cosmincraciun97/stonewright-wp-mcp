<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Loop;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\Loop\LoopIntentCompiler;
use Stonewright\WpMcp\Elementor\Schema\WidgetSchemaRepository;

require_once __DIR__ . '/SyntheticLoopWidgets.php';

/**
 * @covers \Stonewright\WpMcp\Elementor\Loop\LoopIntentCompiler
 */
final class LoopIntentCompilerTest extends TestCase {
	private object $original_elementor;

	protected function setUp(): void {
		$this->original_elementor = \Elementor\Plugin::$instance;
		$GLOBALS['stonewright_test_options']    = [ 'active_plugins' => [] ];
		$GLOBALS['stonewright_test_transients'] = [];
		unset( $GLOBALS['stonewright_test_loop_control_overrides'] );
		\Elementor\Plugin::$instance = (object) [
			'widgets_manager' => new SyntheticLoopWidgetManager(),
		];
		WidgetSchemaRepository::reset_request_cache();
	}

	protected function tearDown(): void {
		\Elementor\Plugin::$instance = $this->original_elementor;
		$GLOBALS['stonewright_test_options']    = [];
		$GLOBALS['stonewright_test_transients'] = [];
		unset( $GLOBALS['stonewright_test_loop_control_overrides'] );
		WidgetSchemaRepository::reset_request_cache();
	}

	public function test_carousel_uses_only_controls_exposed_by_live_schema(): void {
		$result = LoopIntentCompiler::compile(
			'carousel',
			77,
			'project',
			[
				'query'      => [ 'posts_per_page' => 6 ],
				'responsive' => [ 'desktop' => 3, 'tablet' => 2, 'mobile' => 1 ],
				'arrows'     => true,
			]
		);

		self::assertIsArray( $result );
		self::assertSame( 'loop-carousel', $result['widget_type'] );
		self::assertSame( 77, $result['settings']['template_id'] );
		self::assertSame( 'project', $result['settings']['query_post_type'] );
		self::assertSame( 'query_post_type', $result['resolved_controls']['post_type'] );
		self::assertSame( 6, $result['settings']['posts_per_page'] );
		self::assertSame( 3, $result['settings']['slides_to_show'] );
		self::assertSame( 2, $result['settings']['slides_to_show_tablet'] );
		self::assertSame( 1, $result['settings']['slides_to_show_mobile'] );
		self::assertSame( 'yes', $result['settings']['arrows'] );
		self::assertArrayNotHasKey( 'pagination', $result['settings'] );
		self::assertArrayNotHasKey( 'pagination_load_type', $result['settings'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $result['schema_hash'] );
	}

	public function test_grid_maps_columns_and_rejects_unrepresentable_intent(): void {
		$valid = LoopIntentCompiler::compile(
			'grid',
			88,
			'project',
			[ 'responsive' => [ 'desktop' => 4, 'mobile' => 1 ] ]
		);
		self::assertIsArray( $valid );
		self::assertSame( 4, $valid['settings']['columns'] );
		self::assertSame( 1, $valid['settings']['columns_mobile'] );

		$invalid = LoopIntentCompiler::compile(
			'grid',
			88,
			'project',
			[ 'query' => [ 'post__in' => [ 10, 11 ] ] ]
		);
		self::assertInstanceOf( \WP_Error::class, $invalid );
		self::assertSame( 'stonewright_loop_schema_incompatible', $invalid->get_error_code() );
		self::assertSame( 'post__in', $invalid->get_error_data()['missing_semantic_control'] );
		self::assertSame( 'settings.post__in', $invalid->get_error_data()['path'] );
		self::assertNotSame( '', (string) ( $invalid->get_error_data()['expected'] ?? '' ) );
	}

	public function test_widget_without_template_control_fails_precisely(): void {
		$result = LoopIntentCompiler::compile( 'broken', 77, 'project', [] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_loop_display_invalid', $result->get_error_code() );
	}

	public function test_prefixed_query_post_type_is_used_when_schema_confirms_type_and_options(): void {
		$result = LoopIntentCompiler::compile( 'grid', 88, 'project', [] );

		self::assertIsArray( $result );
		self::assertSame( 'query_post_type', $result['resolved_controls']['post_type'] );
		self::assertSame( 'project', $result['settings']['query_post_type'] );
		self::assertArrayNotHasKey( 'post_type', $result['settings'] );
	}

	public function test_name_guess_without_type_and_options_is_not_enough(): void {
		$GLOBALS['stonewright_test_loop_control_overrides']['loop-grid'] = [
			'template_id' => [ 'type' => 'query' ],
			'post_type'   => [ 'type' => 'text' ],
			'columns'     => [ 'type' => 'number', 'responsive' => true ],
		];
		WidgetSchemaRepository::reset_request_cache();

		$result = LoopIntentCompiler::compile( 'grid', 88, 'project', [] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_loop_schema_incompatible', $result->get_error_code() );
		self::assertSame( 'post_type', $result->get_error_data()['missing_semantic_control'] );
		self::assertSame( 'settings.post_type', $result->get_error_data()['path'] );
		self::assertNotSame( '', (string) ( $result->get_error_data()['expected'] ?? '' ) );
	}

	public function test_schema_without_compatible_post_type_control_returns_code_path_expected(): void {
		$GLOBALS['stonewright_test_loop_control_overrides']['loop-grid'] = [
			'template_id' => [ 'type' => 'query' ],
			'columns'     => [ 'type' => 'number', 'responsive' => true ],
		];
		WidgetSchemaRepository::reset_request_cache();

		$result = LoopIntentCompiler::compile( 'grid', 88, 'project', [] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_loop_schema_incompatible', $result->get_error_code() );
		$data = $result->get_error_data();
		self::assertSame( 'post_type', $data['missing_semantic_control'] );
		self::assertSame( 'settings.post_type', $data['path'] );
		self::assertNotSame( '', (string) ( $data['expected'] ?? '' ) );
	}

	public function test_post_type_missing_from_control_options_is_rejected(): void {
		$result = LoopIntentCompiler::compile( 'grid', 88, 'events', [] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_loop_post_type_unsupported', $result->get_error_code() );
		self::assertContains( 'project', (array) $result->get_error_data()['available_options'] );
		self::assertSame( 'events', $result->get_error_data()['post_type'] );
	}

	public function test_pending_post_type_is_accepted_on_a_confirmed_source_control(): void {
		$result = LoopIntentCompiler::compile(
			'grid',
			88,
			'events',
			[ 'pending_post_type' => true ]
		);

		self::assertIsArray( $result );
		self::assertSame( 'events', $result['settings']['query_post_type'] );
	}

	public function test_requested_post_type_is_never_silently_dropped(): void {
		$GLOBALS['stonewright_test_loop_control_overrides']['loop-grid'] = [
			'template_id' => [ 'type' => 'query' ],
			'columns'     => [ 'type' => 'number', 'responsive' => true ],
		];
		WidgetSchemaRepository::reset_request_cache();

		$result = LoopIntentCompiler::compile( 'grid', 88, 'project', [] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_loop_schema_incompatible', $result->get_error_code() );
		self::assertSame( 'post_type', $result->get_error_data()['missing_semantic_control'] );
	}

	public function test_requested_query_filter_is_never_silently_dropped(): void {
		$result = LoopIntentCompiler::compile(
			'grid',
			88,
			'project',
			[ 'query' => [ 'post__in' => [ 10, 11 ] ] ]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_loop_schema_incompatible', $result->get_error_code() );
		self::assertSame( 'post__in', $result->get_error_data()['missing_semantic_control'] );
	}

	public function test_query_relations_survive_live_control_mapping(): void {
		$result = LoopIntentCompiler::compile(
			'grid',
			88,
			'project',
			[
				'query' => [
					'tax_query'  => [
						'relation' => 'OR',
						[ 'taxonomy' => 'project_type', 'terms' => [ 3 ] ],
					],
					'meta_query' => [
						'relation' => 'AND',
						[ 'key' => 'featured', 'value' => '1' ],
					],
				],
			]
		);

		self::assertIsArray( $result );
		self::assertSame( 'OR', $result['settings']['tax_query']['relation'] );
		self::assertSame( 'AND', $result['settings']['meta_query']['relation'] );
	}

	public function test_inactive_pagination_load_type_is_not_written_and_names_condition(): void {
		$mapped = LoopIntentCompiler::compile(
			'grid',
			88,
			'project',
			[ 'pagination' => true ]
		);
		self::assertIsArray( $mapped );
		self::assertSame( 'numbers', $mapped['settings']['pagination_type'] );
		self::assertArrayNotHasKey( 'pagination_load_type', $mapped['settings'] );

		$invalid = LoopIntentCompiler::compile(
			'grid',
			88,
			'project',
			[
				'pagination'           => true,
				'pagination_load_type' => 'click',
			]
		);
		self::assertInstanceOf( \WP_Error::class, $invalid );
		self::assertSame( 'stonewright_loop_inactive_condition', $invalid->get_error_code() );
		$condition = (array) ( $invalid->get_error_data()['condition'] ?? [] );
		self::assertArrayHasKey( 'pagination_type', $condition );
		self::assertStringContainsString( 'pagination_type', $invalid->get_error_message() );
	}

	public function test_mobile_only_posts_per_page_is_rejected_with_plan_alternatives(): void {
		$result = LoopIntentCompiler::compile(
			'grid',
			88,
			'project',
			[
				'query'            => [ 'posts_per_page' => 3 ],
				'responsive_scope' => [ 'mobile' ],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_loop_non_responsive_control', $result->get_error_code() );
		$alternatives = (array) ( $result->get_error_data()['plan_alternatives'] ?? [] );
		$ids          = array_column( $alternatives, 'id' );
		self::assertContains( 'apply_query_globally', $ids );
		self::assertContains( 'explicit_two_loops', $ids );
		foreach ( $alternatives as $alternative ) {
			self::assertStringNotContainsString( 'custom code', strtolower( (string) ( $alternative['summary'] ?? '' ) ) );
			self::assertStringNotContainsString( 'auto-duplicate', strtolower( (string) ( $alternative['summary'] ?? '' ) ) );
		}
	}

	public function test_default_compile_is_a_single_widget_not_a_duplicate_pair(): void {
		$result = LoopIntentCompiler::compile( 'grid', 88, 'project', [] );

		self::assertIsArray( $result );
		self::assertArrayNotHasKey( 'instances', $result );
		self::assertSame( 'query_post_type', $result['resolved_controls']['post_type'] );
		self::assertArrayNotHasKey( 'hide_mobile', $result['settings'] );
		self::assertArrayNotHasKey( 'hide_desktop', $result['settings'] );
	}
}
