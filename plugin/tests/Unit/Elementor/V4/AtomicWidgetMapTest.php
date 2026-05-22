<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\V4;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\V4\AtomicWidgetMap;

/**
 * @covers \Stonewright\WpMcp\Elementor\V4\AtomicWidgetMap
 */
final class AtomicWidgetMapTest extends TestCase {

	public function test_leaf_widgets_map_to_their_atomic_widget(): void {
		$this->assertSame( 'e-heading',   AtomicWidgetMap::widget_type( 'Heading' ) );
		$this->assertSame( 'e-paragraph', AtomicWidgetMap::widget_type( 'TextEditor' ) );
		$this->assertSame( 'e-image',     AtomicWidgetMap::widget_type( 'Image' ) );
		$this->assertSame( 'e-button',    AtomicWidgetMap::widget_type( 'Button' ) );
		$this->assertSame( 'e-divider',   AtomicWidgetMap::widget_type( 'Divider' ) );
		$this->assertSame( 'e-svg',       AtomicWidgetMap::widget_type( 'Icon' ) );
	}

	public function test_every_container_flavour_collapses_to_e_flexbox(): void {
		$this->assertSame( 'e-flexbox', AtomicWidgetMap::widget_type( 'Section' ) );
		$this->assertSame( 'e-flexbox', AtomicWidgetMap::widget_type( 'Column' ) );
		$this->assertSame( 'e-flexbox', AtomicWidgetMap::widget_type( 'Container' ) );
	}

	public function test_unknown_node_type_returns_null(): void {
		$this->assertNull( AtomicWidgetMap::widget_type( 'NotAWidget' ) );
		$this->assertNull( AtomicWidgetMap::widget_type( '' ) );
	}

	public function test_is_container_identifies_only_container_types(): void {
		$this->assertTrue( AtomicWidgetMap::is_container( 'Section' ) );
		$this->assertTrue( AtomicWidgetMap::is_container( 'Column' ) );
		$this->assertTrue( AtomicWidgetMap::is_container( 'Container' ) );
		$this->assertFalse( AtomicWidgetMap::is_container( 'Heading' ) );
		$this->assertFalse( AtomicWidgetMap::is_container( 'Image' ) );
		$this->assertFalse( AtomicWidgetMap::is_container( 'NotAWidget' ) );
	}

	public function test_known_node_types_returns_complete_list(): void {
		$known = AtomicWidgetMap::known_node_types();
		// Containers
		$this->assertContains( 'Section', $known );
		$this->assertContains( 'Column', $known );
		$this->assertContains( 'Container', $known );
		// Leaves
		$this->assertContains( 'Heading', $known );
		$this->assertContains( 'TextEditor', $known );
		$this->assertContains( 'Image', $known );
		$this->assertContains( 'Button', $known );
		$this->assertContains( 'Divider', $known );
		$this->assertContains( 'Icon', $known );
	}
}
