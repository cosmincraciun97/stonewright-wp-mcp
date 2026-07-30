<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Core\RestRoutes;
use Stonewright\WpMcp\Security\AuditLog;
use Stonewright\WpMcp\Security\ErrorPatterns;

/**
 * OAuth protocol failures are the client's problem; OAuth server failures are ours.
 *
 * A 400 `invalid_grant` is the token endpoint working correctly — an expired code
 * gets refused. Recording it as an `error` gives it high severity and feeds it to
 * ErrorPatterns, which then invents a learning record telling agents to repair a
 * failure that has no repair. A 500 from the same endpoint is a real incident and
 * must keep every bit of that machinery.
 *
 * @covers \Stonewright\WpMcp\Security\AuditLog
 * @covers \Stonewright\WpMcp\Security\ErrorPatterns
 */
final class AuditAuthClassificationTest extends TestCase {

	private mixed $original_wpdb;

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = self::make_wpdb();
		$GLOBALS['stonewright_test_options'] = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		AuditLog::reset_request_state();
	}

	protected function tearDown(): void {
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		$GLOBALS['stonewright_test_options'] = [];
		AuditLog::reset_request_state();
	}

	public function test_auth_is_a_first_class_audit_status(): void {
		self::assertContains( 'auth', AuditLog::STATUSES );
	}

	public function test_protocol_failure_is_recorded_as_a_warning_auth_event(): void {
		$recorded = AuditLog::record_auth_event(
			'oauth/token',
			new \WP_REST_Response(
				[
					'error'             => 'invalid_grant',
					'error_description' => 'The provided authorization grant is invalid or expired.',
					'hint'              => 'Check the authorization code.',
				],
				400
			),
			[ 'client_id' => 'client-abc' ]
		);
		self::assertTrue( $recorded );

		$row = self::last_audit_row();
		self::assertSame( 'auth', $row['result_status'] );
		self::assertSame( 'warning', $row['severity'] );
		self::assertSame( 'auth', $row['event_type'] );
		self::assertSame( 'oauth/token', $row['ability_name'] );
		self::assertSame( 'invalid_grant', $row['error_code'] );
	}

	public function test_server_failure_stays_an_error(): void {
		AuditLog::record_auth_event(
			'oauth/token',
			new \WP_REST_Response( [ 'error' => 'server_error' ], 500 ),
			[ 'client_id' => 'client-abc' ]
		);

		$row = self::last_audit_row();
		self::assertSame( 'error', $row['result_status'] );
		self::assertSame( 'high', $row['severity'] );
		self::assertNotSame( 'auth', $row['event_type'] );
	}

	public function test_allowlisted_diagnostics_land_in_top_level_metadata(): void {
		AuditLog::record_auth_event(
			'oauth/token',
			new \WP_REST_Response(
				[
					'error'             => 'invalid_client',
					'error_description' => 'Client authentication failed.',
					'error_uri'         => 'https://example.test/errors/invalid_client',
					'hint'              => 'Missing client_secret.',
				],
				401
			),
			[ 'client_id' => 'client-abc' ]
		);

		$args = self::last_audit_args();
		// Top level, so the admin list and the incident filters can read them
		// without unpacking a nested params blob.
		self::assertSame( 'invalid_client', $args['oauth_error'] ?? null );
		self::assertSame( 'Client authentication failed.', $args['oauth_error_description'] ?? null );
		self::assertSame( 'https://example.test/errors/invalid_client', $args['oauth_error_uri'] ?? null );
		self::assertSame( 'Missing client_secret.', $args['oauth_hint'] ?? null );
		self::assertSame( 'client-abc', $args['client_id'] ?? null );
	}

	public function test_error_description_is_truncated(): void {
		AuditLog::record_auth_event(
			'oauth/token',
			new \WP_REST_Response(
				[
					'error'             => 'invalid_request',
					'error_description' => str_repeat( 'x', 900 ),
				],
				400
			)
		);

		$args = self::last_audit_args();
		self::assertLessThanOrEqual(
			AuditLog::AUTH_DIAGNOSTIC_MAX_LENGTH,
			mb_strlen( (string) ( $args['oauth_error_description'] ?? '' ) )
		);
	}

	public function test_credentials_never_reach_the_audit_row(): void {
		AuditLog::record_auth_event(
			'oauth/token',
			new \WP_REST_Response(
				[
					'error'             => 'invalid_client',
					'error_description' => 'Rejected client_secret=SENTINEL-SECRET with Bearer SENTINEL-HEADER.',
					'error_uri'         => 'https://example.test/oauth?code=SENTINEL-AUTH-CODE',
					'hint'              => 'Refresh token SENTINEL-REFRESH is expired.',
					'access_token'      => 'SENTINEL-BEARER',
					'refresh_token'     => 'SENTINEL-REFRESH',
				],
				401
			),
			[
				'client_id'     => 'client-abc',
				'client_secret' => 'SENTINEL-SECRET',
				'code'          => 'SENTINEL-AUTH-CODE',
				'refresh_token' => 'SENTINEL-REFRESH',
				'assertion'     => 'SENTINEL-ASSERTION',
				'authorization' => 'Bearer SENTINEL-HEADER',
			]
		);

		$encoded = (string) self::last_audit_row()['sanitized_args'];
		foreach (
			[
				'SENTINEL-SECRET',
				'SENTINEL-AUTH-CODE',
				'SENTINEL-REFRESH',
				'SENTINEL-ASSERTION',
				'SENTINEL-HEADER',
				'SENTINEL-BEARER',
			] as $sentinel
		) {
			self::assertStringNotContainsString( $sentinel, $encoded, $sentinel );
		}
		// A digest of a live credential is still a credential artifact.
		self::assertStringNotContainsString( hash( 'sha256', 'SENTINEL-AUTH-CODE' ), $encoded );
		self::assertSame( 'client-abc', self::last_audit_args()['client_id'] ?? null );
	}

	public function test_repeated_protocol_failures_never_become_patterns(): void {
		$response = new \WP_REST_Response( [ 'error' => 'invalid_grant' ], 400 );
		for ( $i = 0; $i < 3; $i++ ) {
			AuditLog::reset_request_state();
			AuditLog::record_auth_event( 'oauth/token', $response, [ 'client_id' => 'client-abc' ] );
		}

		self::assertSame( [], (array) get_option( ErrorPatterns::OPTION_KEY, [] ) );
		self::assertSame( [], ErrorPatterns::recurring() );
	}

	public function test_repeated_server_failures_still_reach_incident_logic(): void {
		$response = new \WP_REST_Response( [ 'error' => 'server_error' ], 500 );
		for ( $i = 0; $i < 2; $i++ ) {
			AuditLog::reset_request_state();
			AuditLog::record_auth_event( 'oauth/token', $response, [ 'client_id' => 'client-abc' ] );
		}

		$store = (array) get_option( ErrorPatterns::OPTION_KEY, [] );
		self::assertCount( 1, $store );
		$row = (array) array_values( $store )[0];
		self::assertSame( 2, (int) ( $row['count'] ?? 0 ) );
		self::assertFalse( (bool) ( $row['expected'] ?? true ) );
		// server_error is an OAuth protocol constant, so it keeps its spelling.
		self::assertSame( 'server_error', $row['error_code'] ?? '' );
	}

	public function test_successful_token_exchange_is_not_an_auth_failure(): void {
		AuditLog::record_auth_event(
			'oauth/token',
			new \WP_REST_Response( [ 'access_token' => 'SENTINEL-BEARER', 'token_type' => 'Bearer' ], 200 ),
			[ 'client_id' => 'client-abc' ]
		);

		$row = self::last_audit_row();
		self::assertSame( 'ok', $row['result_status'] );
		self::assertStringNotContainsString( 'SENTINEL-BEARER', (string) $row['sanitized_args'] );
	}

	public function test_dispatch_routes_the_token_endpoint_to_the_auth_recorder(): void {
		$request = new \WP_REST_Request( 'POST', '/stonewright/v1/oauth/token' );
		$request->set_body_params(
			[
				'grant_type'    => 'authorization_code',
				'client_id'     => 'client-abc',
				'client_secret' => 'SENTINEL-SECRET',
				'code'          => 'SENTINEL-AUTH-CODE',
			]
		);
		$request->set_header( 'Authorization', 'Bearer SENTINEL-HEADER' );
		$response = new \WP_REST_Response(
			[
				'error'             => 'invalid_grant',
				'error_description' => 'Authorization code SENTINEL-AUTH-CODE with Bearer SENTINEL-HEADER was refused.',
			],
			400
		);

		$returned = RestRoutes::audit_post_dispatch( $response, null, $request );
		self::assertSame( $response, $returned );

		$row = self::last_audit_row();
		self::assertSame( 'oauth/token', $row['ability_name'] );
		self::assertSame( 'auth', $row['result_status'] );
		// The generic mutation recorder would have summarized the request body.
		$encoded = (string) $row['sanitized_args'];
		self::assertStringNotContainsString( 'SENTINEL-SECRET', $encoded );
		self::assertStringNotContainsString( 'SENTINEL-AUTH-CODE', $encoded );
		self::assertStringNotContainsString( 'SENTINEL-HEADER', $encoded );
		self::assertStringNotContainsString( hash( 'sha256', 'SENTINEL-AUTH-CODE' ), $encoded );
	}

	public function test_dispatch_keeps_oauth_server_faults_as_errors(): void {
		$request = new \WP_REST_Request( 'POST', '/stonewright/v1/oauth/token' );
		$request->set_body_params( [ 'client_id' => 'client-abc' ] );

		RestRoutes::audit_post_dispatch(
			new \WP_Error( 'server_error', 'Boom', [ 'status' => 500 ] ),
			null,
			$request
		);

		$row = self::last_audit_row();
		self::assertSame( 'oauth/token', $row['ability_name'] );
		self::assertSame( 'error', $row['result_status'] );
		self::assertSame( 'high', $row['severity'] );
	}

	public function test_dispatch_ignores_successful_auth_surface_reads(): void {
		$request = new \WP_REST_Request( 'GET', '/stonewright/v1/oauth/authorize' );

		RestRoutes::audit_post_dispatch( new \WP_REST_Response( [ 'ok' => true ], 200 ), null, $request );

		self::assertSame( [], $GLOBALS['wpdb']->inserts );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function last_audit_row(): array {
		$inserts = $GLOBALS['wpdb']->inserts;
		self::assertNotEmpty( $inserts, 'No audit row was persisted.' );
		return (array) $inserts[ count( $inserts ) - 1 ]['data'];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function last_audit_args(): array {
		$decoded = json_decode( (string) self::last_audit_row()['sanitized_args'], true );
		return is_array( $decoded ) ? $decoded : [];
	}

	private static function make_wpdb(): object {
		return new class() {
			public string $prefix     = 'wp_';
			public string $last_error = '';
			public int $insert_id     = 0;

			/** @var array<int, array{table:string,data:array<string,mixed>}> */
			public array $inserts = [];

			public function get_charset_collate(): string {
				return '';
			}

			public function prepare( string $query, mixed ...$args ): string {
				return $query;
			}

			public function get_var( string $query = '' ): mixed {
				return null;
			}

			/** @return array<int, string> */
			public function get_col( string $query, int $x = 0 ): array {
				return [];
			}

			/** @return array<int, mixed> */
			public function get_results( string $query, string $output = 'OBJECT' ): array {
				return [];
			}

			/** @param array<string, mixed> $data */
			public function insert( string $table, array $data, array $format = [] ): int {
				$this->inserts[] = [ 'table' => $table, 'data' => $data ];
				++$this->insert_id;
				return 1;
			}

			/**
			 * @param array<string, mixed> $data
			 * @param array<string, mixed> $where
			 */
			public function update( string $table, array $data, array $where, array $format = [], array $where_format = [] ): int {
				return 1;
			}
		};
	}
}
