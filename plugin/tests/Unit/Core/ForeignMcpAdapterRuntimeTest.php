<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Core\McpAbilitiesCompatibilityPreflight;
use Stonewright\WpMcp\Core\McpRegistrationState;
use Stonewright\WpMcp\Core\McpRuntimeStatus;
use Stonewright\WpMcp\Core\ServerRegistration;

/**
 * Another plugin loads its own copy of the MCP adapter before Stonewright does. The copies are
 * synthetic stand-ins (fixtures `foreign-adapter-050` and `foreign-adapter-040`) loaded under the
 * canonical class name, as the plugin that shipped them would.
 *
 * Every test runs in its own process because the adapter class name can be defined once.
 *
 * @covers \Stonewright\WpMcp\Core\McpAbilitiesCompatibilityPreflight
 * @covers \Stonewright\WpMcp\Core\ServerRegistration
 * @covers \Stonewright\WpMcp\Core\McpRuntimeStatus
 */
final class ForeignMcpAdapterRuntimeTest extends TestCase {

	private const FOREIGN_FAMILY = [
		'Core/McpServer.php',
		'Transport/HttpTransport.php',
		'Infrastructure/ErrorHandling/Contracts/McpErrorHandlerInterface.php',
		'Infrastructure/Observability/Contracts/McpObservabilityHandlerInterface.php',
	];

	protected function tearDown(): void {
		McpAbilitiesCompatibilityPreflight::reset_for_tests();
		McpRegistrationState::reset_for_tests();
		unset( $GLOBALS['stonewright_test_extra_plugins'] );
	}

	private static function fixtures(): string {
		return dirname( __DIR__, 2 ) . '/fixtures/Compatibility';
	}

