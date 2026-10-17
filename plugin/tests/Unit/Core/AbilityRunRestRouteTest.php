<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Context\ContextToken;
use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Core\FullProfileOnlyAbilities;
use Stonewright\WpMcp\Core\RestRoutes;
use Stonewright\WpMcp\Security\ConfirmationToken;
use Stonewright\WpMcp\Tests\Unit\Core\Fixtures\RegisteredAbilityRig;

/**
 * @covers \Stonewright\WpMcp\Core\RestRoutes
 * @covers \Stonewright\WpMcp\Core\AbilityRegistry
 */
final class AbilityRunRestRouteTest extends TestCase {
	use RegisteredAbilityRig;

	/** The one Full-only ability the essential surface lists, as a read-oriented command runner. */
	private const ESSENTIAL_LISTED_FULL_ONLY = 'stonewright/wp-cli-batch-run';

	protected function setUp(): void {
		$GLOBALS['stonewright_test_rest_validator'] = self::validator();
		unset( $_SERVER['HTTP_MCP_SESSION_ID'] );
		$GLOBALS['stonewright_test_rest_routes'] = [];
		$GLOBALS['stonewright_test_options']     = [
			'stonewright_enabled'            => true,
			'stonewright_disabled_abilities' => [],
			'stonewright_mode'               => 'development',
			'stonewright_essential_tools_mode' => true,
			'stonewright_mcp_surface'        => 'essential',
		];
		$GLOBALS['stonewright_test_user_caps']   = [
			'read'           => true,
			'manage_options' => true,
			'edit_plugins'   => true,
			'edit_themes'    => true,
			'install_plugins' => true,
			'activate_plugins' => true,
		];
		$GLOBALS['stonewright_test_user_logged_in'] = true;
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		$GLOBALS['stonewright_test_transients'] = [];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_rest_routes'] = [];
		$GLOBALS['stonewright_test_options']     = [];
		$GLOBALS['stonewright_test_user_caps']   = [];
		$GLOBALS['stonewright_test_user_logged_in'] = false;
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_transients'] = [];
		unset( $GLOBALS['stonewright_test_rest_validator'], $_SERVER['HTTP_MCP_SESSION_ID'] );
	}

	public function test_registers_stonewright_ability_run_route(): void {
		RestRoutes::register();

		$route = $this->find_route( '/abilities/run' );

		self::assertSame( 'stonewright/v1', $route['namespace'] );
		self::assertSame( 'POST', $route['args']['methods'] );
		self::assertIsCallable( $route['args']['callback'] );
		self::assertSame( [ \Stonewright\WpMcp\Security\Permissions::class, 'manage_options' ], $route['args']['permission_callback'] );
	}

	public function test_run_route_executes_ping_through_stonewright_registry(): void {
		RestRoutes::register();
		$route    = $this->find_route( '/abilities/run' );
		$callback = $route['args']['callback'];
		$request  = new \WP_REST_Request(
			'POST',
			'/stonewright/v1/abilities/run',
			[
				'name'  => 'stonewright/ping',
				'input' => [],
			]
		);

		$response = $callback( $request );

		self::assertInstanceOf( \WP_REST_Response::class, $response );
		$data = $response->get_data();
		self::assertSame( 'stonewright/ping', $data['name'] );
		self::assertSame( 'pong', $data['result']['message'] );
	}

	public function test_run_route_honors_master_toggle_for_non_ping_abilities(): void {
		$GLOBALS['stonewright_test_options']['stonewright_enabled'] = false;

		RestRoutes::register();
		$route    = $this->find_route( '/abilities/run' );
		$callback = $route['args']['callback'];
		$request  = new \WP_REST_Request(
			'POST',
			'/stonewright/v1/abilities/run',
			[
				'name'  => 'stonewright/system-instructions-get',
				'input' => [],
			]
		);

		$result = $callback( $request );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_disabled', $result->get_error_code() );
	}

	public function test_run_route_allows_in_profile_ability_without_extra_token(): void {
		RestRoutes::register();
		$callback = $this->find_route( '/abilities/run' )['args']['callback'];
		$request  = new \WP_REST_Request(
			'POST',
			'/stonewright/v1/abilities/run',
			[
				'name'  => 'stonewright/site-info',
				'input' => [],
			]
		);

		$response = $callback( $request );

		self::assertInstanceOf( \WP_REST_Response::class, $response );
		$data = $response->get_data();
		self::assertSame( 'stonewright/site-info', $data['name'] );
	}

	public function test_run_route_blocks_out_of_profile_ability_without_confirmation_token(): void {
		RestRoutes::register();
		$callback = $this->find_route( '/abilities/run' )['args']['callback'];
		$request  = new \WP_REST_Request(
			'POST',
			'/stonewright/v1/abilities/run',
			[
				'name'  => 'stonewright/system-instructions-get',
				'input' => [],
			]
		);

		$result = $callback( $request );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_confirmation_required', $result->get_error_code() );
	}

