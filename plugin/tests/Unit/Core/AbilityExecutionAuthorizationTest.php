<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Site\Ping;
use Stonewright\WpMcp\Abilities\System\ExecuteAbility;
use Stonewright\WpMcp\Context\ContextToken;
use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Core\RestRoutes;
use Stonewright\WpMcp\Security\ConfirmationToken;
use Stonewright\WpMcp\Tests\Unit\Core\Fixtures\RegisteredAbilityRig;

/**
 * One choke point decides whether an ability may run at all: the master toggle, the disabled list and
 * the tool surface. Every path that runs an ability by name passes through it and answers alike.
 *
 * @covers \Stonewright\WpMcp\Core\AbilityRegistry::authorize_execution
 * @covers \Stonewright\WpMcp\Core\AbilityRegistry::execute_with_context_guard
 * @covers \Stonewright\WpMcp\Abilities\System\ExecuteAbility
 * @covers \Stonewright\WpMcp\Core\RestRoutes
 */
final class AbilityExecutionAuthorizationTest extends TestCase {
	use RegisteredAbilityRig;

	protected function setUp(): void {
		$GLOBALS['stonewright_test_rest_routes']    = [];
		$GLOBALS['stonewright_test_rest_validator'] = self::validator();
		$GLOBALS['stonewright_test_options']        = [
			'stonewright_enabled'              => true,
			'stonewright_disabled_abilities'   => [],
			'stonewright_mode'                 => 'development',
			'stonewright_essential_tools_mode' => true,
			'stonewright_mcp_surface'          => 'essential',
		];
		$GLOBALS['stonewright_test_user_caps']      = [
			'read'             => true,
			'manage_options'   => true,
			'edit_plugins'     => true,
			'edit_themes'      => true,
			'install_plugins'  => true,
			'activate_plugins' => true,
		];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		$GLOBALS['stonewright_test_transients']      = [];
		unset( $_SERVER['HTTP_MCP_SESSION_ID'] );
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_rest_routes']     = [];
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_transients']      = [];
		unset( $GLOBALS['stonewright_test_rest_validator'], $_SERVER['HTTP_MCP_SESSION_ID'] );
	}

	public function test_it_lets_an_ordinary_ability_through(): void {
		$ability = AbilityRegistry::ability_by_name( 'stonewright/site-info' );
		self::assertNotNull( $ability );

		self::assertNull( AbilityRegistry::authorize_execution( $ability ) );
	}

	public function test_it_refuses_everything_but_ping_while_the_master_toggle_is_off(): void {
		$GLOBALS['stonewright_test_options']['stonewright_enabled'] = false;

		$site_info = AbilityRegistry::ability_by_name( 'stonewright/site-info' );
		self::assertNotNull( $site_info );
		$refused = AbilityRegistry::authorize_execution( $site_info );
		self::assertInstanceOf( \WP_Error::class, $refused );
		self::assertSame( 'stonewright_disabled', $refused->get_error_code() );
		self::assertSame( 403, $refused->get_error_data()['status'] );

		self::assertNull( AbilityRegistry::authorize_execution( new Ping() ) );
	}

	public function test_it_refuses_a_disabled_ability(): void {
		$GLOBALS['stonewright_test_options']['stonewright_disabled_abilities'] = [ 'stonewright/site-info' ];
		$ability = AbilityRegistry::ability_by_name( 'stonewright/site-info' );
		self::assertNotNull( $ability );

		$refused = AbilityRegistry::authorize_execution( $ability );

		self::assertInstanceOf( \WP_Error::class, $refused );
		self::assertSame( 'stonewright_ability_disabled', $refused->get_error_code() );
	}

