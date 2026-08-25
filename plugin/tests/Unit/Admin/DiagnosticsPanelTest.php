<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\DiagnosticsPanel;
use Stonewright\WpMcp\Admin\Diagnostics\DiagnosticCheck;
use Stonewright\WpMcp\Admin\Diagnostics\SupportReport;

/**
 * @covers \Stonewright\WpMcp\Admin\DiagnosticsPanel
 */
final class DiagnosticsPanelTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_options']   = [
			'stonewright_enabled' => true,
		];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_caps'] = [];
		$GLOBALS['stonewright_test_options']   = [];
	}

	public function test_issue_first_render_collapses_success_and_redacts_copy(): void {
		$checks = [];
		for ( $i = 1; $i <= 6; $i++ ) {
			$checks[] = DiagnosticCheck::ok( 'ok_' . $i, 'Success ' . $i, 'All good.' )->to_array();
		}
		$checks[] = DiagnosticCheck::warning(
			'bot_filter',
			'Bot / WAF user-agent filter',
			'User-Agent python-httpx was blocked.',
			'Ask hosting to allow python-httpx.',
			[],
			'oauth-http'
		)->with_copy( 'Please allow python-httpx on https://example.test' )->with_action(
			[
				'type'   => 'copy',
				'label'  => 'Copy hosting request',
				'target' => 'stonewright-diag-copy-bot_filter',
			]
		)->to_array();
		$checks[] = DiagnosticCheck::problem(
			'oauth_registration',
			'OAuth dynamic registration',
			'Registration returned HTTP 401.',
			'Restore the OAuth register route, then run diagnostics again.',
			'oauth-http',
			[ 'http_status' => 401 ]
		)->with_action(
			[
				'type'   => 'link',
				'label'  => 'Open Setup',
				'target' => 'admin.php?page=stonewright',
			]
		)->to_array();

		$report = [
			'ready'          => false,
			'method'         => 'oauth-http',
			'counts'         => [
				'problem' => 1,
				'warning' => 1,
				'info'    => 0,
				'ok'      => 6,
				'skipped' => 0,
			],
			'checks'         => $checks,
			'versions'       => [
				'plugin'             => '0.0.0-test',
				'companion_contract' => '1.0.0',
			],
			'correlation_id' => 'corr-example-1234',
			'headers'        => [ 'Authorization' => 'Bearer secret-marker-token' ],
		];

		ob_start();
		DiagnosticsPanel::render( 'stonewright-troubleshoot', 'Connection checks', $report );
		$html = (string) ob_get_clean();

		$problem_pos = strpos( $html, 'OAuth dynamic registration' );
		$warning_pos = strpos( $html, 'Bot / WAF user-agent filter' );
		$success_pos = strpos( $html, '6 successful checks' );
		self::assertNotFalse( $problem_pos );
		self::assertNotFalse( $warning_pos );
		self::assertNotFalse( $success_pos );
		self::assertLessThan( $warning_pos, $problem_pos );
		self::assertLessThan( $success_pos, $warning_pos );
		self::assertStringContainsString( '1 Problems', $html );
		self::assertStringContainsString( '1 Warnings', $html );
		self::assertStringContainsString( '<details', $html );
		self::assertStringContainsString( 'Restore the OAuth register route', $html );
		self::assertStringContainsString( 'Open Setup', $html );
		self::assertStringContainsString( 'Copy hosting request', $html );
		self::assertStringContainsString( 'OAuth', $html );
		self::assertStringContainsString( 'Application Password', $html );
		self::assertStringContainsString( 'Local companion', $html );
		self::assertStringContainsString( 'Not sure', $html );
		self::assertStringContainsString( 'value="oauth-http"', $html );
		self::assertStringContainsString( 'value="application-password-stdio"', $html );
		self::assertStringContainsString( 'value="stdio"', $html );
		self::assertStringContainsString( 'value="not-sure"', $html );
		self::assertStringContainsString( 'Copy report for support', $html );
		self::assertStringNotContainsString( 'secret-marker-token', $html );
		self::assertStringNotContainsString( 'javascript:', $html );

		$copy = DiagnosticsPanel::plaintext_report( $report );
		self::assertStringContainsString( 'oauth-http', $copy );
		self::assertStringContainsString( 'corr-example-1234', $copy );
		self::assertStringNotContainsString( 'secret-marker-token', $copy );
		self::assertSame( $copy, SupportReport::render( $report ) );
	}

	public function test_unknown_action_types_are_not_rendered(): void {
		$check = DiagnosticCheck::problem(
			'endpoint',
			'MCP endpoint',
			'Missing route',
			'Restore the route.'
		)->to_array();
		$check['action'] = [
			'type'   => 'javascript',
			'label'  => 'Run payload',
			'target' => 'javascript:alert(1)',
		];

		ob_start();
		DiagnosticsPanel::render(
			'stonewright-troubleshoot',
			'Connection checks',
			[
				'ready'    => false,
				'method'   => 'oauth-http',
				'counts'   => [ 'problem' => 1, 'warning' => 0, 'info' => 0, 'ok' => 0, 'skipped' => 0 ],
				'checks'   => [ $check ],
				'versions' => [ 'plugin' => '0.0.0-test' ],
			]
		);
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Missing route', $html );
		self::assertStringNotContainsString( 'javascript:alert(1)', $html );
		self::assertStringNotContainsString( 'Run payload', $html );
	}
}
