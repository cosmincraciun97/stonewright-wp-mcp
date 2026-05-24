<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Renderer;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\DesignTokens\Resolver;
use Stonewright\WpMcp\Elementor\Renderer\Heading;

/**
 * Unit tests for the Heading widget renderer.
 *
 * @covers \Stonewright\WpMcp\Elementor\Renderer\Heading
 */
final class HeadingTest extends TestCase {

	private Resolver $resolver;

	protected function setUp(): void {
		$this->resolver = new Resolver( [] );
	}

	// -------------------------------------------------------------------------
	// Basic rendering
	// -------------------------------------------------------------------------

	public function test_renders_heading_widget_type(): void {
		$node = [ 'type' => 'heading', 'text' => 'Hello', 'level' => 1 ];
		$out  = Heading::render( $node, $this->resolver, 's0.b0' );

		$this->assertSame( 'widget', $out['elType'] );
		$this->assertSame( 'heading', $out['widgetType'] );
		$this->assertSame( 'Hello', $out['settings']['title'] );
		$this->assertSame( 'h1', $out['settings']['header_size'] );
	}

	public function test_paragraph_type_uses_p_header_size(): void {
		$node = [ 'type' => 'paragraph', 'text' => 'Body copy' ];
		$out  = Heading::render( $node, $this->resolver, 's0.b1' );

		$this->assertSame( 'p', $out['settings']['header_size'] );
	}

	// -------------------------------------------------------------------------
	// B.2 — top-level node.url
	// -------------------------------------------------------------------------

	public function test_top_level_url_populates_link(): void {
		$node = [
			'type' => 'heading',
			'text' => 'Click me',
			'url'  => 'https://example.com',
		];
		$out  = Heading::render( $node, $this->resolver, 's0.b2' );

		$this->assertSame( 'https://example.com', $out['settings']['link']['url'] );
		$this->assertFalse( $out['settings']['link']['is_external'] );
		$this->assertFalse( $out['settings']['link']['nofollow'] );
	}

	public function test_top_level_url_with_external_flag(): void {
		$node = [
			'type'     => 'heading',
			'text'     => 'External',
			'url'      => 'https://external.com',
			'external' => true,
		];
		$out  = Heading::render( $node, $this->resolver, 's0.b3' );

		$this->assertTrue( $out['settings']['link']['is_external'] );
	}

	public function test_nested_link_url_populates_link(): void {
		$node = [
			'type' => 'heading',
			'text' => 'Nested',
			'link' => [ 'url' => 'https://nested.com' ],
		];
		$out  = Heading::render( $node, $this->resolver, 's0.b4' );

		$this->assertSame( 'https://nested.com', $out['settings']['link']['url'] );
	}

	public function test_nested_link_wins_over_top_level_url(): void {
		// When both are present, the nested (explicit) form must win.
		$node = [
			'type' => 'heading',
			'text' => 'Both',
			'url'  => 'https://toplevel.com',
			'link' => [ 'url' => 'https://nested.com', 'external' => true ],
		];
		$out  = Heading::render( $node, $this->resolver, 's0.b5' );

		$this->assertSame( 'https://nested.com', $out['settings']['link']['url'] );
		$this->assertTrue( $out['settings']['link']['is_external'] );
	}

	public function test_no_link_omits_link_key(): void {
		$node = [ 'type' => 'heading', 'text' => 'No link' ];
		$out  = Heading::render( $node, $this->resolver, 's0.b6' );

		$this->assertArrayNotHasKey( 'link', $out['settings'] );
	}

	// -------------------------------------------------------------------------
	// Stable ID
	// -------------------------------------------------------------------------

	public function test_stable_id_is_consistent(): void {
		$node = [ 'type' => 'heading', 'text' => 'Stable' ];
		$a    = Heading::render( $node, $this->resolver, 's0.b7' );
		$b    = Heading::render( $node, $this->resolver, 's0.b7' );

		$this->assertSame( $a['id'], $b['id'] );
	}
}
