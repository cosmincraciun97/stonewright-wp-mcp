<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Renderer;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\Renderer\StyleMapper;

/**
 * Unit tests for the shared StyleMapper helper that turns a DesignSpec
 * `block.style` dict into Elementor V3 widget setting keys.
 *
 * @covers \Stonewright\WpMcp\Elementor\Renderer\StyleMapper
 */
final class StyleMapperTest extends TestCase {

	// -------------------------------------------------------------------------
	// apply() — high-level mapping
	// -------------------------------------------------------------------------

	public function test_scalar_passthrough_mapping(): void {
		$out = StyleMapper::apply(
			[ 'title' => 'Hi' ],
			[ 'text_transform' => 'uppercase' ],
			[ 'text_transform' => 'typography_text_transform' ]
		);

		$this->assertSame( 'Hi', $out['title'] );
		$this->assertSame( 'uppercase', $out['typography_text_transform'] );
	}

	public function test_color_descriptor(): void {
		$out = StyleMapper::apply(
			[],
			[ 'color' => '#FF0066' ],
			[ 'color' => [ 'key' => 'title_color', 'is_color' => true ] ]
		);

		$this->assertSame( '#FF0066', $out['title_color'] );
	}

	public function test_size_descriptor_emits_unit_size_dict(): void {
		$out = StyleMapper::apply(
			[],
			[ 'font_size' => '32px' ],
			[ 'font_size' => [ 'key' => 'typography_font_size', 'is_size' => true ] ]
		);

		$this->assertSame(
			[ 'unit' => 'px', 'size' => 32, 'sizes' => [] ],
			$out['typography_font_size']
		);
	}

	public function test_size_descriptor_with_viewport_keyed_value_emits_responsive_siblings(): void {
		$out = StyleMapper::apply(
			[],
			[ 'font_size' => [ 'desktop' => '32px', 'mobile' => '24px' ] ],
			[ 'font_size' => [ 'key' => 'typography_font_size', 'is_size' => true ] ]
		);

		$this->assertSame(
			[ 'unit' => 'px', 'size' => 32, 'sizes' => [] ],
			$out['typography_font_size']
		);
		$this->assertSame(
			[ 'unit' => 'px', 'size' => 24, 'sizes' => [] ],
			$out['typography_font_size_mobile']
		);
		$this->assertArrayNotHasKey( 'typography_font_size_tablet', $out );
	}

	public function test_dimension_descriptor_with_string_shorthand(): void {
		$out = StyleMapper::apply(
			[],
			[ 'padding' => '12px 8px' ],
			[ 'padding' => [ 'key' => '_padding', 'is_dimension' => true ] ]
		);

		$this->assertSame(
			[
				'unit'     => 'px',
				'top'      => '12',
				'right'    => '8',
				'bottom'   => '12',
				'left'     => '8',
				'isLinked' => false,
			],
			$out['_padding']
		);
	}

	public function test_background_sets_prefix_flag_and_color(): void {
		$out = StyleMapper::apply(
			[],
			[ 'background' => '#101010' ],
			[ 'background' => [ 'key' => '_background_color', 'is_background' => true ] ]
		);

		$this->assertSame( 'classic', $out['_background_background'] );
		$this->assertSame( '#101010', $out['_background_color'] );
	}

	public function test_background_uses_unprefixed_key_when_button_widget_quirks_in_play(): void {
		// Button's actual quirk: background_color, NOT _background_color.
		$out = StyleMapper::apply(
			[],
			[ 'background' => '#1a73e8' ],
			[ 'background' => [ 'key' => 'background_color', 'is_background' => true ] ]
		);

		$this->assertSame( 'classic', $out['background_background'] );
		$this->assertSame( '#1a73e8', $out['background_color'] );
	}

	public function test_border_full_shorthand_parses_into_three_pieces(): void {
		$out = StyleMapper::apply(
			[],
			[ 'border' => '2px dashed #FFFFFF' ],
			[ 'border' => [ 'is_border' => true, 'prefix' => 'border' ] ]
		);

		$this->assertSame( 'dashed', $out['border_border'] );
		$this->assertSame( '#FFFFFF', $out['border_color'] );
		$this->assertSame(
			[
				'unit'     => 'px',
				'top'      => '2',
				'right'    => '2',
				'bottom'   => '2',
				'left'     => '2',
				'isLinked' => true,
			],
			$out['border_width']
		);
	}

