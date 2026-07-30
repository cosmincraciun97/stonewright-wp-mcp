<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Core\AbilityRegistry;

/**
 * @covers \Stonewright\WpMcp\Core\AbilityRegistry
 */
final class AbilityRegistrySessionUnionTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_options']    = [
			'stonewright_disabled_abilities'        => [],
			'stonewright_essential_tools_mode'      => true,
			'stonewright_essential_extra_abilities' => [],
		];
		$_SERVER['HTTP_MCP_SESSION_ID']         = 'union-session-test';
	}

	protected function tearDown(): void {
		unset( $_SERVER['HTTP_MCP_SESSION_ID'] );
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_options']    = [];
	}

	public function test_session_profile_on_essential_surface_keeps_essential_base_visible(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mcp_surface'] = 'essential';

		AbilityRegistry::set_session_tool_profile(
			'elementor-design',
			[ 'stonewright/theme-custom-css' ]
		);

		$names = array_column( AbilityRegistry::enabled_abilities(), 'name' );

		self::assertContains( 'stonewright/theme-custom-css', $names );
		self::assertContains( 'stonewright/site-pulse', $names );
		self::assertContains( 'stonewright/learning-record', $names );
	}

	public function test_session_profile_never_narrows_full_surface(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mcp_surface'] = 'full';

		AbilityRegistry::set_session_tool_profile(
			'elementor-design',
			[ 'stonewright/theme-custom-css' ]
		);

		$names = array_column( AbilityRegistry::enabled_abilities(), 'name' );

		self::assertContains( 'stonewright/wp-cli-run', $names );
	}

	public function test_session_full_profile_exposes_everything_on_essential_surface(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mcp_surface'] = 'essential';

		AbilityRegistry::set_session_tool_profile( 'full', [] );

		$names = array_column( AbilityRegistry::enabled_abilities(), 'name' );
		self::assertContains( 'stonewright/wp-cli-run', $names );
	}
}
