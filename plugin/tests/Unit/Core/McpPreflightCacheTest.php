<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Stonewright\WpMcp\Core\McpAbilitiesCompatibilityPreflight;
use Stonewright\WpMcp\Core\PluginRegistration;
use Stonewright\WpMcp\Core\ServerRegistration;

/**
 * The compatibility preflight is inspected once per plugin set, not on every request.
 *
 * @covers \Stonewright\WpMcp\Core\McpAbilitiesCompatibilityPreflight
 */
final class McpPreflightCacheTest extends TestCase {

	private const OPTION = 'stonewright_mcp_preflight';

	/** @var int How many times the inspector ran. */
	private int $inspections = 0;

	protected function setUp(): void {
		McpAbilitiesCompatibilityPreflight::reset_for_tests();
		$GLOBALS['stonewright_test_options']          = [ 'active_plugins' => [ 'stonewright/stonewright.php' ] ];
		$GLOBALS['stonewright_test_site_options']     = [];
		$GLOBALS['stonewright_test_option_autoload']  = [];
		$GLOBALS['stonewright_test_transients']       = [];
		$GLOBALS['stonewright_test_actions']          = [];
		$this->inspections                            = 0;
	}

	protected function tearDown(): void {
		McpAbilitiesCompatibilityPreflight::reset_for_tests();
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_site_options']    = [];
		$GLOBALS['stonewright_test_option_autoload'] = [];
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_actions']         = [];
	}

	/** A report the inspector returns; the count of inspections tells how often the cache missed. */
	private function use_inspector( bool $compatible ): void {
		McpAbilitiesCompatibilityPreflight::set_inspector(
			function () use ( $compatible ): array {
				++$this->inspections;
				return [
					'compatible'       => $compatible,
					'adapter'          => [ 'class' => 'WP\\MCP\\Core\\McpAdapter', 'selected_owner' => 'plugin:stonewright' ],
					'abilities'        => [ 'registry' => [], 'ability' => [] ],
					'blocking_reasons' => $compatible ? [] : [ 'adapter_unavailable' ],
					'warnings'         => [],
					'remediation'      => '',
				];
			}
		);
	}

	/** What the next request starts with: no report in memory, whatever is stored stays. */
	private function new_request(): void {
		( new ReflectionProperty( McpAbilitiesCompatibilityPreflight::class, 'current' ) )->setValue( null, null );
	}

	public function test_second_call_within_ttl_reuses_report_without_inspecting(): void {
		$this->use_inspector( true );

		$first = McpAbilitiesCompatibilityPreflight::cached();
		$this->new_request();
		$second = McpAbilitiesCompatibilityPreflight::cached();

		self::assertSame( 1, $this->inspections );
		self::assertTrue( $first['compatible'] );
		self::assertSame( $first, $second );
		self::assertSame( $second, McpAbilitiesCompatibilityPreflight::current(), 'the reused report is what current() answers' );
	}

	public function test_a_report_already_held_in_the_request_is_returned_without_reading_the_option(): void {
		$this->use_inspector( true );
		McpAbilitiesCompatibilityPreflight::cached();
		unset( $GLOBALS['stonewright_test_options'][ self::OPTION ] );

		McpAbilitiesCompatibilityPreflight::cached();

		self::assertSame( 1, $this->inspections );
		self::assertArrayNotHasKey( self::OPTION, $GLOBALS['stonewright_test_options'], 'nothing is stored again' );
	}

	public function test_the_stored_row_carries_fingerprint_report_and_time(): void {
		$this->use_inspector( true );
		$before = time();

		McpAbilitiesCompatibilityPreflight::cached();

		$stored = $GLOBALS['stonewright_test_options'][ self::OPTION ] ?? null;
		self::assertIsArray( $stored );
		self::assertSame( McpAbilitiesCompatibilityPreflight::fingerprint(), $stored['fingerprint'] );
		self::assertTrue( $stored['report']['compatible'] );
		self::assertGreaterThanOrEqual( $before, $stored['at'] );
		self::assertLessThanOrEqual( time(), $stored['at'] );
	}

