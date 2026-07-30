<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Renderer;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\DesignTokens\Resolver;
use Stonewright\WpMcp\Elementor\Renderer\Button;
use Stonewright\WpMcp\Elementor\Renderer\Heading;
use Stonewright\WpMcp\Elementor\Renderer\Image;
use Stonewright\WpMcp\Elementor\Renderer\TextEditor;

/**
 * Verifies that DesignSpec `block.style` actually reaches Elementor widget
 * settings — the bug behind the live Design reference builds rendering headings in the
 * default theme blue link colour and buttons in default Elementor green.
 *
 * @covers \Stonewright\WpMcp\Elementor\Renderer\Heading
 * @covers \Stonewright\WpMcp\Elementor\Renderer\TextEditor
 * @covers \Stonewright\WpMcp\Elementor\Renderer\Button
 * @covers \Stonewright\WpMcp\Elementor\Renderer\Image
 * @covers \Stonewright\WpMcp\Elementor\Renderer\StyleMapper
 */
final class RendererStyleMappingTest extends TestCase {

	private Resolver $resolver;

	protected function setUp(): void {
		$this->resolver = new Resolver( [
			'colors' => [
				'primary'   => '#1a73e8',
				'secondary' => '#FF0066',
				'on-dark'   => '#FFFFFF',
			],
		] );
	}

	// -------------------------------------------------------------------------
	// Heading
	// -------------------------------------------------------------------------

	public function test_heading_style_color_reaches_title_color(): void {
		$node = [
			'type'  => 'heading',
			'text'  => 'Hi',
			'level' => 2,
			'style' => [ 'color' => '#FF0066' ],
		];

		$out = Heading::render( $node, $this->resolver, 's0.b0' );

		$this->assertSame( '#FF0066', $out['settings']['title_color'] );
	}

	public function test_heading_style_color_resolves_token(): void {
		$node = [
			'type'  => 'heading',
			'text'  => 'Hi',
			'level' => 1,
			'style' => [ 'color' => '{colors.primary}' ],
		];

		$out = Heading::render( $node, $this->resolver, 's0.b0' );

		$this->assertSame( '#1a73e8', $out['settings']['title_color'] );
	}

	public function test_heading_style_font_size_emits_typography_dict(): void {
		$node = [
			'type'  => 'heading',
			'text'  => 'Hi',
			'level' => 1,
			'style' => [ 'font_size' => '48px' ],
		];

		$out = Heading::render( $node, $this->resolver, 's0.b0' );

		$this->assertSame(
			[ 'unit' => 'px', 'size' => 48, 'sizes' => [] ],
			$out['settings']['typography_font_size']
		);
	}

	public function test_heading_style_viewport_keyed_font_size_emits_tablet_mobile_siblings(): void {
		$node = [
			'type'  => 'heading',
			'text'  => 'Hi',
			'level' => 1,
			'style' => [
				'font_size' => [ 'desktop' => '48px', 'tablet' => '36px', 'mobile' => '24px' ],
			],
		];

		$out = Heading::render( $node, $this->resolver, 's0.b0' );

		$this->assertSame(
			[ 'unit' => 'px', 'size' => 48, 'sizes' => [] ],
			$out['settings']['typography_font_size']
		);
		$this->assertSame(
			[ 'unit' => 'px', 'size' => 36, 'sizes' => [] ],
			$out['settings']['typography_font_size_tablet']
		);
		$this->assertSame(
			[ 'unit' => 'px', 'size' => 24, 'sizes' => [] ],
			$out['settings']['typography_font_size_mobile']
		);
	}

	public function test_heading_style_background_sets_prefix_flag(): void {
		$node = [
			'type'  => 'heading',
			'text'  => 'Banner',
			'level' => 2,
			'style' => [ 'background' => '#101010' ],
		];

		$out = Heading::render( $node, $this->resolver, 's0.b0' );

		$this->assertSame( 'classic', $out['settings']['_background_background'] );
		$this->assertSame( '#101010', $out['settings']['_background_color'] );
	}

