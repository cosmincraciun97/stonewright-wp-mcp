<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Core\AbilityRegistry;

/**
 * @covers \Stonewright\WpMcp\Core\AbilityRegistry
 */
final class AbilityRegistrySurfaceRevisionTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options'] = [];
		$GLOBALS['stonewright_test_actions'] = [];
		$GLOBALS['stonewright_test_transients'] = [];
	}

	protected function tearDown(): void {
		remove_all_actions( 'stonewright_tool_surface_changed' );
		$GLOBALS['stonewright_test_options'] = [];
		$GLOBALS['stonewright_test_actions'] = [];
		$GLOBALS['stonewright_test_transients'] = [];
		unset( $_SERVER['HTTP_MCP_SESSION_ID'] );
	}

	public function test_surface_revision_bumps_on_surface_change_and_fires_hook(): void {
		$fired = [];
		add_action(
			'stonewright_tool_surface_changed',
			static function ( int $revision ) use ( &$fired ): void {
				$fired[] = $revision;
			}
		);

		$start = AbilityRegistry::surface_revision();
		AbilityRegistry::set_mcp_surface( 'full' );
		$after = AbilityRegistry::surface_revision();

		self::assertGreaterThan( $start, $after );
		self::assertSame( [ $after ], $fired );

		AbilityRegistry::set_mcp_surface( 'full' );
		self::assertSame( $after, AbilityRegistry::surface_revision() );
		self::assertSame( [ $after ], $fired );
	}

	public function test_successful_session_profile_write_bumps_revision(): void {
		$_SERVER['HTTP_MCP_SESSION_ID'] = 'surface-revision-session';
		$start = AbilityRegistry::surface_revision();

		self::assertTrue( AbilityRegistry::set_session_tool_profile( 'essential', [ 'stonewright/ping' ] ) );
		self::assertSame( $start + 1, AbilityRegistry::surface_revision() );
	}
}
