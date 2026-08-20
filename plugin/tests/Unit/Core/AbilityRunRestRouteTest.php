<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Core\RestRoutes;
use Stonewright\WpMcp\Security\ConfirmationToken;

/**
 * @covers \Stonewright\WpMcp\Core\RestRoutes
 * @covers \Stonewright\WpMcp\Core\AbilityRegistry
 */
final class AbilityRunRestRouteTest extends TestCase {

	protected function setUp(): void {
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
