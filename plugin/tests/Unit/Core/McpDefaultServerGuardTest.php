<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Core\McpAbilitiesCompatibilityPreflight;
use Stonewright\WpMcp\Core\McpDefaultServerGuard;
use Stonewright\WpMcp\Core\PluginRegistration;

/**
 * The MCP adapter registers the three abilities of its default server on `wp_abilities_api_init`, a hook
 * it attaches when it initialises. When another part of the request has already made the Abilities API fire
 * that hook, those abilities can no longer be registered and the default server would be created with tools
 * that do not exist. The guard leaves that server out of such a request.
 *
 * @covers \Stonewright\WpMcp\Core\McpDefaultServerGuard
 * @covers \Stonewright\WpMcp\Core\PluginRegistration::maybe_boot_mcp_adapter
 */
final class McpDefaultServerGuardTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_filters']     = [];
		$GLOBALS['stonewright_test_actions']     = [];
		$GLOBALS['stonewright_test_did_actions'] = [];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_filters']     = [];
		$GLOBALS['stonewright_test_actions']     = [];
		$GLOBALS['stonewright_test_did_actions'] = [];
		McpAbilitiesCompatibilityPreflight::reset_for_tests();
	}

	public function test_the_default_server_is_created_while_its_abilities_can_still_be_registered(): void {
		self::assertTrue( McpDefaultServerGuard::permit( true ) );
	}

	public function test_the_default_server_is_left_out_once_the_abilities_hook_has_fired(): void {
		$GLOBALS['stonewright_test_did_actions']['wp_abilities_api_init'] = 1;

		self::assertFalse( McpDefaultServerGuard::permit( true ) );
	}

	public function test_a_site_that_turned_the_default_server_off_keeps_it_off(): void {
		self::assertFalse( McpDefaultServerGuard::permit( false ) );
	}

	public function test_booting_the_adapter_attaches_the_guard_to_the_adapter_filter(): void {
		$plugin_root = dirname( __DIR__, 3 );
		require_once $plugin_root . '/vendor/wordpress/abilities-api/includes/abilities-api/class-wp-ability.php';
		require_once $plugin_root . '/vendor/wordpress/abilities-api/includes/abilities-api/class-wp-abilities-registry.php';
		McpAbilitiesCompatibilityPreflight::reset_for_tests();

		PluginRegistration::maybe_boot_mcp_adapter();

		$guard = $GLOBALS['stonewright_test_filters']['mcp_adapter_create_default_server'] ?? null;
		self::assertSame( [ McpDefaultServerGuard::class, 'permit' ], $guard );
	}
}
