<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Renderer;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\DesignTokens\Resolver;
use Stonewright\WpMcp\Elementor\Renderer\NavMenu;

/**
 * Unit tests for the NavMenu Pro widget renderer.
 *
 * In the test environment Elementor Pro is not available, so all tests exercise
 * the icon-list fallback path. The Pro path settings assembly is validated
 * through a subclass that overrides pro_available() to return true.
 *
 * @covers \Stonewright\WpMcp\Elementor\Renderer\NavMenu
 */
final class NavMenuTest extends TestCase {

	private Resolver $resolver;
	private array    $diagnostics = [];

	protected function setUp(): void {
		$this->resolver    = new Resolver( [] );
		$this->diagnostics = [];
	}

	// -------------------------------------------------------------------------
	// Fallback path (Pro not active)
	// -------------------------------------------------------------------------

	public function test_fallback_emits_icon_list_widget(): void {
		$node = [
			'type'  => 'nav-menu',
			'items' => [
				[ 'text' => 'Home', 'url' => '/' ],
				[ 'text' => 'About', 'url' => '/about' ],
			],
		];
		$out = NavMenu::render( $node, $this->resolver, 's0.b0', $this->diagnostics );

		$this->assertSame( 'icon-list', $out['widgetType'] );
		$this->assertSame( 'widget', $out['elType'] );
		$this->assertSame( 'inline', $out['settings']['view'] );
		$this->assertSame( 'inline', $out['settings']['link_click'] );
	}

	public function test_fallback_maps_items_to_icon_list(): void {
		$node = [
			'type'  => 'nav-menu',
			'items' => [
				[ 'text' => 'Home', 'url' => '/' ],
				[ 'text' => 'About', 'url' => '/about', 'external' => true ],
			],
		];
		$out = NavMenu::render( $node, $this->resolver, 's0.b0', $this->diagnostics );

		$items = $out['settings']['icon_list'];
		$this->assertCount( 2, $items );
		$this->assertSame( 'Home', $items[0]['text'] );
		$this->assertSame( '/', $items[0]['link']['url'] );
		$this->assertFalse( $items[0]['link']['is_external'] );
		$this->assertSame( 'About', $items[1]['text'] );
		$this->assertTrue( $items[1]['link']['is_external'] );
	}

	public function test_fallback_emits_diagnostic(): void {
		$node = [ 'type' => 'nav-menu', 'items' => [] ];
		NavMenu::render( $node, $this->resolver, 's0.b1', $this->diagnostics );

		$this->assertNotEmpty( $this->diagnostics );
		$this->assertSame( 'elementor_pro_required', $this->diagnostics[0]['code'] );
		$this->assertSame( 'nav-menu', $this->diagnostics[0]['type'] );
	}

	public function test_fallback_stable_id_consistent(): void {
		$node = [ 'type' => 'nav-menu', 'items' => [] ];
		$a    = NavMenu::render( $node, $this->resolver, 's0.b2', $this->diagnostics );
		$b    = NavMenu::render( $node, $this->resolver, 's0.b2', $this->diagnostics );

		$this->assertSame( $a['id'], $b['id'] );
	}

	// -------------------------------------------------------------------------
	// Pro path (via subclass override)
	// -------------------------------------------------------------------------

	public function test_pro_path_emits_nav_menu_widget_type(): void {
		$node = [
			'type'   => 'nav-menu',
			'menu'   => 'top-menu',
			'layout' => 'horizontal',
		];
		$out = NavMenuProStub::render( $node, $this->resolver, 's0.b3', $this->diagnostics );

		$this->assertSame( 'nav-menu', $out['widgetType'] );
		$this->assertSame( 'widget', $out['elType'] );
		$this->assertSame( 'top-menu', $out['settings']['menu'] );
		$this->assertSame( 'horizontal', $out['settings']['layout'] );
		$this->assertEmpty( $this->diagnostics );
	}

	public function test_pro_path_maps_pointer(): void {
		$node = [
			'type'    => 'nav-menu',
			'menu'    => 'main',
			'pointer' => 'underline',
		];
		$out = NavMenuProStub::render( $node, $this->resolver, 's0.b4', $this->diagnostics );

		$this->assertSame( 'underline', $out['settings']['pointer'] );
	}

	public function test_pro_path_maps_align_items(): void {
		$node = [
			'type'        => 'nav-menu',
			'menu'        => 'main',
			'align_items' => 'center',
		];
		$out = NavMenuProStub::render( $node, $this->resolver, 's0.b5', $this->diagnostics );

		$this->assertSame( 'center', $out['settings']['align_items'] );
	}

	public function test_pro_path_defaults_layout_to_horizontal_on_invalid(): void {
		$node = [
			'type'   => 'nav-menu',
			'layout' => 'invalid-layout',
		];
		$out = NavMenuProStub::render( $node, $this->resolver, 's0.b6', $this->diagnostics );

		$this->assertSame( 'horizontal', $out['settings']['layout'] );
	}

	public function test_pro_path_maps_mobile_hamburger_dropdown_settings(): void {
		$node = [
			'type'          => 'nav-menu',
			'menu'          => 'mobile',
			'layout'        => 'dropdown',
			'dropdown'      => 'mobile',
			'toggle'        => 'hamburger',
			'toggle_align'  => 'end',
			'toggle_color'  => '#ffffff',
			'style'         => [
				'color'       => '#efefef',
				'font_family' => 'Montserrat',
				'font_weight' => 700,
			],
		];
		$out = NavMenuProStub::render( $node, $this->resolver, 's0.b7', $this->diagnostics );

		$this->assertSame( 'horizontal', $out['settings']['layout'] );
		$this->assertSame( 'mobile', $out['settings']['dropdown'] );
		$this->assertSame( 'burger', $out['settings']['toggle'] );
		$this->assertSame( 'end', $out['settings']['toggle_align'] );
		$this->assertSame( '#ffffff', $out['settings']['toggle_color'] );
		$this->assertSame( '#efefef', $out['settings']['color_menu_item'] );
		$this->assertSame( 'Montserrat', $out['settings']['typography_font_family'] );
		$this->assertSame( '700', $out['settings']['typography_font_weight'] );
	}
}

/**
 * Stub that forces the Pro-available path so we can test Pro settings assembly
 * without actually having Elementor Pro installed.
 */
final class NavMenuProStub extends NavMenu {
	protected static function pro_available(): bool {
		return true;
	}
}
