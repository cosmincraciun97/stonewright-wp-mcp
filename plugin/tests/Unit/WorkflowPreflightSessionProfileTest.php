<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\System\TaskStart;
use Stonewright\WpMcp\Abilities\System\WorkflowPreflight;
use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Security\AuditEvent;
use Stonewright\WpMcp\Security\IncidentStore;

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
		IncidentStore::reset_for_tests();
	}

	protected function tearDown(): void {
		unset( $_SERVER['HTTP_MCP_SESSION_ID'] );
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_options']    = [];
		IncidentStore::reset_for_tests();
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
		self::assertSame( 'essential', $result['configured_mcp_surface'] );
		self::assertNotSame( $result['configured_mcp_surface'], $result['session_tool_profile'] );

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

	public function test_task_start_returns_ranked_open_incident_actions(): void {
		$event = [
			'incident_id'          => hash( 'sha256', 'preflight-incident' ),
			'event_id'             => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
			'outcome'              => AuditEvent::OUTCOME_FAILED,
			'category'             => AuditEvent::CATEGORY_VALIDATION,
			'severity_level'       => 'high',
			'ability'              => 'stonewright/elementor-v3-batch-mutate',
			'ability_family'       => 'elementor-v3',
			'root_error_code'      => 'stonewright_elementor_settings_invalid',
			'resource_type'        => 'post',
			'resource_key_hash'    => hash( 'sha256', 'preflight-resource' ),
			'normalized_path'      => 'elementor/widget/settings/image',
			'cause_fingerprint'    => hash( 'sha256', 'preflight-cause' ),
			'strategy_fingerprint' => hash( 'sha256', 'preflight-strategy' ),
			'expected_verifier'    => 'stonewright/elementor-post-write-verify',
			'remediation_code'     => 'stonewright/elementor-v3-get-widget-schema',
			'change_set_id'        => 'preflight-change',
		];
		IncidentStore::observe( $event );
		$event['event_id'] = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
		IncidentStore::observe( $event );

		$result = ( new WorkflowPreflight() )->execute( [
			'task' => 'Repair the broken image widget', 'surface' => 'elementor', 'intent' => 'write', 'responseMode' => 'compact',
		] );

		self::assertIsArray( $result );
		self::assertContains( 'repair_open_incidents_first', $result['context']['required_actions'] );
		self::assertNotContains( 'fix_recurring_errors_first', $result['context']['required_actions'] );
		self::assertCount( 1, $result['context']['incident_actions'] );
		self::assertSame( hash( 'sha256', 'preflight-incident' ), $result['context']['incident_actions'][0]['incident_id'] );
		self::assertSame( 'stonewright/elementor-post-write-verify', $result['context']['incident_actions'][0]['required_verifier'] );
	}

	public function test_compact_task_start_keeps_required_auth_guidance_when_empty(): void {
		$result = ( new TaskStart() )->execute(
			[
				'task'         => 'Inspect the current site.',
				'responseMode' => 'compact',
			]
		);

		self::assertIsArray( $result );
		self::assertArrayHasKey( 'auth_guidance', $result );
		self::assertIsArray( $result['auth_guidance'] );
	}
}
