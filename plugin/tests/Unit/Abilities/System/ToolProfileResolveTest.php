<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Abilities\System;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\System\ToolProfile;
use Stonewright\WpMcp\Core\AbilityRegistry;

/**
 * @covers \Stonewright\WpMcp\Abilities\System\ToolProfile
 */
final class ToolProfileResolveTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options'] = [
			'stonewright_disabled_abilities'        => [],
			'stonewright_essential_tools_mode'      => true,
			'stonewright_essential_extra_abilities' => [],
			'stonewright_last_tool_profile'         => '',
		];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options'] = [];
	}

	public function test_resolve_returns_ordered_mcp_tools_without_side_effects(): void {
		$ability = new ToolProfile();
		$result  = $ability->execute(
			[
				'action'  => 'resolve',
				'profile' => 'elementor-design',
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertTrue( $result['ordered'] );
		self::assertSame( 'plugin', $result['source'] );
		self::assertSame( 'elementor-design', $result['profile'] );
		self::assertIsArray( $result['tools'] );
		self::assertNotEmpty( $result['tools'] );
		foreach ( $result['tools'] as $name ) {
			self::assertIsString( $name );
			self::assertStringStartsWith( 'stonewright-', $name );
		}
		self::assertContains( 'stonewright-blueprint-apply', $result['tools'] );
		self::assertContains( 'stonewright-brand-kit-apply', $result['tools'] );
		// resolve must not flip last profile option.
		self::assertSame( '', (string) ( $GLOBALS['stonewright_test_options']['stonewright_last_tool_profile'] ?? '' ) );
	}

	public function test_profile_tools_priority_puts_startup_and_blueprints_first(): void {
		$full = ToolProfile::profile_tools( 'full' );
		// full is not a named list — profile_tools('full') falls into default/essential path.
		// Use full list via essential + elementor-design.
		$full_list = ToolProfile::profile_tools( 'elementor-design' );
		$head      = array_slice( $full_list, 0, 15 );

		foreach (
			[
				'stonewright/context-bootstrap',
				'stonewright/task-start',
				'stonewright/tool-profile',
				'stonewright/blueprint-list',
				'stonewright/blueprint-get',
				'stonewright/blueprint-apply',
				'stonewright/brand-kit-list',
				'stonewright/brand-kit-apply',
			] as $required
		) {
			self::assertContains( $required, $head, $required . ' should be in first 15 of elementor-design' );
		}

		$gutenberg = ToolProfile::profile_tools( 'gutenberg' );
		$g_head    = array_slice( $gutenberg, 0, 12 );
		self::assertContains( 'stonewright/blueprint-apply', $g_head );
		self::assertContains( 'stonewright/brand-kit-apply', $g_head );

		$essential = ToolProfile::profile_tools( 'essential' );
		$e_head    = array_slice( $essential, 0, 12 );
		self::assertContains( 'stonewright/blueprint-apply', $e_head );
	}

	public function test_profile_tools_essential_includes_registry_essentials(): void {
		$from_profile  = ToolProfile::profile_tools( 'essential' );
		$from_registry = AbilityRegistry::essential_ability_names_for_test();
		foreach ( $from_registry as $name ) {
			self::assertContains( $name, $from_profile, $name . ' missing from essential profile_tools' );
		}
	}
}
