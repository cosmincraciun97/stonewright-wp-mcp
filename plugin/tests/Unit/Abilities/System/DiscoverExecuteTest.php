<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Abilities\System;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\System\DiscoverAbilities;
use Stonewright\WpMcp\Abilities\System\ExecuteAbility;
use Stonewright\WpMcp\Abilities\System\GetAbilityInfo;
use Stonewright\WpMcp\Abilities\System\ToolProfile;
use Stonewright\WpMcp\Context\ContextToken;
use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Security\IncidentStore;
use Stonewright\WpMcp\Tests\Unit\Core\Fixtures\RegisteredAbilityRig;

/**
 * @covers \Stonewright\WpMcp\Abilities\System\DiscoverAbilities
 * @covers \Stonewright\WpMcp\Abilities\System\GetAbilityInfo
 * @covers \Stonewright\WpMcp\Abilities\System\ExecuteAbility
 * @covers \Stonewright\WpMcp\Abilities\System\ToolProfile
 */
final class DiscoverExecuteTest extends TestCase {
	use RegisteredAbilityRig;

	protected function setUp(): void {
		IncidentStore::reset_for_tests();
		$GLOBALS['stonewright_test_options'] = [
			'stonewright_disabled_abilities'   => [],
			'stonewright_essential_tools_mode' => true,
			'stonewright_mcp_surface'          => 'essential',
			'stonewright_mode'                 => 'development',
			'stonewright_enabled'              => true,
		];
		$GLOBALS['stonewright_test_user_caps'] = [
			'read'           => true,
			'edit_posts'     => true,
			'manage_options' => true,
			'edit_plugins'   => true,
		];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 11;
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_rest_validator']  = self::validator();
		unset( $_SERVER['HTTP_MCP_SESSION_ID'] );
	}

	protected function tearDown(): void {
		IncidentStore::reset_for_tests();
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
		$GLOBALS['stonewright_test_transients']      = [];
		unset( $GLOBALS['stonewright_test_current_user_id'], $GLOBALS['stonewright_test_rest_validator'], $_SERVER['HTTP_MCP_SESSION_ID'] );
	}

	public function test_discover_execute_profile_exposes_protocol_tools_not_the_catalog(): void {
		self::assertContains( 'discover-execute', ToolProfile::profile_names() );

		$names = ToolProfile::profile_tools( 'discover-execute' );
		self::assertContains( 'stonewright/task-start', $names );
		self::assertContains( 'stonewright/tool-profile', $names );
		self::assertContains( 'stonewright/discover-abilities', $names );
		self::assertContains( 'stonewright/get-ability-info', $names );
		self::assertContains( 'stonewright/execute-ability', $names );
		self::assertNotContains( 'stonewright/php-execute', $names );
		self::assertLessThanOrEqual( 16, count( $names ) );

		$ability = new ToolProfile();
		$result  = $ability->execute(
			[
				'action'  => 'resolve',
				'profile' => 'discover-execute',
			]
		);
		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( 'discover-execute', $result['profile'] );
		self::assertContains( 'stonewright-discover-abilities', $result['tools'] );
		self::assertContains( 'stonewright-execute-ability', $result['tools'] );
		self::assertNotContains( 'stonewright-php-execute', $result['tools'] );
		self::assertLessThan( 40, count( $result['tools'] ) );
	}

	public function test_discover_abilities_returns_compact_rows_without_schemas(): void {
		$result = ( new DiscoverAbilities() )->execute( [] );

		self::assertIsArray( $result );
		self::assertArrayHasKey( 'abilities', $result );
		self::assertArrayHasKey( 'count', $result );
		self::assertNotEmpty( $result['abilities'] );

		$row = $result['abilities'][0];
		self::assertArrayHasKey( 'name', $row );
		self::assertArrayHasKey( 'mcp_tool_name', $row );
		self::assertArrayHasKey( 'label', $row );
		self::assertArrayHasKey( 'description', $row );
		self::assertArrayHasKey( 'category', $row );
		self::assertArrayHasKey( 'enabled', $row );
		self::assertArrayNotHasKey( 'input_schema', $row );
		self::assertLessThanOrEqual( 240, mb_strlen( (string) $row['description'] ) );
	}

	public function test_get_ability_info_bounds_schema_and_includes_gate_notes(): void {
		$result = ( new GetAbilityInfo() )->execute( [ 'name' => 'stonewright/php-execute' ] );

		self::assertIsArray( $result );
		self::assertSame( 'stonewright/php-execute', $result['name'] );
		self::assertSame( 'stonewright-php-execute', $result['mcp_tool_name'] );
		self::assertTrue( $result['enabled'] );
		self::assertArrayHasKey( 'input_schema', $result );
		self::assertArrayHasKey( 'output_schema', $result );
		self::assertArrayHasKey( 'permission_notes', $result );
		self::assertIsString( $result['permission_notes'] );
		self::assertStringContainsString( 'permission', strtolower( (string) $result['permission_notes'] ) );
		self::assertStringContainsString( 'production-safe', strtolower( (string) $result['permission_notes'] ) );
		self::assertTrue( $result['has_confirmation_token'] );
		self::assertLessThanOrEqual( 4, $this->schema_depth( $result['input_schema'] ) );
	}

