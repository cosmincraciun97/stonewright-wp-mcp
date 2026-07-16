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
		$GLOBALS['stonewright_test_options'] = [
			'stonewright_disabled_abilities'        => [],
			'stonewright_essential_tools_mode'      => true,
			'stonewright_essential_extra_abilities' => [],
		];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options'] = [];
	}

	public function test_bootstrap_ability_names_cap_at_eight(): void {
		$names = AbilityRegistry::bootstrap_ability_names_for_test();

		self::assertLessThanOrEqual( TokenSurfaceBudgets::BOOTSTRAP_MAX_TOOLS, count( $names ) );
		self::assertContains( 'stonewright/task-start', $names );
		self::assertContains( 'stonewright/tool-profile', $names );
		self::assertContains( 'stonewright/php-execute', $names );
		self::assertContains( 'stonewright/site-info', $names );
		self::assertContains( 'stonewright/ping', $names );
		self::assertContains( 'stonewright/security-issue-confirmation-token', $names );
		self::assertContains( 'stonewright/context-bootstrap', $names );
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
		self::assertSame( 8, TokenSurfaceBudgets::BOOTSTRAP_MAX_TOOLS );
		self::assertSame( 2500, TokenSurfaceBudgets::BOOTSTRAP_MAX_TOKENS );

		$ok = TokenSurfaceBudgets::evaluate(
			[
				'bootstrap_tool_count'         => 8,
				'bootstrap_token_estimate'     => 2048,
				'essential_tool_count'         => 29,
				'default_tool_count'           => 20,
				'strict_tool_count'            => 12,
				'non_visual_task_start_tokens' => 600,
				'visual_task_start_tokens'     => 1000,
			]
		);
		self::assertTrue( $ok['bootstrap_max_8_tools'] );
		self::assertTrue( $ok['bootstrap_max_2500_tokens'] );
		self::assertTrue( TokenSurfaceBudgets::all_pass( $ok ) );
	}
}
