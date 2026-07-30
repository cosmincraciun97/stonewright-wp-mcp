<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\System\WorkflowPreflight;
use Stonewright\WpMcp\Core\AbilityRegistry;

/**
 * @covers \Stonewright\WpMcp\Abilities\System\WorkflowPreflight
 */
final class WorkflowPreflightSessionProfileTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_options']    = [
			'stonewright_disabled_abilities'        => [],
			'stonewright_essential_extra_abilities' => [],
			'stonewright_mcp_surface'               => 'essential',
			'stonewright_essential_tools_mode'      => true,
		];
		$_SERVER['HTTP_MCP_SESSION_ID']         = 'preflight-session-test';
	}

	protected function tearDown(): void {
		unset( $_SERVER['HTTP_MCP_SESSION_ID'] );
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_options']    = [];
	}

	public function test_essential_surface_applies_suggested_task_profile_to_session(): void {
		$result = ( new WorkflowPreflight() )->execute(
			[
				'task'    => 'Rebuild the timeline section of the careers page in Elementor',
				'surface' => 'elementor',
				'intent'  => 'write',
			]
		);

		self::assertIsArray( $result );
		self::assertSame( 'elementor-design', $result['session_tool_profile'] );
		self::assertTrue( $result['session_profile_applied'] );
		self::assertSame( 'session_transient_written', $result['session_profile_reason'] );
		self::assertTrue( $result['tools_changed'] );

		$session = AbilityRegistry::session_tool_profile();
		self::assertIsArray( $session );
		self::assertSame( 'elementor-design', $session['profile'] );
		self::assertContains( 'stonewright/theme-file-patch', $session['ability_names'] );
	}

	public function test_full_surface_skips_transient_and_reports_reason(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mcp_surface'] = 'full';

		$result = ( new WorkflowPreflight() )->execute(
			[
				'task'    => 'Rebuild the timeline section of the careers page in Elementor',
				'surface' => 'elementor',
				'intent'  => 'write',
			]
		);

		self::assertIsArray( $result );
		self::assertSame( 'full', $result['session_tool_profile'] );
		self::assertFalse( $result['session_profile_applied'] );
		self::assertSame( 'surface_full_already_exposes_all_tools', $result['session_profile_reason'] );
		self::assertNull( AbilityRegistry::session_tool_profile() );
	}

	public function test_missing_session_header_reports_reason(): void {
		unset( $_SERVER['HTTP_MCP_SESSION_ID'] );

		$result = ( new WorkflowPreflight() )->execute(
			[
				'task'    => 'Rebuild the timeline section of the careers page in Elementor',
				'surface' => 'elementor',
				'intent'  => 'write',
			]
		);

		self::assertIsArray( $result );
		self::assertFalse( $result['session_profile_applied'] );
		self::assertSame( 'missing_or_invalid_mcp_session_id_header', $result['session_profile_reason'] );
	}
}