	public function test_border_color_only_does_not_set_style_or_width(): void {
		$out = StyleMapper::apply(
			[],
			[ 'border' => '#222222' ],
			[ 'border' => [ 'is_border' => true, 'prefix' => 'border' ] ]
		);

		$this->assertSame( '#222222', $out['border_color'] );
		$this->assertArrayNotHasKey( 'border_border', $out );
		$this->assertArrayNotHasKey( 'border_width', $out );
	}

	public function test_border_width_only_does_not_set_style_or_color(): void {
		$out = StyleMapper::apply(
			[],
			[ 'border' => '3px' ],
			[ 'border' => [ 'is_border' => true, 'prefix' => 'border' ] ]
		);

		$this->assertSame( '3', $out['border_width']['top'] );
		$this->assertArrayNotHasKey( 'border_border', $out );
		$this->assertArrayNotHasKey( 'border_color', $out );
	}

	public function test_image_border_prefix_renames_the_keys(): void {
		$out = StyleMapper::apply(
			[],
			[ 'border' => '1px solid #000' ],
			[ 'border' => [ 'is_border' => true, 'prefix' => 'image_border' ] ]
		);

		$this->assertSame( 'solid', $out['image_border_border'] );
		$this->assertSame( '#000', $out['image_border_color'] );
		$this->assertArrayHasKey( 'image_border_width', $out );
		// And the un-prefixed variants should not exist.
		$this->assertArrayNotHasKey( 'border_border', $out );
	}

	public function test_missing_style_fields_silently_skip(): void {
		$out = StyleMapper::apply(
			[ 'header_size' => 'h1' ],
			[ /* no style entries */ ],
			[ 'color' => 'title_color', 'font_size' => 'typography_font_size' ]
		);

		$this->assertSame( [ 'header_size' => 'h1' ], $out );
	}

	public function test_apply_does_not_mutate_input_settings(): void {
		$settings = [ 'header_size' => 'h2' ];
		$style    = [ 'color' => '#abc' ];
		$frozen   = $settings;

		$out = StyleMapper::apply( $settings, $style, [ 'color' => 'title_color' ] );

		$this->assertSame( $frozen, $settings, 'apply() must not mutate input array' );
		$this->assertNotSame( $settings, $out );
		$this->assertSame( '#abc', $out['title_color'] );
	}

	public function test_empty_string_value_skipped(): void {
		$out = StyleMapper::apply(
			[ 'header_size' => 'h1' ],
			[ 'color' => '' ],
			[ 'color' => 'title_color' ]
		);

		$this->assertArrayNotHasKey( 'title_color', $out );
	}

	// -------------------------------------------------------------------------
	// dimensions()
	// -------------------------------------------------------------------------

	public function test_dimensions_single_int_applies_to_all_sides(): void {
		$this->assertSame(
			[
				'unit'     => 'px',
				'top'      => '12',
				'right'    => '12',
				'bottom'   => '12',
				'left'     => '12',
				'isLinked' => true,
			],
			StyleMapper::dimensions( 12 )
		);
	}

	public function test_dimensions_single_px_string_applies_to_all_sides(): void {
		$this->assertSame(
			[
				'unit'     => 'px',
				'top'      => '16',
				'right'    => '16',
				'bottom'   => '16',
				'left'     => '16',
				'isLinked' => true,
			],
			StyleMapper::dimensions( '16px' )
		);
	}

	public function test_dimensions_four_value_shorthand(): void {
		$this->assertSame(
			[
				'unit'     => 'px',
				'top'      => '1',
				'right'    => '2',
				'bottom'   => '3',
				'left'     => '4',
				'isLinked' => false,
			],
			StyleMapper::dimensions( '1px 2px 3px 4px' )
		);
	}

	public function test_dimensions_keyed_array(): void {
		$this->assertSame(
			[
				'unit'     => 'px',
				'top'      => '10',
				'right'    => '20',
				'bottom'   => '10',
				'left'     => '20',
				'isLinked' => false,
			],
			StyleMapper::dimensions( [ 'top' => 10, 'right' => 20, 'bottom' => 10, 'left' => 20 ] )
		);
	}

