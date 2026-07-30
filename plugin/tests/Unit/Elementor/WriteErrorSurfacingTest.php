<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * Ensures abilities surface the real ElementorData write error (gate codes,
 * fix hints) instead of a generic stonewright_write_failed wrapper.
 *
 * @covers \Stonewright\WpMcp\Support\ElementorData::write_error_for_ability
 * @covers \Stonewright\WpMcp\Support\ElementorData::last_write_error
 * @covers \Stonewright\WpMcp\Support\ElementorData::write
 */
final class WriteErrorSurfacingTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
	}

	public function test_write_error_for_ability_falls_back_when_no_write(): void {
		// Force a clean slate: a successful no-op path isn't available, so
		// call write with skip_integrity on an empty post to clear last error,
		// then read the helper without a failed write. Alternatively, write
		// that succeeds would clear last_write_error. Empty tree to empty post:
		$GLOBALS['stonewright_test_posts'][ 9101 ] = (object) [
			'ID'          => 9101,
			'post_type'   => 'page',
			'post_status' => 'publish',
			'meta'        => [],
		];
		self::assertTrue( ElementorData::write( 9101, [], [ 'skip_integrity' => true ] ) );
		self::assertNull( ElementorData::last_write_error() );

		$err = ElementorData::write_error_for_ability();
		self::assertInstanceOf( \WP_Error::class, $err );
		self::assertSame( 'stonewright_write_failed', $err->get_error_code() );
		self::assertStringContainsString( 'Could not save Elementor data', $err->get_error_message() );
	}

	public function test_write_error_for_ability_surfaces_gate_code(): void {
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
		$json = wp_json_encode( $previous, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$GLOBALS['stonewright_test_posts'][ 9102 ] = (object) [
			'ID'          => 9102,
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
				'id'         => substr( md5( '0' ), 0, 7 ),
				'elType'     => 'widget',
				'widgetType' => 'heading',
				'settings'   => [ 'title' => 'x' ],
				'elements'   => [],
			],
		];

		$ok = ElementorData::write( 9102, $incoming );
		self::assertFalse( $ok );

		$last = ElementorData::last_write_error();
		self::assertInstanceOf( \WP_Error::class, $last );
		self::assertSame( 'stonewright_elementor_size_collapse', $last->get_error_code() );

		$surfaced = ElementorData::write_error_for_ability();
		self::assertInstanceOf( \WP_Error::class, $surfaced );
		self::assertSame( 'stonewright_elementor_size_collapse', $surfaced->get_error_code() );
		self::assertNotSame( 'stonewright_write_failed', $surfaced->get_error_code() );
		// Prefer the concrete gate instance so fix hints / status travel intact.
		self::assertSame( $last, $surfaced );
	}

	public function test_write_error_for_ability_surfaces_widget_type_remap(): void {
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
		$GLOBALS['stonewright_test_posts'][ 9103 ] = (object) [
			'ID'          => 9103,
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

		self::assertFalse( ElementorData::write( 9103, $incoming ) );

		$surfaced = ElementorData::write_error_for_ability();
		self::assertSame( 'stonewright_elementor_widget_type_remap_blocked', $surfaced->get_error_code() );
	}
}
