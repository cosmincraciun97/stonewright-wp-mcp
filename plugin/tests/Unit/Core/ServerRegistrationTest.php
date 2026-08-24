<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\System\ToolProfile;
use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Core\McpAbilitiesCompatibilityPreflight;
use Stonewright\WpMcp\Core\ServerRegistration;

/**
 * @covers \Stonewright\WpMcp\Core\ServerRegistration
 * @covers \Stonewright\WpMcp\Core\AgentInstructions
 */
final class ServerRegistrationTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_actions'] = [];
		$GLOBALS['stonewright_test_filters'] = [
			'stonewright_compatibility_class_names' => static fn( array $classes ): array => [
				'adapter'            => $classes['adapter'],
				'abilities_registry' => RegistrationCompatibleRegistry::class,
				'ability'            => RegistrationCompatibleAbility::class,
			],
		];
		$GLOBALS['stonewright_test_options'] = [
			'stonewright_enabled'                      => true,
			'stonewright_custom_instructions_enabled'  => true,
			'stonewright_custom_instructions'          => '',
			'stonewright_disabled_abilities'           => [],
			'stonewright_essential_tools_mode'         => true,
			'stonewright_essential_extra_abilities'    => [],
			'stonewright_mcp_surface'                  => 'essential',
		];
		$GLOBALS['stonewright_test_transients'] = [];
	}

	protected function tearDown(): void {
		remove_all_actions( 'mcp_adapter_init' );
		McpAbilitiesCompatibilityPreflight::reset_for_tests();
		unset( $_SERVER['HTTP_MCP_SESSION_ID'] );
		$GLOBALS['stonewright_test_filters']    = [];
		$GLOBALS['stonewright_test_options']    = [];
		$GLOBALS['stonewright_test_transients'] = [];
	}

	public function test_register_server_uses_compact_startup_description_without_duplicate_custom_instructions(): void {
		$GLOBALS['stonewright_test_options']['stonewright_custom_instructions'] = 'Site rule unique.';

		$adapter = new CapturingMcpAdapter();

		ServerRegistration::register_server( $adapter );

		$description = $this->created_server_argument( $adapter, 4 );

		self::assertIsString( $description );
		self::assertStringContainsString( 'Stonewright fast start:', $description );
		self::assertStringContainsString( 'stonewright-context-bootstrap', $description );
		self::assertStringContainsString( 'stonewright-workflow-preflight', $description );
		self::assertStringContainsString( 'Site-specific instructions', $description );
		self::assertSame( 1, substr_count( $description, 'Site rule unique.' ) );
		self::assertStringNotContainsString( 'visual_build_gate', $description );
		// Compact bootstrap summary (task-start + Elementor integrity rules); keep under 3k.
		self::assertLessThan( 3000, strlen( $description ) );
	}

	public function test_register_server_exposes_only_current_public_tools(): void {
		$GLOBALS['stonewright_test_options']['stonewright_disabled_abilities'] = [
			'stonewright/wp-cli-run',
		];

		$adapter = new CapturingMcpAdapter();

		ServerRegistration::register_server( $adapter );

		$tools = $this->created_server_argument( $adapter, 9 );

		self::assertIsArray( $tools );
		self::assertContains( 'stonewright/context-bootstrap', $tools );
		self::assertContains( 'stonewright/elementor-v3-build-page-from-spec', $tools );
		self::assertContains( 'stonewright/wp-cli-batch-run', $tools );
		self::assertNotContains( 'stonewright/php-execute', $tools );
		self::assertContains( 'stonewright/security-issue-confirmation-token', $tools );
		self::assertNotContains( 'stonewright/wp-cli-run', $tools );
		self::assertNotContains( 'stonewright/elementor-v3-save-template', $tools );
		self::assertNotContains( 'stonewright/sandbox-write', $tools );
		self::assertContains( 'stonewright/design-direction-brief', $tools );
		self::assertCount( 30, $tools );
	}

	public function test_session_widened_surface_registers_union_tools(): void {
		$_SERVER['HTTP_MCP_SESSION_ID'] = 'server-registration-session-union';
		self::assertTrue(
			AbilityRegistry::set_session_tool_profile(
				'elementor-design',
				ToolProfile::profile_tools( 'elementor-design' )
			)
		);

		$adapter = new CapturingMcpAdapter();
		ServerRegistration::register_server( $adapter );

		$tools = $this->created_server_argument( $adapter, 9 );

		self::assertIsArray( $tools );
		self::assertGreaterThan( 30, count( $tools ) );
		self::assertContains( 'stonewright/site-pulse', $tools );
		self::assertContains( 'stonewright/elementor-v3-batch-mutate', $tools );
	}

	public function test_registers_separate_application_password_and_oauth_servers(): void {
		$adapter = new CapturingMcpAdapter();

		ServerRegistration::register_server( $adapter );

		self::assertCount( 2, $adapter->calls );
		self::assertSame( 'stonewright', $adapter->calls[0][0] );
		self::assertSame( 'stonewright', $adapter->calls[0][2] );
		self::assertSame( 'stonewright-oauth', $adapter->calls[1][0] );
		self::assertSame( 'stonewright-oauth', $adapter->calls[1][2] );
		self::assertSame( $adapter->calls[0][9], $adapter->calls[1][9] );
	}

	public function test_mcp_adapter_init_refuses_a_hostile_incompatible_adapter_before_server_invocation(): void {
		$GLOBALS['stonewright_test_filters']['stonewright_compatibility_class_names'] = static fn(): array => [
			'adapter'            => HostileMcpAdapter::class,
			'abilities_registry' => CompatibleRegistryFixture::class,
			'ability'            => CompatibleAbilityFixture::class,
		];
		$adapter = new HostileMcpAdapter();
		add_action( 'mcp_adapter_init', [ ServerRegistration::class, 'register_server' ], 20 );

		do_action( 'mcp_adapter_init', $adapter );

		self::assertSame( 0, $adapter->invocations );
	}

	/**
	 * @return mixed
	 */
	private function created_server_argument( CapturingMcpAdapter $adapter, int $index ) {
		self::assertNotEmpty( $adapter->calls );
		self::assertArrayHasKey( $index, $adapter->calls[0] );

		return $adapter->calls[0][ $index ];
	}
}

final class CapturingMcpAdapter {
	public const VERSION = '0.3.0';

	/**
	 * @var list<list<mixed>>
	 */
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
		?callable $configure = null
	) {
		$this->calls[] = func_get_args();
		unset( $id, $namespace, $route, $name, $description, $version, $transports, $error_handler, $observability_handler, $tools, $resources, $prompts, $configure );
	}
}

final class HostileMcpAdapter {

	public int $invocations = 0;

	public function create_server( mixed ...$args ): void {
		unset( $args );
		++$this->invocations;
	}
}

final class RegistrationCompatibleRegistry {

	private function __construct() {}

	public static function get_instance(): self {
		return new self();
	}

	public function register( string $name, array $properties = [] ): ?RegistrationCompatibleAbility {
		return new RegistrationCompatibleAbility( $name, $properties );
	}
}

final class RegistrationCompatibleAbility {

	public function __construct( private string $name, private array $properties ) {}

	public function get_name(): string {
		return $this->name;
	}

	public function get_label(): string {
		return $this->name;
	}

	public function get_description(): string {
		return $this->name;
	}

	public function get_meta(): array {
		return $this->properties;
	}

	public function get_input_schema(): array {
		return [];
	}

	public function get_output_schema(): array {
		return [];
	}
}
