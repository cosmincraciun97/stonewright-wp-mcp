<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Gutenberg\Renderer;
use Stonewright\WpMcp\Gutenberg\Renderer\Buttons;
use Stonewright\WpMcp\Gutenberg\Renderer\Columns;
use Stonewright\WpMcp\Gutenberg\Renderer\Cover;
use Stonewright\WpMcp\Gutenberg\Renderer\Embed;
use Stonewright\WpMcp\Gutenberg\Renderer\Group;
use Stonewright\WpMcp\Gutenberg\Renderer\Heading;
use Stonewright\WpMcp\Gutenberg\Renderer\Image;
use Stonewright\WpMcp\Gutenberg\Renderer\ListBlock;
use Stonewright\WpMcp\Gutenberg\Renderer\MediaText;
use Stonewright\WpMcp\Gutenberg\Renderer\Paragraph;
use Stonewright\WpMcp\Gutenberg\Renderer\Quote;
use Stonewright\WpMcp\Gutenberg\Renderer\Reusable;
use Stonewright\WpMcp\Gutenberg\Renderer\Separator;
use Stonewright\WpMcp\Gutenberg\Renderer\Spacer;
use Stonewright\WpMcp\Gutenberg\Renderer\Video;

/**
 * Per-block Gutenberg renderer unit tests.
 *
 * Each test renders a minimal spec node and asserts deep equality against
 * the golden fixture under tests/fixtures/gutenberg/.
 *
 * @covers \Stonewright\WpMcp\Gutenberg\Renderer
 * @covers \Stonewright\WpMcp\Gutenberg\Renderer\Heading
 * @covers \Stonewright\WpMcp\Gutenberg\Renderer\Paragraph
 * @covers \Stonewright\WpMcp\Gutenberg\Renderer\Image
 * @covers \Stonewright\WpMcp\Gutenberg\Renderer\Columns
 * @covers \Stonewright\WpMcp\Gutenberg\Renderer\Group
 * @covers \Stonewright\WpMcp\Gutenberg\Renderer\Buttons
 * @covers \Stonewright\WpMcp\Gutenberg\Renderer\Quote
 * @covers \Stonewright\WpMcp\Gutenberg\Renderer\ListBlock
 * @covers \Stonewright\WpMcp\Gutenberg\Renderer\Cover
 * @covers \Stonewright\WpMcp\Gutenberg\Renderer\Spacer
 * @covers \Stonewright\WpMcp\Gutenberg\Renderer\Separator
 * @covers \Stonewright\WpMcp\Gutenberg\Renderer\Video
 * @covers \Stonewright\WpMcp\Gutenberg\Renderer\Embed
 * @covers \Stonewright\WpMcp\Gutenberg\Renderer\Reusable
 * @covers \Stonewright\WpMcp\Gutenberg\Renderer\MediaText
 * @covers \Stonewright\WpMcp\Gutenberg\UrlGuard
 */
final class GutenbergRendererTest extends TestCase {

	private string $fixture_dir;