	public function test_heading_style_padding_normalises_to_dimensions(): void {
		$node = [
			'type'  => 'heading',
			'text'  => 'Pad',
			'level' => 2,
			'style' => [ 'padding' => '16px 32px' ],
		];

		$out = Heading::render( $node, $this->resolver, 's0.b0' );

		$this->assertSame(
			[
				'unit'     => 'px',
				'top'      => '16',
				'right'    => '32',
				'bottom'   => '16',
				'left'     => '32',
				'isLinked' => false,
			],
			$out['settings']['_padding']
		);
	}

	public function test_heading_style_typography_weight_and_transform(): void {
		$node = [
			'type'  => 'heading',
			'text'  => 'Hi',
			'level' => 1,
			'style' => [
				'font_weight'    => '700',
				'text_transform' => 'uppercase',
				'letter_spacing' => '2px',
			],
		];

		$out = Heading::render( $node, $this->resolver, 's0.b0' );

		$this->assertSame( '700', $out['settings']['typography_font_weight'] );
		$this->assertSame( 'uppercase', $out['settings']['typography_text_transform'] );
		$this->assertSame(
			[ 'unit' => 'px', 'size' => 2, 'sizes' => [] ],
			$out['settings']['typography_letter_spacing']
		);
	}

	public function test_heading_with_no_style_preserves_existing_behaviour(): void {
		$node = [
			'type'  => 'heading',
			'text'  => 'Plain',
			'level' => 2,
		];

		$out = Heading::render( $node, $this->resolver, 's0.b0' );

		$this->assertSame( 'Plain', $out['settings']['title'] );
		$this->assertSame( 'h2', $out['settings']['header_size'] );
		$this->assertArrayNotHasKey( 'title_color', $out['settings'] );
		$this->assertArrayNotHasKey( '_background_background', $out['settings'] );
	}

	// -------------------------------------------------------------------------
	// TextEditor
	// -------------------------------------------------------------------------

	public function test_text_editor_style_color_reaches_text_color(): void {
		$node = [
			'type' => 'text-editor',
			'html' => '<p>Body.</p>',
			'style' => [ 'color' => '#333333' ],
		];

		$out = TextEditor::render( $node, $this->resolver, 's0.b0' );

		$this->assertSame( '#333333', $out['settings']['text_color'] );
	}

	public function test_text_editor_style_font_size_emits_typography_dict(): void {
		$node = [
			'type'  => 'text-editor',
			'html'  => '<p>x</p>',
			'style' => [ 'font_size' => '18px' ],
		];

		$out = TextEditor::render( $node, $this->resolver, 's0.b0' );

		$this->assertSame(
			[ 'unit' => 'px', 'size' => 18, 'sizes' => [] ],
			$out['settings']['typography_font_size']
		);
	}

	// -------------------------------------------------------------------------
	// Button
	// -------------------------------------------------------------------------

	public function test_button_style_color_uses_unprefixed_button_text_color(): void {
		$node = [
			'type'  => 'button',
			'text'  => 'Go',
			'url'   => 'https://example.com',
			'style' => [ 'color' => '#FFFFFF' ],
		];

		$out = Button::render( $node, $this->resolver, 's0.b0' );

		$this->assertSame( '#FFFFFF', $out['settings']['button_text_color'] );
	}

	public function test_button_style_background_uses_unprefixed_background_color(): void {
		// Button's quirk: NOT `_background_color`, it's bare `background_color`.
		$node = [
			'type'  => 'button',
			'text'  => 'Go',
			'url'   => 'https://example.com',
			'style' => [ 'background' => '#1a73e8' ],
		];

		$out = Button::render( $node, $this->resolver, 's0.b0' );

		$this->assertSame( 'classic', $out['settings']['background_background'] );
		$this->assertSame( '#1a73e8', $out['settings']['background_color'] );
		$this->assertArrayNotHasKey( '_background_background', $out['settings'] );
	}

	public function test_button_style_padding_maps_to_text_padding(): void {
		$node = [
			'type'  => 'button',
			'text'  => 'Submit',
			'url'   => '#',
			'style' => [ 'padding' => '12px 24px' ],
		];

		$out = Button::render( $node, $this->resolver, 's0.b0' );

		$this->assertSame(
			[
				'unit'     => 'px',
				'top'      => '12',
				'right'    => '24',
				'bottom'   => '12',
				'left'     => '24',
				'isLinked' => false,
			],
			$out['settings']['text_padding']
		);
	}

