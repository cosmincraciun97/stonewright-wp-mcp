<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\V4;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV4\DescribeAtomicWidget;
use Stonewright\WpMcp\Abilities\ElementorV4\ListAtomicNodeTypes;

/**
 * @covers \Stonewright\WpMcp\Abilities\ElementorV4\ListAtomicNodeTypes
 * @covers \Stonewright\WpMcp\Abilities\ElementorV4\DescribeAtomicWidget
 */
final class AtomicIntrospectionTest extends TestCase {

	protected function setUp(): void {
		// Both abilities gate on manage_options. Grant it for the unit-level
		// tests; ContractTest covers the permission-denied path separately.
		$GLOBALS['stonewright_test_user_caps']        = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_user_logged_in']   = true;
		$GLOBALS['stonewright_test_current_user_id']  = 1;
	}

	// -------------------------------------------------------------------------
	// ListAtomicNodeTypes
	// -------------------------------------------------------------------------

	public function test_list_returns_every_known_node_type(): void {
		$ability = new ListAtomicNodeTypes();
		$result  = $ability->execute( [] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'node_types', $result );
		$names = array_column( $result['node_types'], 'name' );

		// Containers.
		$this->assertContains( 'Section',   $names );
		$this->assertContains( 'Column',    $names );
		$this->assertContains( 'Container', $names );
		// Leaves.
		$this->assertContains( 'Heading',    $names );
		$this->assertContains( 'TextEditor', $names );
		$this->assertContains( 'Image',      $names );
		$this->assertContains( 'Button',     $names );
		$this->assertContains( 'Divider',    $names );
		$this->assertContains( 'Icon',       $names );
	}

	public function test_list_entry_includes_widget_id_and_container_flag(): void {
		$ability = new ListAtomicNodeTypes();
		$result  = $ability->execute( [] );

		$by_name = [];
		foreach ( $result['node_types'] as $row ) {
			$by_name[ $row['name'] ] = $row;
		}

		$this->assertSame( 'e-heading', $by_name['Heading']['widget'] );
		$this->assertFalse( $by_name['Heading']['is_container'] );
		$this->assertSame( 'e-flexbox', $by_name['Section']['widget'] );
		$this->assertTrue( $by_name['Section']['is_container'] );
	}

	// -------------------------------------------------------------------------
	// DescribeAtomicWidget
	// -------------------------------------------------------------------------

	public function test_describe_heading_returns_text_and_level_props(): void {
		$ability = new DescribeAtomicWidget();
		$result  = $ability->execute( [ 'node_type' => 'Heading' ] );

		$this->assertIsArray( $result );
		$this->assertSame( 'Heading',   $result['node_type'] );
		$this->assertSame( 'e-heading', $result['widget'] );
		$this->assertFalse( $result['is_container'] );

		$names = array_column( $result['props'], 'name' );
		$this->assertContains( 'text',  $names );
		$this->assertContains( 'level', $names );
	}

	public function test_describe_button_returns_text_and_link_props(): void {
		$ability = new DescribeAtomicWidget();
		$result  = $ability->execute( [ 'node_type' => 'Button' ] );

		$names = array_column( $result['props'], 'name' );
		$this->assertContains( 'text', $names );
		$this->assertContains( 'link', $names );
	}

	public function test_describe_image_returns_url_and_alt_props(): void {
		$ability = new DescribeAtomicWidget();
		$result  = $ability->execute( [ 'node_type' => 'Image' ] );

		$names = array_column( $result['props'], 'name' );
		$this->assertContains( 'url', $names );
		$this->assertContains( 'alt', $names );
	}

	public function test_describe_divider_returns_empty_props_list(): void {
		$ability = new DescribeAtomicWidget();
		$result  = $ability->execute( [ 'node_type' => 'Divider' ] );

		$this->assertIsArray( $result );
		$this->assertSame( 'e-divider', $result['widget'] );
		$this->assertSame( [], $result['props'] );
	}

	public function test_describe_container_returns_direction_and_gap_props(): void {
		$ability = new DescribeAtomicWidget();
		foreach ( [ 'Section', 'Column', 'Container' ] as $type ) {
			$result = $ability->execute( [ 'node_type' => $type ] );
			$names  = array_column( $result['props'], 'name' );
			$this->assertContains( 'direction', $names, "{$type} should expose direction prop" );
			$this->assertContains( 'gap',       $names, "{$type} should expose gap prop" );
			$this->assertTrue( $result['is_container'], "{$type} should be marked as container" );
			$this->assertSame( 'e-flexbox', $result['widget'], "{$type} should map to e-flexbox" );
		}
	}

	public function test_describe_unknown_node_type_returns_wp_error(): void {
		$ability = new DescribeAtomicWidget();
		$result  = $ability->execute( [ 'node_type' => 'NotAWidget' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_unknown_node_type', $result->get_error_code() );
	}

	public function test_describe_text_editor_returns_paragraph_text_prop(): void {
		$ability = new DescribeAtomicWidget();
		$result  = $ability->execute( [ 'node_type' => 'TextEditor' ] );

		$this->assertSame( 'e-paragraph', $result['widget'] );
		$names = array_column( $result['props'], 'name' );
		$this->assertContains( 'text', $names );
	}

	public function test_describe_icon_returns_svg_prop(): void {
		$ability = new DescribeAtomicWidget();
		$result  = $ability->execute( [ 'node_type' => 'Icon' ] );

		$this->assertSame( 'e-svg', $result['widget'] );
		$names = array_column( $result['props'], 'name' );
		$this->assertContains( 'svg', $names );
	}
}
