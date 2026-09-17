<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\System\ToolProfile;
use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Core\McpAbilitiesCompatibilityPreflight;
use Stonewright\WpMcp\Core\McpRegistrationState;
use Stonewright\WpMcp\Core\ServerRegistration;

/**
 * @covers \Stonewright\WpMcp\Core\ServerRegistration
 * @covers \Stonewright\WpMcp\Core\AgentInstructions
 */
final class ServerRegistrationTest extends TestCase {

	protected function setUp(): void {
		$plugin_root = dirname( __DIR__, 3 );
		require_once $plugin_root . '/vendor/wordpress/abilities-api/includes/abilities-api/class-wp-ability.php';
		require_once $plugin_root . '/vendor/wordpress/abilities-api/includes/abilities-api/class-wp-abilities-registry.php';
		$GLOBALS['stonewright_test_actions'] = [];
		$GLOBALS['stonewright_test_filters'] = [];
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
		McpRegistrationState::reset_for_tests();
		ErrorReturningMcpAdapter::reset();
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
		self::assertContains( 'stonewright/elementor-provider-discovery', $tools );
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

	public function test_create_server_wp_error_is_recorded_as_failed(): void {
		$adapter = ErrorReturningMcpAdapter::returning(
			new \WP_Error( 'invalid_transport', 'The selected MCP transport contract is incompatible.' )
		);

		ServerRegistration::register_server( $adapter );

		$report = McpRegistrationState::report();
		$by_id = array_column( $report['servers'], null, 'server_id' );
		self::assertSame( 'failed', $by_id['stonewright']['state'] );
		self::assertSame( 'invalid_transport', $by_id['stonewright']['error_code'] );
		self::assertSame( 'The selected MCP transport contract is incompatible.', $by_id['stonewright']['message'] );
		self::assertSame( 'failed', $by_id['stonewright-oauth']['state'] );
	}

	public function test_duplicate_hook_with_existing_stonewright_identity_is_idempotent(): void {
		$adapter = new RegistryMcpAdapter();
		ServerRegistration::register_server( $adapter );
		ServerRegistration::register_server( $adapter );

		self::assertSame( 2, $adapter->create_calls, 'Second hook must not recreate already-registered Stonewright servers.' );
		$report = McpRegistrationState::report();
		$by_id = array_column( $report['servers'], null, 'server_id' );
		self::assertSame( 'registered', $by_id['stonewright']['state'] );
		self::assertSame( 'registered', $by_id['stonewright-oauth']['state'] );
	}

	public function test_occupied_server_id_is_not_overwritten(): void {
		$adapter = new RegistryMcpAdapter();
		$adapter->seed(
			'stonewright',
			(object) [
				'id'    => 'stonewright',
				'route' => 'other',
				'name'  => 'Other',
			]
		);

		ServerRegistration::register_server( $adapter );

		self::assertSame( 1, $adapter->create_calls );
		$report = McpRegistrationState::report();
		$by_id = array_column( $report['servers'], null, 'server_id' );
		self::assertSame( 'failed', $by_id['stonewright']['state'] );
		self::assertSame( 'duplicate_server_id', $by_id['stonewright']['error_code'] );
		self::assertSame( 'other', $adapter->get_server( 'stonewright' )->get_server_route() );
	}

	public function test_matching_id_on_a_foreign_namespace_is_not_stonewright_identity(): void {
		$adapter = new RegistryMcpAdapter();
		$adapter->seed(
			'stonewright',
			(object) [
				'id'        => 'stonewright',
				'route'     => 'stonewright',
				'name'      => 'Stonewright',
				'namespace' => 'other-mcp',
			]
		);

		ServerRegistration::register_server( $adapter );

		self::assertSame( 1, $adapter->create_calls );
		$report = McpRegistrationState::report();
		$by_id = array_column( $report['servers'], null, 'server_id' );
		self::assertSame( 'failed', $by_id['stonewright']['state'] );
		self::assertSame( 'duplicate_server_id', $by_id['stonewright']['error_code'] );
		self::assertSame( 'other-mcp', $adapter->get_server( 'stonewright' )->get_server_route_namespace() );
	}

	public function test_default_adapter_server_does_not_count_as_stonewright_registered(): void {
		$adapter = new RegistryMcpAdapter();
		$adapter->seed(
			'mcp-adapter-default-server',
			(object) [
				'id'    => 'mcp-adapter-default-server',
				'route' => 'mcp-adapter-default-server',
				'name'  => 'Default',
			]
		);

		$report = McpRegistrationState::report();
		self::assertSame( 'not_checked', $report['servers'][0]['state'] );

		ServerRegistration::register_server( $adapter );
		$report = McpRegistrationState::report();
		$by_id = array_column( $report['servers'], null, 'server_id' );
		self::assertSame( 'registered', $by_id['stonewright']['state'] );
		self::assertNotSame( 'mcp-adapter-default-server', $by_id['stonewright']['server_id'] );
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

	public function test_compatible_filter_decoy_cannot_authorize_a_hostile_runtime_adapter(): void {
		$GLOBALS['stonewright_test_filters']['stonewright_compatibility_class_names'] = static fn(): array => [
			'adapter'            => CapturingMcpAdapter::class,
			'abilities_registry' => RegistrationCompatibleRegistry::class,
			'ability'            => RegistrationCompatibleAbility::class,
		];
		$adapter = new HostileMcpAdapter();

		ServerRegistration::register_server( $adapter );

		self::assertSame( 0, $adapter->invocations );
		self::assertSame( HostileMcpAdapter::class, McpAbilitiesCompatibilityPreflight::current()['adapter']['class'] ?? null );
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

final class ErrorReturningMcpAdapter {
	public const VERSION = '0.6.1';

	private static ?\WP_Error $error = null;

	public static function instance(): self {
		return new self();
	}

	public static function returning( \WP_Error $error ): self {
		self::$error = $error;
		return new self();
	}

	public static function reset(): void {
		self::$error = null;
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
		unset( $id, $namespace, $route, $name, $description, $version, $transports, $error_handler, $observability_handler, $tools, $resources, $prompts, $configure );
		return self::$error ?? new \WP_Error( 'invalid_transport', 'The selected MCP transport contract is incompatible.' );
	}
}

final class FakeRegisteredMcpServer {
	public function __construct(
		private string $id,
		private string $route,
		private string $name,
		private string $namespace = 'mcp'
	) {}

	public function get_server_id(): string {
		return $this->id;
	}

	public function get_server_route(): string {
		return $this->route;
	}

	public function get_server_route_namespace(): string {
		return $this->namespace;
	}

	public function get_server_name(): string {
		return $this->name;
	}
}

final class RegistryMcpAdapter {
	public const VERSION = '0.6.1';

	public int $create_calls = 0;

	public static function instance(): self {
		return new self();
	}

	/** @var array<string, FakeRegisteredMcpServer> */
	private array $servers = [];

	public function seed( string $id, object $server ): void {
		$this->servers[ $id ] = new FakeRegisteredMcpServer(
			(string) ( $server->id ?? $id ),
			(string) ( $server->route ?? '' ),
			(string) ( $server->name ?? '' ),
			(string) ( $server->namespace ?? 'mcp' )
		);
	}

	public function get_server( string $id ): ?FakeRegisteredMcpServer {
		return $this->servers[ $id ] ?? null;
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
		++$this->create_calls;
		$this->servers[ $id ] = new FakeRegisteredMcpServer( $id, $route, $name, $namespace );
		unset( $description, $version, $transports, $error_handler, $observability_handler, $tools, $resources, $prompts, $configure );
		return $this;
	}
}

final class CapturingMcpAdapter {
	public const VERSION = '0.6.1';

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