	public function test_get_ability_info_returns_schemas_that_encode_empty_nodes_as_objects(): void {
		$ping = ( new GetAbilityInfo() )->execute( [ 'name' => 'stonewright/ping' ] );

		self::assertIsArray( $ping );
		self::assertStringContainsString( '"properties":{}', (string) json_encode( $ping['input_schema'] ) );
	}

	public function test_execute_ability_runs_ping_through_context_guard(): void {
		$result = ( new ExecuteAbility() )->execute(
			[
				'name'      => 'stonewright/ping',
				'arguments' => [],
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( 'stonewright/ping', $result['ability'] );
		self::assertIsArray( $result['result'] );
		self::assertTrue( $result['result']['ok'] );
		self::assertSame( 'pong', $result['result']['message'] );
	}

	public function test_execute_ability_accepts_hyphenated_mcp_tool_name(): void {
		$result = ( new ExecuteAbility() )->execute(
			[
				'name'      => 'stonewright-ping',
				'arguments' => [],
			]
		);

		self::assertIsArray( $result );
		self::assertSame( 'stonewright/ping', $result['ability'] );
		self::assertSame( 'pong', $result['result']['message'] );
	}

	public function test_execute_ability_rejects_disabled_abilities(): void {
		$GLOBALS['stonewright_test_options']['stonewright_disabled_abilities'] = [ 'stonewright/ping' ];

		$result = ( new ExecuteAbility() )->execute(
			[
				'name'      => 'stonewright/ping',
				'arguments' => [],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_ability_disabled', $result->get_error_code() );
	}

	public function test_execute_ability_enforces_target_permission(): void {
		$GLOBALS['stonewright_test_user_caps']['manage_options'] = false;

		$result = ( new ExecuteAbility() )->execute(
			[
				'name'      => 'stonewright/system-instructions-set',
				'arguments' => [
					'text' => 'Changed instructions',
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_ability_forbidden', $result->get_error_code() );
	}

	public function test_execute_ability_reuses_context_guard_for_writes(): void {
		$result = ( new ExecuteAbility() )->execute(
			[
				'name'      => 'stonewright/content-create-page',
				'arguments' => [
					'title' => 'Protocol page',
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_context_required', $result->get_error_code() );
	}

	public function test_execute_ability_refuses_php_execute_on_the_essential_surface(): void {
		$issued = ContextToken::issue( 'Inspect runtime', 'stonewright/php-execute' );
		$result = ( new ExecuteAbility() )->execute(
			[
				'name'      => 'stonewright/php-execute',
				'arguments' => [
					'code'                      => 'update_option("blogname", "mutated-via-execute-ability"); return true;',
					'read_only'                 => true,
					'stonewright_context_token' => $issued['token'],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_ability_not_on_surface', $result->get_error_code() );
		self::assertNotSame( 'mutated-via-execute-ability', get_option( 'blogname' ) );
	}

	/**
	 * @return array<string, array{0: string, 1: array<string, mixed>}>
	 */
	public static function restricted_targets(): array {
		return [
			'php-execute'         => [ 'stonewright/php-execute', [ 'code' => 'return 1;', 'read_only' => true ] ],
			'php-execute by tool' => [ 'stonewright-php-execute', [ 'code' => 'return 1;', 'read_only' => true ] ],
			'sandbox-write'       => [ 'stonewright/sandbox-write', [ 'slug' => 'x', 'code' => '<?php // x' ] ],
			'wp-cli-run'          => [ 'stonewright/wp-cli-run', [ 'command' => [ 'core', 'version' ] ] ],
			'wp-cli-job-start'    => [ 'stonewright/wp-cli-job-start', [ 'command' => [ 'core', 'version' ] ] ],
			'custom-code'         => [ 'stonewright/custom-code-provider', [ 'action' => 'discover' ] ],
		];
	}

	/**
	 * @dataProvider restricted_targets
	 * @param array<string, mixed> $arguments
	 */
	public function test_execute_ability_refuses_restricted_abilities_unless_the_profile_is_full( string $name, array $arguments ): void {
		$result = ( new ExecuteAbility() )->execute( [ 'name' => $name, 'arguments' => $arguments ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_ability_not_on_surface', $result->get_error_code() );
	}

	public function test_execute_ability_refuses_restricted_abilities_in_a_discover_execute_session(): void {
		$_SERVER['HTTP_MCP_SESSION_ID'] = 'discover-execute-session';
		AbilityRegistry::set_session_tool_profile( 'discover-execute', ToolProfile::profile_tools( 'discover-execute' ) );

		$result = ( new ExecuteAbility() )->execute(
			[
				'name'      => 'stonewright/php-execute',
				'arguments' => [ 'code' => 'return 1;', 'read_only' => true ],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_ability_not_on_surface', $result->get_error_code() );
	}

	public function test_execute_ability_runs_php_execute_when_the_session_profile_is_full(): void {
		$_SERVER['HTTP_MCP_SESSION_ID'] = 'full-session';
		AbilityRegistry::set_session_tool_profile( 'full', [] );
		$issued = ContextToken::issue( 'Inspect runtime', 'stonewright/php-execute' );

		$result = ( new ExecuteAbility() )->execute(
			[
				'name'      => 'stonewright/php-execute',
				'arguments' => [
					'code'                      => 'return 7;',
					'read_only'                 => true,
					'stonewright_context_token' => $issued['token'],
				],
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( 'stonewright/php-execute', $result['ability'] );
	}

	public function test_execute_ability_keeps_php_execute_read_only_guards_on_the_full_surface(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mcp_surface'] = 'full';
		$issued = ContextToken::issue( 'Inspect runtime', 'stonewright/php-execute' );

		$result = ( new ExecuteAbility() )->execute(
			[
				'name'      => 'stonewright/php-execute',
				'arguments' => [
					'code'                      => 'update_option("blogname", "mutated-via-execute-ability"); return true;',
					'read_only'                 => true,
					'stonewright_context_token' => $issued['token'],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_php_read_only_violation', $result->get_error_code() );
		self::assertNotSame( 'mutated-via-execute-ability', get_option( 'blogname' ) );
	}

	public function test_execute_ability_refuses_an_ability_the_surface_hides(): void {
		$GLOBALS['stonewright_test_options']['stonewright_elementor_v4_atomic'] = false;

		$result = ( new ExecuteAbility() )->execute( [ 'name' => 'stonewright/elementor-v4-update-node', 'arguments' => [] ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_ability_not_on_surface', $result->get_error_code() );
	}

	public function test_execute_ability_lets_a_listed_wp_cli_ability_reach_its_own_gates(): void {
		// wp-cli-batch-run is on the essential surface, so the surface check lets it through to its own gates.
		$result = ( new ExecuteAbility() )->execute(
			[
				'name'      => 'stonewright/wp-cli-batch-run',
				'arguments' => [ 'commands' => [ [ 'core', 'version' ] ] ],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertNotSame( 'stonewright_ability_not_on_surface', $result->get_error_code() );
	}

	public function test_execute_ability_validates_arguments_against_the_input_schema(): void {
		$result = ( new ExecuteAbility() )->execute(
			[
				'name'      => 'stonewright/system-instructions-set',
				'arguments' => [ 'text' => 'Fine', 'unknown_field' => true ],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_ability_invalid_input', $result->get_error_code() );
	}

	public function test_execute_ability_validates_a_missing_required_argument(): void {
		$result = ( new ExecuteAbility() )->execute( [ 'name' => 'stonewright/system-instructions-set', 'arguments' => [] ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_ability_invalid_input', $result->get_error_code() );
	}

	public function test_execute_ability_validates_before_the_permission_callback(): void {
		$GLOBALS['stonewright_test_user_caps']['manage_options'] = false;

		$result = ( new ExecuteAbility() )->execute(
			[
				'name'      => 'stonewright/system-instructions-set',
				'arguments' => [ 'text' => 123 ],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_ability_invalid_input', $result->get_error_code() );
	}

	public function test_execute_ability_leaves_the_context_token_to_the_context_guard(): void {
		// The published schema requires stonewright_context_token; execute-ability reports the specific context error instead.
		$result = ( new ExecuteAbility() )->execute(
			[
				'name'      => 'stonewright/system-instructions-set',
				'arguments' => [ 'text' => 'Fine' ],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_context_required', $result->get_error_code() );
	}

	public function test_execute_ability_accepts_the_response_projection_argument(): void {
		$result = ( new ExecuteAbility() )->execute(
			[
				'name'      => 'stonewright/ping',
				'arguments' => [ 'stonewright_fields' => [ 'ok' ] ],
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
	}

	public function test_protocol_abilities_are_registered_and_php_execute_stays_off_startup(): void {
		$registered = array_map(
			static fn( string $class ): string => ( new $class() )->name(),
			AbilityRegistry::list()
		);
		self::assertContains( 'stonewright/discover-abilities', $registered );
		self::assertContains( 'stonewright/get-ability-info', $registered );
		self::assertContains( 'stonewright/execute-ability', $registered );

		self::assertNotContains( 'stonewright/php-execute', AbilityRegistry::bootstrap_ability_names_for_test() );
		self::assertNotContains( 'stonewright/php-execute', AbilityRegistry::essential_ability_names_for_test() );
		self::assertNotContains( 'stonewright/php-execute', ToolProfile::profile_tools( 'discover-execute' ) );
	}

	/**
	 * @param mixed $schema
	 */
	private function schema_depth( mixed $schema, int $depth = 1 ): int {
		if ( ! is_array( $schema ) || [] === $schema ) {
			return $depth;
		}
		$max = $depth;
		foreach ( $schema as $child ) {
			if ( is_array( $child ) ) {
				$max = max( $max, $this->schema_depth( $child, $depth + 1 ) );
			}
		}
		return $max;
	}
}
