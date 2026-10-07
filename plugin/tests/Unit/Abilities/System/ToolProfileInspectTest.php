<?php
// SPDX-License-Identifier: GPL-2.0-or-later
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Abilities\System;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\System\ToolProfile;
use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Support\PublicApiContractSnapshot;
use Stonewright\WpMcp\Support\TokenSurfaceBudgets;

/**
 * The inspect profile lists discovery, read, and verify tools and nothing that
 * writes. It is opt-in: auto routing never selects it.
 *
 * @covers \Stonewright\WpMcp\Abilities\System\ToolProfile
 */
final class ToolProfileInspectTest extends TestCase {

	/** Tools every profile except bootstrap carries first. */
	private const STARTUP = [
		'stonewright/context-bootstrap',
		'stonewright/task-start',
		'stonewright/tool-profile',
		'stonewright/skills-get',
		'stonewright/expertise-get',
		'stonewright/rules-get',
	];

	protected function setUp(): void {
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_options']    = [
			'stonewright_disabled_abilities'        => [],
			'stonewright_essential_tools_mode'      => true,
			'stonewright_essential_extra_abilities' => [],
			'stonewright_mcp_surface'               => 'essential',
			'stonewright_last_tool_profile'         => '',
		];
		$GLOBALS['stonewright_test_user_caps']      = [ 'read' => true, 'manage_options' => true ];
		$GLOBALS['stonewright_test_user_logged_in'] = true;
	}

	protected function tearDown(): void {
		unset( $_SERVER['HTTP_MCP_SESSION_ID'] );
		$GLOBALS['stonewright_test_transients']     = [];
		$GLOBALS['stonewright_test_options']        = [];
		$GLOBALS['stonewright_test_user_caps']      = [];
		$GLOBALS['stonewright_test_user_logged_in'] = false;
	}

	public function test_inspect_is_a_named_profile_that_auto_routing_never_selects(): void {
		self::assertContains( 'inspect', ToolProfile::profile_names() );

		$tasks = [
			[ 'Inspect the site and report what is installed', 'unknown', 'read' ],
			[ 'Read the page structure of the pricing page', 'elementor', 'read' ],
			[ 'Audit the theme and plugins', 'wordpress', 'read' ],
			[ 'Verify the last change on the home page', 'unknown', 'read' ],
			[ 'Check site health', 'unknown', 'read' ],
			[ 'Show me the block theme templates', 'gutenberg', 'read' ],
		];
		foreach ( $tasks as [ $task, $surface, $intent ] ) {
			self::assertNotSame( 'inspect', ToolProfile::suggest_profile( $task, $surface, $intent ), $task );
		}
	}

	public function test_the_input_schema_offers_the_profile(): void {
		$schema = ( new ToolProfile() )->input_schema();

		self::assertContains( 'inspect', $schema['properties']['profile']['enum'] );
	}

	public function test_inspect_lists_startup_then_discovery_read_and_verify_tools(): void {
		$names = ToolProfile::profile_tools( 'inspect' );

		self::assertSame( self::STARTUP, array_slice( $names, 0, count( self::STARTUP ) ) );

		$expected = [
			// Discovery.
			'stonewright/site-info',
			'stonewright/site-capabilities',
			'stonewright/site-plugins-list',
			'stonewright/design-direction-brief',
			// Read.
			'stonewright/content-get-page',
			'stonewright/elementor-v3-get-page-structure',
			'stonewright/elementor-v3-get-kit-globals',
			'stonewright/fse-get-theme-json',
			'stonewright/menu-list',
			'stonewright/settings-get',
			// Verify.
			'stonewright/elementor-post-write-verify',
			'stonewright/elementor-document-health',
			'stonewright/design-visual-compare',
			'stonewright/site-health',
		];
		foreach ( $expected as $name ) {
			self::assertContains( $name, $names, $name );
		}
		self::assertSame( $names, array_values( array_unique( $names ) ), 'No tool is listed twice.' );
		self::assertLessThanOrEqual( TokenSurfaceBudgets::ESSENTIAL_MAX_TOOLS, count( $names ) );
	}

	public function test_inspect_has_no_write_tool_and_no_runtime_escape_hatch(): void {
		$names = ToolProfile::profile_tools( 'inspect' );

		foreach (
			[
				'stonewright/php-execute',
				'stonewright/execute-ability',
				'stonewright/security-issue-confirmation-token',
				'stonewright/blueprint-apply',
				'stonewright/brand-kit-apply',
				'stonewright/design-direction-save',
				'stonewright/design-direction-activate',
				'stonewright/elementor-v3-batch-mutate',
				'stonewright/elementor-css-regenerate',
				'stonewright/wp-cli-run',
				'stonewright/theme-file-patch',
				'stonewright/settings-update',
			] as $forbidden
		) {
			self::assertNotContains( $forbidden, $names, $forbidden );
		}

		// Design-system management adds direction writes to other profiles; it must not here.
		self::assertSame(
			$names,
			ToolProfile::profile_tools( 'inspect', 'Update the design direction and brand kit', 'wordpress', 'write' )
		);
	}

	public function test_every_inspect_tool_beyond_the_startup_set_is_a_registered_read_only_ability(): void {
		$classes = [];
		foreach ( AbilityRegistry::list() as $class ) {
			$classes[ ( new $class() )->name() ] = $class;
		}

		foreach ( array_diff( ToolProfile::profile_tools( 'inspect' ), self::STARTUP ) as $name ) {
			self::assertArrayHasKey( $name, $classes, $name . ' is not a registered ability.' );

			$row = PublicApiContractSnapshot::collect_ability( $classes[ $name ] );
			self::assertIsArray( $row, $name );
			self::assertSame( 'Read', $row['kind'], $name . ' must be read-only.' );
			self::assertFalse( $row['gates']['token'], $name . ' must not need a confirmation token.' );
			self::assertFalse( $row['gates']['backup'], $name . ' must not take snapshots.' );
		}
	}

	public function test_the_companion_fallback_list_mirrors_the_inspect_profile_name_for_name(): void {
		$source = dirname( __DIR__, 5 ) . '/companion/src/wordpress-mcp.ts';
		if ( ! is_readable( $source ) ) {
			self::markTestSkipped( 'The companion source is not part of this checkout.' );
		}

		$text = (string) file_get_contents( $source );
		self::assertSame( 1, preg_match( '/\r?\n\tinspect: \[(.*?)\r?\n\t\],/s', $text, $block ), 'The companion declares an inspect fallback list.' );
		self::assertGreaterThan( 0, preg_match_all( "/'(stonewright-[a-z0-9-]+)'/", $block[1], $names ) );

		self::assertSame(
			array_map( [ AbilityRegistry::class, 'mcp_tool_name' ], ToolProfile::profile_tools( 'inspect' ) ),
			$names[1]
		);
	}

	public function test_resolve_returns_the_ordered_inspect_tools(): void {
		$result = ( new ToolProfile() )->execute(
			[
				'action'  => 'resolve',
				'profile' => 'inspect',
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( 'inspect', $result['profile'] );
		self::assertTrue( $result['ordered'] );
		self::assertFalse( $result['degraded'] );
		self::assertContains( 'stonewright-task-start', $result['tools'] );
		self::assertContains( 'stonewright-elementor-post-write-verify', $result['tools'] );
		self::assertNotContains( 'stonewright-php-execute', $result['tools'] );
		self::assertNotContains( 'stonewright-elementor-v3-batch-mutate', $result['tools'] );
		foreach ( $result['tools'] as $tool ) {
			self::assertStringStartsWith( 'stonewright-', $tool );
		}
		// resolve changes nothing.
		self::assertSame( '', (string) $GLOBALS['stonewright_test_options']['stonewright_last_tool_profile'] );
	}

	public function test_activating_inspect_states_that_it_is_read_only_and_how_to_leave(): void {
		$result = ( new ToolProfile() )->execute(
			[
				'action'  => 'activate',
				'profile' => 'inspect',
			]
		);

		self::assertIsArray( $result );
		self::assertSame( 'inspect', $result['profile'] );
		$rules = implode( "\n", $result['workflow_rules'] );
		self::assertStringContainsString( 'read-only', $rules );
		self::assertStringContainsString( 'tool-profile', $rules );
		self::assertStringContainsString( 'opt-in', $rules );
		self::assertNotContains( 'stonewright/php-execute', $result['recommended_tools'] );
		self::assertNotEmpty( $result['next_best_tools'] );
		foreach ( $result['next_best_tools'] as $tool ) {
			self::assertNotSame( 'stonewright/php-execute', $tool['ability'] );
		}
	}

	public function test_activating_inspect_never_widens_the_stored_surface(): void {
		update_option( 'stonewright_mcp_surface', 'bootstrap', false );

		$result = ( new ToolProfile() )->execute(
			[
				'action'  => 'activate',
				'profile' => 'inspect',
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame(
			'bootstrap',
			AbilityRegistry::mcp_surface(),
			'A read-only profile must not turn the operator-chosen bootstrap surface into essential.'
		);
	}

	public function test_an_inspect_session_adds_read_tools_and_exposes_no_write_tool(): void {
		$_SERVER['HTTP_MCP_SESSION_ID'] = 'inspect-session-test';
		update_option( 'stonewright_mcp_surface', 'bootstrap', false );

		$result = ( new ToolProfile() )->execute(
			[
				'action'  => 'activate',
				'profile' => 'inspect',
			]
		);
		self::assertIsArray( $result );
		self::assertTrue( $result['session_profile_applied'] );

		$visible = array_column( AbilityRegistry::enabled_abilities(), 'name' );
		self::assertContains( 'stonewright/elementor-document-health', $visible );
		self::assertContains( 'stonewright/site-health', $visible );
		self::assertNotContains( 'stonewright/elementor-v3-batch-mutate', $visible );
		self::assertNotContains( 'stonewright/content-update-page', $visible );
		self::assertNotContains( 'stonewright/settings-update', $visible );
		$allowed = array_merge( AbilityRegistry::bootstrap_ability_names(), ToolProfile::profile_tools( 'inspect' ) );
		foreach ( $visible as $name ) {
			self::assertContains( $name, $allowed, $name . ' is neither on the bootstrap surface nor in the inspect profile.' );
		}
	}
}
