<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization\WordPress;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Authorization\WordPress\AuthorizationLifecycle;
use Stonewright\WpMcp\Authorization\WordPress\HttpSurface;
use Stonewright\WpMcp\Authorization\WordPress\ProtectedResource;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\HttpRig;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\StorageRig;

/**
 * @covers \Stonewright\WpMcp\Authorization\WordPress\ProtectedResource
 */
final class ProtectedResourceTest extends TestCase {

	private const CHALLENGE = 'Bearer resource_metadata="https://example.test/.well-known/oauth-protected-resource", scope="mcp"';

	private HttpRig $http;

	protected function setUp(): void {
		StorageRig::reset_globals();
		$GLOBALS['stonewright_test_user_caps_by_id'] = [ 7 => [ 'read' => true ] ];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
		$this->http = new HttpRig();
		HttpSurface::use_site( $this->http->site );
		AuthorizationLifecycle::use_storage( $this->http->storage );
	}

	protected function tearDown(): void {
		HttpSurface::use_site( null );
		AuthorizationLifecycle::use_storage( null );
		StorageRig::reset_globals();
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'], $_SERVER['REMOTE_ADDR'] );
	}

	private static function request( string $authorization = '', string $route = '/mcp/stonewright-oauth', string $method = 'POST' ): \WP_REST_Request {
		$request = new \WP_REST_Request( $method, $route );
		if ( '' !== $authorization ) {
			$request->set_header( 'authorization', $authorization );
		}
		return $request;
	}

	private static function assert_required( mixed $response ): void {
		self::assertInstanceOf( \WP_REST_Response::class, $response );
		self::assertSame( 401, $response->get_status() );
		self::assertSame( [ 'code' => 'rest_oauth_required', 'message' => 'OAuth authentication required.' ], $response->get_data() );
		self::assertSame( self::CHALLENGE, $response->get_headers()['WWW-Authenticate'] );
	}

	private static function assert_invalid( mixed $response ): void {
		self::assertInstanceOf( \WP_REST_Response::class, $response );
		self::assertSame( 401, $response->get_status() );
		self::assertSame( [ 'code' => 'rest_oauth_error', 'message' => 'The access token is invalid or expired.', 'data' => [ 'status' => 401 ] ], $response->get_data() );
		self::assertStringContainsString( 'error="invalid_token"', $response->get_headers()['WWW-Authenticate'] );
		self::assertStringContainsString( 'resource_metadata="https://example.test/.well-known/oauth-protected-resource"', $response->get_headers()['WWW-Authenticate'] );
	}

	public function test_a_request_without_a_bearer_gets_the_observed_challenge(): void {
		self::assert_required( ProtectedResource::guard( null, null, self::request() ) );
		self::assert_required( ProtectedResource::guard( null, null, self::request( '', '/MCP/Stonewright-OAuth', 'GET' ) ) );
	}

	public function test_basic_credentials_are_refused_on_the_oauth_route(): void {
		self::assert_required( ProtectedResource::guard( null, null, self::request( 'Basic ' . base64_encode( 'admin:fixture-app-password' ) ) ) );
	}

	public function test_an_unknown_bearer_gets_the_invalid_token_challenge(): void {
		self::assert_invalid( ProtectedResource::guard( null, null, self::request( 'Bearer not.a.token' ) ) );
		self::assert_invalid( ProtectedResource::guard( null, null, self::request( 'Bearer garbage' ) ) );
	}

	public function test_a_valid_bearer_signs_in_its_subject_for_this_request_only(): void {
		[ , $pair ] = $this->http->rig->connect();
		$request = self::request( 'Bearer ' . $pair->access_token );

		self::assertNull( ProtectedResource::guard( null, null, $request ) );

		self::assertSame( 7, get_current_user_id() );
		self::assertTrue( ProtectedResource::permit( $request ) );
		self::assertFalse( ProtectedResource::permit( self::request( 'Bearer ' . $pair->access_token ) ) );
		$response = new \WP_REST_Response( [ 'jsonrpc' => '2.0' ], 200 );
		self::assertSame( $response, ProtectedResource::finish( $response, null, $request ) );
	}

	public function test_revoked_expired_and_unauthorized_bearers_are_refused(): void {
		[ , $pair, $outcome ] = $this->http->rig->connect();

		$this->http->rig->at( StorageRig::T + 3600 );
		self::assert_invalid( ProtectedResource::guard( null, null, self::request( 'Bearer ' . $pair->access_token ) ) );
		$this->http->rig->at( StorageRig::T + 10 );
		$GLOBALS['stonewright_test_user_caps_by_id'] = [];
		self::assert_invalid( ProtectedResource::guard( null, null, self::request( 'Bearer ' . $pair->access_token ) ) );
		$GLOBALS['stonewright_test_user_caps_by_id'] = [ 7 => [ 'read' => true ] ];
		$this->http->rig->access->revoke( $outcome->issuance->access_key );
		self::assert_invalid( ProtectedResource::guard( null, null, self::request( 'Bearer ' . $pair->access_token ) ) );
		self::assertSame( 0, get_current_user_id() );
	}

