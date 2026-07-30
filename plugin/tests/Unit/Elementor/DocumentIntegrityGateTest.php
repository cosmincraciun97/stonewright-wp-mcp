<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\Integrity\DocumentIntegrityGate;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * @covers \Stonewright\WpMcp\Elementor\Integrity\DocumentIntegrityGate
 * @covers \Stonewright\WpMcp\Support\ElementorData
 */
final class DocumentIntegrityGateTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_posts'] = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_posts'] = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
	}

	public function test_rejects_double_encoded_tree(): void {
		$inner = [ [ 'id' => 'abc1234', 'elType' => 'container', 'elements' => [] ] ];
		$tree  = [ wp_json_encode( $inner ) ];
		$result = DocumentIntegrityGate::assert_write_allowed( $tree, [] );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_elementor_double_encoded', $result->get_error_code() );
	}

	public function test_rejects_size_collapse(): void {
		$previous = [];
		for ( $i = 0; $i < 50; $i++ ) {
			$previous[] = [
				'id'         => substr( md5( (string) $i ), 0, 7 ),
				'elType'     => 'widget',
				'widgetType' => 'heading',
				'settings'   => [ 'title' => str_repeat( 'Hello world ', 20 ) ],
				'elements'   => [],
			];
		}
		$incoming = [
			[
				'id'         => substr( md5( '0' ), 0, 7 ),
				'elType'     => 'widget',
				'widgetType' => 'heading',
				'settings'   => [ 'title' => 'x' ],
				'elements'   => [],
			],
		];
		$result = DocumentIntegrityGate::assert_write_allowed( $incoming, $previous );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_elementor_size_collapse', $result->get_error_code() );
	}

	public function test_force_destructive_allows_size_collapse(): void {
		$previous = [];
		for ( $i = 0; $i < 50; $i++ ) {
			$previous[] = [
				'id'         => substr( md5( (string) $i ), 0, 7 ),
				'elType'     => 'widget',
				'widgetType' => 'heading',
				'settings'   => [ 'title' => str_repeat( 'Hello world ', 20 ) ],
				'elements'   => [],
			];
		}
		$incoming = [
			[
				'id'         => substr( md5( '0' ), 0, 7 ),
				'elType'     => 'widget',
				'widgetType' => 'heading',
				'settings'   => [ 'title' => 'x' ],
				'elements'   => [],
			],
		];
		$result = DocumentIntegrityGate::assert_write_allowed(
			$incoming,
			$previous,
			[ 'force_destructive' => true ]
		);
		self::assertTrue( $result );
	}

	public function test_rejects_widget_type_remap(): void {
		$previous = [
			[
				'id'         => 'abc1234',
				'elType'     => 'widget',
				'widgetType' => 'e-paragraph',
				'settings'   => [ 'paragraph' => 'hi' ],
				'elements'   => [],
			],
		];
		$incoming = [
			[
				'id'         => 'abc1234',
				'elType'     => 'widget',
				'widgetType' => 'text-editor',
				'settings'   => [ 'editor' => '&nbsp;' ],
				'elements'   => [],
			],
		];
		$result = DocumentIntegrityGate::assert_write_allowed( $incoming, $previous );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_elementor_widget_type_remap_blocked', $result->get_error_code() );
	}

	public function test_allows_same_widget_type_patch(): void {
		$tree = [
			[
				'id'         => 'abc1234',
				'elType'     => 'widget',
				'widgetType' => 'heading',
				'settings'   => [ 'title' => 'Hello' ],
				'elements'   => [],
			],
		];
		$result = DocumentIntegrityGate::assert_write_allowed( $tree, $tree );
		self::assertTrue( $result );
	}

	public function test_elementor_data_write_blocks_remap(): void {
		$previous = [
			[
				'id'         => 'loop001',
				'elType'     => 'widget',
				'widgetType' => 'loop-grid',
				'settings'   => [ 'offset_sides' => 'right' ],
				'elements'   => [],
			],
		];
		$json = wp_json_encode( $previous, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$GLOBALS['stonewright_test_posts'][ 8104 ] = (object) [
			'ID'          => 8104,
			'post_type'   => 'page',
			'post_status' => 'publish',
			'meta'        => [
				'_elementor_data'      => $json,
				'_elementor_edit_mode' => 'builder',
				'_elementor_version'   => '3.0.0',
			],
		];

		$incoming = [
			[
				'id'         => 'loop001',
				'elType'     => 'widget',
				'widgetType' => 'posts',
				'settings'   => [],
				'elements'   => [],
			],
		];
		$ok = ElementorData::write( 8104, $incoming );
		self::assertFalse( $ok );
		$error = ElementorData::last_write_error();
		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'stonewright_elementor_widget_type_remap_blocked', $error->get_error_code() );
	}
}