	/** Loads the Abilities API classes and makes the fixture directory the plugin directory with one active plugin. */
	private static function boot_site( string $plugin ): void {
		$plugin_root = dirname( __DIR__, 3 );
		require_once $plugin_root . '/vendor/wordpress/abilities-api/includes/abilities-api/class-wp-ability.php';
		require_once $plugin_root . '/vendor/wordpress/abilities-api/includes/abilities-api/class-wp-abilities-registry.php';
		if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
			define( 'WP_PLUGIN_DIR', self::fixtures() );
		}
		$GLOBALS['stonewright_test_options']                 = [
			'active_plugins'                          => [ $plugin . '/' . $plugin . '.php' ],
			'stonewright_enabled'                     => true,
			'stonewright_custom_instructions_enabled' => true,
			'stonewright_custom_instructions'         => '',
			'stonewright_disabled_abilities'          => [],
			'stonewright_essential_tools_mode'        => true,
			'stonewright_essential_extra_abilities'   => [],
			'stonewright_mcp_surface'                 => 'essential',
		];
		$GLOBALS['stonewright_test_extra_plugins']           = [ $plugin . '/' . $plugin . '.php' => [ 'Name' => 'Foreign SEO Suite', 'Version' => '2.0.0' ] ];
		$GLOBALS['stonewright_test_transients']              = [];
	}

	private static function load_foreign_adapter( string $fixture, bool $with_family ): void {
		$includes = self::fixtures() . '/' . $fixture . '/vendor/wordpress/mcp-adapter/includes';
		if ( $with_family ) {
			foreach ( self::FOREIGN_FAMILY as $file ) {
				require_once $includes . '/' . $file;
			}
			require_once self::fixtures() . '/' . $fixture . '/vendor/wordpress/php-mcp-schema/src/Common/McpConstants.php';
		}
		require_once $includes . '/Core/McpAdapter.php';
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_older_adapter_without_a_jetpack_manifest_is_used_when_it_offers_the_server_api(): void {
		self::boot_site( 'foreign-adapter-050' );
		self::load_foreign_adapter( 'foreign-adapter-050', true );

		$result = McpAbilitiesCompatibilityPreflight::inspect();

		self::assertTrue( $result['compatible'], (string) wp_json_encode( [ $result['blocking_reasons'], $result['adapter']['abi'] ] ) );
		self::assertSame( [], $result['adapter']['abi']['issues'] );
		self::assertSame( 'plugin:foreign-adapter-050', $result['adapter']['selected_owner'] );
		self::assertSame( '0.5.0', $result['adapter']['selected_version'] );
		self::assertTrue( $result['adapter']['foreign_runtime'] );
		self::assertContains( 'foreign_mcp_adapter', array_column( $result['warnings'], 'code' ) );
		self::assertStringNotContainsString( self::fixtures(), (string) wp_json_encode( $result ) );
		self::assertSame( 'foreign_adapter', McpRuntimeStatus::current()['state'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_stonewright_registers_both_servers_through_the_older_adapter_and_tools_list_answers(): void {
		self::boot_site( 'foreign-adapter-050' );
		self::load_foreign_adapter( 'foreign-adapter-050', true );
		$adapter = \WP\MCP\Core\McpAdapter::instance();

		ServerRegistration::register_server( $adapter );

		$servers = [];
		foreach ( McpRegistrationState::report()['servers'] as $row ) {
			$servers[ $row['server_id'] ] = $row;
		}
		self::assertSame( 'registered', $servers['stonewright']['state'], (string) wp_json_encode( $servers['stonewright'] ) );
		self::assertSame( 'registered', $servers['stonewright-oauth']['state'] );
		self::assertSame( '0.5.0', $servers['stonewright']['version'] );
		self::assertSame( 'plugin:foreign-adapter-050', $servers['stonewright']['owner'] );

		$route = $adapter->get_server( 'stonewright' );
		self::assertNotNull( $route );
		self::assertSame( 'mcp', $route->get_server_route_namespace() );
		self::assertSame( 'stonewright', $route->get_server_route() );
		$tools = $route->list_tool_names();
		self::assertContains( 'stonewright/task-start', $tools );
		self::assertContains( 'stonewright/site-info', $tools );
		self::assertCount( 30, $tools );
		self::assertNotNull( $adapter->get_server( 'stonewright-oauth' ) );
	}

	/**
	 * Another plugin may load only the adapter class itself first, and the rest of the family then
	 * comes from the copy Stonewright ships. That mixed runtime works, and is said to be mixed.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_older_adapter_class_with_the_rest_of_the_family_from_stonewrights_copy_is_accepted(): void {
		self::boot_site( 'foreign-adapter-050' );
		self::load_foreign_adapter( 'foreign-adapter-050', false );

		$result = McpAbilitiesCompatibilityPreflight::inspect();

		self::assertTrue( $result['compatible'], (string) wp_json_encode( [ $result['blocking_reasons'], $result['adapter']['abi'] ] ) );
		self::assertTrue( $result['adapter']['foreign_runtime'] );
		self::assertContains( 'mixed_adapter_family', array_column( $result['warnings'], 'code' ) );
		self::assertContains( 'foreign_mcp_adapter', array_column( $result['warnings'], 'code' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_adapter_whose_server_api_differs_is_blocked_with_its_owner_and_version(): void {
		self::boot_site( 'foreign-adapter-040' );
		self::load_foreign_adapter( 'foreign-adapter-040', false );

		$result = McpAbilitiesCompatibilityPreflight::inspect();

		self::assertFalse( $result['compatible'] );
		self::assertContains( 'adapter_abi_incompatible', $result['blocking_reasons'] );
		self::assertContains( 'incompatible_create_server_signature', $result['adapter']['abi']['issues'] );
		self::assertContains( 'unsupported_package_version', $result['adapter']['abi']['issues'] );
		self::assertSame( 'plugin:foreign-adapter-040', $result['adapter']['selected_owner'] );

		$status = McpRuntimeStatus::current();
		self::assertSame( 'blocked', $status['state'] );
		self::assertSame( 'Foreign SEO Suite', $status['adapter_plugin'] );
		self::assertSame( '0.4.0', $status['adapter_version'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_blocked_adapter_still_refuses_server_registration(): void {
		self::boot_site( 'foreign-adapter-040' );
		self::load_foreign_adapter( 'foreign-adapter-040', false );

		ServerRegistration::register_server( \WP\MCP\Core\McpAdapter::instance() );

		$states = array_column( McpRegistrationState::report()['servers'], 'state', 'server_id' );
		self::assertSame( 'blocked', $states['stonewright'] );
		self::assertSame( 'blocked', $states['stonewright-oauth'] );
	}
}
