<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization\WordPress;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Authorization\WordPress\AuthorizationLifecycle;
use Stonewright\WpMcp\Authorization\WordPress\HttpSurface;
use Stonewright\WpMcp\Authorization\WordPress\OAuthRestRoutes;
use Stonewright\WpMcp\Authorization\WordPress\RegistrationEndpoint;
use Stonewright\WpMcp\Authorization\WordPress\RequestLimiter;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\BodyRequest;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\HttpRig;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\StorageRig;

/**
 * @covers \Stonewright\WpMcp\Authorization\WordPress\OAuthRestRoutes
 * @covers \Stonewright\WpMcp\Authorization\WordPress\HttpSurface
 */
final class OAuthRestRoutesTest extends TestCase {

	private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D';

	private HttpRig $http;

	protected function setUp(): void {
		StorageRig::reset_globals();
		$GLOBALS['stonewright_test_user_caps_by_id'] = [ 7 => [ 'read' => true ] ];
		$GLOBALS['stonewright_test_rest_routes'] = [];
		$GLOBALS['stonewright_test_wpdb_inserts'] = [];
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_filters'] = [];
		$this->http = new HttpRig( 'pretty', [ 'token' => [ 3, 60 ], 'registration' => [ 20, 3600 ], 'revocation' => [ 30, 60 ], 'introspection' => [ 60, 60 ] ] );
		HttpSurface::use_site( $this->http->site );
		HttpSurface::use_limiter( $this->http->limiter );
		HttpSurface::use_documents( $this->http->documents );
		AuthorizationLifecycle::use_storage( $this->http->storage );
	}

	protected function tearDown(): void {
		HttpSurface::use_site( null );
		HttpSurface::use_limiter( null );
		HttpSurface::use_documents( null );
		AuthorizationLifecycle::use_storage( null );
		StorageRig::reset_globals();
		$GLOBALS['stonewright_test_rest_routes'] = [];
		$GLOBALS['stonewright_test_wpdb_inserts'] = [];
		$GLOBALS['stonewright_test_transients'] = [];
		unset( $_SERVER['REMOTE_ADDR'] );
	}

	/** @param array<string, string> $fields */
	private static function form( string $route, array $fields ): BodyRequest {
		return new BodyRequest( '/stonewright/v1/oauth/' . $route, http_build_query( $fields, '', '&', PHP_QUERY_RFC3986 ), [ 'content_type' => 'application/x-www-form-urlencoded' ] );
	}

	/** @return list<array<string, mixed>> */
	private static function audit_rows(): array {
		$rows = [];
		foreach ( $GLOBALS['stonewright_test_wpdb_inserts'] as $insert ) {
			if ( str_ends_with( (string) $insert['table'], 'stonewright_audit_log' ) ) {
				$rows[] = $insert['data'];
			}
		}
		return $rows;
	}

	public function test_routes_exist_only_while_oauth_is_available(): void {
		HttpSurface::use_site( HttpRig::site( false ) );
		OAuthRestRoutes::register();
		self::assertSame( [], $GLOBALS['stonewright_test_rest_routes'] );

		HttpSurface::use_site( $this->http->site );
		OAuthRestRoutes::register();

		$routes = [];
		foreach ( $GLOBALS['stonewright_test_rest_routes'] as $route ) {
			self::assertSame( 'stonewright/v1', $route['namespace'] );
			self::assertSame( 'POST', $route['args']['methods'] );
			$routes[ $route['route'] ] = $route['args']['permission_callback'];
		}
		self::assertSame( [ '/oauth/register', '/oauth/token', '/oauth/revoke', '/oauth/introspect' ], array_keys( $routes ) );
		foreach ( [ '/oauth/register', '/oauth/token', '/oauth/revoke' ] as $public ) {
			self::assertSame( [ OAuthRestRoutes::class, 'public_client_permission' ], $routes[ $public ] );
		}
		self::assertSame( [ OAuthRestRoutes::class, 'introspection_permission' ], $routes['/oauth/introspect'] );
		self::assertTrue( OAuthRestRoutes::public_client_permission() );
		HttpSurface::use_site( HttpRig::site( false ) );
		self::assertFalse( OAuthRestRoutes::public_client_permission() );
	}

