<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Stonewright\WpMcp\Core\PluginRegistration;
use Stonewright\WpMcp\Core\SiteDefaults;
use Stonewright\WpMcp\Tests\Unit\Core\Fixtures\UninstallSite;

/**
 * The mode and surface a site starts with are written by one function, whichever path first
 * reaches the site: activation, a sub-site's first request after a network activation, a new
 * site, or an update.
 *
 * @covers \Stonewright\WpMcp\Core\SiteDefaults
 * @covers \Stonewright\WpMcp\Core\PluginRegistration::maybe_upgrade
 * @covers \Stonewright\WpMcp\Core\PluginRegistration::on_activate
 */
final class SiteDefaultsTest extends TestCase {

	private const PLUGIN = 'stonewright/stonewright.php';

	private string $log_file = '';
	private string|false $previous_log = false;

	protected function setUp(): void {
		$this->reset();
		// The harness has no database, so the table installs log their failure; keep that out of the test output.
		$this->log_file     = (string) tempnam( sys_get_temp_dir(), 'sw-site-defaults-' );
		$this->previous_log = ini_get( 'error_log' );
		ini_set( 'error_log', $this->log_file );
	}

	protected function tearDown(): void {
		ini_set( 'error_log', false === $this->previous_log ? '' : $this->previous_log );
		@unlink( $this->log_file );
		$this->reset();
		$GLOBALS['stonewright_test_scheduled_hooks'] = [];
	}

	private function reset(): void {
		$GLOBALS['stonewright_test_options']          = [];
		$GLOBALS['stonewright_test_actions']          = [];
		$GLOBALS['stonewright_test_transients']       = [];
		$GLOBALS['stonewright_test_site_options']     = [];
		$GLOBALS['stonewright_test_is_multisite']     = false;
		// Sites 1 (current) and 7 (the site a test creates); the fixture swaps the options of the current site on a switch.
		UninstallSite::reset( 1, 7 );
		$GLOBALS['stonewright_test_options'] = [];
		UninstallSite::switch_to( 7 );
		$GLOBALS['stonewright_test_options'] = [];
		UninstallSite::restore();
		UninstallSite::$log = [];
		unset( $GLOBALS['stonewright_test_environment_type'] );
	}

	private static function environment( string $type ): void {
		$GLOBALS['stonewright_test_environment_type'] = $type;
	}

