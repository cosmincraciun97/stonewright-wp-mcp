<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Support\TokenSurfaceBudgets;

/**
 * @covers \Stonewright\WpMcp\Core\AbilityRegistry
 * @covers \Stonewright\WpMcp\Support\TokenSurfaceBudgets
 */
final class AbilityRegistryBootstrapModeTest extends TestCase {

	protected function setUp(): void {
		unset( $_SERVER['HTTP_MCP_SESSION_ID'] );
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_options'] = [
			'stonewright_disabled_abilities'        => [],
			'stonewright_essential_tools_mode'      => true,
			'stonewright_essential_extra_abilities' => [],
		];
	}

	protected function tearDown(): void {
		unset( $_SERVER['HTTP_MCP_SESSION_ID'] );
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_options'] = [];
	}

	public function test_bootstrap_ability_names_cap_and_runtime_escape_hatches(): void {
		$names = AbilityRegistry::bootstrap_ability_names_for_test();

		self::assertLessThanOrEqual( TokenSurfaceBudgets::BOOTSTRAP_MAX_TOOLS, count( $names ) );
		self::assertContains( 'stonewright/task-start', $names );
		self::assertContains( 'stonewright/tool-profile', $names );
		self::assertContains( 'stonewright/php-execute', $names );
		self::assertContains( 'stonewright/site-info', $names );
		self::assertContains( 'stonewright/ping', $names );
		self::assertContains( 'stonewright/security-issue-confirmation-token', $names );
		self::assertContains( 'stonewright/context-bootstrap', $names );
		self::assertContains( 'stonewright/content-get-page', $names );
		self::assertContains( 'stonewright/theme-file-read', $names );
	}

	public function test_bootstrap_surface_filters_public_abilities(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mcp_surface'] = 'bootstrap';

		$names = array_column( AbilityRegistry::enabled_abilities(), 'name' );

		self::assertSame( 'bootstrap', AbilityRegistry::mcp_surface() );
		self::assertLessThanOrEqual( TokenSurfaceBudgets::BOOTSTRAP_MAX_TOOLS, count( $names ) );
		self::assertContains( 'stonewright/task-start', $names );
		self::assertNotContains( 'stonewright/elementor-v3-batch-mutate', $names );
		self::assertNotContains( 'stonewright/sandbox-write', $names );
	}

	public function test_session_profile_expands_only_the_current_mcp_session(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mcp_surface'] = 'bootstrap';
		$_SERVER['HTTP_MCP_SESSION_ID'] = 'session-a';

		self::assertTrue(
			AbilityRegistry::set_session_tool_profile(
				'elementor-design',
				[ 'stonewright/elementor-v3-batch-mutate' ]
			)
		);
		self::assertContains( 'stonewright/elementor-v3-batch-mutate', array_column( AbilityRegistry::enabled_abilities(), 'name' ) );
		self::assertSame( 'bootstrap', AbilityRegistry::mcp_surface() );

		$_SERVER['HTTP_MCP_SESSION_ID'] = 'session-b';
		self::assertNotContains( 'stonewright/elementor-v3-batch-mutate', array_column( AbilityRegistry::enabled_abilities(), 'name' ) );
	}

	public function test_legacy_essential_mode_maps_when_surface_unset(): void {
		unset( $GLOBALS['stonewright_test_options']['stonewright_mcp_surface'] );
		$GLOBALS['stonewright_test_options']['stonewright_essential_tools_mode'] = true;

		self::assertSame( 'essential', AbilityRegistry::mcp_surface() );

		$GLOBALS['stonewright_test_options']['stonewright_essential_tools_mode'] = false;
		self::assertSame( 'full', AbilityRegistry::mcp_surface() );
	}

	public function test_set_mcp_surface_syncs_legacy_flag(): void {
		AbilityRegistry::set_mcp_surface( 'full' );
		self::assertSame( 'full', AbilityRegistry::mcp_surface() );
		self::assertFalse( (bool) get_option( 'stonewright_essential_tools_mode', true ) );

		AbilityRegistry::set_mcp_surface( 'bootstrap' );
		self::assertSame( 'bootstrap', AbilityRegistry::mcp_surface() );
		self::assertTrue( (bool) get_option( 'stonewright_essential_tools_mode', false ) );
	}

	public function test_bootstrap_budget_constants(): void {
		self::assertSame( 12, TokenSurfaceBudgets::BOOTSTRAP_MAX_TOOLS );
		self::assertSame( 3500, TokenSurfaceBudgets::BOOTSTRAP_MAX_TOKENS );

		$ok = TokenSurfaceBudgets::evaluate(
			[
				'bootstrap_tool_count'         => 12,
				'bootstrap_token_estimate'     => 3000,
				'essential_tool_count'         => 29,
				'default_tool_count'           => 20,
				'strict_tool_count'            => 12,
				'non_visual_task_start_tokens' => 600,
				'visual_task_start_tokens'     => 1000,
			]
		);
		self::assertTrue( $ok['bootstrap_max_12_tools'] );
		self::assertTrue( $ok['bootstrap_max_8_tools'] );
		self::assertTrue( $ok['bootstrap_max_3500_tokens'] );
		self::assertTrue( TokenSurfaceBudgets::all_pass( $ok ) );
	}
}
