<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization\WordPress;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Authorization\WordPress\AuthorizationLifecycle;
use Stonewright\WpMcp\Authorization\WordPress\HttpSurface;
use Stonewright\WpMcp\Authorization\WordPress\OAuthRestRoutes;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\BodyRequest;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\HttpRig;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\StorageRig;

/**
 * Replays that revoke a family, explicit revocations and duplicate deliveries are
 * security events: each one is its own audit row, never folded into the counters of
 * ordinary refusals, and no row holds a credential.
 *
 * @covers \Stonewright\WpMcp\Authorization\WordPress\HttpSurface
 * @covers \Stonewright\WpMcp\Authorization\WordPress\TokenEndpoint
 * @covers \Stonewright\WpMcp\Authorization\WordPress\RevocationEndpoint
 */
final class SecurityAuditTest extends TestCase {

	private HttpRig $http;
	private string $client;

	/** @var list<string> Every credential and verifier the test handled. */
	private array $secrets = [ StorageRig::VERIFIER ];

	protected function setUp(): void {
		StorageRig::reset_globals();
		$GLOBALS['stonewright_test_user_caps_by_id'] = [ 7 => [ 'read' => true ] ];
		$GLOBALS['stonewright_test_wpdb_inserts'] = [];
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_filters'] = [];
		$this->http = new HttpRig( 'pretty', [ 'token' => [ 1000, 60 ], 'revocation' => [ 1000, 60 ] ] );
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
		$GLOBALS['stonewright_test_wpdb_inserts'] = [];
		$GLOBALS['stonewright_test_transients'] = [];
		unset( $_SERVER['REMOTE_ADDR'] );
	}

	/** @param array<string, string> $fields */
	private function post( string $route, array $fields ): \WP_REST_Response {
		$request = new BodyRequest( '/stonewright/v1/oauth/' . $route, http_build_query( $fields, '', '&', PHP_QUERY_RFC3986 ), [ 'content_type' => 'application/x-www-form-urlencoded' ] );
		return 'revoke' === $route ? OAuthRestRoutes::revocation( $request ) : OAuthRestRoutes::token( $request );
	}