	public function test_other_routes_preflights_and_earlier_answers_pass_through(): void {
		self::assertNull( ProtectedResource::guard( null, null, self::request( '', '/mcp/stonewright' ) ) );
		self::assertNull( ProtectedResource::guard( null, null, self::request( '', '/stonewright/v1/oauth/token' ) ) );
		self::assertNull( ProtectedResource::guard( null, null, self::request( '', '/mcp/stonewright-oauth', 'OPTIONS' ) ) );
		$earlier = new \WP_REST_Response( [], 204 );
		self::assertSame( $earlier, ProtectedResource::guard( $earlier, null, self::request() ) );
	}

	public function test_nothing_is_challenged_while_oauth_is_unavailable_and_nobody_is_permitted(): void {
		HttpSurface::use_site( HttpRig::site( false ) );
		$request = self::request();

		self::assertNull( ProtectedResource::guard( null, null, $request ) );
		self::assertFalse( ProtectedResource::permit( $request ) );
		$core = new \WP_REST_Response( [ 'code' => 'rest_forbidden' ], 401 );
		self::assertSame( $core, ProtectedResource::finish( $core, null, $request ) );
	}

	public function test_an_authentication_error_before_dispatch_becomes_the_challenge(): void {
		$request = self::request( 'Basic ' . base64_encode( 'admin:wrong-fixture-password' ) );

		self::assert_required( ProtectedResource::finish( new \WP_REST_Response( [ 'code' => 'incorrect_password' ], 401 ), null, $request ) );
		$other = new \WP_REST_Response( [ 'code' => 'incorrect_password' ], 401 );
		self::assertSame( $other, ProtectedResource::finish( $other, null, self::request( '', '/mcp/stonewright' ) ) );
		$preflight = new \WP_REST_Response( null, 200 );
		self::assertSame( $preflight, ProtectedResource::finish( $preflight, null, self::request( '', '/mcp/stonewright-oauth', 'OPTIONS' ) ) );
	}

	public function test_a_challenge_from_the_guard_is_not_replaced(): void {
		$request = self::request( 'Bearer garbage' );
		$challenge = ProtectedResource::guard( null, null, $request );

		self::assertSame( $challenge, ProtectedResource::finish( $challenge, null, $request ) );
	}

	/** @dataProvider routes */
	public function test_route_classification( string $route, bool $protected ): void {
		self::assertSame( $protected, ProtectedResource::is_protected_route( $route ) );
	}

	public function routes(): array {
		return [
			[ '/mcp/stonewright-oauth', true ],
			[ '/mcp/stonewright-oauth/', true ],
			[ '/MCP/Stonewright-OAuth', true ],
			[ '/mcp/stonewright-oauth/tools/list', true ],
			[ '/wp-json/mcp/stonewright-oauth', true ],
			[ '/index.php/wp-json/mcp/stonewright-oauth', true ],
			[ 'mcp/stonewright-oauth', true ],
			[ '/mcp/stonewright', false ],
			[ '/mcp/stonewright-oauthx', false ],
			[ '/mcp/mcp-adapter-default-server', false ],
			[ '/wp-json/wp/v2/pages', false ],
			[ '/stonewright/v1/oauth/token', false ],
			[ '', false ],
		];
	}

	/** @dataProvider authorization_headers */
	public function test_bearer_tokens_follow_the_rfc_6750_syntax( string $header, ?string $token ): void {
		self::assertSame( $token, ProtectedResource::bearer_token( $header ) );
	}

	public function authorization_headers(): array {
		return [
			[ 'Bearer abc.def-ghi_jkl~mno+/==', 'abc.def-ghi_jkl~mno+/==' ],
			[ 'bearer   abc', 'abc' ],
			[ ' Bearer abc ', 'abc' ],
			[ 'Basic abc', null ],
			[ 'Bearer', null ],
			[ 'Bearer a b', null ],
			[ 'Bearer a,b', null ],
			[ '', null ],
		];
	}

	public function test_the_authorization_header_has_server_fallbacks(): void {
		self::assertSame( '', ProtectedResource::authorization_header() );
		$_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer from-redirect';
		self::assertSame( 'Bearer from-redirect', ProtectedResource::authorization_header() );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer from-server';
		self::assertSame( 'Bearer from-server', ProtectedResource::authorization_header() );
	}

	public function test_the_current_user_must_be_signed_in_with_mcp_access(): void {
		$GLOBALS['stonewright_test_user_caps'] = [ 'read' => true ];
		self::assertFalse( ProtectedResource::current_user_can_use_mcp() );
		$GLOBALS['stonewright_test_current_user_id'] = 17;
		self::assertTrue( ProtectedResource::current_user_can_use_mcp() );
		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true ];
		self::assertTrue( ProtectedResource::current_user_can_use_mcp() );
		$GLOBALS['stonewright_test_user_caps'] = [];
		self::assertFalse( ProtectedResource::current_user_can_use_mcp() );
	}
}
