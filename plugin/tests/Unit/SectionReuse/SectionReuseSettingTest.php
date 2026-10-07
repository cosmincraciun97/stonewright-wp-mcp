<?php
/**
 * The site setting that turns section reuse on or off and tells agents about it.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\SectionReuse;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\System\TaskStart;
use Stonewright\WpMcp\Abilities\System\ToolProfile;
use Stonewright\WpMcp\Context\AgentHints;
use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\SectionReuse\SectionReuseSetting;
use Stonewright\WpMcp\Security\IncidentStore;
use Stonewright\WpMcp\Support\AgentNotices;

/**
 * @covers \Stonewright\WpMcp\SectionReuse\SectionReuseSetting
 * @covers \Stonewright\WpMcp\Core\AbilityRegistry
 * @covers \Stonewright\WpMcp\Abilities\System\ToolProfile
 */
final class SectionReuseSettingTest extends TestCase {

	private const FIND    = 'stonewright/section-reuse-find';
	private const EXTRACT = 'stonewright/section-reuse-extract';

	protected function setUp(): void {
		IncidentStore::reset_for_tests();
		AgentNotices::reset_for_tests();
		$GLOBALS['stonewright_test_options']         = [
			'stonewright_mode'                      => 'development',
			'stonewright_enabled'                   => true,
			'stonewright_disabled_abilities'        => [],
			'stonewright_essential_extra_abilities' => [],
			'stonewright_mcp_surface'               => 'full',
			'stonewright_essential_tools_mode'      => false,
			'stonewright_memory_enabled'            => false,
			'stonewright_custom_instructions_enabled' => true,
			'stonewright_custom_instructions'       => '',
			'stonewright_user_context_enabled'      => false,
		];
		$GLOBALS['stonewright_test_filters']         = [];
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_current_user_id'] = 7;
		$GLOBALS['stonewright_test_user_caps']       = [ 'read' => true, 'manage_options' => true, 'edit_posts' => true ];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
	}

	protected function tearDown(): void {
		IncidentStore::reset_for_tests();
		AgentNotices::reset_for_tests();
		$GLOBALS['stonewright_test_options']        = [];
		$GLOBALS['stonewright_test_filters']        = [];
		$GLOBALS['stonewright_test_user_caps']      = [];
		$GLOBALS['stonewright_test_user_logged_in'] = false;
	}

	public function test_the_option_defaults_to_ask_and_ignores_unknown_values(): void {
		self::assertSame( 'stonewright_section_reuse', SectionReuseSetting::OPTION );
		self::assertSame( 'ask', SectionReuseSetting::value() );
		self::assertTrue( SectionReuseSetting::is_enabled() );

		$GLOBALS['stonewright_test_options'][ SectionReuseSetting::OPTION ] = 'off';
		self::assertSame( 'off', SectionReuseSetting::value() );
		self::assertFalse( SectionReuseSetting::is_enabled() );

		foreach ( [ '', 'ON', 'always', 1, true, null, [ 'off' ] ] as $stored ) {
			$GLOBALS['stonewright_test_options'][ SectionReuseSetting::OPTION ] = $stored;
			self::assertSame( 'ask', SectionReuseSetting::value(), var_export( $stored, true ) );
		}
	}

	public function test_the_settings_form_saves_only_ask_or_off_and_keeps_the_stored_value_for_anything_else(): void {
		self::assertSame( 'off', SectionReuseSetting::sanitize( 'off' ) );
		self::assertSame( 'off', SectionReuseSetting::sanitize( ' OFF ' ) );
		self::assertSame( 'ask', SectionReuseSetting::sanitize( 'ask' ) );

		$GLOBALS['stonewright_test_options'][ SectionReuseSetting::OPTION ] = 'off';
		self::assertSame( 'off', SectionReuseSetting::sanitize( 'sometimes' ), 'An invalid value never flips a stored off.' );
		self::assertSame( 'off', SectionReuseSetting::sanitize( [ 'ask' ] ) );

		$GLOBALS['stonewright_test_options'][ SectionReuseSetting::OPTION ] = 'ask';
		self::assertSame( 'ask', SectionReuseSetting::sanitize( null ) );
	}

	public function test_agents_learn_the_value_through_the_agent_preferences_filter(): void {
		SectionReuseSetting::register();

		self::assertSame( [ 'section_reuse' => 'ask' ], AgentHints::agent_preferences() );
		self::assertStringContainsString( 'section_reuse=ask', implode( ' ', AgentHints::connect_lines() ) );

		$GLOBALS['stonewright_test_options'][ SectionReuseSetting::OPTION ] = 'off';
		self::assertSame( [ 'section_reuse' => 'off' ], AgentHints::agent_preferences() );
		self::assertStringContainsString( 'section_reuse=off', implode( ' ', AgentHints::connect_lines() ) );
	}

