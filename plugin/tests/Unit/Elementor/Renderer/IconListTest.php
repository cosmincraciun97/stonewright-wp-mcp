<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Renderer;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\DesignTokens\Resolver;
use Stonewright\WpMcp\Elementor\Renderer\IconList;

/**
 * Unit tests for the IconList free widget renderer.
 *
 * @covers \Stonewright\WpMcp\Elementor\Renderer\IconList
 */
final class IconListTest extends TestCase {

	private Resolver $resolver;

	protected function setUp(): void {
		$this->resolver = new Resolver( [
			'colors' => [ 'primary' => '#1a73e8' ],
		] );
	}

	// -------------------------------------------------------------------------
	// Basic rendering
	// -------------------------------------------------------------------------

	public function test_renders_icon_list_widget_type(): void {
		$node = [ 'type' => 'icon-list', 'items' => [] ];
		$out  = IconList::render( $node, $this->resolver, 's0.b0' );

		$this->assertSame( 'icon-list', $out['widgetType'] );
		$this->assertSame( 'widget', $out['elType'] );
		$this->assertIsArray( $out['settings']['icon_list'] );
	}

	public function test_default_view_is_traditional(): void {
		$node = [ 'type' => 'icon-list', 'items' => [] ];
		$out  = IconList::render( $node, $this->resolver, 's0.b0' );

		$this->assertSame( 'traditional', $out['settings']['view'] );
	}

	public function test_inline_view(): void {
		$node = [ 'type' => 'icon-list', 'view' => 'inline', 'items' => [] ];
		$out  = IconList::render( $node, $this->resolver, 's0.b0' );

		$this->assertSame( 'inline', $out['settings']['view'] );
	}

	// -------------------------------------------------------------------------
	// Items / repeater
	// -------------------------------------------------------------------------

	public function test_items_mapped_to_icon_list_repeater(): void {
		$node = [
			'type'  => 'icon-list',
			'items' => [
				[
					'text' => 'Check',
					'url'  => 'https://example.com',
					'icon' => [ 'value' => 'fas fa-check', 'library' => 'fa-solid' ],
				],
				[
					'text'     => 'External',
					'url'      => 'https://ext.com',
					'external' => true,
					'icon'     => [ 'value' => 'fas fa-star', 'library' => 'fa-solid' ],
				],
			],
		];
		$out   = IconList::render( $node, $this->resolver, 's0.b1' );
		$items = $out['settings']['icon_list'];

		$this->assertCount( 2, $items );
		$this->assertSame( 'Check', $items[0]['text'] );
		$this->assertSame( 'https://example.com', $items[0]['link']['url'] );
		$this->assertFalse( $items[0]['link']['is_external'] );
		$this->assertSame( 'fas fa-check', $items[0]['selected_icon']['value'] );
		$this->assertSame( 'fa-solid', $items[0]['selected_icon']['library'] );

		$this->assertTrue( $items[1]['link']['is_external'] );
	}

	public function test_item_defaults_icon_to_fa_check(): void {
		$node = [
			'type'  => 'icon-list',
			'items' => [ [ 'text' => 'Item' ] ],
		];
		$out = IconList::render( $node, $this->resolver, 's0.b2' );

		$this->assertSame( 'fas fa-check', $out['settings']['icon_list'][0]['selected_icon']['value'] );
	}

	// -------------------------------------------------------------------------
	// link_click and divider settings
	// -------------------------------------------------------------------------

	public function test_link_click_setting(): void {
		$node = [ 'type' => 'icon-list', 'link_click' => 'inline', 'items' => [] ];
		$out  = IconList::render( $node, $this->resolver, 's0.b3' );

		$this->assertSame( 'inline', $out['settings']['link_click'] );
	}

	public function test_divider_yes(): void {
		$node = [ 'type' => 'icon-list', 'divider' => true, 'items' => [] ];
		$out  = IconList::render( $node, $this->resolver, 's0.b4' );

		$this->assertSame( 'yes', $out['settings']['divider'] );
	}

	public function test_divider_no(): void {
		$node = [ 'type' => 'icon-list', 'divider' => false, 'items' => [] ];
		$out  = IconList::render( $node, $this->resolver, 's0.b5' );

		$this->assertSame( '', $out['settings']['divider'] );
	}

	// -------------------------------------------------------------------------
	// Invalid view falls back to traditional
	// -------------------------------------------------------------------------

	public function test_invalid_view_falls_back_to_traditional(): void {
		$node = [ 'type' => 'icon-list', 'view' => 'grid', 'items' => [] ];
		$out  = IconList::render( $node, $this->resolver, 's0.b6' );

		$this->assertSame( 'traditional', $out['settings']['view'] );
	}

	// -------------------------------------------------------------------------
	// Stable ID
	// -------------------------------------------------------------------------

	public function test_stable_id_is_consistent(): void {
		$node = [ 'type' => 'icon-list', 'items' => [] ];
		$a    = IconList::render( $node, $this->resolver, 's0.b7' );
		$b    = IconList::render( $node, $this->resolver, 's0.b7' );

		$this->assertSame( $a['id'], $b['id'] );
	}

	// -------------------------------------------------------------------------
	// Style block
	// -------------------------------------------------------------------------

	public function test_style_color_maps_to_icon_color(): void {
		$node = [
			'type'  => 'icon-list',
			'items' => [],
			'style' => [ 'icon_color' => '#FF0000' ],
		];
		$out = IconList::render( $node, $this->resolver, 's0.b8' );

		$this->assertSame( '#FF0000', $out['settings']['icon_color'] );
	}
}
