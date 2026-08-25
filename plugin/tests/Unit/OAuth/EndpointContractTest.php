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
use Stonewright\WpMcp\OAuth\Repositories\ClientRepository;
use WP_REST_Request;
use WP_REST_Response;

final class EndpointContractTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_rest_routes'] = [];
		$GLOBALS['stonewright_test_transients']  = [];
		$GLOBALS['stonewright_test_transient_ttls'] = [];
		$GLOBALS['stonewright_test_scheduled_hooks'] = [];
		$GLOBALS['stonewright_test_wpdb_inserts'] = [];
		$_SERVER['REMOTE_ADDR']                  = '';
		$GLOBALS['stonewright_test_filters']     = [];
		if ( isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) ) {
			$GLOBALS['wpdb']->oauth_clients = [];
		}
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

	public function test_self_test_registration_is_ephemeral_and_deleted_before_return(): void {
		$request = $this->self_test_request( $this->valid_dcr_body() );
		$result  = Register::handle( $request );
		$repository = new ClientRepository();

		self::assertInstanceOf( WP_REST_Response::class, $result );
		$http_status = $result->get_status();
		$payload     = $result->get_data();
		self::assertSame( 201, $http_status );
		self::assertIsArray( $payload );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', (string) $payload['client_id'] );
		self::assertSame( 'Stonewright diagnostics', $payload['client_name'] );
		self::assertSame( [ 'http://127.0.0.1/stonewright-oauth/callback' ], $payload['redirect_uris'] );
		self::assertSame( [ 'authorization_code', 'refresh_token' ], $payload['grant_types'] );
		self::assertSame( 'none', $payload['token_endpoint_auth_method'] );
		self::assertSame( 0, $repository->count_ephemeral_clients() );
		self::assertArrayHasKey( ClientRepository::EPHEMERAL_GC_HOOK, $GLOBALS['stonewright_test_scheduled_hooks'] );
	}

	public function test_self_test_token_is_consumed_and_not_reusable(): void {
		$body  = $this->valid_dcr_body();
		$first = Register::handle( $this->self_test_request( $body ) );
		self::assertSame( 201, $first->get_status() );

		$replay = new WP_REST_Request( 'POST', '/oauth/register' );
		$replay->set_header( 'x-stonewright-self-test', 'diagnostic-self-test-token' );
		self::assertFalse( Register::is_self_test_request( $replay ) );
		self::assertSame( 0, ( new ClientRepository() )->count_ephemeral_clients() );
	}

	public function test_empty_self_test_body_is_invalid_request(): void {
		$request = $this->self_test_request( [] );
		$result  = Register::handle( $request );

		self::assertSame( 'invalid_request', $result->get_error_code() );
		self::assertSame( 400, $result->get_error_data()['status'] );
		self::assertSame( 0, ( new ClientRepository() )->count_ephemeral_clients() );
	}

	/**
	 * @param array<string, mixed> $body
	 */
	private function self_test_request( array $body, string $token = 'diagnostic-self-test-token' ): WP_REST_Request {
		$hash = hash( 'sha256', $token );
		set_transient( 'stonewright_oauth_selftest_' . $hash, $hash, 30 );
		$request = new WP_REST_Request( 'POST', '/oauth/register' );
		$request->set_header( 'x-stonewright-self-test', $token );
		$request->set_json_params( $body );
		return $request;
	}

	/**
	 * @return array{client_name: string, redirect_uris: list<string>, grant_types: list<string>, token_endpoint_auth_method: string}
	 */
	private function valid_dcr_body(): array {
		return [
			'client_name'                => 'Stonewright diagnostics',
			'redirect_uris'              => [ 'http://127.0.0.1/stonewright-oauth/callback' ],
			'grant_types'                => [ 'authorization_code', 'refresh_token' ],
			'token_endpoint_auth_method' => 'none',
		];
	}
}
