<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\Pages\TroubleshootPage;
use Stonewright\WpMcp\Authorization\WordPress\AuthorizationLifecycle;
use Stonewright\WpMcp\Authorization\WordPress\HttpSurface;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\HttpRig;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\StorageRig;

/**
 * The Troubleshoot screen: connection checks with their OAuth transport result, the
 * MCP runtime compatibility card and the Elementor provider summary.
 *
 * @covers \Stonewright\WpMcp\Admin\Pages\TroubleshootPage
 */
final class TroubleshootPageRenderTest extends TestCase {

	private HttpRig $http;

	protected function setUp(): void {
		StorageRig::reset_globals();
		$this->http = new HttpRig();
		HttpSurface::use_site( HttpRig::site( true, 'production', 'pretty', 'https://example.com' ) );
		AuthorizationLifecycle::use_storage( $this->http->storage );
		$GLOBALS['stonewright_test_user_caps']                      = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_current_user_id']                = 1;
		$GLOBALS['stonewright_test_options']['stonewright_enabled'] = '1';
		$GLOBALS['stonewright_test_transients']                     = [];
		$_GET = [];
	}

	protected function tearDown(): void {
		HttpSurface::use_site( null );
		AuthorizationLifecycle::use_storage( null );
		StorageRig::reset_globals();
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_transients']      = [];
		unset( $_SERVER['REMOTE_ADDR'] );
		$_GET = [];
	}

	private static function render(): string {
		ob_start();
		try {
			TroubleshootPage::render();
		} finally {
			$html = (string) ob_get_clean();
		}
		return $html;
	}

	public function test_the_page_renders_the_checks_and_runtime_sections_inside_the_shell(): void {
		$html = self::render();

		self::assertStringContainsString( 'data-sw-shell', $html );
		self::assertStringContainsString( '<h1>Troubleshoot</h1>', $html );
		self::assertStringContainsString( 'Connection checks', $html );
		self::assertStringContainsString( 'id="stonewright-diagnostics-form"', $html );
		self::assertStringContainsString( 'name="action" value="stonewright_run_diagnostics"', $html );
		self::assertStringContainsString( 'name="stonewright_diagnostics_return" value="stonewright-troubleshoot"', $html );
		self::assertStringContainsString( 'OAuth transport', $html );
		self::assertStringContainsString( 'MCP runtime compatibility', $html );
		self::assertStringContainsString( 'Elementor provider discovery', $html );
		self::assertSame( substr_count( $html, '<section' ), substr_count( $html, '</section>' ) );
	}

	public function test_a_public_plain_http_site_shows_the_oauth_transport_problem(): void {
		HttpSurface::use_site( HttpRig::site( true, 'production', 'pretty', 'http://example.com' ) );

		$html = self::render();

		self::assertMatchesRegularExpression( '/sw-diag-card--error" data-status="problem">.*?OAuth transport.*?OAuth is disabled on public plain HTTP sites\./s', $html );
	}

	public function test_the_last_saved_report_is_shown_escaped_after_a_run(): void {
		$_GET['stonewright_diagnostics'] = '1';
		update_option(
			'stonewright_diagnostics_last',
			[
				'method'   => 'oauth-http',
				'checks'   => [
					[
						'id'      => 'synthetic',
						'label'   => 'Synthetic <b>check</b>',
						'status'  => 'problem',
						'summary' => 'Synthetic summary',
					],
				],
				'versions' => [
					'plugin'             => '0.0.0-test',
					'companion_contract' => '1.0.0',
				],
			]
		);

		$html = self::render();

		self::assertStringContainsString( 'Synthetic &lt;b&gt;check&lt;/b&gt;', $html );
		self::assertStringNotContainsString( 'Synthetic <b>check</b>', $html );
		self::assertStringContainsString( 'Synthetic summary', $html );
	}

	public function test_the_page_refuses_without_manage_options(): void {
		$GLOBALS['stonewright_test_user_caps'] = [];

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/wp_die/' );
		self::render();
	}
}