	private static function option( string $name ): mixed {
		return get_option( $name, '__absent__' );
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function environments(): array {
		return [
			'production'  => [ 'production', 'production-safe' ],
			'staging'     => [ 'staging', 'staging' ],
			'development' => [ 'development', 'development' ],
			'local'       => [ 'local', 'development' ],
		];
	}

	/**
	 * @dataProvider environments
	 */
	public function test_activation_seeds_the_mode_and_the_first_activation_surface( string $environment, string $mode ): void {
		self::environment( $environment );

		$this->activate();

		self::assertSame( $mode, self::option( 'stonewright_mode' ) );
		self::assertSame( 'essential', self::option( 'stonewright_mcp_surface' ) );
		self::assertTrue( self::option( 'stonewright_essential_tools_mode' ) );
		self::assertSame( STONEWRIGHT_VERSION, self::option( 'stonewright_version' ) );
	}

	/**
	 * A sub-site of a network activation: the plugin ran its activation once, on the main site, and
	 * this site's first request is the first time the plugin touches it.
	 *
	 * @dataProvider environments
	 */
	public function test_a_subsites_first_request_seeds_the_same_defaults( string $environment, string $mode ): void {
		self::environment( $environment );
		$GLOBALS['stonewright_test_is_multisite'] = true;

		PluginRegistration::maybe_upgrade();

		self::assertSame( $mode, self::option( 'stonewright_mode' ) );
		self::assertSame( 'essential', self::option( 'stonewright_mcp_surface' ) );
		self::assertTrue( self::option( 'stonewright_essential_tools_mode' ) );
		self::assertSame( STONEWRIGHT_VERSION, self::option( 'stonewright_version' ) );
	}

	public function test_a_second_request_changes_nothing(): void {
		self::environment( 'production' );
		PluginRegistration::maybe_upgrade();
		update_option( 'stonewright_mode', 'staging' );
		update_option( 'stonewright_mcp_surface', 'full', false );

		PluginRegistration::maybe_upgrade();

		self::assertSame( 'staging', self::option( 'stonewright_mode' ) );
		self::assertSame( 'full', self::option( 'stonewright_mcp_surface' ) );
	}

	public function test_a_new_site_after_a_network_activation_is_seeded_without_touching_the_current_site(): void {
		self::environment( 'production' );
		$GLOBALS['stonewright_test_is_multisite']   = true;
		$GLOBALS['stonewright_test_site_options']['active_sitewide_plugins'] = [ self::PLUGIN => 1700000000 ];
		update_option( 'stonewright_mode', 'staging' );

		SiteDefaults::seed_new_site( (object) [ 'blog_id' => 7 ], self::PLUGIN );

		self::assertSame( [ 'switch:7', 'restore' ], UninstallSite::$log );
		self::assertSame( 1, UninstallSite::$current );
		self::assertSame( 'staging', self::option( 'stonewright_mode' ) );
		self::assertSame(
			[
				'stonewright_mode'                 => 'production-safe',
				'stonewright_mcp_surface'          => 'essential',
				'stonewright_essential_tools_mode' => true,
			],
			UninstallSite::options( 7 )
		);
	}

	public function test_a_new_site_is_left_alone_when_the_plugin_is_not_network_active(): void {
		self::environment( 'production' );
		$GLOBALS['stonewright_test_is_multisite'] = true;
		$GLOBALS['stonewright_test_site_options']['active_sitewide_plugins'] = [ 'other/other.php' => 1 ];

		SiteDefaults::seed_new_site( (object) [ 'blog_id' => 7 ], self::PLUGIN );

		self::assertSame( [], UninstallSite::$log );
		self::assertSame( [], UninstallSite::options( 7 ) );
	}

	public function test_a_new_site_first_request_keeps_what_the_new_site_hook_wrote(): void {
		self::environment( 'production' );
		$GLOBALS['stonewright_test_is_multisite'] = true;
		$GLOBALS['stonewright_test_site_options']['active_sitewide_plugins'] = [ self::PLUGIN => 1 ];
		SiteDefaults::seed_new_site( (object) [ 'blog_id' => 7 ], self::PLUGIN );

		self::environment( 'development' );
		UninstallSite::switch_to( 7 );
		PluginRegistration::maybe_upgrade();

		self::assertSame( 'production-safe', self::option( 'stonewright_mode' ) );
		self::assertSame( 'essential', self::option( 'stonewright_mcp_surface' ) );
		self::assertSame( STONEWRIGHT_VERSION, self::option( 'stonewright_version' ) );
		UninstallSite::restore();
	}

	public function test_existing_values_are_never_overwritten(): void {
		self::environment( 'production' );
		update_option( 'stonewright_mode', 'development' );
		update_option( 'stonewright_mcp_surface', 'full', false );
		update_option( 'stonewright_essential_tools_mode', false, false );

		PluginRegistration::maybe_upgrade();
		$this->activate();

		self::assertSame( 'development', self::option( 'stonewright_mode' ) );
		self::assertSame( 'full', self::option( 'stonewright_mcp_surface' ) );
		self::assertFalse( self::option( 'stonewright_essential_tools_mode' ) );
	}

	public function test_an_update_with_no_stored_mode_gets_the_environment_mode_and_no_surface(): void {
		self::environment( 'production' );
		$GLOBALS['stonewright_test_options']['stonewright_version'] = '0.0.0-before-update';

		PluginRegistration::maybe_upgrade();

		self::assertSame( 'production-safe', self::option( 'stonewright_mode' ) );
		self::assertSame( '__absent__', self::option( 'stonewright_mcp_surface' ) );
		self::assertSame( '__absent__', self::option( 'stonewright_essential_tools_mode' ) );
		self::assertSame( STONEWRIGHT_VERSION, self::option( 'stonewright_version' ) );
	}

	public function test_an_update_keeps_the_stored_mode(): void {
		self::environment( 'production' );
		$GLOBALS['stonewright_test_options']['stonewright_version'] = '0.0.0-before-update';
		$GLOBALS['stonewright_test_options']['stonewright_mode']    = 'development';

		PluginRegistration::maybe_upgrade();

		self::assertSame( 'development', self::option( 'stonewright_mode' ) );
	}

	public function test_activation_and_the_first_request_call_the_one_seeding_function(): void {
		$activate = self::method_source( 'on_activate' );
		$upgrade  = self::method_source( 'maybe_upgrade' );

		self::assertStringContainsString( 'SiteDefaults::seed(', $activate );
		self::assertStringContainsString( 'SiteDefaults::seed(', $upgrade );
		self::assertStringNotContainsString( 'wp_get_environment_type', $activate . $upgrade );
		self::assertStringNotContainsString( "update_option( 'stonewright_mode'", $activate . $upgrade );
		self::assertStringContainsString( "'wp_initialize_site'", self::method_source( 'register_hooks' ) );
	}

	private function activate(): void {
		$registration = ( new ReflectionClass( PluginRegistration::class ) )->newInstanceWithoutConstructor();
		$registration->on_activate();
	}

	private static function method_source( string $method ): string {
		$reflection = new ReflectionMethod( PluginRegistration::class, $method );
		$lines      = file( (string) $reflection->getFileName() );
		return implode( '', array_slice( (array) $lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1 ) );
	}
}