	public function test_fingerprint_change_reinspects(): void {
		$this->use_inspector( true );
		McpAbilitiesCompatibilityPreflight::cached();

		$GLOBALS['stonewright_test_options']['active_plugins'][] = 'another-plugin/another-plugin.php';
		$this->new_request();
		McpAbilitiesCompatibilityPreflight::cached();

		self::assertSame( 2, $this->inspections );
		self::assertSame( McpAbilitiesCompatibilityPreflight::fingerprint(), $GLOBALS['stonewright_test_options'][ self::OPTION ]['fingerprint'], 'the new plugin set is stored' );
	}

	public function test_a_network_activated_plugin_changes_the_fingerprint(): void {
		$before = McpAbilitiesCompatibilityPreflight::fingerprint();

		$GLOBALS['stonewright_test_site_options']['active_sitewide_plugins'] = [ 'network-plugin/network-plugin.php' => 1700000000 ];

		self::assertNotSame( $before, McpAbilitiesCompatibilityPreflight::fingerprint() );
	}

	public function test_activation_time_of_a_network_plugin_does_not_change_the_fingerprint(): void {
		$GLOBALS['stonewright_test_site_options']['active_sitewide_plugins'] = [ 'network-plugin/network-plugin.php' => 1700000000 ];
		$before                                                              = McpAbilitiesCompatibilityPreflight::fingerprint();

		$GLOBALS['stonewright_test_site_options']['active_sitewide_plugins'] = [ 'network-plugin/network-plugin.php' => 1800000000 ];

		self::assertSame( $before, McpAbilitiesCompatibilityPreflight::fingerprint(), 'only the keys count' );
	}

	public function test_the_plugin_version_is_part_of_the_fingerprint(): void {
		$method = new ReflectionMethod( McpAbilitiesCompatibilityPreflight::class, 'fingerprint' );
		$lines  = file( (string) $method->getFileName() );
		self::assertIsArray( $lines );
		$body = implode( '', array_slice( $lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1 ) );

		// An upgrade changes STONEWRIGHT_VERSION, so the report stored by the earlier version is never reused.
		self::assertStringContainsString( 'STONEWRIGHT_VERSION', $body );
		self::assertStringContainsString( 'installed.json', $body );
	}

	public function test_a_report_older_than_the_ttl_is_inspected_again(): void {
		$this->use_inspector( true );
		McpAbilitiesCompatibilityPreflight::cached();
		$GLOBALS['stonewright_test_options'][ self::OPTION ]['at'] = time() - McpAbilitiesCompatibilityPreflight::TTL - 1;

		$this->new_request();
		McpAbilitiesCompatibilityPreflight::cached();

		self::assertSame( 2, $this->inspections );
	}

	public function test_a_report_just_inside_the_ttl_is_reused(): void {
		$this->use_inspector( true );
		McpAbilitiesCompatibilityPreflight::cached();
		$GLOBALS['stonewright_test_options'][ self::OPTION ]['at'] = time() - McpAbilitiesCompatibilityPreflight::TTL + 60;

		$this->new_request();
		McpAbilitiesCompatibilityPreflight::cached();

		self::assertSame( 1, $this->inspections );
	}

	public function test_the_ttl_is_one_hour(): void {
		self::assertSame( HOUR_IN_SECONDS, McpAbilitiesCompatibilityPreflight::TTL );
	}

	public function test_incompatible_report_is_not_reused(): void {
		$this->use_inspector( false );

		$first = McpAbilitiesCompatibilityPreflight::cached();
		$this->new_request();
		$second = McpAbilitiesCompatibilityPreflight::cached();

		self::assertFalse( $first['compatible'] );
		self::assertFalse( $second['compatible'] );
		self::assertSame( 2, $this->inspections, 'a broken site is inspected again on the next request' );
	}

