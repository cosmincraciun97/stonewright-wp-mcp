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
		$GLOBALS['stonewright_test_options'] = [
			'stonewright_enabled'              => true,
			'site_url'                         => 'https://example.test',
			'stonewright_essential_tools_mode' => true,
		];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options'] = [];
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
