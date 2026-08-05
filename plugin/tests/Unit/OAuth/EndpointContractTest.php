<?php
/**
 * OAuth endpoint contract tests.
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\OAuth;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\OAuth\Endpoints\Introspect;
use Stonewright\WpMcp\OAuth\Endpoints\Register;
use Stonewright\WpMcp\OAuth\Endpoints\Revoke;
use Stonewright\WpMcp\OAuth\Endpoints\Token;
use Stonewright\WpMcp\OAuth\ClientValidation;
use WP_REST_Request;
use WP_REST_Response;

final class EndpointContractTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_rest_routes'] = [];
		$GLOBALS['stonewright_test_transients']  = [];
		$_SERVER['REMOTE_ADDR']                  = '';
		$GLOBALS['stonewright_test_filters']     = [];
	}

	public function test_registers_all_oauth_rest_endpoints(): void {
		Register::register();
		Token::register();
		Revoke::register();
		Introspect::register();

		$routes = array_column( $GLOBALS['stonewright_test_rest_routes'], 'route' );
		self::assertSame(
			[ '/oauth/register', '/oauth/token', '/oauth/revoke', '/oauth/introspect' ],
			$routes
		);
		foreach ( $GLOBALS['stonewright_test_rest_routes'] as $route ) {
			self::assertSame( 'stonewright/v1', $route['namespace'] );
			self::assertSame( 'POST', $route['args']['methods'] );
			self::assertIsCallable( $route['args']['permission_callback'] );
			self::assertIsCallable( $route['args']['callback'] );
		}
	}

	public function test_public_oauth_callbacks_are_explicit_and_admin_introspection_is_gated(): void {
		self::assertTrue( Register::allow_public_oauth() );
		self::assertTrue( Token::allow_public_oauth() );
		self::assertTrue( Revoke::allow_public_oauth() );

		$GLOBALS['stonewright_test_user_caps'] = [];
		self::assertFalse( Introspect::can_introspect() );
		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true ];
		self::assertTrue( Introspect::can_introspect() );
	}

	public function test_missing_self_test_header_is_not_a_self_test_request(): void {
		$request = new WP_REST_Request( 'POST', '/oauth/register' );

		self::assertFalse( Register::is_self_test_request( $request ) );
	}

	public function test_registration_rejects_invalid_payload_before_database_write(): void {
		$request = new WP_REST_Request( 'POST', '/oauth/register' );
		$request->set_json_params( [ 'client_name' => '' ] );

		$result = Register::handle( $request );
		self::assertSame( 'invalid_request', $result->get_error_code() );
		self::assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_registration_rate_limit_returns_real_rest_headers(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.20';
		$request = new WP_REST_Request( 'POST', '/oauth/register' );
		$request->set_json_params( [ 'client_name' => '' ] );
		for ( $i = 0; $i < ClientValidation::DCR_RATE_LIMIT_PER_HOUR; ++$i ) {
			self::assertSame( 'invalid_request', Register::handle( $request )->get_error_code() );
		}

		$result = Register::handle( $request );
		self::assertInstanceOf( WP_REST_Response::class, $result );
		self::assertSame( 429, $result->get_status() );
		self::assertSame( 'rate_limited', $result->get_data()['error'] );
		self::assertGreaterThan( 0, (int) $result->get_headers()['Retry-After'] );
		self::assertSame( 'no-store', $result->get_headers()['Cache-Control'] );
		self::assertSame( 'no-cache', $result->get_headers()['Pragma'] );
		self::assertNotSame( '', $result->get_headers()['X-Stonewright-Correlation-ID'] );
	}

	public function test_token_rejects_foreign_resource_before_server_processing(): void {
		$request = new WP_REST_Request( 'POST', '/oauth/token', [ 'resource' => 'https://foreign.example/mcp' ] );
		$result  = Token::handle( $request );

		self::assertInstanceOf( WP_REST_Response::class, $result );
		self::assertSame( 400, $result->get_status() );
		self::assertSame( 'invalid_target', $result->get_data()['error'] );
		self::assertSame( 'no-store', $result->get_headers()['Cache-Control'] );
		self::assertSame( 'no-cache', $result->get_headers()['Pragma'] );
	}

	public function test_token_rate_limit_exposes_retry_after_and_no_store_headers(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.21';
		$request = new WP_REST_Request( 'POST', '/oauth/token', [ 'resource' => 'https://foreign.example/mcp' ] );
		for ( $i = 0; $i < ClientValidation::ENDPOINT_RATE_LIMIT_PER_MINUTE; ++$i ) {
			self::assertSame( 400, Token::handle( $request )->get_status() );
		}

		$result = Token::handle( $request );
		self::assertSame( 429, $result->get_status() );
		self::assertSame( 'temporarily_unavailable', $result->get_data()['error'] );
		self::assertSame( 'rate_limited', $result->get_data()['reason'] );
		self::assertGreaterThan( 0, (int) $result->get_headers()['Retry-After'] );
		self::assertSame( 'no-store', $result->get_headers()['Cache-Control'] );
		self::assertSame( 'no-cache', $result->get_headers()['Pragma'] );
	}

	public function test_empty_revoke_is_idempotently_successful(): void {
		$request = new WP_REST_Request( 'POST', '/oauth/revoke' );
		$result  = Revoke::handle( $request );

		self::assertSame( 200, $result->get_status() );
		self::assertNull( $result->get_data() );
	}

	public function test_empty_introspection_is_inactive(): void {
		$request = new WP_REST_Request( 'POST', '/oauth/introspect' );
		$result  = Introspect::handle( $request );

		self::assertSame( 200, $result->get_status() );
		self::assertSame( [ 'active' => false ], $result->get_data() );
	}
}
