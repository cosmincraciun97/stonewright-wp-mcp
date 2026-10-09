<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ElementorV3;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\KitBatchMutate;
use Stonewright\WpMcp\Abilities\ElementorV3\UpdateKitColors;
use Stonewright\WpMcp\Abilities\ElementorV3\UpdateKitTypography;

/**
 * Kit colour and typography writers accept only values that are safe to emit
 * into generated CSS.
 *
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\UpdateKitColors
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\UpdateKitTypography
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\KitBatchMutate
 */
final class KitValueSafetyTest extends TestCase {

	private const KIT_BEFORE = [
		'custom_colors'     => [ [ '_id' => 'brand', 'title' => 'Brand', 'color' => '#00aa00' ] ],
		'custom_typography' => [ [ '_id' => 'body', 'title' => 'Body', 'typography_font_family' => 'Inter' ] ],
	];

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']         = [ 'elementor_active_kit' => 44, 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_mode']                 = 'development';
		$GLOBALS['stonewright_test_user_caps']       = [ 'edit_theme_options' => true ];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
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
				'meta'         => [ '_elementor_page_settings' => self::KIT_BEFORE ],
			],
		];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
		unset( $GLOBALS['stonewright_mode'] );
	}

	/** @return array<string, mixed> */
	private function kit_settings(): array {
		return $GLOBALS['stonewright_test_posts'][44]->meta['_elementor_page_settings'];
	}

	private function assert_kit_untouched(): void {
		self::assertSame( self::KIT_BEFORE, $this->kit_settings() );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );
	}

	/** @return array<string, array{0:mixed}> */
	public static function invalid_colors(): array {
		return [
			'breakout'   => [ 'red;}body{display:none}' ],
			'not colour' => [ 'not-a-color' ],
			'javascript' => [ 'javascript:alert(1)' ],
			'url'        => [ 'url(//example.com/x.png)' ],
			'empty'      => [ '' ],
		];
	}

	/**
	 * @dataProvider invalid_colors
	 */
	public function test_update_kit_colors_refuses_invalid_colour_and_writes_nothing( string $color ): void {
		$result = ( new UpdateKitColors() )->execute( [ 'colors' => [ [ 'id' => 'accent', 'title' => 'Accent', 'color' => $color ] ] ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_elementor_settings_invalid', $result->get_error_code() );
		self::assertSame( 'colors.0.color', $result->get_error_data()['violations'][0]['path'] );
		self::assertStringContainsString( 'colors.0.color', $result->get_error_message() );
		$this->assert_kit_untouched();
	}

	public function test_update_kit_colors_refuses_unsafe_identifier(): void {
		foreach ( [ 'a;}b{', 'bad id', '', str_repeat( 'a', 65 ) ] as $id ) {
			$result = ( new UpdateKitColors() )->execute( [ 'colors' => [ [ 'id' => $id, 'color' => '#fff' ] ] ] );
			self::assertInstanceOf( \WP_Error::class, $result, $id );
			self::assertSame( 'stonewright_elementor_settings_invalid', $result->get_error_code() );
			self::assertSame( 'colors.0.id', $result->get_error_data()['violations'][0]['path'] );
		}
		$this->assert_kit_untouched();
	}

	public function test_update_kit_colors_names_the_failing_row_and_writes_no_earlier_row(): void {
		$result = ( new UpdateKitColors() )->execute(
			[
				'colors' => [
					[ 'id' => 'ok-one', 'color' => '#123456' ],
					[ 'id' => 'bad-two', 'color' => 'blue;}x{' ],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'colors.1.color', $result->get_error_data()['violations'][0]['path'] );
		$this->assert_kit_untouched();
	}

	public function test_update_kit_colors_accepts_every_valid_colour_form(): void {
		$forms = [ '#fff', '#fff8', '#aabbcc', '#aabbccdd', 'rgb(1, 2, 3)', 'rgba(0,0,0,.5)', 'hsl(10, 20%, 30%)', 'hsla(10, 20%, 30%, 0.5)', 'transparent', 'tomato', 'var(--e-global-color-primary)' ];
		$rows  = [];
		foreach ( $forms as $i => $form ) {
			$rows[] = [ 'id' => 'c' . $i, 'title' => 'Colour ' . $i, 'color' => $form ];
		}

		$result = ( new UpdateKitColors() )->execute( [ 'colors' => $rows, 'mode' => 'replace' ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertSame( $forms, array_column( $this->kit_settings()['custom_colors'], 'color' ) );
	}

	/** @return array<string, array{0:array<string,mixed>,1:string}> */
	public static function invalid_fonts(): array {
		return [
			'family breakout'  => [ [ 'font_family' => 'Arial;}body{display:none}' ], 'fonts.0.font_family' ],
			'family url'       => [ [ 'font_family' => 'url(//example.com/f.woff)' ], 'fonts.0.font_family' ],
			'weight breakout'  => [ [ 'font_weight' => '700;}x{' ], 'fonts.0.font_weight' ],
			'weight text'      => [ [ 'font_weight' => 'heavy' ], 'fonts.0.font_weight' ],
			'size unit'        => [ [ 'font_size' => [ 'size' => 16, 'unit' => 'px;}x{' ] ], 'fonts.0.font_size' ],
			'size value'       => [ [ 'font_size' => [ 'size' => 'url(x)', 'unit' => 'px' ] ], 'fonts.0.font_size' ],
			'line height'      => [ [ 'line_height' => [ 'size' => '1;}', 'unit' => 'em' ] ], 'fonts.0.line_height' ],
			'letter spacing'   => [ [ 'letter_spacing' => [ 'size' => 1, 'unit' => 'nope' ] ], 'fonts.0.letter_spacing' ],
			'extra key'        => [ [ 'typography_text_transform' => 'uppercase;}x{' ], 'fonts.0.typography_text_transform' ],
		];
	}

	/**
	 * @param array<string,mixed> $font
	 * @dataProvider invalid_fonts
	 */
	public function test_update_kit_typography_refuses_unsafe_values_and_writes_nothing( array $font, string $path ): void {
		$result = ( new UpdateKitTypography() )->execute( [ 'fonts' => [ array_merge( [ 'id' => 'display', 'title' => 'Display' ], $font ) ] ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_elementor_settings_invalid', $result->get_error_code() );
		self::assertSame( $path, $result->get_error_data()['violations'][0]['path'] );
		$this->assert_kit_untouched();
	}

	public function test_update_kit_typography_refuses_unsafe_identifier(): void {
		$result = ( new UpdateKitTypography() )->execute( [ 'fonts' => [ [ 'id' => 'x;}y{', 'font_family' => 'Inter' ] ] ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'fonts.0.id', $result->get_error_data()['violations'][0]['path'] );
		$this->assert_kit_untouched();
	}

	public function test_update_kit_typography_accepts_real_values(): void {
		$result = ( new UpdateKitTypography() )->execute(
			[
				'mode'  => 'replace',
				'fonts' => [
					[
						'id'             => 'display',
						'title'          => 'Display',
						'font_family'    => 'Open Sans',
						'font_weight'    => '700',
						'font_size'      => [ 'size' => 2.5, 'unit' => 'rem' ],
						'line_height'    => [ 'size' => 1.2, 'unit' => 'em' ],
						'letter_spacing' => [ 'size' => -0.5, 'unit' => 'px' ],
					],
				],
			]
		);

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$row = $this->kit_settings()['custom_typography'][0];
		self::assertSame( 'Open Sans', $row['typography_font_family'] );
		self::assertSame( [ 'size' => 2.5, 'unit' => 'rem' ], $row['typography_font_size'] );
	}

	public function test_kit_batch_mutate_refuses_invalid_colour_in_dry_run_and_apply(): void {
		foreach ( [ true, false ] as $dry_run ) {
			$result = ( new KitBatchMutate() )->execute(
				[
					'dry_run'    => $dry_run,
					'operations' => [ [ 'group' => 'colors', 'colors' => [ [ 'id' => 'brand', 'color' => 'red;}body{display:none}' ] ] ] ],
				]
			);
			self::assertInstanceOf( \WP_Error::class, $result );
			self::assertSame( 'stonewright_elementor_settings_invalid', $result->get_error_code() );
			self::assertSame( 'operations.0.colors.0.color', $result->get_error_data()['violations'][0]['path'] );
		}
		$this->assert_kit_untouched();
	}

	public function test_kit_batch_mutate_refuses_unsafe_typography(): void {
		$result = ( new KitBatchMutate() )->execute(
			[
				'operations' => [
					[ 'group' => 'typography', 'bucket' => 'system', 'fonts' => [ [ 'id' => 'primary', 'font_family' => 'Arial;}body{display:none}' ] ] ],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_elementor_settings_invalid', $result->get_error_code() );
		self::assertSame( 'operations.0.fonts.0.font_family', $result->get_error_data()['violations'][0]['path'] );
		$this->assert_kit_untouched();
	}

	public function test_kit_batch_mutate_refuses_unsafe_typography_extra_key(): void {
		$result = ( new KitBatchMutate() )->execute(
			[
				'operations' => [
					[ 'group' => 'typography', 'fonts' => [ [ 'id' => 'primary', 'typography_font_size' => [ 'size' => 1, 'unit' => 'px;}x{' ] ] ] ],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'operations.0.fonts.0.typography_font_size', $result->get_error_data()['violations'][0]['path'] );
		$this->assert_kit_untouched();
	}

	public function test_kit_batch_mutate_settings_group_checks_colour_and_typography_keys(): void {
		foreach ( [
			[ 'body_color' => 'red;}body{display:none}' ],
			[ 'h1_typography_font_family' => 'Arial;}x{' ],
			[ 'h1_typography_font_size' => [ 'size' => 20, 'unit' => 'x;' ] ],
			[ 'link_hover_color' => 'javascript:alert(1)' ],
		] as $settings ) {
			foreach ( [ 'settings', 'layout' ] as $group ) {
				$result = ( new KitBatchMutate() )->execute( [ 'operations' => [ [ 'group' => $group, 'settings' => $settings ] ] ] );
				self::assertInstanceOf( \WP_Error::class, $result, $group . ' ' . array_key_first( $settings ) );
				self::assertSame( 'stonewright_elementor_settings_invalid', $result->get_error_code() );
				self::assertSame( 'operations.0.settings.' . array_key_first( $settings ), $result->get_error_data()['violations'][0]['path'] );
			}
		}
		$single = ( new KitBatchMutate() )->execute( [ 'operations' => [ [ 'group' => 'layout', 'setting' => 'body_color', 'value' => 'red;}x{' ] ] ] );
		self::assertInstanceOf( \WP_Error::class, $single );
		self::assertSame( 'operations.0.value', $single->get_error_data()['violations'][0]['path'] );
		$this->assert_kit_untouched();
	}

	public function test_kit_batch_mutate_keeps_accepting_valid_settings(): void {
		$result = ( new KitBatchMutate() )->execute(
			[
				'operations' => [
					[
						'group'    => 'settings',
						'settings' => [
							'body_color'                => '#222222',
							'h1_typography_font_family' => 'Roboto',
							'h1_typography_font_size'   => [ 'size' => 40, 'unit' => 'px' ],
							'container_width'           => [ 'size' => 1140, 'unit' => 'px' ],
							'page_title_selector'       => 'h1.entry-title',
						],
					],
				],
			]
		);

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertSame( '#222222', $this->kit_settings()['body_color'] );
	}
}
