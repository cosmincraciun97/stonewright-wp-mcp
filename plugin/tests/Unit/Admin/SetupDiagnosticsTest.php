<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\System\ToolProfile;
use Stonewright\WpMcp\Admin\SetupDiagnostics;
use Stonewright\WpMcp\Core\AbilityRegistry;

/**
 * @covers \Stonewright\WpMcp\Admin\SetupDiagnostics
 */
final class SetupDiagnosticsTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']         = [
			'stonewright_enabled'              => true,
			'site_url'                         => 'https://example.test',
			'stonewright_essential_tools_mode' => true,
		];
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_transient_ttls']  = [];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options']        = [];
		$GLOBALS['stonewright_test_transients']     = [];
		$GLOBALS['stonewright_test_transient_ttls'] = [];
		unset( $_SERVER['HTTP_MCP_SESSION_ID'] );
	}

	public function test_report_is_compact_and_versioned(): void {
		$report = SetupDiagnostics::report();

		self::assertArrayHasKey( 'ready', $report );
		self::assertArrayHasKey( 'method', $report );
		self::assertArrayHasKey( 'counts', $report );
		self::assertGreaterThanOrEqual( 11, count( $report['checks'] ) );
		self::assertSame( 'ok', $this->find_check( $report['checks'], 'connection' )['status'] );
		self::assertSame( 'ok', $this->find_check( $report['checks'], 'endpoint' )['status'] );
		self::assertSame( 'ok', $this->find_check( $report['checks'], 'tool_surface' )['status'] );
		self::assertSame( 'info', $this->find_check( $report['checks'], 'connection_probe' )['status'] );
		self::assertSame( 'info', $this->find_check( $report['checks'], 'waf' )['status'] );
		self::assertSame( 'ok', $this->find_check( $report['checks'], 'oauth_transport' )['status'] );
		self::assertSame( 'ok', $this->find_check( $report['checks'], 'oauth_endpoint' )['status'] );
		self::assertSame( 'ok', $this->find_check( $report['checks'], 'oauth_discovery' )['status'] );
		self::assertSame( '0.0.0-test', $report['versions']['plugin'] );
		self::assertSame( '1.0.0', $report['versions']['companion_contract'] );
		self::assertLessThanOrEqual( 30, $report['versions']['tool_count'] );
		self::assertArrayHasKey( 'problem', $report['counts'] );
		self::assertArrayHasKey( 'skipped', $report['counts'] );
		self::assertSame( 0, $report['counts']['skipped'] );
	}

	public function test_disabled_plugin_skips_dependent_connection_checks(): void {
		$GLOBALS['stonewright_test_options']['stonewright_enabled'] = false;

		$report = SetupDiagnostics::report();
		$plugin = $this->find_check( $report['checks'], 'plugin' );
		$connection = $this->find_check( $report['checks'], 'connection' );

		self::assertFalse( $report['ready'] );
		self::assertSame( 'problem', $plugin['status'] );
		self::assertSame( 'skipped', $connection['status'] );
		self::assertStringContainsString( 'plugin', (string) ( $connection['summary'] ?? $connection['detail'] ?? '' ) );
		self::assertGreaterThanOrEqual( 1, $report['counts']['problem'] );
		self::assertGreaterThanOrEqual( 1, $report['counts']['skipped'] );
	}

	public function test_tool_budget_passes_at_essential_maximum(): void {
		$GLOBALS['stonewright_test_options']['stonewright_essential_tools_mode']     = true;
		$GLOBALS['stonewright_test_options']['stonewright_essential_extra_abilities'] = [];

		$report = SetupDiagnostics::report();
		$budget = $this->find_check( $report['checks'], 'tool_budget' );

		self::assertSame( 30, $report['versions']['tool_count'] );
		self::assertSame( 'ok', $budget['status'], 'The committed essential set is within ESSENTIAL_MAX_TOOLS and must pass.' );
	}

	public function test_tool_budget_warns_when_compact_preference_drifts_above_budget(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mcp_surface']            = 'essential';
		$GLOBALS['stonewright_test_options']['stonewright_essential_tools_mode']   = true;
		$GLOBALS['stonewright_test_options']['stonewright_essential_extra_abilities'] = [
			'stonewright/ping',
			'stonewright/site-health',
		];

		$report = SetupDiagnostics::report();
		$budget = $this->find_check( $report['checks'], 'tool_budget' );

		self::assertSame( 32, $report['versions']['tool_count'] );
		self::assertSame( 'warning', $budget['status'] );
		self::assertStringContainsString( 'essential', strtolower( (string) ( $budget['summary'] ?? $budget['detail'] ?? '' ) ) );
	}

	public function test_tool_budget_is_info_when_full_surface_is_selected(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mcp_surface']          = 'full';
		$GLOBALS['stonewright_test_options']['stonewright_essential_tools_mode'] = false;

		$report = SetupDiagnostics::report();
		$budget = $this->find_check( $report['checks'], 'tool_budget' );
		$count  = (int) $report['versions']['tool_count'];

		self::assertGreaterThan( 30, $count );
		self::assertSame( 'info', $budget['status'] );
		self::assertSame(
			sprintf( 'Full surface selected — %d tools. Compact profiles reduce agent token cost.', $count ),
			(string) ( $budget['summary'] ?? $budget['detail'] ?? '' )
		);
	}

	public function test_tool_surface_reports_configured_and_active_session_when_widened(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mcp_surface']          = 'essential';
		$GLOBALS['stonewright_test_options']['stonewright_essential_tools_mode'] = true;

		$_SERVER['HTTP_MCP_SESSION_ID'] = 'setup-diag-session-union';
		$configured_count               = count( AbilityRegistry::enabled_abilities() );
		self::assertTrue(
			AbilityRegistry::set_session_tool_profile(
				'elementor-design',
				ToolProfile::profile_tools( 'elementor-design' )
			)
		);
		$session_count = count( AbilityRegistry::enabled_abilities() );
		self::assertGreaterThan( $configured_count, $session_count );
		unset( $_SERVER['HTTP_MCP_SESSION_ID'] );

		$report = SetupDiagnostics::report();
		$card   = $this->find_check( $report['checks'], 'tool_surface' );

		self::assertSame(
			sprintf(
				'Configured: essential (%d) · Active session: elementor-design (%d)',
				$configured_count,
				$session_count
			),
			(string) ( $card['summary'] ?? $card['detail'] ?? '' )
		);
		self::assertSame( $configured_count, $report['versions']['tool_count'] );
	}

	public function test_probe_maps_loopback_and_waf_without_production_hostnames(): void {
		$report = SetupDiagnostics::report(
			[
				'probe'    => true,
				'method'   => 'oauth-http',
				'loopback' => static fn (): array => [
					'ok'       => false,
					'endpoint' => 'https://example.test/wp-json/mcp/stonewright',
					'steps'    => [
						[
							'id'     => 'initialize',
							'status' => 'failed',
							'detail' => 'MCP initialize rejected authentication (HTTP 403).',
						],
					],
				],
			]
		);

		$probe = $this->find_check( $report['checks'], 'connection_probe' );
		$waf   = $this->find_check( $report['checks'], 'waf' );

		self::assertSame( 'problem', $probe['status'] );
		self::assertSame( 'problem', $waf['status'] );
		self::assertStringContainsString( 'example.test', (string) ( $probe['summary'] ?? $probe['detail'] ?? '' ) );
		self::assertStringNotContainsString( 'wp.test', (string) ( $probe['summary'] ?? $probe['detail'] ?? '' ) );
	}

	public function test_bot_filter_probe_warns_on_user_agent_403_with_hosting_ticket(): void {
		$uas = [];
		$report = SetupDiagnostics::report(
			[
				'probe'    => true,
				'method'   => 'oauth-http',
				'loopback' => static fn (): array => [
					'ok'       => true,
					'endpoint' => 'https://example.test/wp-json/mcp/stonewright',
					'steps'    => [],
				],
				'http'     => static function ( string $method, string $url, array $args ) use ( &$uas ): array {
					if ( 'GET' === strtoupper( $method ) ) {
						$headers = (array) ( $args['headers'] ?? [] );
						$ua      = (string) ( $headers['User-Agent'] ?? $headers['user-agent'] ?? '' );
						if ( in_array( $ua, [ 'python-httpx', 'node', 'Go-http-client' ], true ) ) {
							$uas[] = $ua;
							return [
								'response' => [ 'code' => 403 ],
								'body'     => '',
							];
						}
						return [
							'response' => [ 'code' => 401 ],
							'headers'  => [ 'www-authenticate' => 'Bearer realm="stonewright"' ],
							'body'     => '',
						];
					}

					return [
						'response' => [ 'code' => 201 ],
						'body'     => wp_json_encode(
							[
								'client_id'                  => str_repeat( 'ab', 16 ),
								'client_name'                => 'Stonewright diagnostics',
								'redirect_uris'              => [ 'http://127.0.0.1/stonewright-oauth/callback' ],
								'grant_types'                => [ 'authorization_code', 'refresh_token' ],
								'token_endpoint_auth_method' => 'none',
							]
						),
					];
				},
			]
		);

		$bot = $this->find_check( $report['checks'], 'bot_filter' );

		self::assertSame( [ 'python-httpx', 'node', 'Go-http-client' ], $uas );
		self::assertSame( 'warning', $bot['status'] );
		self::assertNotSame( '', (string) ( $bot['copy'] ?? $bot['ticket'] ?? '' ) );
		self::assertStringContainsString( 'example.test', (string) ( $bot['copy'] ?? $bot['ticket'] ?? '' ) );
		self::assertStringContainsString( 'python-httpx', (string) ( $bot['copy'] ?? $bot['ticket'] ?? '' ) );
		self::assertStringContainsString( 'User-Agent', (string) ( $bot['copy'] ?? $bot['ticket'] ?? '' ) );
		self::assertStringNotContainsString( 'Novamira', (string) ( $bot['copy'] ?? $bot['ticket'] ?? '' ) );
		self::assertStringNotContainsString( 'wp.test', (string) ( $bot['copy'] ?? $bot['ticket'] ?? '' ) );
	}

	public function test_oauth_registration_probe_warns_with_timeout_error_string(): void {
		$posts = [];
		$report = SetupDiagnostics::report(
			[
				'probe'    => true,
				'method'   => 'oauth-http',
				'loopback' => static fn (): array => [
					'ok'       => true,
					'endpoint' => 'https://example.test/wp-json/mcp/stonewright',
					'steps'    => [],
				],
				'http'     => static function ( string $method, string $url, array $args ) use ( &$posts ): array|\WP_Error {
					if ( 'POST' === strtoupper( $method ) ) {
						$posts[] = [
							'url'     => $url,
							'timeout' => $args['timeout'] ?? null,
						];
						return new \WP_Error( 'http_request_failed', 'cURL error 28: Connection timed out after 5001 milliseconds' );
					}

					return [
						'response' => [ 'code' => 200 ],
						'body'     => '',
					];
				},
			]
		);

		$oauth = $this->find_check( $report['checks'], 'oauth_registration' );

		self::assertCount( 1, $posts );
		self::assertStringContainsString( 'oauth/register', (string) $posts[0]['url'] );
		self::assertSame( 5, (int) $posts[0]['timeout'] );
		self::assertSame( 'problem', $oauth['status'] );
		self::assertStringContainsString( 'cURL error 28: Connection timed out after 5001 milliseconds', (string) ( $oauth['summary'] ?? $oauth['detail'] ?? '' ) );
		self::assertNotSame( '', (string) ( $oauth['remedy'] ?? '' ) );
	}

	public function test_oauth_registration_probe_sets_self_test_transient_and_header_before_post(): void {
		$captured = [];
		$report   = SetupDiagnostics::report(
			[
				'probe'    => true,
				'method'   => 'oauth-http',
				'loopback' => static fn (): array => [
					'ok'       => true,
					'endpoint' => 'https://example.test/wp-json/mcp/stonewright',
					'steps'    => [],
				],
				'http'     => static function ( string $method, string $url, array $args ) use ( &$captured ): array {
					if ( 'POST' === strtoupper( $method ) ) {
						$headers = (array) ( $args['headers'] ?? [] );
						$token   = (string) ( $headers['x-stonewright-self-test'] ?? $headers['X-Stonewright-Self-Test'] ?? '' );
						$key     = 'stonewright_oauth_selftest_' . hash( 'sha256', $token );
						$captured = [
							'url'        => $url,
							'token'      => $token,
							'transient'  => $GLOBALS['stonewright_test_transients'][ $key ] ?? null,
							'ttl'        => $GLOBALS['stonewright_test_transient_ttls'][ $key ] ?? null,
							'key_exists' => array_key_exists( $key, $GLOBALS['stonewright_test_transients'] ?? [] ),
						];
						return [
							'response' => [ 'code' => 400 ],
							'body'     => '{"error":"invalid_request"}',
						];
					}

					return [
						'response' => [ 'code' => 200 ],
						'body'     => '',
					];
				},
			]
		);

		$oauth = $this->find_check( $report['checks'], 'oauth_registration' );

		self::assertNotSame( '', $captured['token'] ?? '' );
		self::assertTrue( (bool) ( $captured['key_exists'] ?? false ), 'Self-test transient must be set before POST.' );
		self::assertSame( hash( 'sha256', (string) $captured['token'] ), (string) ( $captured['transient'] ?? '' ) );
		self::assertSame( 30, (int) ( $captured['ttl'] ?? 0 ) );
		self::assertSame( 'problem', $oauth['status'] );
		self::assertSame( 400, (int) ( $oauth['evidence']['http_status'] ?? 0 ) );
		self::assertNotSame( '', (string) ( $oauth['remedy'] ?? '' ) );
	}

	/**
	 * @dataProvider oauth_unavailable_status_provider
	 */
	public function test_oauth_registration_probe_warns_on_unavailable_http_status( int $code, string $body ): void {
		$report = SetupDiagnostics::report(
			[
				'probe'    => true,
				'method'   => 'oauth-http',
				'loopback' => static fn (): array => [
					'ok'       => true,
					'endpoint' => 'https://example.test/wp-json/mcp/stonewright',
					'steps'    => [],
				],
				'http'     => static function ( string $method, string $url, array $args ) use ( $code, $body ): array {
					if ( 'POST' === strtoupper( $method ) ) {
						return [
							'response' => [ 'code' => $code ],
							'body'     => $body,
						];
					}

					return [
						'response' => [ 'code' => 200 ],
						'body'     => '',
					];
				},
			]
		);

		$oauth = $this->find_check( $report['checks'], 'oauth_registration' );

		self::assertSame( 'warning', $oauth['status'] );
		self::assertStringContainsString( (string) $code, (string) ( $oauth['summary'] ?? $oauth['detail'] ?? '' ) );
		self::assertStringContainsString( $body, (string) ( $oauth['summary'] ?? $oauth['detail'] ?? '' ) );
		self::assertSame( $code, (int) ( $oauth['evidence']['http_status'] ?? 0 ) );
		self::assertNotSame( '', (string) ( $oauth['remedy'] ?? '' ) );
	}

	/**
	 * @return array<string, array{0: int, 1: string}>
	 */
	public static function oauth_unavailable_status_provider(): array {
		return [
			'too many requests'      => [ 429, 'Too many registrations' ],
			'temporarily unavailable' => [ 503, 'Client cap reached' ],
		];
	}

	public function test_bot_filter_probe_warns_on_wp_error_instead_of_ok_reached(): void {
		$error  = 'cURL error 28: Connection timed out after 5001 milliseconds';
		$report = SetupDiagnostics::report(
			[
				'probe'    => true,
				'method'   => 'oauth-http',
				'loopback' => static fn (): array => [
					'ok'       => true,
					'endpoint' => 'https://example.test/wp-json/mcp/stonewright',
					'steps'    => [],
				],
				'http'     => static function ( string $method ) use ( $error ): array|\WP_Error {
					if ( 'GET' === strtoupper( $method ) ) {
						return new \WP_Error( 'http_request_failed', $error );
					}

					return [
						'response' => [ 'code' => 400 ],
						'body'     => '{}',
					];
				},
			]
		);

		$bot = $this->find_check( $report['checks'], 'bot_filter' );

		self::assertSame( 'warning', $bot['status'] );
		self::assertStringContainsString( $error, (string) ( $bot['summary'] ?? $bot['detail'] ?? '' ) );
		self::assertStringNotContainsString(
			'reached the MCP endpoint without a 403/406 block',
			(string) ( $bot['summary'] ?? $bot['detail'] ?? '' )
		);
	}

	public function test_bot_filter_probe_warns_on_5xx_without_success(): void {
		$report = SetupDiagnostics::report(
			[
				'probe'    => true,
				'method'   => 'oauth-http',
				'loopback' => static fn (): array => [
					'ok'       => true,
					'endpoint' => 'https://example.test/wp-json/mcp/stonewright',
					'steps'    => [],
				],
				'http'     => static function ( string $method ): array {
					if ( 'GET' === strtoupper( $method ) ) {
						return [
							'response' => [ 'code' => 502 ],
							'body'     => 'Bad Gateway',
						];
					}

					return [
						'response' => [ 'code' => 400 ],
						'body'     => '{}',
					];
				},
			]
		);

		$bot = $this->find_check( $report['checks'], 'bot_filter' );

		self::assertSame( 'warning', $bot['status'] );
		self::assertStringContainsString( '502', (string) ( $bot['summary'] ?? $bot['detail'] ?? '' ) );
		self::assertStringContainsString( 'Bad Gateway', (string) ( $bot['summary'] ?? $bot['detail'] ?? '' ) );
		self::assertStringNotContainsString(
			'reached the MCP endpoint without a 403/406 block',
			(string) ( $bot['summary'] ?? $bot['detail'] ?? '' )
		);
	}

	public function test_bot_filter_probe_ok_reached_copy_only_for_http_responses(): void {
		$report = SetupDiagnostics::report(
			[
				'probe'    => true,
				'method'   => 'oauth-http',
				'loopback' => static fn (): array => [
					'ok'       => true,
					'endpoint' => 'https://example.test/wp-json/mcp/stonewright',
					'steps'    => [],
				],
				'http'     => static function ( string $method ): array {
					return [
						'response' => [ 'code' => 200 ],
						'body'     => '',
					];
				},
			]
		);

		$bot = $this->find_check( $report['checks'], 'bot_filter' );

		self::assertSame( 'ok', $bot['status'] );
		self::assertSame(
			'python-httpx, node, and Go-http-client reached the MCP endpoint without a 403/406 block.',
			(string) ( $bot['summary'] ?? $bot['detail'] ?? '' )
		);
	}

	public function test_oauth_http_schedules_discovery_waf_registration_challenge_and_loopback(): void {
		$report = $this->probed_report( [ 'method' => 'oauth-http' ] );
		$ids    = array_column( $report['checks'], 'id' );

		self::assertSame( 'oauth-http', $report['method'] );
		self::assertContains( 'oauth_discovery', $ids );
		self::assertContains( 'bot_filter', $ids );
		self::assertContains( 'oauth_registration', $ids );
		self::assertContains( 'oauth_challenge', $ids );
		self::assertContains( 'connection_probe', $ids );
		self::assertSame( 'ok', $this->find_check( $report['checks'], 'oauth_registration' )['status'] );
		self::assertSame( 'ok', $this->find_check( $report['checks'], 'oauth_challenge' )['status'] );
	}

	public function test_application_password_stdio_skips_remote_dcr_and_checks_companion_store(): void {
		$GLOBALS['stonewright_test_options']['stonewright_companion_url'] = 'http://127.0.0.1:8765';
		$http_called = false;
		$report      = SetupDiagnostics::report(
			[
				'probe'  => true,
				'method' => 'application-password-stdio',
				'http'   => static function () use ( &$http_called ): array {
					$http_called = true;
					return [
						'response' => [ 'code' => 201 ],
						'body'     => '{}',
					];
				},
			]
		);

		self::assertFalse( $http_called );
		self::assertSame( 'skipped', $this->find_check( $report['checks'], 'oauth_registration' )['status'] );
		self::assertSame( 'skipped', $this->find_check( $report['checks'], 'bot_filter' )['status'] );
		self::assertSame( 'skipped', $this->find_check( $report['checks'], 'connection_probe' )['status'] );
		self::assertSame( 'ok', $this->find_check( $report['checks'], 'companion_url' )['status'] );
		self::assertSame( 'ok', $this->find_check( $report['checks'], 'credential_store' )['status'] );
	}

	public function test_not_sure_runs_safe_discovery_and_one_recommendation(): void {
		$posts = 0;
		$report = SetupDiagnostics::report(
			[
				'probe'  => true,
				'method' => 'not-sure',
				'http'   => static function ( string $method ) use ( &$posts ): array {
					if ( 'POST' === strtoupper( $method ) ) {
						++$posts;
					}
					return [
						'response' => [ 'code' => 201 ],
						'body'     => '{}',
					];
				},
			]
		);

		self::assertSame( 0, $posts );
		self::assertSame( 'not-sure', $report['method'] );
		self::assertSame( 'ok', $this->find_check( $report['checks'], 'oauth_discovery' )['status'] );
		$recommendation = $this->find_check( $report['checks'], 'recommendation' );
		self::assertSame( 'info', $recommendation['status'] );
		self::assertNotSame( '', (string) ( $recommendation['summary'] ?? '' ) );
		self::assertSame( 'skipped', $this->find_check( $report['checks'], 'oauth_registration' )['status'] );
	}

	/**
	 * @dataProvider oauth_registration_failure_provider
	 * @param array<string, mixed>|\WP_Error $response
	 */
	public function test_oauth_registration_probe_rejects_non_201_shapes( mixed $response, string $marker ): void {
		$report = $this->probed_report(
			[
				'http' => static function ( string $method ) use ( $response ): array|\WP_Error {
					if ( 'POST' === strtoupper( $method ) ) {
						return $response;
					}
					return [
						'response' => [ 'code' => 401 ],
						'headers'  => [ 'www-authenticate' => 'Bearer realm="stonewright"' ],
						'body'     => '',
					];
				},
			]
		);
		$oauth = $this->find_check( $report['checks'], 'oauth_registration' );
		self::assertSame( 'problem', $oauth['status'] );
		self::assertStringContainsString( $marker, (string) ( $oauth['summary'] ?? $oauth['detail'] ?? '' ) . wp_json_encode( $oauth['evidence'] ?? [] ) );
		self::assertNotSame( '', (string) ( $oauth['remedy'] ?? '' ) );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>|\WP_Error, 1: string}>
	 */
	public static function oauth_registration_failure_provider(): array {
		return [
			'unauthorized'     => [ [ 'response' => [ 'code' => 401 ], 'body' => '{"error":"invalid_token"}' ], '401' ],
			'forbidden'        => [ [ 'response' => [ 'code' => 403 ], 'body' => 'Forbidden' ], '403' ],
			'server error'     => [ [ 'response' => [ 'code' => 500 ], 'body' => 'oops' ], '500' ],
			'invalid json'     => [ [ 'response' => [ 'code' => 201 ], 'body' => '{not-json' ], 'json' ],
			'missing client'   => [ [ 'response' => [ 'code' => 201 ], 'body' => '{"client_name":"x"}' ], 'client_id' ],
		];
	}

	public function test_oauth_registration_probe_sends_rfc7591_metadata_and_accepts_201(): void {
		$captured = [];
		$client_id = str_repeat( 'cd', 16 );
		$report    = $this->probed_report(
			[
				'ephemeral_remaining' => 0,
				'http'                => static function ( string $method, string $url, array $args ) use ( &$captured, $client_id ): array {
					if ( 'POST' === strtoupper( $method ) ) {
						$captured = $args;
						return [
							'response' => [ 'code' => 201 ],
							'body'     => wp_json_encode(
								[
									'client_id'                  => $client_id,
									'client_name'                => 'Stonewright diagnostics',
									'redirect_uris'              => [ 'http://127.0.0.1/stonewright-oauth/callback' ],
									'grant_types'                => [ 'authorization_code', 'refresh_token' ],
									'token_endpoint_auth_method' => 'none',
								]
							),
						];
					}
					return [
						'response' => [ 'code' => 401 ],
						'headers'  => [ 'www-authenticate' => 'Bearer realm="stonewright"' ],
						'body'     => '',
					];
				},
			]
		);

		$body = json_decode( (string) ( $captured['body'] ?? '' ), true );
		self::assertIsArray( $body );
		self::assertNotSame( '', (string) ( $body['client_name'] ?? '' ) );
		self::assertSame( [ 'http://127.0.0.1/stonewright-oauth/callback' ], $body['redirect_uris'] ?? null );
		self::assertContains( 'authorization_code', (array) ( $body['grant_types'] ?? [] ) );
		self::assertContains( 'refresh_token', (array) ( $body['grant_types'] ?? [] ) );
		self::assertSame( 'none', $body['token_endpoint_auth_method'] ?? null );
		self::assertSame( 'ok', $this->find_check( $report['checks'], 'oauth_registration' )['status'] );
	}

	public function test_oauth_registration_probe_fails_when_ephemeral_clients_remain(): void {
		$client_id = str_repeat( 'ef', 16 );
		$report    = $this->probed_report(
			[
				'ephemeral_remaining' => 1,
				'http'                => static function ( string $method ) use ( $client_id ): array {
					if ( 'POST' === strtoupper( $method ) ) {
						return [
							'response' => [ 'code' => 201 ],
							'body'     => wp_json_encode(
								[
									'client_id'                  => $client_id,
									'client_name'                => 'Stonewright diagnostics',
									'redirect_uris'              => [ 'http://127.0.0.1/stonewright-oauth/callback' ],
									'grant_types'                => [ 'authorization_code', 'refresh_token' ],
									'token_endpoint_auth_method' => 'none',
								]
							),
						];
					}
					return [
						'response' => [ 'code' => 401 ],
						'headers'  => [ 'www-authenticate' => 'Bearer realm="stonewright"' ],
						'body'     => '',
					];
				},
			]
		);
		$oauth = $this->find_check( $report['checks'], 'oauth_registration' );
		self::assertSame( 'problem', $oauth['status'] );
		self::assertStringContainsString( 'ephemeral', strtolower( (string) ( $oauth['summary'] ?? '' ) . (string) ( $oauth['remedy'] ?? '' ) ) );
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	private function probed_report( array $args = [] ): array {
		$http = $args['http'] ?? static function ( string $method ): array {
			if ( 'POST' === strtoupper( $method ) ) {
				return [
					'response' => [ 'code' => 201 ],
					'body'     => wp_json_encode(
						[
							'client_id'                  => str_repeat( 'ab', 16 ),
							'client_name'                => 'Stonewright diagnostics',
							'redirect_uris'              => [ 'http://127.0.0.1/stonewright-oauth/callback' ],
							'grant_types'                => [ 'authorization_code', 'refresh_token' ],
							'token_endpoint_auth_method' => 'none',
						]
					),
				];
			}
			return [
				'response' => [ 'code' => 401 ],
				'headers'  => [ 'www-authenticate' => 'Bearer realm="stonewright"' ],
				'body'     => '',
			];
		};
		unset( $args['http'] );

		return SetupDiagnostics::report(
			array_merge(
				[
					'probe'    => true,
					'method'   => 'oauth-http',
					'loopback' => static fn (): array => [
						'ok'       => true,
						'endpoint' => 'https://example.test/wp-json/mcp/stonewright',
						'steps'    => [],
					],
					'http'     => $http,
				],
				$args
			)
		);
	}

	/**
	 * @param list<array{id: string, status: string, label: string, detail: string}> $checks
	 * @return array{id: string, status: string, label: string, detail: string}
	 */
	private function find_check( array $checks, string $id ): array {
		foreach ( $checks as $check ) {
			if ( $check['id'] === $id ) {
				return $check;
			}
		}

		self::fail( 'Check not found: ' . $id );
	}
}
