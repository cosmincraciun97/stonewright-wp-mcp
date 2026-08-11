<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\ConnectClientConfig;
use Stonewright\WpMcp\Core\RestRoutes;

/**
 * Application Password REST contract: create once, no-store, credential-free prompt.
 *
 * @covers \Stonewright\WpMcp\Core\RestRoutes
 */
final class AppPasswordRestTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps']       = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_enabled' => true ];
		$GLOBALS['stonewright_test_rest_routes']     = [];
		$GLOBALS['stonewright_test_app_passwords']   = [];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_rest_routes']     = [];
		$GLOBALS['stonewright_test_app_passwords']   = [];
	}

	/**
	 * @return callable|null
	 */
	private function find_app_password_callback( string $method ): ?callable {
		RestRoutes::register();
		foreach ( $GLOBALS['stonewright_test_rest_routes'] as $route ) {
			if ( ! is_array( $route ) ) {
				continue;
			}
			if ( ! str_contains( (string) ( $route['route'] ?? '' ), 'app-password' ) ) {
				continue;
			}
			$args = $route['args'] ?? null;
			if ( ! is_array( $args ) ) {
				continue;
			}
			// Multi-method list: [ [methods=>GET,...], [methods=>POST,...], ... ]
			$is_list = array_is_list( $args ) && isset( $args[0] ) && is_array( $args[0] );
			$endpoints = $is_list ? $args : [ $args ];
			foreach ( $endpoints as $endpoint ) {
				if ( ! is_array( $endpoint ) ) {
					continue;
				}
				$methods = $endpoint['methods'] ?? '';
				$matches = $methods === $method
					|| ( is_array( $methods ) && ( in_array( $method, $methods, true ) || isset( $methods[ $method ] ) ) )
					|| ( is_string( $methods ) && str_contains( $methods, $method ) );
				if ( $matches && is_callable( $endpoint['callback'] ?? null ) ) {
					return $endpoint['callback'];
				}
			}
		}
		return null;
	}

	public function test_app_password_routes_register_get_post_delete(): void {
		self::assertIsCallable( $this->find_app_password_callback( 'GET' ) );
		self::assertIsCallable( $this->find_app_password_callback( 'POST' ) );
		self::assertIsCallable( $this->find_app_password_callback( 'DELETE' ) );
	}

	public function test_create_callback_returns_password_once_with_no_store(): void {
		$post_cb = $this->find_app_password_callback( 'POST' );
		self::assertIsCallable( $post_cb );

		$request = new \WP_REST_Request( 'POST', '/stonewright/v1/app-password', [ 'name' => 'Cursor laptop' ] );
		$response = $post_cb( $request );

		if ( $response instanceof \WP_Error ) {
			self::fail( $response->get_error_message() );
		}

		self::assertInstanceOf( \WP_REST_Response::class, $response );
		$data = $response->get_data();
		self::assertIsArray( $data );
		self::assertArrayHasKey( 'password', $data );
		self::assertNotSame( '', (string) $data['password'] );
		self::assertArrayHasKey( 'uuid', $data );
		self::assertArrayHasKey( 'name', $data );

		$headers = $response->get_headers();
		self::assertArrayHasKey( 'Cache-Control', $headers );
		self::assertStringContainsString( 'no-store', strtolower( (string) $headers['Cache-Control'] ) );

		// Password must not land in options (no flash for REST path).
		foreach ( $GLOBALS['stonewright_test_options'] as $key => $value ) {
			$encoded = is_string( $value ) ? $value : ( wp_json_encode( $value ) ?: '' );
			self::assertStringNotContainsString( (string) $data['password'], $encoded );
			self::assertStringNotContainsString( 'app_password_flash', (string) $key );
		}
	}

	public function test_paste_prompt_never_contains_real_password(): void {
		$prompt = ConnectClientConfig::paste_to_agent_prompt( 'admin', 'super-secret-app-pass-xyz', 'cursor' );
		self::assertStringNotContainsString( 'super-secret-app-pass-xyz', $prompt );
		self::assertStringContainsString( '<your-application-password>', $prompt );
		self::assertDoesNotMatchRegularExpression( '/STONEWRIGHT_MCP_TOOL_PROFILE=full\b/', $prompt );
	}

	public function test_generate_path_form_supports_rest_intercept_attributes(): void {
		// Configuration markup contract: form is interceptable without full redirect.
		$html = file_get_contents( dirname( __DIR__, 3 ) . '/includes/Admin/ConfigurationPage.php' );
		self::assertIsString( $html );
		self::assertStringContainsString( 'data-stonewright-app-password-form', $html );
		self::assertStringContainsString( 'stonewright/v1/app-password', $html );
		self::assertStringContainsString( 'Without JavaScript this navigates', $html );
	}
}
