<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Site;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Site\SiteSnapshot;
use Stonewright\WpMcp\Core\AbilityRegistry;

/**
 * @covers \Stonewright\WpMcp\Abilities\Site\SiteSnapshot
 */
final class SiteSnapshotTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_logged_in'] = true;
		$GLOBALS['stonewright_test_user_caps']      = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_options']        = [
			'stonewright_mode'        => 'development',
			'stonewright_mcp_surface' => 'essential',
		];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_caps']      = [];
		$GLOBALS['stonewright_test_user_logged_in'] = false;
		$GLOBALS['stonewright_test_options']        = [];
	}

	public function test_registry_exposes_site_snapshot(): void {
		$ability = AbilityRegistry::ability_by_name( 'stonewright/site-snapshot' );
		self::assertInstanceOf( SiteSnapshot::class, $ability );
		self::assertSame( 'site', $ability->category() );
	}

	public function test_permission_requires_manage_options(): void {
		$ability = new SiteSnapshot();
		self::assertTrue( $ability->permission_callback( [] ) );

		$GLOBALS['stonewright_test_user_caps'] = [ 'edit_posts' => true ];
		self::assertFalse( $ability->permission_callback( [] ) );
	}

	public function test_execute_returns_compact_snapshot_fields(): void {
		$result = ( new SiteSnapshot() )->execute( [] );

		self::assertIsArray( $result );
		self::assertArrayHasKey( 'name', $result );
		self::assertArrayHasKey( 'url', $result );
		self::assertArrayHasKey( 'theme', $result );
		self::assertArrayHasKey( 'plugins', $result );
		self::assertArrayHasKey( 'post_counts', $result );
		self::assertArrayHasKey( 'mode', $result );
		self::assertArrayHasKey( 'mcp_surface', $result );
		self::assertSame( 'development', $result['mode'] );
		self::assertSame( 'essential', $result['mcp_surface'] );
		self::assertArrayHasKey( 'active', $result['plugins'] );
		self::assertArrayHasKey( 'total', $result['plugins'] );
		self::assertIsArray( $result['post_counts'] );
	}
}
