<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization\WordPress;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Authorization\WordPress\AuthorizationLifecycle;
use Stonewright\WpMcp\Authorization\WordPress\CredentialKeys;
use Stonewright\WpMcp\Authorization\WordPress\HttpSurface;
use Stonewright\WpMcp\Authorization\WordPress\OAuthRestRoutes;
use Stonewright\WpMcp\Core\RestRoutes;
use Stonewright\WpMcp\Security\AuditLog;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\BodyRequest;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\HttpRig;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\StorageRig;

/**
 * A request to an OAuth route runs through the REST server's hooks: the pre-dispatch
 * hook, the route callback, then the post-dispatch hook. The OAuth recorder is the only
 * audit path of those routes, so an identifier a caller made up is named in no audit
 * row, a client the site knows is named once, and a refusal is counted once.
 *
 * @covers \Stonewright\WpMcp\Core\RestRoutes
 * @covers \Stonewright\WpMcp\Authorization\WordPress\HttpSurface
 * @covers \Stonewright\WpMcp\Authorization\WordPress\IntrospectionEndpoint
 */
final class RestDispatchAuditTest extends TestCase {

	private const MADE_UP = [ 'made-up-client-one', 'made-up-client-two', 'made-up-client-three', 'made-up-client-four' ];

	private HttpRig $http;
	private string $client;

	protected function setUp(): void {
		StorageRig::reset_globals();
		$GLOBALS['stonewright_test_user_caps_by_id'] = [ 7 => [ 'read' => true ] ];
		$GLOBALS['stonewright_test_user_caps'] = [];
		$GLOBALS['stonewright_test_wpdb_inserts'] = [];
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_filters'] = [];
		AuditLog::reset_request_state();
		$this->http = new HttpRig( 'pretty', [ 'token' => [ 1000, 60 ], 'revocation' => [ 1000, 60 ], 'introspection' => [ 1000, 60 ] ] );
		HttpSurface::use_site( $this->http->site );
		HttpSurface::use_limiter( $this->http->limiter );
		HttpSurface::use_documents( $this->http->documents );
		AuthorizationLifecycle::use_storage( $this->http->storage );
		$this->client = $this->http->rig->register_client();
	}

	protected function tearDown(): void {
		HttpSurface::use_site( null );
		HttpSurface::use_limiter( null );
		HttpSurface::use_documents( null );
		AuthorizationLifecycle::use_storage( null );
		StorageRig::reset_globals();
		$GLOBALS['stonewright_test_user_caps'] = [];
		$GLOBALS['stonewright_test_wpdb_inserts'] = [];
		$GLOBALS['stonewright_test_transients'] = [];
		AuditLog::reset_request_state();
		unset( $_SERVER['REMOTE_ADDR'] );
	}

	/**
	 * One request the way the REST server handles it: a form post is decoded into body
	 * parameters first, then the pre-dispatch hook, the route callback and the
	 * post-dispatch hook run in that order.
	 *
	 * @param array<string, string> $fields
	 */
	private function dispatch( string $route, array $fields ): \WP_REST_Response {
		$request = new BodyRequest( '/stonewright/v1/oauth/' . $route, http_build_query( $fields, '', '&', PHP_QUERY_RFC3986 ), [ 'content_type' => 'application/x-www-form-urlencoded' ] );
		$request->set_body_params( $fields );
		RestRoutes::audit_pre_dispatch( null, null, $request );
		$response = match ( $route ) {
			'revoke'     => OAuthRestRoutes::revocation( $request ),
			'introspect' => OAuthRestRoutes::introspection( $request ),
			default      => OAuthRestRoutes::token( $request ),
		};
		self::assertSame( $response, RestRoutes::audit_post_dispatch( $response, null, $request ) );
		return $response;
	}

	/** @return list<array<string, mixed>> Audit rows of one ability, oldest first. */
	private static function rows( string $ability ): array {
		$rows = [];
		foreach ( $GLOBALS['stonewright_test_wpdb_inserts'] as $insert ) {
			if ( str_ends_with( (string) $insert['table'], 'stonewright_audit_log' ) && $ability === ( $insert['data']['ability_name'] ?? null ) ) {
				$rows[] = $insert['data'];
			}
		}
		return $rows;
	}

	/**
	 * @param list<string> $identifiers
	 * @return list<string> The identifiers that appear anywhere in a stored audit row.
	 */
	private static function named( array $identifiers ): array {
		$recorded = (string) json_encode( $GLOBALS['stonewright_test_wpdb_inserts'] );
		return array_values( array_filter( $identifiers, static fn ( string $identifier ): bool => str_contains( $recorded, $identifier ) ) );
	}

	/** @return array<string, mixed> */
	private static function args( array $row ): array {
		return (array) json_decode( (string) $row['sanitized_args'], true );
	}