	public function test_an_incompatible_report_is_not_stored_and_does_not_leave_a_stale_compatible_one(): void {
		$GLOBALS['stonewright_test_options'][ self::OPTION ] = [
			'fingerprint' => 'an-earlier-plugin-set',
			'report'      => [ 'compatible' => true ],
			'at'          => time(),
		];
		$this->use_inspector( false );

		McpAbilitiesCompatibilityPreflight::cached();

		self::assertArrayNotHasKey( self::OPTION, $GLOBALS['stonewright_test_options'] );
	}

	public function test_a_stored_incompatible_report_is_never_reused(): void {
		$this->use_inspector( true );
		$GLOBALS['stonewright_test_options'][ self::OPTION ] = [
			'fingerprint' => McpAbilitiesCompatibilityPreflight::fingerprint(),
			'report'      => [ 'compatible' => false ],
			'at'          => time(),
		];

		$report = McpAbilitiesCompatibilityPreflight::cached();

		self::assertSame( 1, $this->inspections );
		self::assertTrue( $report['compatible'] );
	}

	/** @dataProvider malformed_rows */
	public function test_a_malformed_stored_row_is_inspected_again( mixed $row ): void {
		$this->use_inspector( true );
		$GLOBALS['stonewright_test_options'][ self::OPTION ] = $row;

		$report = McpAbilitiesCompatibilityPreflight::cached();

		self::assertSame( 1, $this->inspections );
		self::assertTrue( $report['compatible'] );
	}

	/** @return array<string, array{mixed}> */
	public static function malformed_rows(): array {
		return [
			'a string'          => [ 'broken' ],
			'no report'         => [ [ 'fingerprint' => 'x', 'at' => time() ] ],
			'report not array'  => [ [ 'fingerprint' => 'x', 'report' => 'yes', 'at' => time() ] ],
			'no time'           => [ [ 'fingerprint' => 'x', 'report' => [ 'compatible' => true ] ] ],
			'a time in future'  => [ [ 'fingerprint' => 'x', 'report' => [ 'compatible' => true ], 'at' => time() + 86400 ] ],
		];
	}

	public function test_forget_clears_the_cached_report(): void {
		$this->use_inspector( true );
		McpAbilitiesCompatibilityPreflight::cached();
		self::assertArrayHasKey( self::OPTION, $GLOBALS['stonewright_test_options'] );

		McpAbilitiesCompatibilityPreflight::forget();
		$this->new_request();
		McpAbilitiesCompatibilityPreflight::cached();

		self::assertSame( 2, $this->inspections );
	}

	public function test_forget_deletes_the_option(): void {
		$GLOBALS['stonewright_test_options'][ self::OPTION ] = [ 'fingerprint' => 'x', 'report' => [], 'at' => 1 ];

		McpAbilitiesCompatibilityPreflight::forget();

		self::assertArrayNotHasKey( self::OPTION, $GLOBALS['stonewright_test_options'] );
	}

	public function test_cache_is_an_autoloaded_option_not_a_transient(): void {
		$this->use_inspector( true );

		McpAbilitiesCompatibilityPreflight::cached();

		self::assertTrue( $GLOBALS['stonewright_test_option_autoload'][ self::OPTION ] ?? null, 'stored with autoload true: no query of its own on a cold cache' );
		self::assertSame( [], $GLOBALS['stonewright_test_transients'], 'a transient would cost a query per request without an object cache' );
	}

	public function test_a_plugin_or_theme_change_forgets_the_report(): void {
		McpAbilitiesCompatibilityPreflight::register();

		foreach ( [ 'activated_plugin', 'deactivated_plugin', 'upgrader_process_complete', 'switch_theme' ] as $hook ) {
			$GLOBALS['stonewright_test_options'][ self::OPTION ] = [ 'fingerprint' => 'x', 'report' => [ 'compatible' => true ], 'at' => time() ];

			do_action( $hook, 'some/plugin.php', false );

			self::assertArrayNotHasKey( self::OPTION, $GLOBALS['stonewright_test_options'], $hook . ' forgets the report' );
		}
	}