	public function test_dimensions_null_returns_null(): void {
		$this->assertNull( StyleMapper::dimensions( null ) );
	}

	// -------------------------------------------------------------------------
	// size()
	// -------------------------------------------------------------------------

	public function test_size_integer_input(): void {
		$this->assertSame(
			[ 'unit' => 'px', 'size' => 32, 'sizes' => [] ],
			StyleMapper::size( 32 )
		);
	}

	public function test_size_px_string(): void {
		$this->assertSame(
			[ 'unit' => 'px', 'size' => 48, 'sizes' => [] ],
			StyleMapper::size( '48px' )
		);
	}

	public function test_size_em_string_preserves_unit(): void {
		$this->assertSame(
			[ 'unit' => 'em', 'size' => 1, 'sizes' => [] ],
			StyleMapper::size( '1em' )
		);
	}

	public function test_size_percent_string_preserves_unit(): void {
		$this->assertSame(
			[ 'unit' => '%', 'size' => 50, 'sizes' => [] ],
			StyleMapper::size( '50%' )
		);
	}

	public function test_size_viewport_keyed_input_returns_viewport_dict(): void {
		$out = StyleMapper::size( [ 'desktop' => '32px', 'mobile' => '24px' ] );

		$this->assertIsArray( $out );
		$this->assertArrayHasKey( 'desktop', $out );
		$this->assertArrayHasKey( 'mobile', $out );
		$this->assertSame(
			[ 'unit' => 'px', 'size' => 32, 'sizes' => [] ],
			$out['desktop']
		);
		$this->assertSame(
			[ 'unit' => 'px', 'size' => 24, 'sizes' => [] ],
			$out['mobile']
		);
	}

	public function test_size_bare_number_string_defaults_to_px(): void {
		$this->assertSame(
			[ 'unit' => 'px', 'size' => 14, 'sizes' => [] ],
			StyleMapper::size( '14' )
		);
	}

	// -------------------------------------------------------------------------
	// color()
	// -------------------------------------------------------------------------

	public function test_color_hex_passthrough(): void {
		$this->assertSame( '#abcdef', StyleMapper::color( '#abcdef' ) );
	}

	public function test_color_var_passthrough(): void {
		$this->assertSame( 'var(--brand)', StyleMapper::color( 'var(--brand)' ) );
	}

	public function test_color_trims_whitespace(): void {
		$this->assertSame( '#fff', StyleMapper::color( '  #fff  ' ) );
	}

	// -------------------------------------------------------------------------
	// border()
	// -------------------------------------------------------------------------

	public function test_border_full_shorthand_three_segments(): void {
		$parsed = StyleMapper::border( '1px solid #FFFFFF' );

		$this->assertSame( 'solid', $parsed['border_border'] );
		$this->assertSame( '#FFFFFF', $parsed['border_color'] );
		$this->assertSame( '1', $parsed['border_width']['top'] );
		$this->assertTrue( $parsed['border_width']['isLinked'] );
	}

	public function test_border_color_only(): void {
		$parsed = StyleMapper::border( '#222222' );

		$this->assertSame( [ 'border_color' => '#222222' ], $parsed );
	}

	public function test_border_width_only(): void {
		$parsed = StyleMapper::border( '5px' );

		$this->assertArrayHasKey( 'border_width', $parsed );
		$this->assertSame( '5', $parsed['border_width']['top'] );
		$this->assertArrayNotHasKey( 'border_border', $parsed );
		$this->assertArrayNotHasKey( 'border_color', $parsed );
	}

	public function test_border_style_only(): void {
		$parsed = StyleMapper::border( 'dashed' );

		$this->assertSame( [ 'border_border' => 'dashed' ], $parsed );
	}

	public function test_border_array_form(): void {
		$parsed = StyleMapper::border( [
			'width' => 2,
			'style' => 'double',
			'color' => '#abc',
		] );

		$this->assertSame( 'double', $parsed['border_border'] );
		$this->assertSame( '#abc', $parsed['border_color'] );
		$this->assertSame( '2', $parsed['border_width']['top'] );
	}

	public function test_border_empty_returns_empty_array(): void {
		$this->assertSame( [], StyleMapper::border( '' ) );
		$this->assertSame( [], StyleMapper::border( null ) );
	}
}