	public function test_it_refuses_a_full_only_ability_off_the_surface_with_the_settings_link(): void {
		$ability = AbilityRegistry::ability_by_name( 'stonewright/php-execute' );
		self::assertNotNull( $ability );

		$refused = AbilityRegistry::authorize_execution( $ability );

		self::assertInstanceOf( \WP_Error::class, $refused );
		self::assertSame( 'stonewright_ability_not_on_surface', $refused->get_error_code() );
		$data = $refused->get_error_data();
		self::assertSame( 403, $data['status'] );
		self::assertTrue( $data['approval_required'] );
		self::assertTrue( $data['agent_must_stop'] );
		self::assertStringContainsString( 'stonewright_mcp_surface', (string) $data['approval_url'] );
		self::assertStringContainsString( 'administrator', $refused->get_error_message() );
	}

	public function test_a_hidden_ability_that_runs_no_code_goes_on_to_its_own_handling(): void {
		$GLOBALS['stonewright_test_options']['stonewright_elementor_v4_atomic'] = false;
		$ability = AbilityRegistry::ability_by_name( 'stonewright/elementor-v4-update-node' );
		self::assertNotNull( $ability );

		// Hidden from the tool list, not a security boundary: the central gate lets it through.
		self::assertNull( AbilityRegistry::authorize_execution( $ability ) );

		// execute-ability still runs only what the current tool list offers.
		$hidden = AbilityRegistry::surface_refusal( $ability );
		self::assertInstanceOf( \WP_Error::class, $hidden );
		self::assertSame( 'stonewright_ability_not_on_surface', $hidden->get_error_code() );
		self::assertTrue( $hidden->get_error_data()['agent_must_stop'] );
	}

	public function test_it_lets_a_full_only_ability_through_on_the_full_surface(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mcp_surface'] = 'full';
		$ability = AbilityRegistry::ability_by_name( 'stonewright/php-execute' );
		self::assertNotNull( $ability );

		self::assertNull( AbilityRegistry::authorize_execution( $ability ) );
	}

	public function test_it_lets_a_full_only_ability_through_when_the_session_profile_lists_it(): void {
		$_SERVER['HTTP_MCP_SESSION_ID'] = 'full-session';
		AbilityRegistry::set_session_tool_profile( 'full', [] );
		$ability = AbilityRegistry::ability_by_name( 'stonewright/php-execute' );
		self::assertNotNull( $ability );

		self::assertNull( AbilityRegistry::authorize_execution( $ability ) );
	}

