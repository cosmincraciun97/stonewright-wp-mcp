<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization\WordPress;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Authorization\WordPress\ProtectedResource;
use Stonewright\WpMcp\Core\McpAbilitiesCompatibilityPreflight;
use Stonewright\WpMcp\Core\McpRegistrationState;
use Stonewright\WpMcp\Core\ServerRegistration;

/**
 * @covers \Stonewright\WpMcp\Core\ServerRegistration
 */
final class ProtectedServerRegistrationTest extends TestCase {

	protected function setUp(): void {
		$plugin_root = dirname( __DIR__, 4 );
		require_once $plugin_root . '/vendor/wordpress/abilities-api/includes/abilities-api/class-wp-ability.php';
		require_once $plugin_root . '/vendor/wordpress/abilities-api/includes/abilities-api/class-wp-abilities-registry.php';
		$GLOBALS['stonewright_test_actions'] = [];
		$GLOBALS['stonewright_test_filters'] = [];
		$GLOBALS['stonewright_test_options'] = [
			'stonewright_enabled'                     => true,
			'stonewright_custom_instructions_enabled' => true,
			'stonewright_custom_instructions'         => '',
			'stonewright_disabled_abilities'          => [],
			'stonewright_essential_tools_mode'        => true,
			'stonewright_essential_extra_abilities'   => [],
			'stonewright_mcp_surface'                 => 'essential',
		];
		$GLOBALS['stonewright_test_transients'] = [];
	}

	protected function tearDown(): void {
		McpAbilitiesCompatibilityPreflight::reset_for_tests();
		McpRegistrationState::reset_for_tests();
		$GLOBALS['stonewright_test_filters'] = [];
		$GLOBALS['stonewright_test_options'] = [];
		$GLOBALS['stonewright_test_transients'] = [];
	}

	public function test_only_the_oauth_server_admits_requests_through_the_bearer_check(): void {
		$adapter = new PermissionCapturingMcpAdapter();

		ServerRegistration::register_server( $adapter );

		self::assertCount( 2, $adapter->calls );
		self::assertSame( 'stonewright', $adapter->calls[0][0] );
		self::assertNull( $adapter->calls[0][12] ?? null );
		self::assertSame( 'stonewright-oauth', $adapter->calls[1][0] );
		self::assertSame( [ ProtectedResource::class, 'permit' ], $adapter->calls[1][12] );
		self::assertIsCallable( $adapter->calls[1][12] );
	}
}

final class PermissionCapturingMcpAdapter {
	public const VERSION = '0.6.1';

	/** @var list<list<mixed>> */
	public array $calls = [];

	public static function instance(): self {
		return new self();
	}

	public function create_server(
		string $id,
		string $namespace,
		string $route,
		string $name,
		string $description,
		string $version,
		array $transports,
		?string $error_handler,
		?string $observability_handler = null,
		array $tools = [],
		array $resources = [],
		array $prompts = [],
		?callable $transport_permission_callback = null
	) {
		$this->calls[] = func_get_args();
		unset( $id, $namespace, $route, $name, $description, $version, $transports, $error_handler, $observability_handler, $tools, $resources, $prompts, $transport_permission_callback );
	}
}