	public function test_made_up_client_identifiers_are_named_in_no_audit_row(): void {
		foreach ( self::MADE_UP as $made_up ) {
			$this->dispatch( 'token', [ 'grant_type' => 'authorization_code', 'code' => 'sentinel-garbage-code', 'redirect_uri' => StorageRig::REDIRECT, 'client_id' => $made_up, 'code_verifier' => StorageRig::VERIFIER ] );
			$this->dispatch( 'token', [ 'grant_type' => 'refresh_token', 'refresh_token' => 'sentinel-garbage-refresh', 'client_id' => $made_up ] );
			$this->dispatch( 'token', [ 'grant_type' => 'password', 'client_id' => $made_up ] );
			$this->dispatch( 'revoke', [ 'token' => 'sentinel-garbage-token', 'client_id' => $made_up ] );
		}

		self::assertSame( [], self::named( self::MADE_UP ), 'Made-up client identifiers reached the audit log.' );
		self::assertCount( 2, self::rows( 'oauth/token' ), 'Each kind of refusal keeps one row without a client.' );
		self::assertCount( 1, self::rows( 'oauth/revoke' ) );
		foreach ( [ ...self::rows( 'oauth/token' ), ...self::rows( 'oauth/revoke' ) ] as $row ) {
			self::assertArrayNotHasKey( 'client_id', self::args( $row ) );
		}
	}

	public function test_a_client_the_site_knows_is_named_once(): void {
		foreach ( self::MADE_UP as $made_up ) {
			$this->dispatch( 'token', [ 'grant_type' => 'refresh_token', 'refresh_token' => 'sentinel-garbage-refresh', 'client_id' => $made_up ] );
		}
		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$this->dispatch( 'token', [ 'grant_type' => 'refresh_token', 'refresh_token' => 'sentinel-garbage-refresh', 'client_id' => $this->client ] );
		}

		$tokens = self::rows( 'oauth/token' );
		self::assertCount( 2, $tokens, 'One row without a client and one for the registered client.' );
		self::assertArrayNotHasKey( 'client_id', self::args( $tokens[0] ) );
		self::assertSame( $this->client, self::args( $tokens[1] )['client_id'] );
	}

	public function test_a_refusal_is_counted_once_so_the_aggregate_row_comes_at_the_twenty_fifth(): void {
		$fields = [ 'grant_type' => 'refresh_token', 'refresh_token' => 'sentinel-garbage-refresh', 'client_id' => $this->client ];
		for ( $attempt = 1; $attempt <= 24; $attempt++ ) {
			$this->dispatch( 'token', $fields );
		}
		self::assertCount( 1, self::rows( 'oauth/token' ), 'Twenty-four identical refusals stay one row.' );

		$this->dispatch( 'token', $fields );

		$tokens = self::rows( 'oauth/token' );
		self::assertCount( 2, $tokens );
		self::assertSame( 24, self::args( $tokens[1] )['_meta']['coalesced_count'] ?? null );
	}

	public function test_a_server_fault_of_an_endpoint_stays_one_error_row(): void {
		$code = $this->http->rig->authorize( $this->client );
		unset( $GLOBALS['stonewright_test_options'][ CredentialKeys::PRIVATE_KEY_OPTION ] );

		$response = $this->dispatch( 'token', [ 'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => StorageRig::REDIRECT, 'client_id' => $this->client, 'code_verifier' => StorageRig::VERIFIER ] );

		self::assertSame( 500, $response->get_status() );
		$rows = self::rows( 'oauth/token' );
		self::assertCount( 1, $rows );
		self::assertSame( 'error', $rows[0]['result_status'] );
		self::assertSame( 'high', $rows[0]['severity'] );
		self::assertSame( [], self::named( [ $code ] ), 'The authorization code reached the audit log.' );
	}

	public function test_introspection_names_a_client_only_when_the_site_knows_it(): void {
		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true ];
		foreach ( self::MADE_UP as $made_up ) {
			self::assertSame( 200, $this->dispatch( 'introspect', [ 'token' => 'sentinel-garbage-token', 'client_id' => $made_up ] )->get_status() );
		}
		$this->dispatch( 'introspect', [ 'token' => 'sentinel-garbage-token', 'client_id' => $this->client ] );

		self::assertSame( [], self::named( self::MADE_UP ), 'Made-up client identifiers reached the audit log.' );
		$rows = self::rows( 'oauth/introspect' );
		self::assertCount( 2, $rows );
		self::assertArrayNotHasKey( 'client_id', self::args( $rows[0] ) );
		self::assertSame( $this->client, self::args( $rows[1] )['client_id'] );
	}

	public function test_the_generic_rest_hook_leaves_the_oauth_routes_to_their_own_recorder(): void {
		$request = new BodyRequest( '/stonewright/v1/oauth/token', '', [ 'content_type' => 'application/x-www-form-urlencoded' ] );
		$request->set_body_params( [ 'grant_type' => 'refresh_token', 'client_id' => self::MADE_UP[0] ] );
		RestRoutes::audit_pre_dispatch( null, null, $request );

		foreach ( [ new \WP_REST_Response( [ 'error' => 'invalid_grant' ], 400 ), new \WP_REST_Response( [ 'token_type' => 'Bearer' ], 200 ), new \WP_Error( 'server_error', 'Failure', [ 'status' => 500 ] ) ] as $answer ) {
			self::assertSame( $answer, RestRoutes::audit_post_dispatch( $answer, null, $request ) );
		}

		self::assertSame( [], $GLOBALS['stonewright_test_wpdb_inserts'] );
	}
}
