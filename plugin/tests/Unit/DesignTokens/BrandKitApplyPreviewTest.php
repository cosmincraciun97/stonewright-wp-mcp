<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\DesignTokens;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\DesignTokens\BrandKit;
use Stonewright\WpMcp\Elementor\Write\PostWriteLock;
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

	private const KIT_POST = 77;

	private function seed_kit(): string {
		$GLOBALS['stonewright_test_options']['elementor_active_kit'] = self::KIT_POST;
		$GLOBALS['stonewright_test_posts']                           = [
			self::KIT_POST => (object) [
				'ID'           => self::KIT_POST,
				'post_type'    => 'elementor_library',
				'post_status'  => 'publish',
				'post_title'   => 'Default Kit',
				'post_content' => '',
				'post_excerpt' => '',
				'meta'         => [ '_elementor_page_settings' => [ 'custom_colors' => [ [ '_id' => 'existing', 'title' => 'Existing', 'color' => '#123456' ] ] ] ],
			],
		];
		$GLOBALS['stonewright_test_post_meta_calls']                 = [];
		$kits = BrandKit::list();
		self::assertNotEmpty( $kits );
		return (string) ( $kits[0]['id'] ?? '' );
	}

	/** @return list<string> */
	private function kit_color_ids(): array {
		$settings = $GLOBALS['stonewright_test_posts'][ self::KIT_POST ]->meta['_elementor_page_settings'] ?? [];
		return array_column( (array) ( $settings['custom_colors'] ?? [] ), '_id' );
	}

	public function test_apply_leaves_the_elementor_kit_alone_while_another_writer_holds_its_lease(): void {
		$id = $this->seed_kit();
		PostWriteLock::acquire( self::KIT_POST, 'other-writer', 30 );

		$result = BrandKit::apply( $id, false );

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertFalse( $result['elementor']['applied'] );
		self::assertSame( 'kit_busy', $result['elementor']['reason'] );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'], 'The kit was not snapshotted or written.' );
		self::assertSame( [ 'existing' ], $this->kit_color_ids() );
		self::assertNotEmpty( $result['theme_mods'], 'The options and theme mods are still applied.' );
		self::assertTrue( PostWriteLock::owned_by( self::KIT_POST, 'other-writer' ), 'The other writer keeps its lease.' );
	}

	public function test_apply_keeps_a_kit_color_another_writer_saved_while_the_call_was_starting(): void {
		$id = $this->seed_kit();
		// Fires right after the apply takes the kit lease, which is where a read taken earlier would already be stale.
		$GLOBALS['stonewright_test_after_add_option'] = static function (): void {
			$GLOBALS['stonewright_test_posts'][ self::KIT_POST ]->meta['_elementor_page_settings']['custom_colors'][] = [ '_id' => 'added-by-other', 'title' => 'Other', 'color' => '#999999' ];
		};

		$result = BrandKit::apply( $id, false );

		self::assertIsArray( $result );
		self::assertTrue( $result['elementor']['applied'] ?? false );
		self::assertContains( 'added-by-other', $this->kit_color_ids(), 'The color another writer saved is not lost.' );
		self::assertContains( 'existing', $this->kit_color_ids() );
	}

	public function test_apply_releases_the_kit_lease(): void {
		$id = $this->seed_kit();

		$result = BrandKit::apply( $id, false );

		self::assertTrue( $result['elementor']['applied'] ?? false );
		self::assertIsArray( PostWriteLock::acquire( self::KIT_POST, 'next-writer', 30 ), 'The lease is free afterwards.' );
	}
}
