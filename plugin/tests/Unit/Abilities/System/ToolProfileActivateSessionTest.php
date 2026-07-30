<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Abilities\System;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\System\ToolProfile;
use Stonewright\WpMcp\Core\AbilityRegistry;

/**
 * @covers \Stonewright\WpMcp\Abilities\System\ToolProfile
 */
final class ToolProfileActivateSessionTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_options']    = [
			'stonewright_disabled_abilities'        => [],
			'stonewright_essential_tools_mode'      => true,
			'stonewright_essential_extra_abilities' => [],
			'stonewright_mcp_surface'               => 'essential',
			'stonewright_last_tool_profile'         => '',
		];
		$_SERVER['HTTP_MCP_SESSION_ID']         = 'activate-session-test';
	}

	protected function tearDown(): void {
		unset( $_SERVER['HTTP_MCP_SESSION_ID'] );
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_options']    = [];
	}

	public function test_activate_task_profile_writes_session_transient(): void {
		$result = ( new ToolProfile() )->execute(
			[
				'action'  => 'activate',
				'profile' => 'elementor-design',
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['session_profile_applied'] );
		self::assertSame( 'session_transient_written', $result['session_profile_reason'] );
		self::assertTrue( $result['tools_changed'] );
		self::assertArrayHasKey( 'surface_revision', $result );
		self::assertIsInt( $result['surface_revision'] );

		$session = AbilityRegistry::session_tool_profile();
		self::assertIsArray( $session );
		self::assertSame( 'elementor-design', $session['profile'] );
		self::assertContains( 'stonewright/theme-file-patch', $session['ability_names'] );
	}

	public function test_activate_full_writes_full_session_profile_on_essential_surface(): void {
		$result = ( new ToolProfile() )->execute(
			[
				'action'  => 'activate',
				'profile' => 'full',
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['session_profile_applied'] );

		$session = AbilityRegistry::session_tool_profile();
		self::assertIsArray( $session );
		self::assertSame( 'full', $session['profile'] );
		self::assertSame( 'essential', AbilityRegistry::mcp_surface() );
	}

	public function test_activate_without_session_header_reports_reason(): void {
		unset( $_SERVER['HTTP_MCP_SESSION_ID'] );

		$result = ( new ToolProfile() )->execute(
			[
				'action'  => 'activate',
				'profile' => 'elementor-design',
			]
		);

		self::assertIsArray( $result );
		self::assertFalse( $result['session_profile_applied'] );
		self::assertSame( 'missing_or_invalid_mcp_session_id_header', $result['session_profile_reason'] );
	}
}