	public function test_the_context_guard_checks_authorization_before_anything_else(): void {
		$ability = AbilityRegistry::ability_by_name( 'stonewright/php-execute' );
		self::assertNotNull( $ability );

		// Without a context token the guard would answer stonewright_context_required if it ran first.
		$result = AbilityRegistry::execute_with_context_guard( $ability, [ 'code' => 'return 1;', 'read_only' => true ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_ability_not_on_surface', $result->get_error_code() );
	}

	public function test_the_context_guard_honors_the_master_toggle_and_the_disabled_list(): void {
		$site_info = AbilityRegistry::ability_by_name( 'stonewright/site-info' );
		self::assertNotNull( $site_info );

		$GLOBALS['stonewright_test_options']['stonewright_disabled_abilities'] = [ 'stonewright/site-info' ];
		$disabled = AbilityRegistry::execute_with_context_guard( $site_info, [] );
		self::assertInstanceOf( \WP_Error::class, $disabled );
		self::assertSame( 'stonewright_ability_disabled', $disabled->get_error_code() );

		$GLOBALS['stonewright_test_options']['stonewright_disabled_abilities'] = [];
		$GLOBALS['stonewright_test_options']['stonewright_enabled']            = false;
		$off = AbilityRegistry::execute_with_context_guard( $site_info, [] );
		self::assertInstanceOf( \WP_Error::class, $off );
		self::assertSame( 'stonewright_disabled', $off->get_error_code() );

		self::assertIsArray( AbilityRegistry::execute_with_context_guard( new Ping(), [] ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function refused_abilities(): array {
		return [
			'php-execute'         => [ 'stonewright/php-execute' ],
			'sandbox-activate'    => [ 'stonewright/sandbox-activate' ],
			'wp-cli-run'          => [ 'stonewright/wp-cli-run' ],
			'custom-code-provider' => [ 'stonewright/custom-code-provider' ],
		];
	}

	/**
	 * @dataProvider refused_abilities
	 */
	public function test_every_path_refuses_a_full_only_ability_alike( string $name ): void {
		$ability = AbilityRegistry::ability_by_name( $name );
		self::assertNotNull( $ability );

		$token = ConfirmationToken::issue( 'stonewright/rest-abilities-run', [ 'name' => $name, 'input' => [] ] );
		RestRoutes::register();
		$rest_callback = $this->find_route( '/abilities/run' )['args']['callback'];

		$paths = [
			'authorize_execution'        => AbilityRegistry::authorize_execution( $ability ),
			'execute_with_context_guard' => AbilityRegistry::execute_with_context_guard( $ability, [] ),
			'core abilities route'       => AbilityRegistry::registration_args( $ability )['execute_callback']( [] ),
			'execute-ability'            => ( new ExecuteAbility() )->execute( [ 'name' => $name, 'arguments' => [] ] ),
			'rest run without token'     => $rest_callback( new \WP_REST_Request( 'POST', '/stonewright/v1/abilities/run', [ 'name' => $name, 'input' => [] ] ) ),
			'rest run with token'        => $rest_callback( new \WP_REST_Request( 'POST', '/stonewright/v1/abilities/run', [ 'name' => $name, 'input' => [], 'confirmation_token' => $token ] ) ),
		];

		$reference = AbilityRegistry::authorize_execution( $ability );
		self::assertInstanceOf( \WP_Error::class, $reference );
		foreach ( $paths as $path => $result ) {
			self::assertInstanceOf( \WP_Error::class, $result, $path );
			self::assertSame( $reference->get_error_code(), $result->get_error_code(), $path );
			self::assertSame( $reference->get_error_message(), $result->get_error_message(), $path );
			self::assertSame( $reference->get_error_data(), $result->get_error_data(), $path );
		}
		self::assertSame( 'stonewright_ability_not_on_surface', $reference->get_error_code() );
	}

	public function test_every_path_reaches_the_ability_itself_on_the_full_surface(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mcp_surface'] = 'full';
		$ability = AbilityRegistry::ability_by_name( 'stonewright/php-execute' );
		self::assertNotNull( $ability );
		RestRoutes::register();
		$rest_callback = $this->find_route( '/abilities/run' )['args']['callback'];

		$input = static function () {
			$issued = ContextToken::issue( 'Inspect runtime', 'stonewright/php-execute' );
			return [ 'code' => 'return 7;', 'read_only' => true, 'stonewright_context_token' => $issued['token'] ];
		};

		self::assertIsArray( AbilityRegistry::execute_with_context_guard( $ability, $input() ) );
		self::assertIsArray( AbilityRegistry::registration_args( $ability )['execute_callback']( $input() ) );
		$executed = ( new ExecuteAbility() )->execute( [ 'name' => 'stonewright/php-execute', 'arguments' => $input() ] );
		self::assertIsArray( $executed );
		self::assertTrue( $executed['ok'] );
		$rest = $rest_callback( new \WP_REST_Request( 'POST', '/stonewright/v1/abilities/run', [ 'name' => 'stonewright/php-execute', 'input' => $input() ] ) );
		self::assertInstanceOf( \WP_REST_Response::class, $rest );
	}

	public function test_execute_ability_returns_the_choke_point_refusal_unchanged(): void {
		$ability = AbilityRegistry::ability_by_name( 'stonewright/php-execute' );
		self::assertNotNull( $ability );

		$result = ( new ExecuteAbility() )->execute( [ 'name' => 'stonewright/php-execute', 'arguments' => [] ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertTrue( $result->get_error_data()['agent_must_stop'] );
		self::assertArrayHasKey( 'approval_url', $result->get_error_data() );
	}

	/**
	 * @return array{namespace: string, route: string, args: array<string, mixed>}
	 */
	private function find_route( string $route ): array {
		foreach ( $GLOBALS['stonewright_test_rest_routes'] as $registered ) {
			if ( $route === $registered['route'] ) {
				return $registered;
			}
		}

		self::fail( "Route {$route} was not registered." );
	}
}