	public function test_plugin_registration_registers_the_forget_hooks(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/includes/Core/PluginRegistration.php' );

		self::assertStringContainsString( 'McpAbilitiesCompatibilityPreflight::register()', $source );
	}

	public function test_the_boot_path_uses_the_cached_report(): void {
		$method = new ReflectionMethod( PluginRegistration::class, 'maybe_boot_mcp_adapter' );
		$lines  = file( (string) $method->getFileName() );
		self::assertIsArray( $lines );
		$body = implode( '', array_slice( $lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1 ) );

		self::assertStringContainsString( 'McpAbilitiesCompatibilityPreflight::cached()', $body );
		self::assertStringNotContainsString( 'McpAbilitiesCompatibilityPreflight::inspect()', $body );
	}

	public function test_server_registration_reuses_the_report_for_the_canonical_adapter_class_only(): void {
		$method = new ReflectionMethod( ServerRegistration::class, 'register_server' );
		$lines  = file( (string) $method->getFileName() );
		self::assertIsArray( $lines );
		$body = implode( '', array_slice( $lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1 ) );

		self::assertStringContainsString( 'McpAbilitiesCompatibilityPreflight::cached()', $body );
		self::assertStringContainsString( 'McpAbilitiesCompatibilityPreflight::inspect( null, $adapter_class )', $body, 'any other adapter class is still inspected' );
	}

	public function test_the_diagnostics_screens_ask_for_a_fresh_report(): void {
		foreach ( [ '/includes/Admin/SetupDiagnostics.php', '/includes/Admin/Pages/TroubleshootPage.php' ] as $file ) {
			$source = (string) file_get_contents( dirname( __DIR__, 3 ) . $file );

			self::assertStringContainsString( 'McpAbilitiesCompatibilityPreflight::fresh()', $source, $file );
			self::assertStringNotContainsString( 'McpAbilitiesCompatibilityPreflight::current() ??', $source, $file );
		}
	}

	public function test_fresh_returns_the_report_this_request_inspected(): void {
		$this->use_inspector( true );
		McpAbilitiesCompatibilityPreflight::cached();
		self::assertSame( 1, $this->inspections );

		McpAbilitiesCompatibilityPreflight::fresh();

		self::assertSame( 1, $this->inspections );
	}

	public function test_fresh_inspects_again_when_the_report_came_from_the_cache(): void {
		$this->use_inspector( true );
		McpAbilitiesCompatibilityPreflight::cached();
		$this->new_request();
		McpAbilitiesCompatibilityPreflight::cached();
		self::assertSame( 1, $this->inspections );

		McpAbilitiesCompatibilityPreflight::fresh();

		self::assertSame( 2, $this->inspections, 'an administrator running the diagnostics sees the site as it is now' );
	}

	public function test_fresh_replaces_a_stored_compatible_report_that_is_no_longer_true(): void {
		$this->use_inspector( true );
		McpAbilitiesCompatibilityPreflight::cached();
		$this->new_request();
		McpAbilitiesCompatibilityPreflight::cached();
		$this->use_inspector( false );

		$report = McpAbilitiesCompatibilityPreflight::fresh();

		self::assertFalse( $report['compatible'] );
		self::assertArrayNotHasKey( self::OPTION, $GLOBALS['stonewright_test_options'], 'the next request does not trust the old answer' );
	}

	public function test_a_report_injected_into_the_request_is_what_fresh_returns(): void {
		$report = [ 'compatible' => true, 'adapter' => [ 'selected_owner' => 'plugin:injected' ], 'blocking_reasons' => [] ];
		( new ReflectionProperty( McpAbilitiesCompatibilityPreflight::class, 'current' ) )->setValue( null, $report );

		self::assertSame( $report, McpAbilitiesCompatibilityPreflight::fresh() );
	}

	public function test_without_a_seam_the_real_inspection_runs(): void {
		$report = McpAbilitiesCompatibilityPreflight::cached();

		self::assertArrayHasKey( 'compatible', $report );
		self::assertArrayHasKey( 'adapter', $report );
	}
}
