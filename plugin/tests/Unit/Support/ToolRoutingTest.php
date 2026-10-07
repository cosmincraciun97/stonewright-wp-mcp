<?php
// SPDX-License-Identifier: GPL-2.0-or-later
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Settings\SettingsGet;
use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Support\ToolRouting;

/**
 * The mapping from common php-execute patterns and task phrases to typed tools
 * is measured against a fixture of synthetic snippets, so a pattern that starts
 * naming the wrong tool, or stops naming one, fails here.
 *
 * @covers \Stonewright\WpMcp\Support\ToolRouting
 */
final class ToolRoutingTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../fixtures/tool-routing/hint-mapping.json';

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options'] = [ 'stonewright_disabled_abilities' => [] ];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options'] = [];
	}

	/**
	 * @dataProvider snippet_cases
	 * @param array<string, list<string>> $expect
	 */
	public function test_snippet_fixture_names_the_expected_typed_tools( string $code, array $expect ): void {
		self::assertSame( $expect, ToolRouting::for_snippet( $code ) );
	}

	/**
	 * @dataProvider task_cases
	 * @param array<string, list<string>> $expect
	 */
	public function test_task_fixture_names_the_expected_typed_tools( string $task, array $expect ): void {
		self::assertSame( $expect, ToolRouting::for_task( $task ) );
	}

	public function test_every_routed_tool_is_a_registered_typed_ability(): void {
		$registered = [];
		foreach ( AbilityRegistry::list() as $class ) {
			$registered[ AbilityRegistry::mcp_tool_name( ( new $class() )->name() ) ] = true;
		}

		$tools = ToolRouting::tools();
		self::assertNotEmpty( $tools );
		foreach ( $tools as $tool ) {
			self::assertArrayHasKey( $tool, $registered, $tool . ' is not a registered ability.' );
			self::assertStringStartsWith( 'stonewright-', $tool );
			self::assertStringNotContainsString( '/', $tool, 'Hints carry MCP tool names, not ability slugs.' );
		}
		self::assertNotContains( 'stonewright-php-execute', $tools );
	}

	public function test_every_pattern_in_the_fixture_is_known_and_every_known_pattern_is_covered(): void {
		$fixture = self::read_fixture();
		$seen    = [];
		foreach ( array_merge( $fixture['snippets'], $fixture['tasks'] ) as $case ) {
			foreach ( array_keys( $case['expect'] ) as $pattern ) {
				$seen[ $pattern ] = true;
			}
		}

		self::assertEqualsCanonicalizing( ToolRouting::patterns(), array_keys( $seen ) );
	}

	public function test_options_hint_follows_the_settings_the_typed_ability_accepts(): void {
		foreach ( SettingsGet::ALLOWLIST as $option ) {
			self::assertArrayHasKey(
				'options',
				ToolRouting::for_snippet( "update_option( '" . $option . "', 'x' );" ),
				$option . ' is accepted by stonewright-settings-update.'
			);
			self::assertArrayHasKey(
				'options',
				ToolRouting::for_snippet( "return get_option( '" . $option . "' );" ),
				$option . ' is readable through stonewright-settings-get.'
			);
		}

		foreach ( [ 'siteurl', 'home', 'permalink_structure', 'active_plugins', 'example_plugin_settings' ] as $option ) {
			self::assertSame(
				[],
				ToolRouting::for_snippet( "update_option( '" . $option . "', 'x' );" ),
				$option . ' is not an allowlisted setting.'
			);
		}
	}

	public function test_a_disabled_typed_ability_is_never_suggested(): void {
		$GLOBALS['stonewright_test_options']['stonewright_disabled_abilities'] = [ 'stonewright/settings-update', 'stonewright/menu-create' ];

		self::assertSame( [], ToolRouting::for_snippet( "update_option( 'blogname', 'x' );" ) );
		self::assertSame( [], ToolRouting::for_snippet( "wp_create_nav_menu( 'Example Menu' );" ) );
		self::assertSame(
			[ 'options' => [ 'stonewright-settings-get' ] ],
			ToolRouting::for_task( 'Change the site tagline' )
		);
		self::assertSame(
			[ 'options' => [ 'stonewright-settings-get' ] ],
			ToolRouting::for_snippet( "update_option( 'blogname', 'x' ); return get_option( 'blogname' );" )
		);
	}

	public function test_the_hint_is_bounded_and_never_echoes_the_snippet(): void {
		$code = "update_option( 'blogname', 'ZZ-MARKER-VALUE' );\n"
			. "update_post_meta( 9912345, 'zz_marker_key', 'ZZ-MARKER-VALUE' );\n"
			. "\$raw = get_post_meta( 9912345, '_elementor_data', true );\n"
			. "wp_create_nav_menu( 'ZZ Marker Menu' );\n"
			. "wp_update_nav_menu_item( 9912345, 0, [ 'menu-item-title' => 'ZZ-MARKER-VALUE' ] );\n";

		$matches = ToolRouting::for_snippet( $code );
		$hint    = ToolRouting::hint( $matches, true );
		$encoded = (string) wp_json_encode( $hint );

		self::assertLessThanOrEqual( ToolRouting::MAX_PATTERNS, count( $hint['prefer'] ) );
		foreach ( $hint['prefer'] as $tools ) {
			self::assertLessThanOrEqual( ToolRouting::MAX_TOOLS_PER_PATTERN, count( $tools ) );
		}
		self::assertLessThan( 700, strlen( $encoded ) );
		self::assertLessThan( 200, strlen( $hint['note'] ) );
		foreach ( [ 'ZZ', 'marker', 'Marker', '9912345', 'blogname', '_elementor_data' ] as $leak ) {
			self::assertStringNotContainsString( $leak, $encoded, 'The hint must not repeat anything from the snippet.' );
		}
	}

	public function test_hint_shape_with_and_without_the_note(): void {
		$matches = [ 'menus' => [ 'stonewright-menu-list' ] ];

		self::assertSame( [], ToolRouting::hint( [], true ) );
		self::assertSame( [ 'prefer' => $matches ], ToolRouting::hint( $matches, false ) );

		$with_note = ToolRouting::hint( $matches, true );
		self::assertSame( $matches, $with_note['prefer'] );
		self::assertIsString( $with_note['note'] );
		self::assertStringContainsString( 'not blocked', $with_note['note'] );
		self::assertStringContainsString( 'stonewright-tool-profile', $with_note['note'] );
	}

	/**
	 * @return iterable<string, array{0:string,1:array<string,list<string>>}>
	 */
	public static function snippet_cases(): iterable {
		foreach ( self::read_fixture()['snippets'] as $case ) {
			yield (string) $case['name'] => [ (string) $case['code'], (array) $case['expect'] ];
		}
	}

	/**
	 * @return iterable<string, array{0:string,1:array<string,list<string>>}>
	 */
	public static function task_cases(): iterable {
		foreach ( self::read_fixture()['tasks'] as $case ) {
			yield (string) $case['name'] => [ (string) $case['task'], (array) $case['expect'] ];
		}
	}

	/**
	 * @return array{snippets:list<array<string,mixed>>,tasks:list<array<string,mixed>>}
	 */
	private static function read_fixture(): array {
		$decoded = json_decode( (string) file_get_contents( self::FIXTURE ), true );
		self::assertIsArray( $decoded );
		self::assertIsArray( $decoded['snippets'] ?? null );
		self::assertIsArray( $decoded['tasks'] ?? null );

		return $decoded;
	}
}
