<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Core\RestRoutes;
use Stonewright\WpMcp\Security\Permissions;

/**
 * GET stonewright/v1/abilities is a Stonewright route that publishes every ability's input
 * schema. Where WordPress provides wp_prepare_json_schema_for_client() (7.1 and later) the
 * schemas leave through that helper, as they do through WordPress's own abilities route.
 *
 * @covers \Stonewright\WpMcp\Core\RestRoutes
 * @covers \Stonewright\WpMcp\Support\ClientSchema
 */
final class AbilitiesListRestRouteTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_rest_routes'] = [];
		$GLOBALS['stonewright_test_options']     = [
			'stonewright_enabled'              => true,
			'stonewright_disabled_abilities'   => [],
			'stonewright_mode'                 => 'development',
			'stonewright_essential_tools_mode' => true,
			'stonewright_mcp_surface'          => 'essential',
		];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_rest_routes'] = [];
		$GLOBALS['stonewright_test_options']     = [];
	}

	/**
	 * @return callable
	 */
	private function route_callback(): callable {
		RestRoutes::register();
		foreach ( $GLOBALS['stonewright_test_rest_routes'] as $route ) {
			if ( 'stonewright/v1' === $route['namespace'] && '/abilities' === $route['route'] ) {
				return $route['args']['callback'];
			}
		}
		self::fail( 'The abilities list route is not registered.' );
	}

	public function test_the_route_is_read_only_and_for_administrators(): void {
		RestRoutes::register();

		$found = null;
		foreach ( $GLOBALS['stonewright_test_rest_routes'] as $route ) {
			if ( 'stonewright/v1' === $route['namespace'] && '/abilities' === $route['route'] ) {
				$found = $route;
			}
		}

		self::assertNotNull( $found );
		self::assertSame( 'GET', $found['args']['methods'] );
		self::assertSame( [ Permissions::class, 'manage_options' ], $found['args']['permission_callback'] );
	}

	public function test_schemas_are_unchanged_where_wordpress_has_no_schema_helper(): void {
		self::assertFalse( function_exists( 'wp_prepare_json_schema_for_client' ) );

		$response = ( $this->route_callback() )();

		self::assertInstanceOf( \WP_REST_Response::class, $response );
		self::assertEquals( AbilityRegistry::all_abilities(), $response->get_data() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_schemas_leave_through_the_wordpress_schema_helper_when_it_exists(): void {
		$GLOBALS['stonewright_test_prepared_schemas'] = 0;
		require_once dirname( __DIR__, 2 ) . '/fixtures/Compatibility/core-schema-helper.php';

		$response = ( $this->route_callback() )();

		self::assertInstanceOf( \WP_REST_Response::class, $response );
		$rows = $response->get_data();
		self::assertIsArray( $rows );
		self::assertNotEmpty( $rows );
		self::assertCount( count( AbilityRegistry::all_abilities() ), $rows );
		self::assertSame( count( $rows ), $GLOBALS['stonewright_test_prepared_schemas'] );
		foreach ( $rows as $row ) {
			self::assertSame( 'draft-04', $row['input_schema']['x-prepared'], $row['name'] . ' leaves the route without preparation.' );
			self::assertArrayHasKey( 'name', $row );
			self::assertArrayHasKey( 'mcp_tool_name', $row );
		}
	}
}
