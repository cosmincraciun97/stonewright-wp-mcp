<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\DesignTokens\Resolver;
use Stonewright\WpMcp\Elementor\Renderer\Accordion;
use Stonewright\WpMcp\Elementor\Renderer\Button;
use Stonewright\WpMcp\Elementor\Renderer\Column;
use Stonewright\WpMcp\Elementor\Renderer\Container;
use Stonewright\WpMcp\Elementor\Renderer\Counter;
use Stonewright\WpMcp\Elementor\Renderer\Divider;
use Stonewright\WpMcp\Elementor\Renderer\Form;
use Stonewright\WpMcp\Elementor\Renderer\Heading;
use Stonewright\WpMcp\Elementor\Renderer\ImageGallery;
use Stonewright\WpMcp\Elementor\Renderer\ProGate;
use Stonewright\WpMcp\Elementor\Renderer\Icon;
use Stonewright\WpMcp\Elementor\Renderer\IconBox;
use Stonewright\WpMcp\Elementor\Renderer\Image;
use Stonewright\WpMcp\Elementor\Renderer\ImageBox;
use Stonewright\WpMcp\Elementor\Renderer\ProgressBar;
use Stonewright\WpMcp\Elementor\Renderer\Section;
use Stonewright\WpMcp\Elementor\Renderer\Slides;
use Stonewright\WpMcp\Elementor\Renderer\SocialIcons;
use Stonewright\WpMcp\Elementor\Renderer\Spacer;
use Stonewright\WpMcp\Elementor\Renderer\Tabs;
use Stonewright\WpMcp\Elementor\Renderer\Testimonial;
use Stonewright\WpMcp\Elementor\Renderer\TextEditor;
use Stonewright\WpMcp\Elementor\Renderer\Toggle;
use Stonewright\WpMcp\Elementor\Renderer\Video;
use Stonewright\WpMcp\Elementor\Renderer;

/**
 * Per-widget renderer unit tests.
 *
 * Each test:
 *  1. Builds a minimal DesignSpec node.
 *  2. Renders via the per-widget renderer.
 *  3. Asserts deep equality against the golden fixture under tests/fixtures/elementor/.
 *
 * @covers \Stonewright\WpMcp\Elementor\Renderer\Section
 * @covers \Stonewright\WpMcp\Elementor\Renderer\Column
 * @covers \Stonewright\WpMcp\Elementor\Renderer\Container
 * @covers \Stonewright\WpMcp\Elementor\Renderer\Heading
 * @covers \Stonewright\WpMcp\Elementor\Renderer\TextEditor
 * @covers \Stonewright\WpMcp\Elementor\Renderer\Image
 * @covers \Stonewright\WpMcp\Elementor\Renderer\Button
 * @covers \Stonewright\WpMcp\Elementor\Renderer\Spacer
 * @covers \Stonewright\WpMcp\Elementor\Renderer\Divider
 * @covers \Stonewright\WpMcp\Elementor\Renderer\Video
 * @covers \Stonewright\WpMcp\Elementor\Renderer\Icon
 * @covers \Stonewright\WpMcp\Elementor\Renderer\IconBox
 * @covers \Stonewright\WpMcp\Elementor\Renderer\ImageBox
 * @covers \Stonewright\WpMcp\Elementor\Renderer\Testimonial
 * @covers \Stonewright\WpMcp\Elementor\Renderer\Tabs
 * @covers \Stonewright\WpMcp\Elementor\Renderer\Accordion
 * @covers \Stonewright\WpMcp\Elementor\Renderer\Toggle
 * @covers \Stonewright\WpMcp\Elementor\Renderer\SocialIcons
 * @covers \Stonewright\WpMcp\Elementor\Renderer\ProgressBar
 * @covers \Stonewright\WpMcp\Elementor\Renderer\Counter
 * @covers \Stonewright\WpMcp\Elementor\Renderer\Form
 * @covers \Stonewright\WpMcp\Elementor\Renderer\ProGate
 * @covers \Stonewright\WpMcp\Elementor\Renderer\Slides
 * @covers \Stonewright\WpMcp\Elementor\Renderer
 * @covers \Stonewright\WpMcp\DesignTokens\Resolver
 */
final class ElementorRendererTest extends TestCase {

	private Resolver $resolver;
	private string   $fixture_dir;

