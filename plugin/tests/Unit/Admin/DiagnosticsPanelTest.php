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
			'headers'        => [ 'Authorization' => 'Bearer sentinel-marker-token' ],
		];

		ob_start();
		DiagnosticsPanel::render( 'stonewright-troubleshoot', 'Connection checks', $report );
		$html = (string) ob_get_clean();

		$problem_pos = strpos( $html, 'OAuth dynamic registration' );
		$warning_pos = strpos( $html, 'Bot / WAF user-agent filter' );
		$success_pos = strpos( $html, '6 checks passed' );
		self::assertNotFalse( $problem_pos );
		self::assertNotFalse( $warning_pos );
		self::assertNotFalse( $success_pos );
		self::assertLessThan( $warning_pos, $problem_pos );
		self::assertLessThan( $success_pos, $warning_pos );
		self::assertStringContainsString( '1 problem and 1 warning', $html, 'Plurals are real.' );
		self::assertStringNotContainsString( '1 Problems', $html );
		self::assertStringContainsString( '<details', $html );
		self::assertStringContainsString( 'sw-ui-table', $html );
		self::assertStringContainsString( 'sw-ui-badge--danger', $html );
		self::assertStringContainsString( 'sw-ui-badge--warn', $html );
		self::assertStringNotContainsString( 'sw-diag-card', $html, 'No older diagnostic card classes.' );
		self::assertStringNotContainsString( ' style=', $html );
		self::assertSame( 1, substr_count( $html, 'sw-ui-btn--primary' ), 'Run diagnostics is the one primary action.' );
		self::assertMatchesRegularExpression( '/<button[^>]*type="submit"[^>]*form="stonewright-diagnostics-form"[^>]*>Run diagnostics</', $html, 'The run button sits in the card header, above the results, and still posts the form.' );
		self::assertMatchesRegularExpression( '/data-sw-diag-results[^>]*aria-busy="false"|aria-busy="false"[^>]*data-sw-diag-results/', $html );
		self::assertMatchesRegularExpression( '/role="status"[^>]*data-sw-diag-summary|data-sw-diag-summary[^>]*role="status"/', $html, 'The summary is announced.' );
		preg_match_all( '/\sid="([^"]+)"/', $html, $ids );
		self::assertSame( array_values( array_unique( $ids[1] ) ), $ids[1], 'No duplicate ids.' );
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
		self::assertStringNotContainsString( 'sentinel-marker-token', $html );
		self::assertStringNotContainsString( 'javascript:', $html );

		$copy = DiagnosticsPanel::plaintext_report( $report );
		self::assertStringContainsString( 'oauth-http', $copy );
		self::assertStringContainsString( 'corr-example-1234', $copy );
		self::assertStringNotContainsString( 'sentinel-marker-token', $copy );
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

	public function test_info_checks_are_not_counted_as_successful(): void {
		$report = [
			'ready'    => false,
			'method'   => 'oauth-http',
			'counts'   => [ 'problem' => 1, 'warning' => 0, 'info' => 2, 'ok' => 1, 'skipped' => 0 ],
			'checks'   => [
				DiagnosticCheck::problem( 'mcp_runtime', 'MCP runtime', 'Blocked.', 'Install a compatible adapter.' )->to_array(),
				DiagnosticCheck::info( 'endpoint', 'MCP endpoint configured', 'https://example.test/wp-json/mcp/stonewright' )->to_array(),
				DiagnosticCheck::info( 'mcp_server_registration', 'MCP server registration', 'REST routes have not been initialized in this request.' )->to_array(),
				DiagnosticCheck::ok( 'transport', 'Connection transport', 'HTTPS active.' )->to_array(),
			],
			'versions' => [ 'plugin' => '0.0.0-test' ],
		];

		ob_start();
		DiagnosticsPanel::render( 'stonewright-troubleshoot', 'Connection checks', $report );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '1 problem', $html );
		self::assertStringContainsString( '1 check passed', $html );
		self::assertStringNotContainsString( '3 checks passed', $html );
		self::assertStringContainsString( '2 other checks', $html, 'Info checks are listed apart from the passed ones.' );
		self::assertStringContainsString( 'MCP endpoint configured', $html );
	}

	public function test_a_report_with_nothing_wrong_says_so_and_a_group_with_one_check_is_singular(): void {
		$report = [
			'ready'    => true,
			'method'   => 'oauth-http',
			'counts'   => [ 'problem' => 0, 'warning' => 0, 'info' => 0, 'ok' => 2, 'skipped' => 0 ],
			'checks'   => [
				DiagnosticCheck::ok( 'a', 'First check', 'Fine.' )->to_array(),
				DiagnosticCheck::ok( 'b', 'Second check', 'Fine.' )->to_array(),
			],
			'versions' => [ 'plugin' => '0.0.0-test' ],
		];

		ob_start();
		DiagnosticsPanel::render( 'stonewright-troubleshoot', 'Connection checks', $report );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'No problems or warnings', $html );
		self::assertStringContainsString( '2 checks passed', $html );
		self::assertStringNotContainsString( 'sw-ui-badge--danger', $html );
	}

	public function test_a_clean_report_with_checks_that_have_not_run_does_not_claim_everything_passed(): void {
		self::assertSame( 'No problems or warnings so far. Run the diagnostics to complete the checks that have not run.', DiagnosticsPanel::summary_text( [ 'problem' => 0, 'warning' => 0, 'info' => 2, 'ok' => 3, 'skipped' => 0 ], true ) );
		self::assertSame( 'No problems or warnings.', DiagnosticsPanel::summary_text( [ 'problem' => 0, 'warning' => 0, 'info' => 0, 'ok' => 3, 'skipped' => 0 ] ) );
		self::assertSame( '2 problems and 1 warning to look at.', DiagnosticsPanel::summary_text( [ 'problem' => 2, 'warning' => 1, 'info' => 2, 'ok' => 3, 'skipped' => 0 ], true ) );
	}

	public function test_a_finished_run_with_only_information_rows_says_there_are_no_problems(): void {
		$counts = [ 'problem' => 0, 'warning' => 0, 'info' => 6, 'ok' => 14, 'skipped' => 2 ];

		self::assertSame( 'No problems or warnings.', DiagnosticsPanel::summary_text( $counts ) );
		self::assertSame( 'No problems or warnings.', DiagnosticsPanel::summary_text( $counts, false ) );
	}

	public function test_the_panel_says_so_far_only_while_a_check_has_not_run(): void {
		$finished = [
			'ready'    => true,
			'method'   => 'oauth-http',
			'counts'   => [ 'problem' => 0, 'warning' => 0, 'info' => 2, 'ok' => 2, 'skipped' => 0 ],
			'checks'   => [
				DiagnosticCheck::ok( 'a', 'First check', 'Fine.' )->to_array(),
				DiagnosticCheck::ok( 'b', 'Second check', 'Fine.' )->to_array(),
				DiagnosticCheck::info( 'endpoint', 'MCP endpoint configured', 'https://example.test/wp-json/mcp/stonewright' )->to_array(),
				DiagnosticCheck::info( 'connection', 'Connection', 'Configuration was verified; the connection has not been tested.' )->to_array(),
			],
			'versions' => [ 'plugin' => '0.0.0-test' ],
		];
		ob_start();
		DiagnosticsPanel::render( 'stonewright-troubleshoot', 'Connection checks', $finished );
		$html = (string) ob_get_clean();
		self::assertStringContainsString( 'No problems or warnings.', $html );
		self::assertStringNotContainsString( 'so far', $html );

		$pending                    = $finished;
		$pending['checks'][]        = DiagnosticCheck::info( 'connection_probe', 'MCP connection probe', 'Not run yet', [ 'state' => 'not_run' ] )->to_array();
		$pending['counts']['info']  = 3;
		ob_start();
		DiagnosticsPanel::render( 'stonewright-troubleshoot', 'Connection checks', $pending );
		$html = (string) ob_get_clean();
		self::assertStringContainsString( 'No problems or warnings so far.', $html );
	}

	public function test_a_problem_row_never_prints_the_same_sentence_twice(): void {
		$report = [
			'ready'    => false,
			'method'   => 'oauth-http',
			'counts'   => [ 'problem' => 1, 'warning' => 0, 'info' => 0, 'ok' => 0, 'skipped' => 0 ],
			'checks'   => [ DiagnosticCheck::problem( 'oauth_transport', 'OAuth transport', 'OAuth is disabled on public plain HTTP sites.', 'OAuth is disabled on public plain HTTP sites.' )->to_array() ],
			'versions' => [ 'plugin' => '0.0.0-test' ],
		];

		ob_start();
		DiagnosticsPanel::render( 'stonewright-troubleshoot', 'Connection checks', $report );
		$html = (string) ob_get_clean();

		$visible = (string) preg_replace( '#<pre[^>]*hidden.*?</pre>#s', '', $html );

		self::assertSame( 1, substr_count( $visible, 'OAuth is disabled on public plain HTTP sites.' ), 'A cause equal to its remedy is shown once.' );
	}

	public function test_the_report_button_shows_a_visible_copied_state_like_the_other_copy_buttons(): void {
		$report = [
			'ready'    => true,
			'method'   => 'oauth-http',
			'counts'   => [ 'problem' => 0, 'warning' => 0, 'info' => 0, 'ok' => 1, 'skipped' => 0 ],
			'checks'   => [ DiagnosticCheck::ok( 'a', 'First check', 'Fine.' )->to_array() ],
			'versions' => [ 'plugin' => '0.0.0-test' ],
		];

		ob_start();
		DiagnosticsPanel::render( 'stonewright-troubleshoot', 'Connection checks', $report );
		$html = (string) ob_get_clean();

		self::assertMatchesRegularExpression( '/<button[^>]*data-sw-ui-copy="#stonewright-diagnostics-copy"[^>]*>(?:(?!<\/button>).)*sw-ui-copy__icon-done(?:(?!<\/button>).)*Copy report for support/s', $html, 'The button swaps its icon for a check.' );
		self::assertMatchesRegularExpression( '/<span[^>]*class="sw-ui-copy__status"[^>]*id="stonewright-diagnostics-copy-status"|<span[^>]*id="stonewright-diagnostics-copy-status"[^>]*class="sw-ui-copy__status"/', $html, 'The status text is visible, not visually hidden.' );
		self::assertDoesNotMatchRegularExpression( '/class="sw-ui-visually-hidden"[^>]*id="stonewright-diagnostics-copy-status"/', $html );
	}

	public function test_the_script_paints_results_with_the_layer_classes_and_text_only(): void {
		$js = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/admin/pages/troubleshoot.js' );

		self::assertStringContainsString( 'sw-ui-badge', $js );
		self::assertStringContainsString( 'sw-ui-table sw-ui-table--stack', $js );
		self::assertStringContainsString( 'aria-busy', $js, 'A run shows a loading state.' );
		self::assertStringContainsString( 'sw-ui-skeleton', $js );
		self::assertStringContainsString( 'Stonewright.ui.notify', $js, 'A failed run says so and stays until the next run.' );
		self::assertStringContainsString( 'scrollTo', $js );
		self::assertStringContainsString( "'not_run'", $js, 'The summary says so far only for checks that have not run.' );
		self::assertStringContainsString( 'report_text', $js, 'The support report is the one the server built, with the same redaction.' );
		self::assertStringNotContainsString( 'function formatReport', $js );
		self::assertDoesNotMatchRegularExpression( '/innerHTML|outerHTML|insertAdjacentHTML|document\.write|eval\(|new Function|scrollIntoView/', $js );
		self::assertDoesNotMatchRegularExpression( '/\.style\b|cssText|setAttribute\(\s*[\'"]style/', $js );
	}
}