	public function test_button_style_border_radius_normalises_to_dimension(): void {
		$node = [
			'type'  => 'button',
			'text'  => 'Submit',
			'url'   => '#',
			'style' => [ 'border_radius' => '8px' ],
		];

		$out = Button::render( $node, $this->resolver, 's0.b0' );

		$this->assertSame(
			[
				'unit'     => 'px',
				'top'      => '8',
				'right'    => '8',
				'bottom'   => '8',
				'left'     => '8',
				'isLinked' => true,
			],
			$out['settings']['border_radius']
		);
	}

	public function test_button_style_border_shorthand_splits_into_three_keys(): void {
		$node = [
			'type'  => 'button',
			'text'  => 'Outlined',
			'url'   => '#',
			'style' => [ 'border' => '1px solid #FFFFFF' ],
		];

		$out = Button::render( $node, $this->resolver, 's0.b0' );

		$this->assertSame( 'solid', $out['settings']['border_border'] );
		$this->assertSame( '#FFFFFF', $out['settings']['border_color'] );
		$this->assertSame(
			[
				'unit'     => 'px',
				'top'      => '1',
				'right'    => '1',
				'bottom'   => '1',
				'left'     => '1',
				'isLinked' => true,
			],
			$out['settings']['border_width']
		);
	}

	public function test_button_style_token_resolution_in_color(): void {
		$node = [
			'type'  => 'button',
			'text'  => 'Tokenised',
			'url'   => '#',
			'style' => [ 'background' => '{colors.primary}' ],
		];

		$out = Button::render( $node, $this->resolver, 's0.b0' );

		$this->assertSame( '#1a73e8', $out['settings']['background_color'] );
	}

	public function test_button_with_only_legacy_node_color_still_works(): void {
		// Pre-style behaviour must remain intact for existing specs.
		$node = [
			'type'  => 'button',
			'text'  => 'Legacy',
			'url'   => '#',
			'color' => '#abc',
		];

		$out = Button::render( $node, $this->resolver, 's0.b0' );

		$this->assertSame( '#abc', $out['settings']['button_text_color'] );
	}

	// -------------------------------------------------------------------------
	// Image
	// -------------------------------------------------------------------------

	public function test_image_style_border_radius_normalises_to_dimension(): void {
		$node = [
			'type'  => 'image',
			'url'   => 'https://example.com/x.jpg',
			'alt'   => '',
			'style' => [ 'border_radius' => 16 ],
		];

		$out = Image::render( $node, $this->resolver, 's0.b0' );

		$this->assertSame(
			[
				'unit'     => 'px',
				'top'      => '16',
				'right'    => '16',
				'bottom'   => '16',
				'left'     => '16',
				'isLinked' => true,
			],
			$out['settings']['border_radius']
		);
	}

	public function test_image_style_border_shorthand_uses_image_border_prefix(): void {
		// Elementor's image widget uses `image_border_*` not `border_*`.
		$node = [
			'type'  => 'image',
			'url'   => 'https://example.com/x.jpg',
			'alt'   => '',
			'style' => [ 'border' => '2px solid #000000' ],
		];

		$out = Image::render( $node, $this->resolver, 's0.b0' );

		$this->assertSame( 'solid', $out['settings']['image_border_border'] );
		$this->assertSame( '#000000', $out['settings']['image_border_color'] );
		$this->assertArrayHasKey( 'image_border_width', $out['settings'] );
		$this->assertArrayNotHasKey( 'border_border', $out['settings'] );
	}

	public function test_image_style_width_via_size_normalises(): void {
		$node = [
			'type'  => 'image',
			'url'   => 'https://example.com/x.jpg',
			'alt'   => '',
			'style' => [ 'width' => '300px' ],
		];

		$out = Image::render( $node, $this->resolver, 's0.b0' );

		$this->assertSame(
			[ 'unit' => 'px', 'size' => 300, 'sizes' => [] ],
			$out['settings']['width']
		);
	}
}
