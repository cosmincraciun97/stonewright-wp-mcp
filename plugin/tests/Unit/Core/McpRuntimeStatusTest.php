<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Core\McpAbilitiesCompatibilityPreflight;
use Stonewright\WpMcp\Core\McpRuntimeStatus;

/**
 * What an administrator or an agent is told about the MCP adapter that is loaded: nothing when
 * Stonewright's own copy runs, a note when another plugin's older copy is used, and a named owner,
 * version and remedy when no usable adapter is loaded.
 *
 * @covers \Stonewright\WpMcp\Core\McpRuntimeStatus
 */
final class McpRuntimeStatusTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_extra_plugins'] = [
			'foreign-seo/foreign-seo.php' => [ 'Name' => 'Foreign SEO Suite', 'Version' => '2.0.0' ],
		];
	}

	protected function tearDown(): void {
		unset( $GLOBALS['stonewright_test_extra_plugins'] );
		McpAbilitiesCompatibilityPreflight::reset_for_tests();
	}

	/** @return array<string,mixed> */
	private static function report( bool $compatible, string $owner, string $version, array $blocking = [], array $issues = [], bool $foreign = false ): array {
		return [
			'compatible'       => $compatible,
			'adapter'          => [
				'class'            => 'WP\\MCP\\Core\\McpAdapter',
				'status'           => 'loaded',
				'selected_owner'   => $owner,
				'selected_version' => $version,
				'selection_state'  => 'selected',
				'foreign_runtime'  => $foreign,
				'owner_details'    => [ [ 'owner' => $owner, 'version' => $version, 'state' => 'autoloadable' ] ],
				'abi'              => [ 'status' => $compatible ? 'compatible' : 'incompatible', 'issues' => $issues, 'version' => $version ],
			],
			'abilities'        => [ 'registry' => [ 'status' => 'loaded', 'abi' => [ 'status' => 'compatible', 'issues' => [] ] ], 'ability' => [ 'status' => 'loaded', 'abi' => [ 'status' => 'compatible', 'issues' => [] ] ] ],
			'blocking_reasons' => $blocking,
			'warnings'         => [],
		];
	}

	public function test_stonewrights_own_adapter_reports_ok_and_says_nothing(): void {
		$status = McpRuntimeStatus::from_report( self::report( true, 'plugin:stonewright', '0.6.1' ) );

		self::assertSame( 'ok', $status['state'] );
		self::assertSame( [], McpRuntimeStatus::agent_notice( $status ) );
	}

	public function test_another_plugins_older_adapter_that_works_is_reported_as_in_use_not_as_a_problem(): void {
		$status = McpRuntimeStatus::from_report( self::report( true, 'plugin:foreign-seo', '0.5.0', [], [], true ) );

		self::assertSame( 'foreign_adapter', $status['state'] );
		self::assertSame( 'Foreign SEO Suite', $status['adapter_plugin'] );
		self::assertSame( '0.5.0', $status['adapter_version'] );
		self::assertStringContainsString( 'Foreign SEO Suite', $status['summary'] );
		self::assertStringContainsString( '0.5.0', $status['summary'] );
		self::assertSame( [], McpRuntimeStatus::agent_notice( $status ), 'A working route needs no warning to the agent.' );
	}

	public function test_a_blocked_adapter_names_the_plugin_the_version_the_reason_and_what_to_do(): void {
		$status = McpRuntimeStatus::from_report(
			self::report( false, 'plugin:foreign-seo', '0.4.0', [ 'adapter_abi_incompatible' ], [ 'incompatible_create_server_signature', 'unsupported_package_version' ] )
		);

		self::assertSame( 'blocked', $status['state'] );
		self::assertSame( 'plugin:foreign-seo', $status['adapter_owner'] );
		self::assertSame( 'Foreign SEO Suite', $status['adapter_plugin'] );
		self::assertSame( '0.4.0', $status['adapter_version'] );
		self::assertSame( [ 'adapter_abi_incompatible' ], $status['blocking_reasons'] );
		self::assertStringContainsString( 'Foreign SEO Suite', $status['summary'] );
		self::assertStringContainsString( '0.4.0', $status['summary'] );
		self::assertStringContainsString( 'MCP clients cannot connect', $status['summary'] );
		self::assertStringContainsString( 'Foreign SEO Suite', $status['remedy'] );
		self::assertStringContainsString( '0.5.0 or newer', $status['remedy'] );
		self::assertStringContainsString( 'deactivate', $status['remedy'] );
	}

	public function test_agents_get_a_short_notice_only_while_the_route_is_blocked(): void {
		$status = McpRuntimeStatus::from_report( self::report( false, 'plugin:foreign-seo', '0.4.0', [ 'adapter_abi_incompatible' ] ) );
		$notice = McpRuntimeStatus::agent_notice( $status );

		self::assertSame( 'blocked', $notice['state'] );
		self::assertSame( 'Foreign SEO Suite', $notice['adapter_plugin'] );
		self::assertSame( '0.4.0', $notice['adapter_version'] );
		self::assertArrayHasKey( 'summary', $notice );
		self::assertArrayHasKey( 'remedy', $notice );
		self::assertLessThan( 900, strlen( (string) wp_json_encode( $notice ) ) );
	}

	public function test_an_owner_that_is_not_a_plugin_directory_is_described_without_inventing_a_name(): void {
		$status = McpRuntimeStatus::from_report( self::report( false, 'package:wordpress/mcp-adapter', '', [ 'adapter_abi_incompatible' ] ) );

		self::assertSame( 'blocked', $status['state'] );
		self::assertSame( '', $status['adapter_plugin'] );
		self::assertStringContainsString( 'another plugin, theme or must-use plugin', $status['summary'] );
		self::assertStringContainsString( 'unknown', $status['summary'] );
	}

	public function test_an_unlisted_plugin_directory_falls_back_to_its_folder_name(): void {
		$status = McpRuntimeStatus::from_report( self::report( false, 'plugin:not-installed-seo', '0.3.0', [ 'adapter_abi_incompatible' ] ) );

		self::assertSame( 'not-installed-seo', $status['adapter_plugin'] );
		self::assertStringContainsString( 'not-installed-seo', $status['summary'] );
	}

	public function test_an_abilities_api_conflict_is_reported_with_its_owners_not_as_an_adapter_problem(): void {
		$report                                           = self::report( false, 'plugin:stonewright', '0.6.1', [ 'abilities_registry_multiple_owners' ] );
		$report['abilities']['registry']                  = [
			'class'         => 'WP_Abilities_Registry',
			'status'        => 'conflict',
			'owner_details' => [
				[ 'owner' => 'plugin:foreign-seo', 'version' => '0.1.0', 'state' => 'autoloadable' ],
				[ 'owner' => 'plugin:stonewright', 'version' => '0.1.1', 'state' => 'autoloadable' ],
			],
			'abi'           => [ 'status' => 'not_checked', 'issues' => [ 'multiple_owners' ] ],
		];
		$report['adapter']['abi']['status']               = 'compatible';

		$status = McpRuntimeStatus::from_report( $report );

		self::assertSame( 'blocked', $status['state'] );
		self::assertStringContainsString( 'Foreign SEO Suite', $status['summary'] );
		self::assertStringContainsString( 'Abilities API', $status['summary'] );
		self::assertStringNotContainsString( 'copy of the WordPress MCP Adapter', $status['summary'] );
	}

	public function test_the_text_carries_no_filesystem_path_and_no_markup(): void {
		$GLOBALS['stonewright_test_extra_plugins']['foreign-seo/foreign-seo.php']['Name'] = '<b>Foreign</b> SEO';
		$status  = McpRuntimeStatus::from_report( self::report( false, 'plugin:foreign-seo', '0.4.0', [ 'adapter_abi_incompatible' ] ) );
		$encoded = (string) wp_json_encode( $status );

		self::assertStringNotContainsString( '<b>', $encoded );
		self::assertStringNotContainsString( '/wp-content/', $encoded );
	}

	public function test_current_reads_the_cached_preflight(): void {
		$property = new \ReflectionProperty( McpAbilitiesCompatibilityPreflight::class, 'current' );
		$property->setValue( null, self::report( false, 'plugin:foreign-seo', '0.4.0', [ 'adapter_abi_incompatible' ] ) );

		self::assertSame( 'blocked', McpRuntimeStatus::current()['state'] );
	}
}
