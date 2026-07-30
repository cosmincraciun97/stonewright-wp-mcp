<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\DesignTokens;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\DesignTokens\BrandKit;
use Stonewright\WpMcp\Security\Backup;

/**
 * @covers \Stonewright\WpMcp\DesignTokens\BrandKit
 * @covers \Stonewright\WpMcp\Security\Backup::snapshot_options
 * @covers \Stonewright\WpMcp\Security\Backup::restore_options
 */
final class BrandKitApplyPreviewTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']    = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_theme_mods'] = [];
		$GLOBALS['stonewright_test_posts']      = [];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options']    = [];
		$GLOBALS['stonewright_test_theme_mods'] = [];
		$GLOBALS['stonewright_test_posts']      = [];
	}

	public function test_preview_does_not_write_and_returns_diff(): void {
		$kits = BrandKit::list();
		self::assertNotEmpty( $kits );
		$id = (string) ( $kits[0]['id'] ?? '' );
		self::assertNotSame( '', $id );

		$result = BrandKit::apply( $id, true );
		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertTrue( $result['preview'] );
		self::assertArrayHasKey( 'restore_id', $result );
		self::assertNotSame( '', $result['restore_id'] );
		self::assertArrayHasKey( 'diff', $result );
		self::assertIsArray( $result['diff']['theme_mods'] ?? null );

		// No active kit option written in preview.
		self::assertFalse(
			array_key_exists( BrandKit::OPTION_ACTIVE, $GLOBALS['stonewright_test_options'] )
			&& is_array( $GLOBALS['stonewright_test_options'][ BrandKit::OPTION_ACTIVE ] ?? null )
			&& ( ( $GLOBALS['stonewright_test_options'][ BrandKit::OPTION_ACTIVE ]['id'] ?? '' ) === $id )
		);
	}

	public function test_apply_returns_restore_id_and_restore_works(): void {
		$kits = BrandKit::list();
		$id   = (string) ( $kits[0]['id'] ?? '' );
		self::assertNotSame( '', $id );

		set_theme_mod( 'stonewright_color_primary', '#111111' );

		$result = BrandKit::apply( $id, false );
		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertFalse( $result['preview'] );
		self::assertNotEmpty( $result['restore_id'] );
		self::assertNotEmpty( $result['theme_mods'] );

		$active = get_option( BrandKit::OPTION_ACTIVE );
		self::assertIsArray( $active );
		self::assertSame( $id, $active['id'] ?? null );

		$restored = BrandKit::restore( (string) $result['restore_id'] );
		self::assertTrue( $restored );
		self::assertSame( '#111111', get_theme_mod( 'stonewright_color_primary', null ) );
	}

	public function test_option_snapshot_round_trip(): void {
		update_option( 'stonewright_active_brand_kit', [ 'id' => 'before' ], false );
		$rid = Backup::snapshot_options( [ 'stonewright_active_brand_kit' ], [ 'stonewright_color_primary' ] );
		self::assertNotSame( '', $rid );
		update_option( 'stonewright_active_brand_kit', [ 'id' => 'after' ], false );
		set_theme_mod( 'stonewright_color_primary', '#ff0000' );
		self::assertTrue( Backup::restore_options( $rid ) );
		$opt = get_option( 'stonewright_active_brand_kit' );
		self::assertIsArray( $opt );
		self::assertSame( 'before', $opt['id'] ?? null );
	}
}
