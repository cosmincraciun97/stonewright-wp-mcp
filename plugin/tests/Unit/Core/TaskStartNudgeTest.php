<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Site\Info;
use Stonewright\WpMcp\Core\AbilityRegistry;

/**
 * @covers \Stonewright\WpMcp\Core\AbilityRegistry::execute_with_context_guard
 * @covers \Stonewright\WpMcp\Core\AbilityRegistry::session_task_started
 * @covers \Stonewright\WpMcp\Core\AbilityRegistry::mark_session_task_started
 */
final class TaskStartNudgeTest extends TestCase {

	protected function setUp(): void {
		unset( $_SERVER['HTTP_MCP_SESSION_ID'] );
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_options']    = [
			'stonewright_disabled_abilities'   => [],
			'stonewright_essential_tools_mode' => true,
		];
	}

	protected function tearDown(): void {
		unset( $_SERVER['HTTP_MCP_SESSION_ID'] );
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_options']    = [];
	}

	public function test_read_result_carries_task_start_hint_before_session_start(): void {
		$_SERVER['HTTP_MCP_SESSION_ID'] = 'nudge-session-a';

		$result = AbilityRegistry::execute_with_context_guard( new Info(), [] );

		self::assertIsArray( $result );
		self::assertArrayHasKey( 'task_start_hint', $result );
		self::assertStringContainsString( 'stonewright-task-start', (string) $result['task_start_hint'] );
	}

	public function test_hint_absent_after_task_start(): void {
		$_SERVER['HTTP_MCP_SESSION_ID'] = 'nudge-session-b';
		self::assertTrue( AbilityRegistry::mark_session_task_started() );

		$result = AbilityRegistry::execute_with_context_guard( new Info(), [] );

		self::assertIsArray( $result );
		self::assertArrayNotHasKey( 'task_start_hint', $result );
	}

	public function test_hint_absent_after_session_tool_profile(): void {
		$_SERVER['HTTP_MCP_SESSION_ID'] = 'nudge-session-c';
		AbilityRegistry::set_session_tool_profile( 'essential', [] );

		$result = AbilityRegistry::execute_with_context_guard( new Info(), [] );

		self::assertIsArray( $result );
		self::assertArrayNotHasKey( 'task_start_hint', $result );
	}

	public function test_hint_skipped_without_mcp_session(): void {
		// No HTTP_MCP_SESSION_ID — bare REST / unit paths stay quiet.
		$result = AbilityRegistry::execute_with_context_guard( new Info(), [] );

		self::assertIsArray( $result );
		self::assertArrayNotHasKey( 'task_start_hint', $result );
	}
}
