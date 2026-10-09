<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\Diagnostics\DiagnosticCheck;
use Stonewright\WpMcp\Admin\Diagnostics\SupportReport;

/**
 * @covers \Stonewright\WpMcp\Admin\Diagnostics\SupportReport
 */
final class SupportReportTest extends TestCase {

	private const SECRET_MARKERS = [
		'sentinel-marker-token',
		'sentinel-marker-cookie',
		'sentinel-marker-query',
		'sentinel-marker-password',
		'sentinel-marker-path',
		'sentinel-marker-html',
		'sentinel-marker-nested',
	];

	public function test_report_keeps_safe_fields_and_drops_injected_secrets(): void {
		$check = DiagnosticCheck::problem(
			'endpoint',
			'MCP endpoint',
			'The endpoint returned HTTP 404.',
			'Restore the MCP route.',
			'oauth-http',
			[
				'http_status'   => 404,
				'duration_ms'   => 43,
				'error_code'    => 'missing_route',
				'authorization' => 'Bearer sentinel-marker-token',
				'cookie'        => 'sentinel-marker-cookie',
				'access_token'  => 'sentinel-marker-query',
				'password'      => 'sentinel-marker-password',
				'path'          => '/var/www/sentinel-marker-path/wp-config.php',
				'html'          => '<script>sentinel-marker-html</script>',
			]
		)->with_copy( "Site: https://example.test\nMCP endpoint: https://example.test/wp-json/mcp/stonewright" );

		$text = SupportReport::render(
			[
				'method'          => 'oauth-http',
				'counts'          => [
					'problem' => 1,
					'warning' => 0,
					'info'    => 0,
					'ok'      => 0,
					'skipped' => 0,
				],
				'checks'          => [ $check->to_array() ],
				'versions'        => [
					'plugin'             => '0.0.0-test',
					'companion_contract' => '1.0.0',
					'wordpress'          => '6.7.2',
					'php'                => '8.1.0',
				],
				'correlation_id'  => 'corr-example-1234',
				'headers'         => [
					'Authorization' => 'Bearer sentinel-marker-token',
					'Cookie'        => 'sentinel-marker-cookie',
				],
				'cookies'         => [ 'wordpress_logged_in' => 'sentinel-marker-cookie' ],
				'query'           => [ 'access_token' => 'sentinel-marker-query' ],
				'password'        => 'sentinel-marker-password',
				'path'            => '/var/www/sentinel-marker-path/wp-config.php',
				'html'            => '<p>sentinel-marker-html</p>',
				'nested'          => [ 'token' => 'sentinel-marker-nested' ],
				'server'          => [ 'HTTP_AUTHORIZATION' => 'Bearer sentinel-marker-token' ],
			]
		);

		self::assertStringContainsString( 'endpoint', $text );
		self::assertStringContainsString( 'oauth-http', $text );
		self::assertStringContainsString( 'problem', $text );
		self::assertStringContainsString( '0.0.0-test', $text );
		self::assertStringContainsString( '1.0.0', $text );
		self::assertStringContainsString( '6.7.2', $text );
		self::assertStringContainsString( '8.1.0', $text );
		self::assertStringContainsString( 'corr-example-1234', $text );
		self::assertStringContainsString( '404', $text );
		self::assertStringContainsString( '43', $text );
		self::assertStringContainsString( 'missing_route', $text );
		self::assertStringNotContainsString( 'Bearer', $text );
		self::assertStringNotContainsString( '/var/www/', $text );
		self::assertStringNotContainsString( '<script>', $text );
		foreach ( self::SECRET_MARKERS as $marker ) {
			self::assertStringNotContainsString( $marker, $text );
		}
		foreach ( explode( "\n", $text ) as $line ) {
			self::assertLessThanOrEqual( SupportReport::MAX_LINE_LENGTH, strlen( $line ) );
		}
		self::assertLessThanOrEqual( SupportReport::MAX_BYTES, strlen( $text ) );
	}