	public function test_task_start_carries_the_value_next_to_the_design_direction_reference(): void {
		SectionReuseSetting::register();
		$GLOBALS['stonewright_test_options'][ SectionReuseSetting::OPTION ] = 'off';

		$start = ( new TaskStart() )->execute( [ 'task' => 'Build a landing page', 'surface' => 'wordpress', 'intent' => 'write' ] );

		self::assertIsArray( $start );
		self::assertSame( [ 'section_reuse' => 'off' ], $start['context']['agent_preferences'] );
	}

	public function test_a_change_is_audited_pushes_a_fifteen_minute_notice_and_bumps_the_tool_surface(): void {
		$before = AbilityRegistry::surface_revision();
		$now    = 1_800_000_000;

		SectionReuseSetting::on_change( 'ask', 'off', $now );

		$notices = AgentNotices::fields( $now + 1 )['notices'] ?? [];
		self::assertSame( [ 'section_reuse: off - do not offer section reuse' ], $notices );
		self::assertSame( [], AgentNotices::fields( $now + 900 ), 'The line lives for fifteen minutes.' );
		self::assertSame( $before + 1, AbilityRegistry::surface_revision(), 'Clients are told to list the tools again.' );

		$rows = $GLOBALS['stonewright_test_wpdb_inserts'];
		self::assertNotEmpty( $rows );
		$row = end( $rows )['data'];
		self::assertSame( 'stonewright/section-reuse-setting', $row['ability_name'] );
		self::assertStringContainsString( '"to":"off"', (string) $row['sanitized_args'] );
	}

	public function test_turning_it_back_on_replaces_the_line(): void {
		$now = 1_800_000_000;
		SectionReuseSetting::on_change( 'ask', 'off', $now );
		SectionReuseSetting::on_change( 'off', 'ask', $now + 5 );

		$notices = AgentNotices::fields( $now + 6 )['notices'] ?? [];
		self::assertCount( 1, $notices );
		self::assertStringStartsWith( 'section_reuse: ask - ', $notices[0] );
	}

	public function test_an_unchanged_value_does_nothing(): void {
		$before = AbilityRegistry::surface_revision();

		SectionReuseSetting::on_change( 'ask', 'ask' );
		SectionReuseSetting::on_change( 'off', 'off' );
		SectionReuseSetting::on_change( false, 'ask' );

		self::assertSame( [], AgentNotices::fields() );
		self::assertSame( $before, AbilityRegistry::surface_revision() );
		self::assertSame( [], $GLOBALS['stonewright_test_wpdb_inserts'] );
	}

	public function test_the_reuse_abilities_leave_the_tool_lists_while_the_setting_is_off(): void {
		self::assertContains( self::FIND, AbilityRegistry::mcp_server_ability_names() );
		self::assertContains( self::EXTRACT, AbilityRegistry::mcp_server_ability_names() );
		self::assertContains( self::FIND, array_column( AbilityRegistry::enabled_abilities(), 'name' ) );

		$GLOBALS['stonewright_test_options'][ SectionReuseSetting::OPTION ] = 'off';

		self::assertNotContains( self::FIND, AbilityRegistry::mcp_server_ability_names() );
		self::assertNotContains( self::EXTRACT, AbilityRegistry::mcp_server_ability_names() );
		self::assertNotContains( self::FIND, array_column( AbilityRegistry::enabled_abilities(), 'name' ) );
		self::assertContains( self::FIND, array_column( AbilityRegistry::all_abilities(), 'name' ), 'The full catalog still describes them for the admin pages.' );
	}

	public function test_the_design_profiles_list_the_reuse_abilities_only_while_the_setting_is_ask(): void {
		foreach ( [ 'elementor-design', 'gutenberg' ] as $profile ) {
			$names = ToolProfile::profile_tools( $profile );
			self::assertContains( self::FIND, $names, $profile );
			self::assertContains( self::EXTRACT, $names, $profile );
		}

		$GLOBALS['stonewright_test_options'][ SectionReuseSetting::OPTION ] = 'off';

		foreach ( ToolProfile::profile_names() as $profile ) {
			$names = ToolProfile::profile_tools( $profile );
			self::assertNotContains( self::FIND, $names, $profile );
			self::assertNotContains( self::EXTRACT, $names, $profile );
		}
	}

	public function test_the_reuse_abilities_stay_off_the_compact_surfaces_when_the_setting_is_ask(): void {
		self::assertNotContains( self::FIND, AbilityRegistry::essential_ability_names_for_test() );
		self::assertNotContains( self::FIND, AbilityRegistry::bootstrap_ability_names_for_test() );
	}
}