	public function test_a_token_request_is_answered_and_audited_without_credentials(): void {
		$client = $this->http->rig->register_client();
		$code = $this->http->rig->authorize( $client );

		$response = OAuthRestRoutes::token( self::form( 'token', [ 'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => StorageRig::REDIRECT, 'client_id' => $client, 'code_verifier' => StorageRig::VERIFIER, 'resource' => StorageRig::RESOURCE ] ) );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( 'Bearer', $response->get_data()['token_type'] );
		self::assertSame( 'no-store', $response->get_headers()['Cache-Control'] );
		self::assertArrayNotHasKey( 'X-Stonewright-Correlation-ID', $response->get_headers() );
		$audit = self::audit_rows();
		self::assertCount( 1, $audit );
		self::assertSame( 'oauth/token', $audit[0]['ability_name'] );
		self::assertSame( 'ok', $audit[0]['result_status'] );
		$recorded = (string) json_encode( $GLOBALS['stonewright_test_wpdb_inserts'] );
		foreach ( [ $code, StorageRig::VERIFIER, $response->get_data()['access_token'], $response->get_data()['refresh_token'] ] as $secret ) {
			self::assertStringNotContainsString( $secret, $recorded );
		}
		self::assertStringContainsString( $client, (string) $audit[0]['sanitized_args'] );
	}

	public function test_errors_carry_a_correlation_id_that_matches_the_audit_row(): void {
		$response = OAuthRestRoutes::token( self::form( 'token', [ 'grant_type' => 'authorization_code', 'code' => 'invalid-code-1', 'redirect_uri' => StorageRig::REDIRECT, 'client_id' => $this->http->rig->register_client(), 'code_verifier' => StorageRig::VERIFIER ] ) );

		self::assertSame( 400, $response->get_status() );
		self::assertSame( 'invalid_grant', $response->get_data()['error'] );
		$correlation = $response->get_headers()['X-Stonewright-Correlation-ID'];
		self::assertMatchesRegularExpression( self::UUID, $correlation );
		self::assertSame( '0', $response->get_headers()['X-Stonewright-Refresh-Consumed'] );
		self::assertSame( 'auth', self::audit_rows()[0]['result_status'] );
		self::assertSame( $correlation, self::audit_rows()[0]['correlation_id'] );
	}

	public function test_a_burst_is_limited_with_the_observed_answer(): void {
		$client = $this->http->rig->register_client();
		$fields = [ 'grant_type' => 'authorization_code', 'code' => 'invalid-code', 'redirect_uri' => StorageRig::REDIRECT, 'client_id' => $client, 'code_verifier' => StorageRig::VERIFIER ];
		for ( $request = 0; $request < 3; $request++ ) {
			self::assertSame( 400, OAuthRestRoutes::token( self::form( 'token', $fields ) )->get_status() );
		}

		$limited = OAuthRestRoutes::token( self::form( 'token', $fields ) );

		self::assertSame( 429, $limited->get_status() );
		self::assertSame( [ 'error' => 'temporarily_unavailable', 'reason' => 'rate_limited' ], $limited->get_data() );
		self::assertSame( '60', $limited->get_headers()['Retry-After'] );
		self::assertSame( '0', $limited->get_headers()['X-Stonewright-Refresh-Consumed'] );
		self::assertMatchesRegularExpression( self::UUID, $limited->get_headers()['X-Stonewright-Correlation-ID'] );
		$registration = OAuthRestRoutes::registration( new BodyRequest( '/stonewright/v1/oauth/register', (string) json_encode( [ 'redirect_uris' => [ StorageRig::REDIRECT ], 'token_endpoint_auth_method' => 'none' ] ), [ 'content_type' => 'application/json' ] ) );
		self::assertSame( 201, $registration->get_status() );
		self::assertSame( RequestLimiter::bucket( 'token', '192.0.2.10' ), $this->http->rig->rows( 'rate_limits' )[0]['bucket_key'] );
	}

	public function test_registration_and_revocation_answer_through_the_rest_shape(): void {
		$registered = OAuthRestRoutes::registration( new BodyRequest( '/stonewright/v1/oauth/register', (string) json_encode( [ 'client_name' => 'Synthetic', 'redirect_uris' => [ StorageRig::REDIRECT ], 'token_endpoint_auth_method' => 'none' ] ), [ 'content_type' => 'application/json' ] ) );
		self::assertSame( 201, $registered->get_status() );
		self::assertSame( 'oauth/register', self::audit_rows()[0]['ability_name'] );

		[ $client, $pair ] = $this->http->rig->connect();
		$revoked = OAuthRestRoutes::revocation( self::form( 'revoke', [ 'token' => $pair->refresh_token, 'client_id' => $client ] ) );
		self::assertSame( 200, $revoked->get_status() );
		self::assertNull( $revoked->get_data() );
		self::assertTrue( OAuthRestRoutes::serve_empty_body( false, $revoked, self::form( 'revoke', [] ) ) );
		self::assertFalse( OAuthRestRoutes::serve_empty_body( false, $registered, new BodyRequest( '/stonewright/v1/oauth/register', '' ) ) );
		self::assertSame( 'revoked', $this->http->rig->rows( 'families' )[0]['phase'] );
	}

	public function test_a_diagnostics_self_test_header_reaches_the_registration_endpoint(): void {
		$token = str_repeat( 'ef', 16 );
		$hash = hash( 'sha256', $token );
		set_transient( RegistrationEndpoint::SELF_TEST_TRANSIENT . $hash, $hash, 30 );

		$response = OAuthRestRoutes::registration( new BodyRequest( '/stonewright/v1/oauth/register', (string) json_encode( [ 'client_name' => 'Stonewright diagnostics', 'redirect_uris' => [ 'http://127.0.0.1/stonewright-oauth/callback' ], 'grant_types' => [ 'authorization_code', 'refresh_token' ], 'token_endpoint_auth_method' => 'none' ] ), [ 'content_type' => 'application/json', 'x_stonewright_self_test' => $token ] ) );

		self::assertSame( 201, $response->get_status() );
		self::assertSame( [], $this->http->rig->rows( 'clients' ) );
	}

	public function test_introspection_requires_a_site_administrator(): void {
		[ , $pair ] = $this->http->rig->connect();
		$GLOBALS['stonewright_test_user_caps'] = [];
		self::assertFalse( OAuthRestRoutes::introspection_permission() );
		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true ];
		self::assertTrue( OAuthRestRoutes::introspection_permission() );

		$response = OAuthRestRoutes::introspection( self::form( 'introspect', [ 'token' => $pair->access_token ] ) );

		self::assertSame( 200, $response->get_status() );
		self::assertTrue( $response->get_data()['active'] );
		self::assertSame( 'oauth/introspect', self::audit_rows()[0]['ability_name'] );
	}
}
