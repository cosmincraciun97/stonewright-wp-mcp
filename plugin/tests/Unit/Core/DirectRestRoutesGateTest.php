<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Core\RestRoutes;

/**
 * The typed bridge for Direct-capable companions answers to the master toggle and the disabled list
 * like every other way to run an ability.
 *
 * @covers \Stonewright\WpMcp\Core\RestRoutes
 */
final class DirectRestRoutesGateTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_rest_routes'] = [];
		$GLOBALS['stonewright_test_options']     = [
			'stonewright_enabled'              => true,
			'stonewright_disabled_abilities'   => [],
			'stonewright_mode'                 => 'development',
			'stonewright_essential_tools_mode' => true,
			'stonewright_mcp_surface'          => 'essential',
		];
		$GLOBALS['stonewright_test_user_caps']      = [
			'read'           => true,
			'manage_options' => true,
		];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		$GLOBALS['stonewright_test_transients']      = [];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_rest_routes']     = [];
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_transients']      = [];
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function direct_routes(): array {
		return [
			'task-start'      => [ '/direct/task-start', 'stonewright/task-start' ],
			'learning-record' => [ '/direct/learning-record', 'stonewright/learning-record' ],
		];
	}

	/**
	 * @dataProvider direct_routes
	 */
	public function test_direct_route_is_refused_while_the_master_toggle_is_off( string $route, string $ability ): void {
		unset( $ability );
		$GLOBALS['stonewright_test_options']['stonewright_enabled'] = false;

		$result = $this->call( $route );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_disabled', $result->get_error_code() );
		self::assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * @dataProvider direct_routes
	 */
	public function test_direct_route_is_refused_for_a_disabled_ability( string $route, string $ability ): void {
		$GLOBALS['stonewright_test_options']['stonewright_disabled_abilities'] = [ $ability ];

		$result = $this->call( $route );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_ability_disabled', $result->get_error_code() );
	}

	public function test_task_start_route_still_answers_while_enabled(): void {
		$result = $this->call( '/direct/task-start', [ 'task' => 'Check the site', 'surface' => 'essential', 'intent' => 'inspect' ] );

		self::assertInstanceOf( \WP_REST_Response::class, $result );
		self::assertIsArray( $result->get_data() );
	}

	public function test_learning_record_route_still_reaches_its_gates_while_enabled(): void {
		$result = $this->call( '/direct/learning-record', [] );

		if ( $result instanceof \WP_Error ) {
			self::assertNotContains( $result->get_error_code(), [ 'stonewright_disabled', 'stonewright_ability_disabled', 'stonewright_ability_not_on_surface' ] );
		} else {
			self::assertInstanceOf( \WP_REST_Response::class, $result );
		}
	}

	/**
	 * @param array<string, mixed> $json
	 */
	private function call( string $route, array $json = [] ): mixed {
		RestRoutes::register();
		$registered = null;
		foreach ( $GLOBALS['stonewright_test_rest_routes'] as $candidate ) {
			if ( $route === $candidate['route'] ) {
				$registered = $candidate;
				break;
			}
		}
		self::assertNotNull( $registered, "Route {$route} was not registered." );

		$request = new \WP_REST_Request( 'POST', '/stonewright/v1' . $route, $json );
		$request->set_json_params( $json );

		return $registered['args']['callback']( $request );
	}
}
