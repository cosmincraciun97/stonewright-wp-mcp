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

	public function test_column_renderer(): void {
		$node   = [ 'type' => 'column' ];
		$result = Column::render( $node, $this->resolver, 's0' );
		$this->assertSame( $this->fixture( 'column' ), $result );
	}

	public function test_container_renderer(): void {
		$node   = [ 'type' => 'group' ];
		$result = Container::render( $node, $this->resolver, 's0' );
		$this->assertSame( $this->fixture( 'container' ), $result );
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