	public function test_run_route_allows_out_of_profile_ability_with_confirmation_token(): void {
		RestRoutes::register();
		$verify = [
			'name'  => 'stonewright/system-instructions-get',
			'input' => [],
		];
		$token = ConfirmationToken::issue( 'stonewright/rest-abilities-run', $verify );
		$callback = $this->find_route( '/abilities/run' )['args']['callback'];
		$request  = new \WP_REST_Request(
			'POST',
			'/stonewright/v1/abilities/run',
			[
				'name'               => 'stonewright/system-instructions-get',
				'input'              => [],
				'confirmation_token' => $token,
			]
		);

		$response = $callback( $request );

		self::assertInstanceOf( \WP_REST_Response::class, $response );
		$data = $response->get_data();
		self::assertSame( 'stonewright/system-instructions-get', $data['name'] );
	}

	public function test_toggle_requires_confirmation_token_in_all_modes(): void {
		RestRoutes::register();
		$callback = $this->find_route( '/abilities/toggle' )['args']['callback'];
		$request  = new \WP_REST_Request(
			'POST',
			'/stonewright/v1/abilities/toggle',
			[
				'name'    => 'stonewright/php-execute',
				'enabled' => false,
			]
		);

		$result = $callback( $request );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_confirmation_required', $result->get_error_code() );
		self::assertNotContains( 'stonewright/php-execute', (array) get_option( 'stonewright_disabled_abilities', [] ) );
	}

	public function test_toggle_succeeds_with_confirmation_token(): void {
		RestRoutes::register();
		$verify = [
			'name'    => 'stonewright/php-execute',
			'enabled' => false,
		];
		$token    = ConfirmationToken::issue( 'stonewright/rest-abilities-toggle', $verify );
		$callback = $this->find_route( '/abilities/toggle' )['args']['callback'];
		$request  = new \WP_REST_Request(
			'POST',
			'/stonewright/v1/abilities/toggle',
			[
				'name'               => 'stonewright/php-execute',
				'enabled'            => false,
				'confirmation_token' => $token,
			]
		);

		$response = $callback( $request );

		self::assertInstanceOf( \WP_REST_Response::class, $response );
		self::assertContains( 'stonewright/php-execute', (array) get_option( 'stonewright_disabled_abilities', [] ) );
	}

	/**
	 * Every Full-only ability except the one the essential surface lists on purpose.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function full_only_abilities(): array {
		$rows = [];
		foreach ( FullProfileOnlyAbilities::names() as $name ) {
			if ( self::ESSENTIAL_LISTED_FULL_ONLY === $name ) {
				continue;
			}
			$rows[ $name ] = [ $name ];
		}
		return $rows;
	}

	public function test_only_wp_cli_batch_run_is_full_only_and_listed_on_the_essential_surface(): void {
		self::assertSame(
			[ self::ESSENTIAL_LISTED_FULL_ONLY ],
			array_values( array_intersect( FullProfileOnlyAbilities::names(), AbilityRegistry::essential_ability_names_for_test() ) )
		);
	}

	/**
	 * @dataProvider full_only_abilities
	 */
	public function test_run_route_refuses_a_full_only_ability_even_with_a_self_issued_token( string $name ): void {
		$GLOBALS['stonewright_test_options']['stonewright_probe'] = 'untouched';
		$input = [ 'code' => 'update_option( "stonewright_probe", "changed" ); return 1;', 'read_only' => false ];
		$token = ConfirmationToken::issue( 'stonewright/rest-abilities-run', [ 'name' => $name, 'input' => $input ] );

		$result = $this->run_route( $name, $input, $token );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_ability_not_on_surface', $result->get_error_code() );
		$data = $result->get_error_data();
		self::assertSame( 403, $data['status'] );
		self::assertTrue( $data['agent_must_stop'] );
		self::assertNotSame( '', (string) ( $data['approval_url'] ?? '' ) );
		self::assertSame( 'untouched', get_option( 'stonewright_probe' ) );
	}

	/**
	 * @dataProvider full_only_abilities
	 */
	public function test_run_route_refuses_a_full_only_ability_with_the_ability_scoped_token_too( string $name ): void {
		$token = ConfirmationToken::issue( $name, [] );

		$result = $this->run_route( $name, [], $token );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_ability_not_on_surface', $result->get_error_code() );
	}

	/**
	 * @dataProvider full_only_abilities
	 */
	public function test_run_route_answers_a_full_only_ability_the_same_with_and_without_a_token( string $name ): void {
		$without = $this->run_route( $name, [], '' );
		$with    = $this->run_route( $name, [], ConfirmationToken::issue( 'stonewright/rest-abilities-run', [ 'name' => $name, 'input' => [] ] ) );

		self::assertInstanceOf( \WP_Error::class, $without );
		self::assertInstanceOf( \WP_Error::class, $with );
		self::assertSame( 'stonewright_ability_not_on_surface', $without->get_error_code() );
		self::assertSame( $without->get_error_code(), $with->get_error_code() );
		self::assertSame( $without->get_error_message(), $with->get_error_message() );
	}

