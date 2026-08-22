<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Gutenberg;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Gutenberg\ColorPresetLookup;

/**
 * @covers \Stonewright\WpMcp\Gutenberg\ColorPresetLookup
 */
final class ColorPresetLookupTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_global_settings'] = [
			'color' => [
				'palette' => [
					[ 'slug' => 'contrast', 'color' => '#111111', 'name' => 'Contrast' ],
					[ 'slug' => 'base', 'color' => '#f8fafc', 'name' => 'Base' ],
				],
			],
		];
	}

	protected function tearDown(): void {
		unset( $GLOBALS['stonewright_test_global_settings'] );
	}

	public function test_matching_hex_returns_theme_preset_slug(): void {
		self::assertSame( 'contrast', ColorPresetLookup::slug_for_hex( '#111111' ) );
		self::assertSame( 'contrast', ColorPresetLookup::slug_for_hex( '#111' ) );
	}

	public function test_unknown_hex_returns_null(): void {
		self::assertNull( ColorPresetLookup::slug_for_hex( '#2563eb' ) );
	}
}
