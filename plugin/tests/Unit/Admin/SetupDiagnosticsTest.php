<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\SetupDiagnostics;

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
	}

	public function test_report_is_compact_and_versioned(): void {
		$report = SetupDiagnostics::report();

		self::assertArrayHasKey( 'ready', $report );
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
		self::assertSame( 'warn', $budget['status'] );
		self::assertStringContainsString( 'essential', strtolower( (string) $budget['detail'] ) );
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
			$budget['detail']
		);
	}

	public function test_probe_maps_loopback_and_waf_without_production_hostnames(): void {
		$report = SetupDiagnostics::report(
			[
				'probe'    => true,
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

		self::assertSame( 'error', $probe['status'] );
		self::assertSame( 'error', $waf['status'] );
		self::assertStringContainsString( 'example.test', $probe['detail'] );
		self::assertStringNotContainsString( 'wp.test', $probe['detail'] );
	}

	public function test_bot_filter_probe_warns_on_user_agent_403_with_hosting_ticket(): void {
		$uas = [];
		$report = SetupDiagnostics::report(
			[
				'probe'    => true,
				'loopback' => static fn (): array => [
					'ok'       => true,
					'endpoint' => 'https://example.test/wp-json/mcp/stonewright',
					'steps'    => [],
				],
				'http'     => static function ( string $method, string $url, array $args ) use ( &$uas ): array {
					if ( 'GET' === strtoupper( $method ) ) {
						$headers = (array) ( $args['headers'] ?? [] );
						$ua      = (string) ( $headers['User-Agent'] ?? $headers['user-agent'] ?? '' );
						$uas[]   = $ua;
						$code    = in_array( $ua, [ 'python-httpx', 'node', 'Go-http-client' ], true ) ? 403 : 200;
						return [
							'response' => [ 'code' => $code ],
							'body'     => '',
						];
					}

					return [
						'response' => [ 'code' => 201 ],
						'body'     => '{}',
					];
				},
			]
		);

		$bot = $this->find_check( $report['checks'], 'bot_filter' );

		self::assertSame( [ 'python-httpx', 'node', 'Go-http-client' ], $uas );
		self::assertSame( 'warn', $bot['status'] );
		self::assertArrayHasKey( 'ticket', $bot );
		self::assertStringContainsString( 'example.test', (string) $bot['ticket'] );
		self::assertStringContainsString( 'python-httpx', (string) $bot['ticket'] );
		self::assertStringContainsString( 'User-Agent', (string) $bot['ticket'] );
		self::assertStringNotContainsString( 'Novamira', (string) $bot['ticket'] );
		self::assertStringNotContainsString( 'wp.test', (string) $bot['ticket'] );
	}

	public function test_oauth_registration_probe_warns_with_timeout_error_string(): void {
		$posts = [];
		$report = SetupDiagnostics::report(
			[
				'probe'    => true,
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
		self::assertSame( 'warn', $oauth['status'] );
		self::assertStringContainsString( 'cURL error 28: Connection timed out after 5001 milliseconds', (string) $oauth['detail'] );
	}

	public function test_oauth_registration_probe_sets_self_test_transient_and_header_before_post(): void {
		$captured = [];
		$report   = SetupDiagnostics::report(
			[
				'probe'    => true,
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
		self::assertSame( '1', (string) ( $captured['transient'] ?? '' ) );
		self::assertSame( 30, (int) ( $captured['ttl'] ?? 0 ) );
		self::assertSame( 'ok', $oauth['status'] );
		self::assertStringContainsString( 'HTTP 400', (string) $oauth['detail'] );
	}

	/**
	 * @dataProvider oauth_unavailable_status_provider
	 */
	public function test_oauth_registration_probe_warns_on_unavailable_http_status( int $code, string $body ): void {
		$report = SetupDiagnostics::report(
			[
				'probe'    => true,
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

		self::assertSame( 'warn', $oauth['status'] );
		self::assertStringContainsString( (string) $code, (string) $oauth['detail'] );
		self::assertStringContainsString( $body, (string) $oauth['detail'] );
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

		self::assertSame( 'warn', $bot['status'] );
		self::assertStringContainsString( $error, (string) $bot['detail'] );
		self::assertStringNotContainsString(
			'reached the MCP endpoint without a 403/406 block',
			(string) $bot['detail']
		);
	}

	public function test_bot_filter_probe_warns_on_5xx_without_success(): void {
		$report = SetupDiagnostics::report(
			[
				'probe'    => true,
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

		self::assertSame( 'warn', $bot['status'] );
		self::assertStringContainsString( '502', (string) $bot['detail'] );
		self::assertStringContainsString( 'Bad Gateway', (string) $bot['detail'] );
		self::assertStringNotContainsString(
			'reached the MCP endpoint without a 403/406 block',
			(string) $bot['detail']
		);
	}

	public function test_bot_filter_probe_ok_reached_copy_only_for_http_responses(): void {
		$report = SetupDiagnostics::report(
			[
				'probe'    => true,
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
			(string) $bot['detail']
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
