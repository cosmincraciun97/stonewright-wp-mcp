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

/**
 * @covers \Stonewright\WpMcp\Abilities\System\DiscoverAbilities
 * @covers \Stonewright\WpMcp\Abilities\System\GetAbilityInfo
 * @covers \Stonewright\WpMcp\Abilities\System\ExecuteAbility
 * @covers \Stonewright\WpMcp\Abilities\System\ToolProfile
 */
final class DiscoverExecuteTest extends TestCase {

	protected function setUp(): void {
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
		];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 11;
		$GLOBALS['stonewright_test_transients']      = [];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
		$GLOBALS['stonewright_test_transients']      = [];
		unset( $GLOBALS['stonewright_test_current_user_id'] );
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
				'name'      => 'stonewright/php-execute',
				'arguments' => [
					'code'      => 'return 1;',
					'read_only' => true,
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

	public function test_execute_ability_does_not_bypass_php_execute_read_only_guards(): void {
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
