<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\ConfigurationPage;

/**
 * @covers \Stonewright\WpMcp\Admin\ConfigurationPage
 */
final class ConfigurationPageTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_options']   = [
			'stonewright_enabled'         => false,
			'stonewright_mode'            => 'staging',
			'stonewright_companion_token' => 'test-token',
		];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_caps'] = [];
		$GLOBALS['stonewright_test_options']   = [];
	}

	public function test_render_outputs_guided_connect_wizard_controls(): void {
		ob_start();
		ConfigurationPage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'stonewright-admin-shell', $html );
		self::assertStringContainsString( 'stonewright-setup-step', $html );
		self::assertStringContainsString( 'stonewright-risk-notice', $html );
		self::assertStringContainsString( 'id="stonewright_enabled"', $html );
		self::assertStringContainsString( 'id="stonewright_mode"', $html );
		self::assertStringContainsString( 'Manage application passwords', $html );
		self::assertStringContainsString( 'stonewright-client-tabs', $html );
		self::assertStringContainsString( 'stonewright-bootstrap-prompt', $html );
		self::assertStringContainsString( 'stonewright-onboarding-prompt', $html );
		self::assertStringContainsString( 'Describe the WordPress task, desired page or template, visual reference, allowed plugins, safety mode, and acceptance checks.', $html );
		self::assertStringContainsString( 'data-stonewright-secret-toggle', $html );
		self::assertStringContainsString( 'data-stonewright-copy', $html );
		self::assertStringContainsString( 'type="password"', $html );
	}
}
