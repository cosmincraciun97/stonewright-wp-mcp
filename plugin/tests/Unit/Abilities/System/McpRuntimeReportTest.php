<?php
// SPDX-License-Identifier: GPL-2.0-or-later
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Abilities\System;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Site\Capabilities;
use Stonewright\WpMcp\Abilities\System\TaskStart;
use Stonewright\WpMcp\Core\McpAbilitiesCompatibilityPreflight;
use Stonewright\WpMcp\Elementor\Provider\ProviderRouter;
use Stonewright\WpMcp\Elementor\Provider\UpstreamAbilityDiscovery;
use Stonewright\WpMcp\Security\IncidentStore;

/**
 * `stonewright-site-capabilities` and `stonewright-task-start` tell an agent which MCP adapter
 * serves it and, while none can, who owns the adapter that was loaded and what to do.
 *
 * @covers \Stonewright\WpMcp\Abilities\Site\Capabilities
 * @covers \Stonewright\WpMcp\Abilities\System\WorkflowPreflight
 */
final class McpRuntimeReportTest extends TestCase {

	protected function setUp(): void {
		IncidentStore::reset_for_tests();
		$GLOBALS['stonewright_test_transients']    = [];
		$GLOBALS['stonewright_test_extra_plugins'] = [ 'foreign-seo/foreign-seo.php' => [ 'Name' => 'Foreign SEO Suite', 'Version' => '2.0.0' ] ];
		$GLOBALS['stonewright_test_user_caps']     = [ 'read' => true, 'edit_posts' => true, 'manage_options' => true ];
		$GLOBALS['stonewright_test_options']       = [
			'stonewright_mode'                 => 'development',
			'stonewright_essential_tools_mode' => true,
			'stonewright_disabled_abilities'   => [],
		];
	}

	protected function tearDown(): void {
		IncidentStore::reset_for_tests();
		McpAbilitiesCompatibilityPreflight::reset_for_tests();
		unset( $GLOBALS['stonewright_test_extra_plugins'] );
		$GLOBALS['stonewright_test_options']    = [];
		$GLOBALS['stonewright_test_transients'] = [];
	}

	/** @param list<string> $blocking */
	private static function runtime( bool $compatible, array $blocking, string $owner, string $version, bool $foreign = false ): void {
		$property = new \ReflectionProperty( McpAbilitiesCompatibilityPreflight::class, 'current' );
		$property->setValue(
			null,
			[
				'compatible'       => $compatible,
				'adapter'          => [
					'selected_owner'   => $owner,
					'selected_version' => $version,
					'selection_state'  => 'selected',
					'foreign_runtime'  => $foreign,
					'owner_details'    => [ [ 'owner' => $owner, 'version' => $version, 'state' => 'autoloadable' ] ],
					'abi'              => [ 'status' => $compatible ? 'compatible' : 'incompatible', 'issues' => [] ],
				],
				'blocking_reasons' => $blocking,
			]
		);
	}

	private static function capabilities(): array {
		$path    = dirname( __DIR__, 3 ) . '/fixtures/elementor-native/elementor-4.3.4-abilities.json';
		$decoded = json_decode( (string) file_get_contents( $path ), true );
		$all     = array_values( (array) $decoded['abilities'] );
		$router  = new ProviderRouter(
			static fn(): array => [ 'document_architecture' => 'v4', 'write_target' => 'v4', 'write_blocked' => false ],
			static fn(): array => [],
			static fn(): array => [ 'items' => [], 'issues' => [] ],
			static fn(): array => $all,
			static fn(): array => UpstreamAbilityDiscovery::summarize_environment(
				[
					'elementor_version' => '4.3.4', 'module_present' => true, 'module_active' => true, 'abilities_api' => true,
					'mcp_adapter' => true, 'mcp_adapter_version' => '0.6.1', 'mcp_adapter_provider' => 'elementor-core',
					'mcp_composer' => true, 'mcp_composer_version' => '1.0.19', 'site_exposure_enabled' => true, 'atomic_editor_active' => true,
				]
			)
		);

		return ( new Capabilities( $router ) )->execute( [] );
	}