	/** @return array<string, mixed> Token response of a new grant at $time. */
	private function connect( int $time ): array {
		$this->http->rig->at( $time );
		$code = $this->http->rig->authorize( $this->client );
		$this->secrets[] = $code;
		$tokens = (array) $this->post( 'token', [ 'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => StorageRig::REDIRECT, 'client_id' => $this->client, 'code_verifier' => StorageRig::VERIFIER, 'resource' => StorageRig::RESOURCE ] )->get_data();
		self::assertArrayHasKey( 'refresh_token', $tokens );
		$this->remember( $tokens );
		return $tokens;
	}

	/** @return array<string, mixed> */
	private function refresh( string $refresh_token, int $time ): array {
		$this->http->rig->at( $time );
		$data = (array) $this->post( 'token', [ 'grant_type' => 'refresh_token', 'refresh_token' => $refresh_token, 'client_id' => $this->client ] )->get_data();
		$this->remember( $data );
		return $data;
	}

	/** @param array<string, mixed> $tokens */
	private function remember( array $tokens ): void {
		foreach ( [ 'access_token', 'refresh_token' ] as $name ) {
			if ( isset( $tokens[ $name ] ) && is_string( $tokens[ $name ] ) ) {
				$this->secrets[] = $tokens[ $name ];
			}
		}
	}

	/** @return list<array<string, mixed>> Audit rows of one ability. */
	private static function rows( string $ability ): array {
		$rows = [];
		foreach ( $GLOBALS['stonewright_test_wpdb_inserts'] as $insert ) {
			if ( str_ends_with( (string) $insert['table'], 'stonewright_audit_log' ) && $ability === ( $insert['data']['ability_name'] ?? null ) ) {
				$rows[] = $insert['data'];
			}
		}
		return $rows;
	}

	/** @return list<array<string, mixed>> Rows recorded as the given security event. */
	private static function events( string $ability, string $event ): array {
		return array_values(
			array_filter(
				self::rows( $ability ),
				static fn ( array $row ): bool => $event === ( json_decode( (string) $row['sanitized_args'], true )['_meta']['security_event'] ?? null )
			)
		);
	}

	private function assert_no_credentials(): void {
		$recorded = (string) json_encode( $GLOBALS['stonewright_test_wpdb_inserts'] );
		foreach ( $this->secrets as $secret ) {
			self::assertStringNotContainsString( $secret, $recorded );
		}
	}

	public function test_every_replay_that_revokes_a_family_is_its_own_audit_row(): void {
		for ( $family = 1; $family <= 4; $family++ ) {
			$start = StorageRig::T + $family * 1000;
			$tokens = $this->connect( $start );
			$this->refresh( $tokens['refresh_token'], $start + 1 );
			$replay = $this->refresh( $tokens['refresh_token'], $start + 200 );
			self::assertSame( 'refresh_token_revoked', $replay['reason'] ?? null );
		}

		$hinted = array_filter( self::rows( 'oauth/token' ), static fn ( array $row ): bool => str_contains( (string) ( json_decode( (string) $row['sanitized_args'], true )['oauth_hint'] ?? '' ), 'replay' ) );
		self::assertCount( 4, $hinted, 'Each replay revocation needs its own audit row.' );
		$replays = self::events( 'oauth/token', 'refresh_replay_revoked' );
		self::assertCount( 4, $replays );
		foreach ( $replays as $row ) {
			self::assertSame( 'auth', $row['result_status'] );
			$args = json_decode( (string) $row['sanitized_args'], true );
			self::assertSame( $this->client, $args['client_id'] );
			self::assertSame( 'invalid_grant', $args['oauth_error'] );
		}
		$this->assert_no_credentials();
	}

	public function test_every_code_replay_that_revokes_a_family_is_its_own_audit_row(): void {
		for ( $family = 1; $family <= 3; $family++ ) {
			$this->http->rig->at( StorageRig::T + $family * 1000 );
			$code = $this->http->rig->authorize( $this->client );
			$this->secrets[] = $code;
			$fields = [ 'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => StorageRig::REDIRECT, 'client_id' => $this->client, 'code_verifier' => StorageRig::VERIFIER ];
			$this->remember( (array) $this->post( 'token', $fields )->get_data() );
			self::assertSame( 'invalid_grant', $this->post( 'token', $fields )->get_data()['error'] ?? null );
		}

		self::assertCount( 3, self::events( 'oauth/token', 'code_replay_revoked' ) );
		$this->assert_no_credentials();
	}

	public function test_every_duplicate_delivery_is_its_own_audit_row(): void {
		$tokens = $this->connect( StorageRig::T );
		$this->refresh( $tokens['refresh_token'], StorageRig::T + 1 );
		for ( $duplicate = 2; $duplicate <= 4; $duplicate++ ) {
			self::assertArrayHasKey( 'access_token', $this->refresh( $tokens['refresh_token'], StorageRig::T + $duplicate ) );
		}

		$deliveries = self::events( 'oauth/token', 'refresh_redelivered' );
		self::assertCount( 3, $deliveries );
		self::assertSame( 'ok', $deliveries[0]['result_status'] );
		$this->assert_no_credentials();
	}

	public function test_every_effective_revocation_is_its_own_audit_row_and_unknown_tokens_are_not(): void {
		for ( $family = 1; $family <= 3; $family++ ) {
			$tokens = $this->connect( StorageRig::T + $family * 100 );
			self::assertSame( 200, $this->post( 'revoke', [ 'token' => $tokens['refresh_token'], 'client_id' => $this->client ] )->get_status() );
		}
		$this->post( 'revoke', [ 'token' => 'sentinel-unknown-token-value', 'client_id' => $this->client ] );

		$revocations = self::events( 'oauth/revoke', 'revocation' );
		self::assertCount( 3, $revocations );
		self::assertSame( 'ok', $revocations[0]['result_status'] );
		$this->secrets[] = 'sentinel-unknown-token-value';
		$this->assert_no_credentials();
	}

	public function test_ordinary_refusals_stay_coalesced(): void {
		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$this->post( 'token', [ 'grant_type' => 'refresh_token', 'refresh_token' => 'sentinel-garbage-refresh', 'client_id' => $this->client ] );
		}

		self::assertCount( 1, self::rows( 'oauth/token' ) );
		self::assertSame( [], self::events( 'oauth/token', 'refresh_replay_revoked' ) );
	}
}