	public function test_report_lists_each_check_with_its_label_summary_and_remedy(): void {
		$text = SupportReport::render(
			[
				'method' => 'oauth-http',
				'checks' => [
					DiagnosticCheck::problem( 'application_passwords', 'Application Passwords', 'Unavailable: a filter turns them off.', 'Look for the wp_is_application_passwords_available filter.' )->to_array(),
					DiagnosticCheck::ok( 'transport', 'Connection transport', 'HTTPS active.' )->to_array(),
					DiagnosticCheck::skipped( 'connection', 'Connection', 'Skipped: needs Stonewright abilities to pass first.', [ 'plugin' ] )->to_array(),
				],
			]
		);

		self::assertStringContainsString( '[problem] application_passwords', $text );
		self::assertStringContainsString( 'Application Passwords: Unavailable: a filter turns them off.', $text );
		self::assertStringContainsString( 'Fix: Look for the wp_is_application_passwords_available filter.', $text );
		self::assertStringContainsString( 'Connection transport: HTTPS active.', $text );
		self::assertStringContainsString( 'Connection: Skipped: needs Stonewright abilities to pass first.', $text );
		self::assertSame( 1, substr_count( $text, 'Fix: ' ), 'Only problems and warnings carry a remedy.' );
	}

	public function test_report_summaries_never_carry_secrets(): void {
		$group   = implode( ' ', [ 'abcd', 'efgh', 'ijkl', 'mnop', 'qrst', 'uvwx' ] );
		$summary ='Registration failed: Authorization: Bearer abc123def456ghi789, Basic dXNlcjpwYXNzd29yZA==, password=hunter2hunter2, '
			. 'https://admin:s3cr3tpass@example.test/wp-json, access_token=tok-9f8e7d6c5b4a, cookie: wordpress_logged_in_x=abcdef, '
			. 'app password ' . $group . ', and a key 0123456789abcdef0123456789abcdef0123456789abcdef.';
		$text    = SupportReport::render(
			[
				'checks' => [
					DiagnosticCheck::problem( 'oauth_registration', 'OAuth dynamic registration', $summary, 'Retry with client_secret=shh-its-a-secret.' )->to_array(),
				],
			]
		);

		foreach ( [ 'abc123def456ghi789', 'dXNlcjpwYXNzd29yZA==', 'hunter2hunter2', 's3cr3tpass', 'tok-9f8e7d6c5b4a', 'wordpress_logged_in_x=abcdef', $group, '0123456789abcdef0123456789abcdef', 'shh-its-a-secret' ] as $secret ) {
			self::assertStringNotContainsString( $secret, $text, $secret );
		}
		self::assertStringContainsString( 'Registration failed', $text );
		self::assertStringContainsString( 'example.test/wp-json', $text, 'Ordinary addresses stay readable.' );
		foreach ( explode( "\n", $text ) as $line ) {
			self::assertLessThanOrEqual( SupportReport::MAX_LINE_LENGTH, strlen( $line ) );
		}
	}

	public function test_report_keeps_a_plain_bearer_challenge_sentence(): void {
		$text = SupportReport::render(
			[ 'checks' => [ DiagnosticCheck::ok( 'oauth_challenge', 'OAuth challenge', 'OAuth MCP endpoint returned HTTP 401 with a Bearer challenge.' )->to_array() ] ]
		);

		self::assertStringContainsString( 'with a Bearer challenge.', $text );
	}

	public function test_report_omits_evidence_lines_with_no_value(): void {
		$text = SupportReport::render(
			[
				'checks' => [
					DiagnosticCheck::ok( 'mcp_server_registration', 'MCP server registration', 'MCP server stonewright is registered.', [ 'server_id' => 'stonewright', 'state' => 'registered', 'error_code' => '', 'http_status' => 0 ] )->to_array(),
				],
			]
		);

		self::assertStringNotContainsString( 'error_code', $text );
		self::assertStringContainsString( 'http_status: 0', $text, 'A zero is a value.' );
	}

	public function test_report_rejects_an_arbitrary_request_dump(): void {
		$text = SupportReport::render(
			[
				'REQUEST' => [
					'headers' => [ 'Authorization' => 'Bearer sentinel-marker-token' ],
				],
				'_SERVER' => [ 'HTTP_COOKIE' => 'sentinel-marker-cookie' ],
			]
		);

		self::assertStringContainsString( 'Stonewright support report', $text );
		self::assertStringNotContainsString( 'sentinel-marker-token', $text );
		self::assertStringNotContainsString( 'sentinel-marker-cookie', $text );
		self::assertStringNotContainsString( 'Authorization', $text );
	}
}
