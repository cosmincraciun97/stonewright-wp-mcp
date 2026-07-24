<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Loop;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\Loop\LoopIntentCompiler;
use Stonewright\WpMcp\Elementor\Schema\WidgetSchemaRepository;

/**
 * @covers \Stonewright\WpMcp\Elementor\Loop\LoopIntentCompiler
 */
final class LoopIntentCompilerTest extends TestCase {
	private object $original_elementor;

	protected function setUp(): void {
		$this->original_elementor = \Elementor\Plugin::$instance;
		$GLOBALS['stonewright_test_options']    = [ 'active_plugins' => [] ];
		$GLOBALS['stonewright_test_transients'] = [];
		\Elementor\Plugin::$instance = (object) [
			'widgets_manager' => new LoopWidgetManager(),
		];
		WidgetSchemaRepository::reset_request_cache();
	}

	protected function tearDown(): void {
		\Elementor\Plugin::$instance = $this->original_elementor;
		$GLOBALS['stonewright_test_options']    = [];
		$GLOBALS['stonewright_test_transients'] = [];
		WidgetSchemaRepository::reset_request_cache();
		unset( $GLOBALS['stonewright_test_loop_schema_without_post_type'] );
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
		self::assertSame( 'project', $result['settings']['post_type'] );
		self::assertSame( 6, $result['settings']['posts_per_page'] );
		self::assertSame( 3, $result['settings']['slides_to_show'] );
		self::assertSame( 2, $result['settings']['slides_to_show_tablet'] );
		self::assertSame( 1, $result['settings']['slides_to_show_mobile'] );
		self::assertSame( 'yes', $result['settings']['arrows'] );
		self::assertArrayNotHasKey( 'pagination', $result['settings'] );
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
			[ 'pagination' => 'numbers' ]
		);
		self::assertInstanceOf( \WP_Error::class, $invalid );
		self::assertSame( 'stonewright_loop_schema_incompatible', $invalid->get_error_code() );
		self::assertSame( 'pagination', $invalid->get_error_data()['missing_semantic_control'] );
	}

	public function test_widget_without_template_control_fails_precisely(): void {
		$result = LoopIntentCompiler::compile( 'broken', 77, 'project', [] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_loop_display_invalid', $result->get_error_code() );
	}

	public function test_requested_post_type_is_never_silently_dropped(): void {
		$GLOBALS['stonewright_test_loop_schema_without_post_type'] = true;

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
}

final class LoopWidgetManager {
	public function get_widget_types( ?string $name = null ): array|object|null {
		$widgets = [
			'loop-carousel' => new LoopCarouselWidget(),
			'loop-grid'     => new LoopGridWidget(),
		];
		return null === $name ? $widgets : ( $widgets[ $name ] ?? null );
	}
}

final class LoopCarouselWidget {
	public function get_title(): string {
		return 'Loop Carousel';
	}

	/** @return list<string> */
	public function get_categories(): array {
		return [ 'pro-elements' ];
	}

	/** @return array<string, array<string, mixed>> */
	public function get_controls(): array {
		return [
			'template_id'    => [ 'type' => 'select' ],
			'post_type'      => [ 'type' => 'text' ],
			'posts_per_page' => [ 'type' => 'number' ],
			'slides_to_show' => [ 'type' => 'number', 'responsive' => true ],
			'arrows'         => [ 'type' => 'switcher', 'return_value' => 'yes' ],
		];
	}
}

final class LoopGridWidget {
	public function get_title(): string {
		return 'Loop Grid';
	}

	/** @return list<string> */
	public function get_categories(): array {
		return [ 'pro-elements' ];
	}

	/** @return array<string, array<string, mixed>> */
	public function get_controls(): array {
		$controls = [
			'template_id' => [ 'type' => 'select' ],
			'columns'     => [ 'type' => 'number', 'responsive' => true ],
			'tax_query'   => [ 'type' => 'repeater' ],
			'meta_query'  => [ 'type' => 'repeater' ],
		];
		if ( empty( $GLOBALS['stonewright_test_loop_schema_without_post_type'] ) ) {
			$controls['post_type'] = [ 'type' => 'text' ];
		}
		return $controls;
	}
}
