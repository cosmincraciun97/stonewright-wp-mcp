<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Core\RestRoutes;

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
		];
		$GLOBALS['stonewright_test_user_caps']   = [
			'read'           => true,
			'manage_options' => true,
		];
		$GLOBALS['stonewright_test_user_logged_in'] = true;
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_rest_routes'] = [];
		$GLOBALS['stonewright_test_options']     = [];
		$GLOBALS['stonewright_test_user_caps']   = [];
		$GLOBALS['stonewright_test_user_logged_in'] = false;
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