	private static function task_start( string $mode = 'compact' ): array {
		$start = ( new TaskStart() )->execute( [ 'task' => 'Update the site tagline', 'surface' => 'wordpress', 'intent' => 'write', 'responseMode' => $mode ] );
		self::assertIsArray( $start );

		return $start;
	}

	public function test_site_capabilities_names_the_plugin_version_and_remedy_while_the_route_is_blocked(): void {
		self::runtime( false, [ 'adapter_abi_incompatible' ], 'plugin:foreign-seo', '0.4.0' );

		$runtime = self::capabilities()['mcp_runtime'] ?? null;

		self::assertIsArray( $runtime );
		self::assertSame( 'blocked', $runtime['state'] );
		self::assertSame( 'Foreign SEO Suite', $runtime['adapter_plugin'] );
		self::assertSame( '0.4.0', $runtime['adapter_version'] );
		self::assertStringContainsString( 'MCP clients cannot connect', $runtime['summary'] );
		self::assertStringContainsString( 'Foreign SEO Suite', $runtime['remedy'] );
	}

	public function test_site_capabilities_always_says_which_state_the_runtime_is_in(): void {
		self::runtime( true, [], 'plugin:stonewright', '0.6.1' );
		self::assertSame( 'ok', self::capabilities()['mcp_runtime']['state'] );

		self::runtime( true, [], 'plugin:foreign-seo', '0.5.0', true );
		$runtime = self::capabilities()['mcp_runtime'];
		self::assertSame( 'foreign_adapter', $runtime['state'] );
		self::assertSame( 'Foreign SEO Suite', $runtime['adapter_plugin'] );
	}

	public function test_site_capabilities_declares_the_field_in_its_output_schema(): void {
		$schema = ( new Capabilities() )->output_schema();

		self::assertSame( 'object', $schema['properties']['mcp_runtime']['type'] );
		self::assertArrayHasKey( 'state', $schema['properties']['mcp_runtime']['properties'] );
		self::assertNotContains( 'mcp_runtime', $schema['required'], 'Older clients keep working: the field is optional.' );
	}

	public function test_task_start_carries_the_notice_while_the_route_is_blocked(): void {
		self::runtime( false, [ 'adapter_abi_incompatible' ], 'plugin:foreign-seo', '0.4.0' );

		foreach ( [ 'compact', 'full' ] as $mode ) {
			$runtime = self::task_start( $mode )['mcp_runtime'] ?? null;

			self::assertIsArray( $runtime, $mode );
			self::assertSame( 'blocked', $runtime['state'], $mode );
			self::assertSame( 'Foreign SEO Suite', $runtime['adapter_plugin'], $mode );
			self::assertSame( '0.4.0', $runtime['adapter_version'], $mode );
			self::assertArrayHasKey( 'remedy', $runtime, $mode );
		}
	}

	public function test_task_start_is_unchanged_while_an_adapter_serves_the_route(): void {
		self::runtime( true, [], 'plugin:stonewright', '0.6.1' );
		self::assertArrayNotHasKey( 'mcp_runtime', self::task_start() );

		self::runtime( true, [], 'plugin:foreign-seo', '0.5.0', true );
		self::assertArrayNotHasKey( 'mcp_runtime', self::task_start() );
		self::assertArrayNotHasKey( 'mcp_runtime', self::task_start( 'full' ) );
	}

	public function test_task_start_declares_the_field_in_its_output_schema(): void {
		$schema = ( new TaskStart() )->output_schema();

		self::assertSame( 'object', $schema['properties']['mcp_runtime']['type'] );
		self::assertNotContains( 'mcp_runtime', $schema['required'] );
	}
}