	protected function setUp(): void {
		$this->resolver    = new Resolver( [] );
		$this->fixture_dir = dirname( __DIR__ ) . '/fixtures/elementor';
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * @return array<string, mixed>
	 */
	private function fixture( string $name ): array {
		$path = $this->fixture_dir . '/' . $name . '.json';
		self::assertFileExists( $path, "Golden fixture missing: {$name}.json" );
		$raw = file_get_contents( $path );
		self::assertIsString( $raw );
		$decoded = json_decode( $raw, true );
		self::assertIsArray( $decoded, "Fixture {$name}.json is not valid JSON" );
		return $decoded;
	}

	// -------------------------------------------------------------------------
	// Layout shells
	// -------------------------------------------------------------------------

	public function test_section_renderer(): void {
		$node   = [ 'type' => 'section' ];
		$result = Section::render( $node, $this->resolver, 's0' );
		$this->assertSame( $this->fixture( 'section' ), $result );
	}

	public function test_section_honors_companion_full_width_flag(): void {
		$node   = [ 'type' => 'section', 'fullWidth' => true ];
		$result = Section::render( $node, $this->resolver, 's0' );

		$this->assertSame( 'full', $result['settings']['content_width'] );
		$this->assertSame( '0', $result['settings']['padding']['top'] );
		$this->assertSame( '0', $result['settings']['padding']['right'] );
	}

	public function test_section_maps_background_image_controls(): void {
		$node   = [
			'type'       => 'section',
			'background' => [
				'image'    => 'https://example.test/wp-content/uploads/hero-glow.png',
				'image_id' => 42,
				'position' => 'center center',
				'size'     => 'cover',
			],
		];
		$result = Section::render( $node, $this->resolver, 's0' );

		$this->assertSame( 'classic', $result['settings']['background_background'] );
		$this->assertSame( 'https://example.test/wp-content/uploads/hero-glow.png', $result['settings']['background_image']['url'] );
		$this->assertSame( 42, $result['settings']['background_image']['id'] );
		$this->assertSame( 'center center', $result['settings']['background_position'] );
		$this->assertSame( 'cover', $result['settings']['background_size'] );
	}

	public function test_section_maps_sticky_header_settings(): void {
		$node   = [
			'type'          => 'section',
			'fullWidth'     => true,
			'sticky'        => 'top',
			'sticky_on'     => [ 'desktop', 'tablet', 'mobile' ],
			'sticky_offset' => 0,
			'z_index'       => 999,
			'hide_on'       => [ 'desktop' ],
		];
		$result = Section::render( $node, $this->resolver, 's0' );

		$this->assertSame( 'top', $result['settings']['sticky'] );
		$this->assertSame( [ 'desktop', 'tablet', 'mobile' ], $result['settings']['sticky_on'] );
		$this->assertSame( 0, $result['settings']['sticky_offset'] );
		$this->assertSame( 999, $result['settings']['z_index'] );
		$this->assertSame( 'hidden-desktop', $result['settings']['hide_desktop'] );
		$this->assertArrayNotHasKey( '_element_hide_desktop', $result['settings'] );
	}

	public function test_section_maps_flex_alignment_for_centered_full_width_wrappers(): void {
		$node   = [
			'type'            => 'section',
			'layout'          => 'stack',
			'align_items'     => 'center',
			'justify_content' => 'center',
		];
		$result = Section::render( $node, $this->resolver, 's0' );

		$this->assertSame( 'center', $result['settings']['flex_align_items'] );
		$this->assertSame( 'center', $result['settings']['flex_justify_content'] );
		$this->assertArrayNotHasKey( 'align_items', $result['settings'] );
		$this->assertArrayNotHasKey( 'justify_content', $result['settings'] );
	}

	public function test_column_renderer(): void {
		$node   = [ 'type' => 'column' ];
		$result = Column::render( $node, $this->resolver, 's0' );
		$this->assertSame( $this->fixture( 'column' ), $result );
	}

	public function test_column_accepts_nested_style_shape(): void {
		$node   = [
			'type'  => 'column',
			'style' => [
				'width'            => '50%',
				'gap'              => '24px',
				'background_color' => '#0a0526',
				'padding'          => '20px 32px',
			],
		];
		$result = Column::render( $node, $this->resolver, 's0' );

		$this->assertSame( 50, $result['settings']['width']['size'] );
		$this->assertSame( '#0a0526', $result['settings']['background_color'] );
		$this->assertSame( '24', $result['settings']['flex_gap']['column'] );
		$this->assertSame( '20', $result['settings']['padding']['top'] );
		$this->assertSame( '32', $result['settings']['padding']['right'] );
	}

	public function test_container_renderer(): void {
		$node   = [ 'type' => 'group' ];
		$result = Container::render( $node, $this->resolver, 's0' );
		$this->assertSame( $this->fixture( 'container' ), $result );
	}

	public function test_container_grid_layout_uses_elementor_grid_container_settings(): void {
		$node   = [ 'type' => 'container', 'layout' => 'grid', 'columns' => 4, 'gap' => 16 ];
		$result = Container::render( $node, $this->resolver, 's0' );

		$this->assertSame( 'container', $result['elType'] );
		$this->assertSame( 'grid', $result['settings']['container_type'] );
		$this->assertSame( 4, $result['settings']['grid_columns_grid']['size'] );
		$this->assertArrayNotHasKey( '_column_size', $result['settings'] );
	}

	public function test_container_flex_layout_uses_direction_without_legacy_column_size(): void {
		$node   = [ 'type' => 'container', 'layout' => 'flex', 'direction' => 'row' ];
		$result = Container::render( $node, $this->resolver, 's0' );

		$this->assertSame( 'container', $result['elType'] );
		$this->assertSame( 'flex', $result['settings']['container_type'] );
		$this->assertSame( 'row', $result['settings']['flex_direction'] );
		$this->assertArrayNotHasKey( '_column_size', $result['settings'] );
	}

	public function test_container_maps_flex_alignment_and_wrap(): void {
		$node   = [
			'type'            => 'container',
			'layout'          => 'flex',
			'direction'       => 'row',
			'justify_content' => 'space-between',
			'align_items'     => 'center',
			'wrap'            => 'wrap',
		];
		$result = Container::render( $node, $this->resolver, 's0' );

		$this->assertSame( 'space-between', $result['settings']['flex_justify_content'] );
		$this->assertSame( 'center', $result['settings']['flex_align_items'] );
		$this->assertSame( 'wrap', $result['settings']['flex_wrap'] );
		$this->assertArrayNotHasKey( 'justify_content', $result['settings'] );
		$this->assertArrayNotHasKey( 'align_items', $result['settings'] );
	}

	public function test_container_accepts_legacy_companion_horizontal_layout_value(): void {
		$node   = [ 'type' => 'container', 'layout' => 'horizontal' ];
		$result = Container::render( $node, $this->resolver, 's0' );

		$this->assertSame( 'flex', $result['settings']['container_type'] );
		$this->assertSame( 'row', $result['settings']['flex_direction'] );
	}

	public function test_dimensioned_companion_container_resets_elementor_default_padding(): void {
		$node   = [
			'type'      => 'container',
			'layout'    => 'flex',
			'direction' => 'row',
			'width'     => 1216,
			'height'    => 88,
		];
		$result = Container::render( $node, $this->resolver, 's0' );

		$this->assertSame( 'full', $result['settings']['content_width'] );
		$this->assertSame( '0', $result['settings']['padding']['top'] );
		$this->assertSame( '0', $result['settings']['padding']['left'] );
	}

	public function test_container_maps_sticky_and_responsive_visibility_settings(): void {
		$node   = [
			'type'          => 'container',
			'sticky'        => 'top',
			'sticky_on'     => [ 'desktop', 'tablet', 'mobile' ],
			'sticky_offset' => 0,
			'z_index'       => 1000,
			'hide_on'       => [ 'mobile' ],
		];
		$result = Container::render( $node, $this->resolver, 's0' );

		$this->assertSame( 'top', $result['settings']['sticky'] );
		$this->assertSame( [ 'desktop', 'tablet', 'mobile' ], $result['settings']['sticky_on'] );
		$this->assertSame( 0, $result['settings']['sticky_offset'] );
		$this->assertSame( 1000, $result['settings']['z_index'] );
		$this->assertSame( 'hidden-mobile', $result['settings']['hide_mobile'] );
		$this->assertArrayNotHasKey( '_element_hide_mobile', $result['settings'] );
	}

	public function test_container_maps_background_image_controls(): void {
		$node   = [
			'type'       => 'container',
			'background' => [
				'image'    => 'https://example.test/wp-content/uploads/section-bg.png',
				'image_id' => 84,
				'position' => 'top center',
				'size'     => 'contain',
			],
		];
		$result = Container::render( $node, $this->resolver, 's0' );

		$this->assertSame( 'classic', $result['settings']['background_background'] );
		$this->assertSame( 'https://example.test/wp-content/uploads/section-bg.png', $result['settings']['background_image']['url'] );
		$this->assertSame( 84, $result['settings']['background_image']['id'] );
		$this->assertSame( 'top center', $result['settings']['background_position'] );
		$this->assertSame( 'contain', $result['settings']['background_size'] );
	}

	public function test_container_accepts_companion_dimensions_and_styles_shape(): void {
		$node   = [
			'type'      => 'container',
			'layout'    => 'flex',
			'direction' => 'row',
			'width'     => 1280,
			'height'    => 640,
			'styles'    => [
				'backgroundColor' => '#030712',
				'padding'         => '80px 40px',
				'gap'             => '48px',
			],
		];
		$result = Container::render( $node, $this->resolver, 's0' );

		$this->assertSame( '#030712', $result['settings']['background_color'] );
		$this->assertSame( 1280, $result['settings']['width']['size'] );
		$this->assertSame( 640, $result['settings']['height']['size'] );
		$this->assertSame( 'full', $result['settings']['content_width'] );
		$this->assertSame( '80', $result['settings']['padding']['top'] );
		$this->assertSame( '40', $result['settings']['padding']['right'] );
		$this->assertSame( '48', $result['settings']['flex_gap']['column'] );
	}

	public function test_render_centers_fixed_width_inner_container_in_full_width_section(): void {
		$spec        = [
			'sections' => [
				[
					'type'      => 'section',
					'fullWidth' => true,
					'blocks'    => [
						[
							'type'      => 'container',
							'layout'    => 'flex',
							'direction' => 'column',
							'width'     => 1280,
						],
					],
				],
			],
		];
		$diagnostics = [];
		$result      = Renderer::render( $spec, $diagnostics );

		$this->assertSame( 'center', $result[0]['settings']['flex_align_items'] ?? null );
	}

	public function test_render_shrinks_percent_row_children_when_gap_would_wrap(): void {
		$spec        = [
			'sections' => [
				[
					'type'   => 'section',
					'blocks' => [
						[
							'type'      => 'container',
							'layout'    => 'flex',
							'direction' => 'row',
							'gap'       => 80,
							'width'     => 1280,
							'blocks'    => [
								[
									'type'  => 'container',
									'style' => [ 'width' => '49%' ],
								],
								[
									'type'  => 'container',
									'style' => [ 'width' => '49%' ],
								],
							],
						],
					],
				],
			],
		];
		$diagnostics = [];
		$result      = Renderer::render( $spec, $diagnostics );

		$row       = $result[0]['elements'][0];
		$first     = $row['elements'][0]['settings']['width']['size'];
		$second    = $row['elements'][1]['settings']['width']['size'];
		$gap_ratio = 80 / 1280 * 100;

		$this->assertLessThanOrEqual( 100, $first + $second + $gap_ratio );
		$this->assertLessThan( 49, $first );
		$this->assertSame( $first, $second );
	}

	public function test_container_maps_visual_frame_style_to_native_settings(): void {
		$node   = [
			'type'   => 'container',
			'style'  => [
				'border_radius' => 16,
				'border'        => '1px solid rgba(255,255,255,0.15)',
				'margin'        => '0 0 24px 0',
			],
		];
		$result = Container::render( $node, $this->resolver, 's0' );

		$this->assertSame( '16', $result['settings']['border_radius']['top'] );
		$this->assertSame( 'solid', $result['settings']['border_border'] );
		$this->assertSame( 'rgba(255,255,255,0.15)', $result['settings']['border_color'] );
		$this->assertSame( '24', $result['settings']['_margin']['bottom'] );
	}

	// -------------------------------------------------------------------------
	// Text widgets
	// -------------------------------------------------------------------------

	public function test_heading_renderer(): void {
		$node   = [ 'type' => 'heading', 'text' => 'Hello World', 'level' => 2 ];
		$result = Heading::render( $node, $this->resolver, 's0.b0' );
		$this->assertSame( $this->fixture( 'heading' ), $result );
	}

	public function test_heading_paragraph_type(): void {
		$node   = [ 'type' => 'paragraph', 'text' => 'Some text' ];
		$result = Heading::render( $node, $this->resolver, 's0.b0' );
		$this->assertSame( 'p', $result['settings']['header_size'] );
		$this->assertSame( 'heading', $result['widgetType'] );
	}

	public function test_heading_accepts_companion_typography_and_styles_shape(): void {
		$node   = [
			'type'       => 'heading',
			'text'       => 'Companion heading',
			'level'      => 1,
			'typography' => [
				'fontFamily'   => 'Montserrat',
				'fontSize'     => 72,
				'fontWeight'   => 700,
				'lineHeightPx' => 79,
			],
			'styles'     => [
				'color' => '#fdee17',
			],
		];
		$result = Heading::render( $node, $this->resolver, 's0.b0' );

		$this->assertSame( '#fdee17', $result['settings']['title_color'] );
		$this->assertSame( 'Montserrat', $result['settings']['typography_font_family'] );
		$this->assertSame( '700', $result['settings']['typography_font_weight'] );
		$this->assertSame( 72, $result['settings']['typography_font_size']['size'] );
		$this->assertSame( 79, $result['settings']['typography_line_height']['size'] );
		$this->assertSame( 'custom', $result['settings']['typography_typography'] );
	}

	public function test_heading_level_zero_clamps_to_h1(): void {
		$node   = [ 'type' => 'heading', 'text' => 'Clamped', 'level' => 0 ];
		$result = Heading::render( $node, $this->resolver, 's0.b0' );
		$this->assertSame( 'h1', $result['settings']['header_size'] );
	}

	public function test_heading_level_99_clamps_to_h6(): void {
		$node   = [ 'type' => 'heading', 'text' => 'Clamped', 'level' => 99 ];
		$result = Heading::render( $node, $this->resolver, 's0.b0' );
		$this->assertSame( 'h6', $result['settings']['header_size'] );
	}

	public function test_text_editor_renderer_html(): void {
		$node   = [ 'type' => 'text-editor', 'html' => '<p>Rich content here.</p>' ];
		$result = TextEditor::render( $node, $this->resolver, 's0.b0' );
		$this->assertSame( $this->fixture( 'text-editor' ), $result );
	}

	public function test_text_editor_renderer_plain_text(): void {
		$node   = [ 'type' => 'text-editor', 'text' => 'Plain text' ];
		$result = TextEditor::render( $node, $this->resolver, 's0.b0' );
		$this->assertStringContainsString( '<p>', $result['settings']['editor'] );
	}

	public function test_dispatcher_html_type_is_not_rendered_as_widget(): void {
		$spec = [
			'version'  => '1.0.0',
			'page'     => [ 'title' => 'HTML Test' ],
			'sections' => [
				[
					'id'     => 's0',
					'blocks' => [
						[
							'type' => 'html',
							'html' => '<style>.sw-test{color:#fdee17}</style><div class="sw-test">Styled</div>',
						],
					],
				],
			],
		];

		$diag   = [];
		$output = Renderer::render( $spec, $diag );

		$this->assertEmpty( $output[0]['elements'] );
		$this->assertCount( 1, $diag );
		$this->assertSame( 'unsupported_node', $diag[0]['code'] );
		$this->assertSame( 'html', $diag[0]['type'] );
	}

	// -------------------------------------------------------------------------
	// Media widgets
	// -------------------------------------------------------------------------

	public function test_image_renderer(): void {
		$node   = [
			'type' => 'image',
			'url'  => 'https://example.com/photo.jpg',
			'alt'  => 'A photo',
		];
		$result = Image::render( $node, $this->resolver, 's0.b0' );
		$this->assertSame( $this->fixture( 'image' ), $result );
	}

	public function test_image_accepts_companion_src_and_dimensions(): void {
		$node   = [
			'type'   => 'image',
			'src'    => 'https://example.com/design-export.png',
			'alt'    => 'Design reference export',
			'width'  => 631,
			'height' => 441,
		];
		$result = Image::render( $node, $this->resolver, 's0.b0' );

		$this->assertSame( 'https://example.com/design-export.png', $result['settings']['image']['url'] );
		$this->assertSame( 631, $result['settings']['width']['size'] );
		$this->assertSame( 441, $result['settings']['height']['size'] );
	}

	public function test_image_gallery_renderer_uses_native_elementor_widget(): void {
		$node   = [
			'type'    => 'image-gallery',
			'columns' => 4,
			'images'  => [
				[ 'id' => 11, 'url' => 'https://example.com/one.jpg' ],
				[ 'id' => 12, 'url' => 'https://example.com/two.jpg' ],
			],
		];
		$result = ImageGallery::render( $node, $this->resolver, 's0.b2' );

		$this->assertSame( 'image-gallery', $result['widgetType'] );
		$this->assertSame( 4, $result['settings']['gallery_columns'] );
		$this->assertSame( 11, $result['settings']['wp_gallery'][0]['id'] );
		$this->assertSame( 'https://example.com/two.jpg', $result['settings']['wp_gallery'][1]['url'] );
	}

	public function test_video_youtube_renderer(): void {
		$node   = [
			'type' => 'video',
			'url'  => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
		];
		$result = Video::render( $node, $this->resolver, 's0.b0' );
		$this->assertSame( $this->fixture( 'video' ), $result );
		$this->assertSame( 'youtube', $result['settings']['video_type'] );
	}

	public function test_video_vimeo_detection(): void {
		$node   = [ 'type' => 'video', 'url' => 'https://vimeo.com/12345' ];
		$result = Video::render( $node, $this->resolver, 's0.b0' );
		$this->assertSame( 'vimeo', $result['settings']['video_type'] );
		$this->assertArrayHasKey( 'vimeo_url', $result['settings'] );
	}

	public function test_video_hosted_detection(): void {
		$node   = [ 'type' => 'video', 'url' => 'https://example.com/video.mp4' ];
		$result = Video::render( $node, $this->resolver, 's0.b0' );
		$this->assertSame( 'hosted', $result['settings']['video_type'] );
	}

	// -------------------------------------------------------------------------
	// Interactive widgets
	// -------------------------------------------------------------------------

	public function test_button_renderer(): void {
		$node   = [
			'type' => 'button',
			'text' => 'Click Me',
			'url'  => 'https://example.com',
		];
		$result = Button::render( $node, $this->resolver, 's0.b0' );
		$this->assertSame( $this->fixture( 'button' ), $result );
	}

	public function test_button_accepts_companion_typography_and_styles_shape(): void {
		$node   = [
			'type'       => 'button',
			'text'       => 'Free ticket',
			'url'        => '#',
			'typography' => [
				'fontFamily' => 'Montserrat',
				'fontSize'   => 16,
				'fontWeight' => 700,
			],
			'styles'     => [
				'color'           => '#000000',
				'backgroundColor' => '#fdee17',
				'padding'         => '14px 28px',
			],
		];
		$result = Button::render( $node, $this->resolver, 's0.b0' );

		$this->assertSame( '#000000', $result['settings']['button_text_color'] );
		$this->assertSame( '#fdee17', $result['settings']['background_color'] );
		$this->assertSame( 'Montserrat', $result['settings']['typography_font_family'] );
		$this->assertSame( 16, $result['settings']['typography_font_size']['size'] );
		$this->assertSame( '700', $result['settings']['typography_font_weight'] );
		$this->assertSame( '14', $result['settings']['text_padding']['top'] );
		$this->assertSame( '28', $result['settings']['text_padding']['right'] );
	}

	public function test_spacer_renderer(): void {
		$node   = [ 'type' => 'spacer', 'height' => 60 ];
		$result = Spacer::render( $node, $this->resolver, 's0.b0' );
		$this->assertSame( $this->fixture( 'spacer' ), $result );
	}

	public function test_spacer_default_height(): void {
		$node   = [ 'type' => 'spacer' ];
		$result = Spacer::render( $node, $this->resolver, 's0.b0' );
		$this->assertSame( 40, $result['settings']['space']['size'] );
	}

	public function test_divider_renderer(): void {
		$node   = [ 'type' => 'divider', 'style' => 'solid', 'weight' => 2 ];
		$result = Divider::render( $node, $this->resolver, 's0.b0' );
		$this->assertSame( $this->fixture( 'divider' ), $result );
	}

	public function test_divider_accepts_style_array(): void {
		$node   = [
			'type'  => 'divider',
			'style' => [
				'color'  => '#ff0000',
				'width'  => '50%',
				'margin' => '10px 20px',
			],
		];
		$result = Divider::render( $node, $this->resolver, 's0.b0' );

		$this->assertSame( '#ff0000', $result['settings']['color'] );
		$this->assertSame( 50, $result['settings']['width']['size'] );
		$this->assertSame( '%', $result['settings']['width']['unit'] );
		$this->assertSame( '10', $result['settings']['_margin']['top'] );
		$this->assertSame( '20', $result['settings']['_margin']['right'] );
		$this->assertArrayNotHasKey( 'style', $result['settings'] );
	}

	// -------------------------------------------------------------------------
	// Icon widgets
	// -------------------------------------------------------------------------

	public function test_icon_renderer(): void {
		$node   = [ 'type' => 'icon', 'icon' => 'fas fa-check' ];
		$result = Icon::render( $node, $this->resolver, 's0.b0' );
		$this->assertSame( $this->fixture( 'icon' ), $result );
	}

	public function test_icon_box_renderer(): void {
		$node   = [
			'type'        => 'icon-box',
			'icon'        => 'fas fa-rocket',
			'title'       => 'Feature Title',
			'description' => 'Feature description goes here.',
		];
		$result = IconBox::render( $node, $this->resolver, 's0.b0' );
		$this->assertSame( $this->fixture( 'icon-box' ), $result );
	}

	public function test_image_box_renderer(): void {
		$node   = [
			'type'        => 'image-box',
			'image'       => [ 'url' => 'https://example.com/card.jpg', 'alt' => 'Card image' ],
			'title'       => 'Card Title',
			'description' => 'Card description.',
		];
		$result = ImageBox::render( $node, $this->resolver, 's0.b0' );
		$this->assertSame( $this->fixture( 'image-box' ), $result );
	}

	// -------------------------------------------------------------------------
	// Content blocks
	// -------------------------------------------------------------------------

	public function test_testimonial_renderer(): void {
		$node   = [
			'type'    => 'testimonial',
			'content' => 'This product changed my life.',
			'name'    => 'Jane Doe',
			'job'     => 'CEO, Acme Corp',
		];
		$result = Testimonial::render( $node, $this->resolver, 's0.b0' );
		$this->assertSame( $this->fixture( 'testimonial' ), $result );
	}

	public function test_tabs_renderer(): void {
		$node   = [
			'type' => 'tabs',
			'tabs' => [
				[ 'title' => 'Tab One', 'content' => 'Content of tab one.' ],
			],
		];
		$result = Tabs::render( $node, $this->resolver, 's0.b0' );
		$this->assertSame( $this->fixture( 'tabs' ), $result );
	}

	public function test_accordion_renderer(): void {
		$node   = [
			'type'  => 'accordion',
			'items' => [
				[ 'title' => 'Question One', 'content' => 'Answer to question one.' ],
			],
		];
		$result = Accordion::render( $node, $this->resolver, 's0.b0' );
		$this->assertSame( $this->fixture( 'accordion' ), $result );
	}

	public function test_toggle_renderer(): void {
		$node   = [
			'type'  => 'toggle',
			'items' => [
				[ 'title' => 'Toggle Title', 'content' => 'Toggle body content.' ],
			],
		];
		$result = Toggle::render( $node, $this->resolver, 's0.b0' );
		$this->assertSame( $this->fixture( 'toggle' ), $result );
	}

	public function test_social_icons_renderer(): void {
		$node   = [
			'type'  => 'social-icons',
			'icons' => [
				[
					'network' => 'facebook',
					'icon'    => 'fab fa-facebook-f',
					'url'     => 'https://facebook.com/example',
				],
			],
		];
		$result = SocialIcons::render( $node, $this->resolver, 's0.b0' );
		$this->assertSame( $this->fixture( 'social-icons' ), $result );
	}

	public function test_social_icons_uses_elementor_social_icon_repeater_key(): void {
		$node   = [
			'type'  => 'social-icons',
			'icons' => [
				[
					'network' => 'instagram',
					'icon'    => 'fab fa-instagram',
					'url'     => 'https://instagram.com/example',
				],
			],
		];
		$result = SocialIcons::render( $node, $this->resolver, 's0.b0' );
		$item   = $result['settings']['social_icon_list'][0];

		$this->assertArrayHasKey( 'social_icon', $item );
		$this->assertArrayNotHasKey( 'social', $item );
		$this->assertSame( 'fab fa-instagram', $item['social_icon']['value'] );
	}

	public function test_progress_bar_renderer(): void {
		$node   = [
			'type'    => 'progress-bar',
			'title'   => 'Design Skills',
			'percent' => 80,
		];
		$result = ProgressBar::render( $node, $this->resolver, 's0.b0' );
		$this->assertSame( $this->fixture( 'progress-bar' ), $result );
	}

	public function test_progress_bar_clamps_to_100(): void {
		$node   = [ 'type' => 'progress-bar', 'title' => 'Test', 'percent' => 150 ];
		$result = ProgressBar::render( $node, $this->resolver, 's0.b0' );
		$this->assertSame( 100, $result['settings']['percent']['size'] );
	}

	public function test_counter_renderer(): void {
		$node   = [
			'type'           => 'counter',
			'starting_number' => 0,
			'ending_number'   => 500,
			'title'          => 'Happy Clients',
		];
		$result = Counter::render( $node, $this->resolver, 's0.b0' );
		$this->assertSame( $this->fixture( 'counter' ), $result );
	}

	// -------------------------------------------------------------------------
	// Pro-gated widgets (no Elementor Pro in test env)
	// -------------------------------------------------------------------------

	public function test_form_falls_back_when_pro_unavailable(): void {
		$diagnostics = [];
		$node        = [ 'type' => 'form', 'form_name' => 'Contact' ];
		$result      = Form::render( $node, $this->resolver, 's0.b0', $diagnostics );

		$this->assertSame( $this->fixture( 'form-pro-fallback' ), $result );
		$this->assertCount( 1, $diagnostics );
		$this->assertSame( ProGate::DIAGNOSTIC_REQUIRED, $diagnostics[0]['code'] );
	}

	public function test_form_settings_map_newsletter_field_and_button_styles(): void {
		$node     = [
			'type'        => 'form',
			'form_name'   => 'Newsletter',
			'button_text' => 'Aboneaza-te',
			'fields'      => [
				[ 'type' => 'text', 'label' => 'Nume', 'required' => true ],
				[ 'type' => 'email', 'label' => 'Email', 'required' => true ],
			],
			'field_style' => [
				'background'    => '#ffffff',
				'border_color'  => '#e5e7eb',
				'border_radius' => 0,
				'text_color'    => '#030712',
			],
			'button_style' => [
				'background'  => '#fdee17',
				'color'       => '#030712',
				'font_family' => 'Montserrat',
				'font_weight' => 700,
			],
		];
		$settings = Form::settings_from_node( $node, $this->resolver, 's0.b0' );

		$this->assertCount( 2, $settings['form_fields'] );
		$this->assertSame( '#ffffff', $settings['field_background_color'] );
		$this->assertSame( '#e5e7eb', $settings['field_border_color'] );
		$this->assertSame( '#fdee17', $settings['button_background_color'] );
		$this->assertSame( '#030712', $settings['button_text_color'] );
		$this->assertSame( '700', $settings['button_typography_font_weight'] );
	}

	public function test_slides_falls_back_when_pro_unavailable(): void {
		$diagnostics = [];
		$node        = [ 'type' => 'slides', 'slides' => [] ];
		$result      = Slides::render( $node, $this->resolver, 's0.b0', $diagnostics );

		$this->assertSame( $this->fixture( 'slides-pro-fallback' ), $result );
		$this->assertCount( 1, $diagnostics );
		$this->assertSame( ProGate::DIAGNOSTIC_REQUIRED, $diagnostics[0]['code'] );
	}

	// -------------------------------------------------------------------------
	// Stable IDs
	// -------------------------------------------------------------------------

	public function test_stable_id_is_deterministic(): void {
		$id1 = Section::stable_id( 's0.b3' );
		$id2 = Section::stable_id( 's0.b3' );
		$this->assertSame( $id1, $id2 );
		$this->assertSame( 7, strlen( $id1 ) );
	}

	public function test_different_paths_produce_different_ids(): void {
		$id1 = Section::stable_id( 's0.b0' );
		$id2 = Section::stable_id( 's0.b1' );
		$this->assertNotSame( $id1, $id2 );
	}

	// -------------------------------------------------------------------------
	// Determinism test (full render, same spec twice → identical output)
	// -------------------------------------------------------------------------

	public function test_render_is_deterministic(): void {
		$spec = [
			'version'  => '1.0.0',
			'page'     => [ 'title' => 'Determinism Page' ],
			'sections' => [
				[
					'id'     => 'hero',
					'blocks' => [
						[ 'type' => 'heading', 'text' => 'Hello', 'level' => 1 ],
						[ 'type' => 'button', 'text' => 'Go', 'url' => 'https://example.com' ],
					],
				],
			],
		];

		$diag1 = [];
		$diag2 = [];
		$out1  = Renderer::render( $spec, $diag1 );
		$out2  = Renderer::render( $spec, $diag2 );

		$this->assertSame( $out1, $out2, 'Renderer must be deterministic: same spec → same output' );
		$this->assertSame( $diag1, $diag2 );
	}

	// -------------------------------------------------------------------------
	// Token resolution
	// -------------------------------------------------------------------------

	public function test_resolver_resolves_color_token(): void {
		$resolver = new Resolver( [
			'colors' => [ 'primary' => '#0073aa' ],
		] );

		$node   = [
			'type'  => 'heading',
			'text'  => 'Colored',
			'color' => '{colors.primary}',
		];
		$result = Heading::render( $node, $resolver, 's0.b0' );
		$this->assertSame( '#0073aa', $result['settings']['title_color'] );
	}

	public function test_resolver_falls_back_on_unknown_token(): void {
		$resolver = new Resolver( [] );
		$resolved = $resolver->resolve( '{colors.missing}' );
		$this->assertSame( '{colors.missing}', $resolved );
	}

	public function test_spacer_resolves_spacing_token(): void {
		$resolver = new Resolver( [ 'spacing' => [ 'lg' => '48' ] ] );
		$node     = [ 'type' => 'spacer', 'spacing' => 'lg' ];
		$result   = Spacer::render( $node, $resolver, 's0.b0' );
		$this->assertSame( 48, $result['settings']['space']['size'] );
	}

	// -------------------------------------------------------------------------
	// Dispatcher routes
	// -------------------------------------------------------------------------

	public function test_dispatcher_unsupported_type_emits_diagnostic(): void {
		$spec = [
			'version'  => '1.0.0',
			'page'     => [ 'title' => 'Test' ],
			'sections' => [
				[
					'id'     => 's0',
					'blocks' => [
						[ 'type' => 'unknown-widget-xyz', 'text' => 'N/A' ],
					],
				],
			],
		];

		$diag   = [];
		$output = Renderer::render( $spec, $diag );

		$this->assertCount( 1, $output ); // one section
		$this->assertEmpty( $output[0]['elements'] ); // block dropped
		$this->assertCount( 1, $diag );
		$this->assertSame( 'unsupported_node', $diag[0]['code'] );
		$this->assertSame( 'unknown-widget-xyz', $diag[0]['type'] );
	}

	public function test_dispatcher_list_type_becomes_text_editor(): void {
		$spec = [
			'version'  => '1.0.0',
			'page'     => [ 'title' => 'List Test' ],
			'sections' => [
				[
					'id'     => 's0',
					'blocks' => [
						[ 'type' => 'list', 'items' => [ 'Alpha', 'Beta' ] ],
					],
				],
			],
		];

		$diag   = [];
		$output = Renderer::render( $spec, $diag );

		$this->assertEmpty( $diag );
		$block = $output[0]['elements'][0];
		$this->assertSame( 'text-editor', $block['widgetType'] );
		$this->assertStringContainsString( '<ul>', $block['settings']['editor'] );
		$this->assertStringContainsString( 'Alpha', $block['settings']['editor'] );
	}
}
