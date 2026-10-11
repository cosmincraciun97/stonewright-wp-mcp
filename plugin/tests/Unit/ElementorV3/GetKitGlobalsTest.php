<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ElementorV3;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\GetKitGlobals;

/**
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\GetKitGlobals
 */
final class GetKitGlobalsTest extends TestCase {

	private object $elementor_instance;

	protected function setUp(): void {
		$this->elementor_instance                    = \Elementor\Plugin::$instance;
		$GLOBALS['stonewright_test_options']         = [ 'elementor_active_kit' => 44 ];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_posts']           = [
			44 => (object) [
				'ID'           => 44,
				'post_type'    => 'elementor_library',
				'post_status'  => 'publish',
				'post_title'   => 'Active Kit',
				'post_content' => '',
				'post_excerpt' => '',
				'post_parent'  => 0,
				'post_name'    => 'active-kit',
				'meta'         => [
					'_elementor_page_settings' => [
						'system_colors'      => [
							[ '_id' => 'primary', 'title' => 'Primary', 'color' => '#111111' ],
						],
						'custom_colors'      => [
							[ '_id' => 'brand-green', 'title' => 'Brand Green', 'color' => '#19a974' ],
							[ '_id' => 'accent', 'title' => 'Accent', 'color' => '#f4c430' ],
						],
						'custom_typography'  => [
							[
								'_id'                     => 'display',
								'title'                   => 'Display',
								'typography_font_family'  => 'Inter',
								'typography_font_weight'  => '700',
								'typography_font_size'    => [ 'size' => 64, 'unit' => 'px' ],
								'typography_line_height'  => [ 'size' => 1.05, 'unit' => 'em' ],
								'typography_letter_spacing' => [ 'size' => 0, 'unit' => 'px' ],
							],
						],
						'page_title_selector' => 'h1.entry-title',
					],
				],
			],
		];
	}

	protected function tearDown(): void {
		\Elementor\Plugin::$instance                  = $this->elementor_instance;
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
	}

	public function test_returns_compact_active_kit_globals_without_writes(): void {
		$result = ( new GetKitGlobals() )->execute( [ 'max_items' => 1 ] );

		self::assertIsArray( $result );
		self::assertSame( 44, $result['kit_id'] );
		self::assertSame( 'Active Kit', $result['kit_title'] );
		self::assertSame( [ 'system_colors', 'custom_colors', 'custom_typography', 'page_title_selector' ], $result['settings_keys'] );
		self::assertSame( [ 'system' => 1, 'custom' => 1 ], $result['colors']['counts'] );
		self::assertSame( 'brand-green', $result['colors']['items']['custom'][0]['id'] );
		self::assertSame( '#19a974', $result['colors']['items']['custom'][0]['color'] );
		self::assertSame( [ 'system' => 0, 'custom' => 1 ], $result['typography']['counts'] );
		self::assertSame( 'Inter', $result['typography']['items']['custom'][0]['font_family'] );
		self::assertSame( 'stonewright/elementor-v3-update-kit-colors', $result['token_plan']['color_write_tool'] );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );
	}

	public function test_only_the_real_kit_buckets_are_reported_and_a_key_elementor_does_not_use_is_not_read(): void {
		$GLOBALS['stonewright_test_posts'][44]->meta['_elementor_page_settings']['global_colors']     = [ [ '_id' => 'stray', 'title' => 'Stray', 'color' => '#000000' ] ];
		$GLOBALS['stonewright_test_posts'][44]->meta['_elementor_page_settings']['global_typography'] = [ [ '_id' => 'stray', 'title' => 'Stray' ] ];

		$result = ( new GetKitGlobals() )->execute( [] );

		self::assertIsArray( $result );
		self::assertSame( [ 'system', 'custom' ], array_keys( $result['colors']['items'] ) );
		self::assertSame( [ 'system', 'custom' ], array_keys( $result['typography']['items'] ) );
		self::assertStringNotContainsString( 'stray', (string) wp_json_encode( $result['colors'] ) . (string) wp_json_encode( $result['typography'] ) );
	}

	public function test_an_unsaved_kit_reports_the_default_colors_and_fonts_elementor_reports(): void {
		unset( $GLOBALS['stonewright_test_posts'][44]->meta['_elementor_page_settings'] );
		$defaults = [
			'system_colors'     => [
				[ '_id' => 'primary', 'title' => 'Primary', 'color' => '#6EC1E4' ],
				[ '_id' => 'secondary', 'title' => 'Secondary', 'color' => '#54595F' ],
				[ '_id' => 'text', 'title' => 'Text', 'color' => '#7A7A7A' ],
				[ '_id' => 'accent', 'title' => 'Accent', 'color' => '#61CE70' ],
			],
			'system_typography' => [ [ '_id' => 'primary', 'title' => 'Primary Headline', 'typography_font_family' => 'Roboto', 'typography_font_weight' => '600' ] ],
		];
		\Elementor\Plugin::$instance = (object) [
			'kits_manager' => new class( $defaults ) {
				public function __construct( private array $defaults ) {}

				public function get_active_kit(): object {
					return new class( $this->defaults ) {
						public function __construct( private array $defaults ) {}

						public function get_settings( string $key ): mixed {
							return $this->defaults[ $key ] ?? null;
						}
					};
				}
			},
		];

		$result = ( new GetKitGlobals() )->execute( [] );

		self::assertIsArray( $result );
		self::assertSame( 4, $result['colors']['counts']['system'] );
		self::assertSame( [ 'primary', 'secondary', 'text', 'accent' ], array_column( $result['colors']['items']['system'], 'id' ) );
		self::assertSame( 'Roboto', $result['typography']['items']['system'][0]['font_family'] );
		self::assertSame( 0, $result['colors']['counts']['custom'] );
	}

	public function test_returns_error_when_active_kit_missing(): void {
		$GLOBALS['stonewright_test_options'] = [];

		$result = ( new GetKitGlobals() )->execute( [] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_no_kit', $result->get_error_code() );
	}
}