	protected function setUp(): void {
		$this->fixture_dir = dirname( __DIR__ ) . '/fixtures/gutenberg';
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * @return array<string, mixed>
	 */
	private function fixture( string $name ): array {
		$path = $this->fixture_dir . '/' . $name . '.json';
		self::assertFileExists( $path, "Missing fixture: {$name}.json" );
		$raw     = file_get_contents( $path );
		self::assertIsString( $raw );
		$decoded = json_decode( $raw, true );
		self::assertIsArray( $decoded, "Fixture {$name}.json is not valid JSON" );
		return $decoded;
	}

	// -------------------------------------------------------------------------
	// core/heading
	// -------------------------------------------------------------------------

	public function test_heading_h2_renders(): void {
		$node   = [ 'type' => 'heading', 'text' => 'Hello World', 'level' => 2 ];
		$result = Heading::render( $node, 's0.b0' );
		$this->assertSame( $this->fixture( 'heading' ), $result );
	}

	public function test_heading_h2_omits_level_attr(): void {
		$node   = [ 'type' => 'heading', 'text' => 'Top', 'level' => 1 ];
		$result = Heading::render( $node, 's0.b0' );
		// Level 2 is the WP default; level 1 differs so it IS stored in attrs.
		$this->assertSame( 'core/heading', $result['blockName'] );
		$this->assertStringContainsString( '<h1', $result['innerHTML'] );
	}

	public function test_heading_stores_level_in_attrs(): void {
		$node   = [ 'type' => 'heading', 'text' => 'Title', 'level' => 3 ];
		$result = Heading::render( $node, 's0.b0' );
		$this->assertArrayHasKey( 'level', $result['attrs'] );
		$this->assertSame( 3, $result['attrs']['level'] );
		$this->assertStringContainsString( '<h3', $result['innerHTML'] );
	}

	public function test_heading_clamps_level_min(): void {
		$node   = [ 'type' => 'heading', 'text' => 'X', 'level' => 0 ];
		$result = Heading::render( $node, 's0.b0' );
		$this->assertStringContainsString( '<h1', $result['innerHTML'] );
	}

	public function test_heading_clamps_level_max(): void {
		$node   = [ 'type' => 'heading', 'text' => 'X', 'level' => 99 ];
		$result = Heading::render( $node, 's0.b0' );
		$this->assertStringContainsString( '<h6', $result['innerHTML'] );
	}

	public function test_heading_escapes_html(): void {
		$node   = [ 'type' => 'heading', 'text' => '<script>alert(1)</script>', 'level' => 2 ];
		$result = Heading::render( $node, 's0.b0' );
		$this->assertStringNotContainsString( '<script>', $result['innerHTML'] );
	}

	// -------------------------------------------------------------------------
	// core/paragraph
	// -------------------------------------------------------------------------

	public function test_paragraph_renders(): void {
		$node   = [ 'type' => 'paragraph', 'text' => 'Some text here.' ];
		$result = Paragraph::render( $node, 's0.b0' );
		$this->assertSame( $this->fixture( 'paragraph' ), $result );
	}

	public function test_paragraph_escapes_html(): void {
		$node   = [ 'type' => 'paragraph', 'text' => '<b>bold</b>' ];
		$result = Paragraph::render( $node, 's0.b0' );
		$this->assertStringNotContainsString( '<b>', $result['innerHTML'] );
	}

	public function test_paragraph_align_sets_attr_and_class(): void {
		$node   = [ 'type' => 'paragraph', 'text' => 'Hi', 'align' => 'center' ];
		$result = Paragraph::render( $node, 's0.b0' );
		$this->assertSame( 'center', $result['attrs']['align'] );
		$this->assertStringContainsString( 'has-text-align-center', $result['innerHTML'] );
	}

	// -------------------------------------------------------------------------
	// core/image
	// -------------------------------------------------------------------------

	public function test_image_renders(): void {
		$node   = [ 'type' => 'image', 'url' => 'https://example.com/photo.jpg', 'alt' => 'A photo' ];
		$result = Image::render( $node, 's0.b0' );
		$this->assertSame( $this->fixture( 'image' ), $result );
	}

	public function test_image_with_id_includes_class(): void {
		$node   = [ 'type' => 'image', 'url' => 'https://example.com/x.jpg', 'alt' => '', 'id' => 42 ];
		$result = Image::render( $node, 's0.b0' );
		$this->assertSame( 42, $result['attrs']['id'] );
		$this->assertStringContainsString( 'wp-image-42', $result['innerHTML'] );
	}

	// -------------------------------------------------------------------------
	// core/spacer
	// -------------------------------------------------------------------------

	public function test_spacer_renders(): void {
		$node   = [ 'type' => 'spacer', 'height' => 60 ];
		$result = Spacer::render( $node, 's0.b0' );
		$this->assertSame( $this->fixture( 'spacer' ), $result );
	}

	public function test_spacer_default_height(): void {
		$node   = [ 'type' => 'spacer' ];
		$result = Spacer::render( $node, 's0.b0' );
		$this->assertSame( '40px', $result['attrs']['height'] );
	}

	// -------------------------------------------------------------------------
	// core/separator
	// -------------------------------------------------------------------------

	public function test_separator_renders(): void {
		$node   = [ 'type' => 'separator' ];
		$result = Separator::render( $node, 's0.b0' );
		$this->assertSame( $this->fixture( 'separator' ), $result );
	}

	public function test_separator_wide_style(): void {
		$node   = [ 'type' => 'separator', 'style' => 'wide' ];
		$result = Separator::render( $node, 's0.b0' );
		$this->assertSame( 'is-style-wide', $result['attrs']['className'] );
	}

	// -------------------------------------------------------------------------
	// core/buttons
	// -------------------------------------------------------------------------

	public function test_buttons_renders(): void {
		$node   = [ 'type' => 'button', 'text' => 'Click Me', 'url' => 'https://example.com' ];
		$result = Buttons::render( $node, 's0.b0' );
		$this->assertSame( $this->fixture( 'buttons' ), $result );
	}

	public function test_button_escapes_xss_text(): void {
		$node   = [ 'type' => 'button', 'text' => '<XSS>', 'url' => 'https://example.com' ];
		$result = Buttons::render( $node, 's0.b0' );
		$html   = $result['innerBlocks'][0]['innerHTML'];
		$this->assertStringNotContainsString( '<XSS>', $html );
		$this->assertStringContainsString( '&lt;XSS&gt;', $html );
	}

	// -------------------------------------------------------------------------
	// core/quote
	// -------------------------------------------------------------------------

	public function test_quote_renders(): void {
		$node   = [ 'type' => 'quote', 'text' => 'Great words.' ];
		$result = Quote::render( $node, 's0.b0' );
		$this->assertSame( $this->fixture( 'quote' ), $result );
	}

	public function test_quote_with_citation(): void {
		$node   = [ 'type' => 'quote', 'text' => 'Wise.', 'citation' => 'Someone' ];
		$result = Quote::render( $node, 's0.b0' );
		$this->assertStringContainsString( '<cite>Someone</cite>', $result['innerHTML'] );
	}

	// -------------------------------------------------------------------------
	// core/list
	// -------------------------------------------------------------------------

	public function test_list_renders(): void {
		$node   = [ 'type' => 'list', 'items' => [ 'Alpha', 'Beta' ] ];
		$result = ListBlock::render( $node, 's0.b0' );
		$this->assertSame( $this->fixture( 'list' ), $result );
	}

	public function test_list_ordered(): void {
		$node   = [ 'type' => 'list', 'items' => [ 'One' ], 'ordered' => true ];
		$result = ListBlock::render( $node, 's0.b0' );
		$this->assertTrue( $result['attrs']['ordered'] );
		$this->assertStringContainsString( '<ol', $result['innerHTML'] );
	}

	// -------------------------------------------------------------------------
	// core/cover
	// -------------------------------------------------------------------------

	public function test_cover_renders(): void {
		$node   = [
			'type'          => 'cover',
			'url'           => 'https://example.com/bg.jpg',
			'alt'           => 'Background',
			'overlay_color' => '#000000',
			'dim'           => 40,
		];
		$diag   = [];
		$result = Cover::render( $node, 's0.b0', $diag );
		$this->assertSame( $this->fixture( 'cover' ), $result );
	}

	public function test_cover_dim_clamps_to_100(): void {
		$diag   = [];
		$node   = [ 'type' => 'cover', 'dim' => 200 ];
		$result = Cover::render( $node, 's0.b0', $diag );
		$this->assertSame( 100, $result['attrs']['dimRatio'] );
	}

	// -------------------------------------------------------------------------
	// core/columns
	// -------------------------------------------------------------------------

	public function test_columns_renders(): void {
		$node = [
			'type'    => 'columns',
			'columns' => [
				[ 'blocks' => [] ],
				[ 'blocks' => [] ],
			],
		];
		$diag   = [];
		$result = Columns::render( $node, 's0.b0', $diag );
		$this->assertSame( $this->fixture( 'columns' ), $result );
	}

	// -------------------------------------------------------------------------
	// core/group
	// -------------------------------------------------------------------------

	public function test_group_renders_empty(): void {
		$node   = [ 'type' => 'group', 'blocks' => [] ];
		$diag   = [];
		$result = Group::render( $node, 's0.b0', $diag );
		$this->assertSame( $this->fixture( 'group' ), $result );
	}

	// -------------------------------------------------------------------------
	// core/block (reusable)
	// -------------------------------------------------------------------------

	public function test_reusable_renders_valid_ref(): void {
		$diag   = [];
		$node   = [ 'type' => 'reusable', 'ref' => 42 ];
		$result = Reusable::render( $node, 's0.b0', $diag );
		$this->assertSame( $this->fixture( 'reusable' ), $result );
		$this->assertEmpty( $diag );
	}

	public function test_reusable_returns_null_on_invalid_ref(): void {
		$diag   = [];
		$node   = [ 'type' => 'reusable', 'ref' => 0 ];
		$result = Reusable::render( $node, 's0.b0', $diag );
		$this->assertNull( $result );
		$this->assertCount( 1, $diag );
		$this->assertSame( 'invalid_reusable_ref', $diag[0]['code'] );
	}

	// -------------------------------------------------------------------------
	// core/video
	// -------------------------------------------------------------------------

	public function test_video_renders(): void {
		$diag   = [];
		$node   = [ 'type' => 'video', 'url' => 'https://example.com/video.mp4', 'caption' => 'My video' ];
		$result = Video::render( $node, 's0.b0', $diag );
		$this->assertSame( $this->fixture( 'video' ), $result );
		$this->assertEmpty( $diag );
	}

	public function test_video_rejects_javascript_url(): void {
		$diag   = [];
		$node   = [ 'type' => 'video', 'url' => 'javascript:alert(1)', 'caption' => '' ];
		$result = Video::render( $node, 's0.b0', $diag );
		$this->assertNull( $result );
		$this->assertCount( 1, $diag );
		$this->assertSame( 'unsafe_video_url', $diag[0]['code'] );
	}

	public function test_video_rejects_data_url(): void {
		$diag   = [];
		$node   = [ 'type' => 'video', 'url' => 'data:video/mp4;base64,AAAA', 'caption' => '' ];
		$result = Video::render( $node, 's0.b0', $diag );
		$this->assertNull( $result );
		$this->assertSame( 'unsafe_video_url', $diag[0]['code'] );
	}

	// -------------------------------------------------------------------------
	// core/embed
	// -------------------------------------------------------------------------

	public function test_embed_renders_with_valid_provider(): void {
		$diag   = [];
		$node   = [
			'type'               => 'embed',
			'url'                => 'https://www.youtube.com/watch?v=abc123',
			'provider_name_slug' => 'youtube',
			'caption'            => 'My embed',
		];
		$result = Embed::render( $node, 's0.b0', $diag );
		$this->assertSame( $this->fixture( 'embed' ), $result );
		$this->assertEmpty( $diag );
	}

	public function test_embed_rejects_javascript_url(): void {
		$diag   = [];
		$node   = [ 'type' => 'embed', 'url' => 'javascript:void(0)' ];
		$result = Embed::render( $node, 's0.b0', $diag );
		$this->assertNull( $result );
		$this->assertCount( 1, $diag );
		$this->assertSame( 'unsafe_embed_url', $diag[0]['code'] );
	}

	// -------------------------------------------------------------------------
	// core/media-text
	// -------------------------------------------------------------------------

	public function test_media_text_renders_with_image(): void {
		$diag   = [];
		$node   = [
			'type'  => 'media-text',
			'media' => [ 'url' => 'https://example.com/hero.jpg', 'alt' => 'Hero image' ],
		];
		$result = MediaText::render( $node, 's0.b0', $diag );
		$this->assertSame( $this->fixture( 'media-text' ), $result );
		$this->assertEmpty( $diag );
	}

	public function test_media_text_rejects_unsafe_url(): void {
		$diag   = [];
		$node   = [
			'type'  => 'media-text',
			'media' => [ 'url' => 'javascript:evil()', 'alt' => 'bad' ],
		];
		$result = MediaText::render( $node, 's0.b0', $diag );
		// MediaText doesn't return null on bad URL — it just omits mediaUrl from attrs.
		$this->assertIsArray( $result );
		$this->assertArrayNotHasKey( 'mediaUrl', $result['attrs'] );
	}

	// -------------------------------------------------------------------------
	// UrlGuard — cover rejects unsafe URL
	// -------------------------------------------------------------------------

	public function test_cover_rejects_javascript_url(): void {
		$diag   = [];
		$node   = [ 'type' => 'cover', 'url' => 'javascript:alert(1)', 'dim' => 50 ];
		$result = Cover::render( $node, 's0.b0', $diag );
		$this->assertIsArray( $result );
		// url attr must be absent — we must not store a javascript: URI.
		$this->assertArrayNotHasKey( 'url', $result['attrs'] );
	}

	// -------------------------------------------------------------------------
	// Dispatcher — unsupported node
	// -------------------------------------------------------------------------

	public function test_dispatcher_unsupported_appends_diagnostic(): void {
		$diag   = [];
		$block  = [ 'type' => 'completely-unknown-xyz' ];
		$result = Renderer::render_block( $block, 's0.b0', $diag );
		$this->assertNull( $result );
		$this->assertCount( 1, $diag );
		$this->assertSame( 'unsupported_node', $diag[0]['code'] );
		$this->assertSame( 'completely-unknown-xyz', $diag[0]['type'] );
	}

	// -------------------------------------------------------------------------
	// Full render pipeline (Renderer::render)
	// -------------------------------------------------------------------------

	public function test_full_render_produces_group_wrappers(): void {
		$spec = [
			'version'  => '1.0.0',
			'page'     => [ 'title' => 'Test Page' ],
			'sections' => [
				[
					'id'     => 'hero',
					'blocks' => [
						[ 'type' => 'heading', 'text' => 'Welcome', 'level' => 1 ],
					],
				],
			],
		];
		$diag   = [];
		$result = Renderer::render( $spec, $diag );
		$this->assertCount( 1, $result );
		$this->assertSame( 'core/group', $result[0]['blockName'] );
		$this->assertCount( 1, $result[0]['innerBlocks'] );
		$this->assertSame( 'core/heading', $result[0]['innerBlocks'][0]['blockName'] );
		$this->assertEmpty( $diag );
	}
}