	public function test_run_route_leaves_the_token_unspent_when_it_refuses_a_full_only_ability(): void {
		$args  = [ 'name' => 'stonewright/php-execute', 'input' => [] ];
		$token = ConfirmationToken::issue( 'stonewright/rest-abilities-run', $args );

		$this->run_route( 'stonewright/php-execute', [], $token );

		self::assertTrue( ConfirmationToken::verify_or_error( $token, 'stonewright/rest-abilities-run', $args ) );
	}

	public function test_run_route_refuses_a_hidden_ability_even_with_a_token(): void {
		$GLOBALS['stonewright_test_options']['stonewright_elementor_v4_atomic'] = false;
		$name  = 'stonewright/elementor-v4-update-node';
		$token = ConfirmationToken::issue( 'stonewright/rest-abilities-run', [ 'name' => $name, 'input' => [] ] );

		$result = $this->run_route( $name, [], $token );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_ability_not_on_surface', $result->get_error_code() );
		self::assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_run_route_lets_a_full_only_ability_reach_its_own_gates_on_the_full_surface(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mcp_surface'] = 'full';

		$no_context = $this->run_route( 'stonewright/php-execute', [ 'code' => 'return 7;', 'read_only' => true ], '' );
		self::assertInstanceOf( \WP_Error::class, $no_context );
		self::assertSame( 'stonewright_context_required', $no_context->get_error_code() );

		$issued = ContextToken::issue( 'Inspect runtime', 'stonewright/php-execute' );
		$result = $this->run_route(
			'stonewright/php-execute',
			[ 'code' => 'return 7;', 'read_only' => true, 'stonewright_context_token' => $issued['token'] ],
			''
		);
		self::assertInstanceOf( \WP_REST_Response::class, $result );
		self::assertSame( 'stonewright/php-execute', $result->get_data()['name'] );
	}

	public function test_run_route_keeps_the_read_only_guard_of_php_execute_on_the_full_surface(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mcp_surface'] = 'full';
		$issued = ContextToken::issue( 'Inspect runtime', 'stonewright/php-execute' );

		$result = $this->run_route(
			'stonewright/php-execute',
			[ 'code' => 'update_option("blogname", "mutated-via-rest"); return true;', 'read_only' => true, 'stonewright_context_token' => $issued['token'] ],
			''
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_php_read_only_violation', $result->get_error_code() );
		self::assertNotSame( 'mutated-via-rest', get_option( 'blogname' ) );
	}

	public function test_run_route_validates_input_against_the_ability_schema(): void {
		$result = $this->run_route( 'stonewright/system-instructions-set', [ 'text' => 123 ], '' );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_ability_invalid_input', $result->get_error_code() );
		self::assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_run_route_rejects_unknown_input_properties(): void {
		$result = $this->run_route( 'stonewright/system-instructions-set', [ 'text' => 'Fine', 'unknown_field' => true ], '' );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_ability_invalid_input', $result->get_error_code() );
	}

	public function test_run_route_does_not_count_the_confirmation_token_as_unknown_input(): void {
		$input = [ 'text' => 'Fine' ];
		$token = ConfirmationToken::issue( 'stonewright/rest-abilities-run', [ 'name' => 'stonewright/system-instructions-set', 'input' => $input ] );

		$result = $this->run_route( 'stonewright/system-instructions-set', $input + [ 'confirmation_token' => $token ], '' );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertNotSame( 'stonewright_ability_invalid_input', $result->get_error_code() );
	}

	public function test_run_route_still_unlocks_an_ordinary_out_of_profile_ability_with_a_token(): void {
		$name  = 'stonewright/system-instructions-get';
		$token = ConfirmationToken::issue( 'stonewright/rest-abilities-run', [ 'name' => $name, 'input' => [] ] );

		$response = $this->run_route( $name, [], $token );

		self::assertInstanceOf( \WP_REST_Response::class, $response );
		self::assertSame( $name, $response->get_data()['name'] );
	}

	public function test_run_route_honors_the_disabled_ability_list(): void {
		$GLOBALS['stonewright_test_options']['stonewright_disabled_abilities'] = [ 'stonewright/site-info' ];

		$result = $this->run_route( 'stonewright/site-info', [], '' );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_ability_disabled', $result->get_error_code() );
	}

	public function test_sandbox_file_routes_are_not_registered(): void {
		RestRoutes::register();

		foreach ( $GLOBALS['stonewright_test_rest_routes'] as $registered ) {
			self::assertStringNotContainsString( 'sandbox', (string) $registered['route'], 'No sandbox route belongs in the stonewright/v1 REST namespace.' );
		}
	}

	/**
	 * Calls the /abilities/run route callback.
	 *
	 * @param array<string, mixed> $input
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function run_route( string $name, array $input, string $token ): mixed {
		RestRoutes::register();
		$callback = $this->find_route( '/abilities/run' )['args']['callback'];
		$params   = [ 'name' => $name, 'input' => $input ];
		if ( '' !== $token ) {
			$params['confirmation_token'] = $token;
		}

		return $callback( new \WP_REST_Request( 'POST', '/stonewright/v1/abilities/run', $params ) );
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
