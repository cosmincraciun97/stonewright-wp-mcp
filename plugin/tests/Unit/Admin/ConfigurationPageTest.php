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
		$GLOBALS['stonewright_test_current_user_id'] = 42;
		$GLOBALS['stonewright_test_current_user_login'] = 'admin';
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_app_passwords'] = [
			42 => [
				[
					'uuid'    => 'stonewright-test-uuid',
					'name'    => 'Stonewright',
					'created' => 1710000000,
				],
			],
		];
		$GLOBALS['stonewright_test_options']   = [
			'stonewright_enabled'         => false,
			'stonewright_mode'            => 'staging',
			'stonewright_companion_token' => 'test-token',
		];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_caps'] = [];
		$GLOBALS['stonewright_test_options']   = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_current_user_login'] = 'admin';
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_app_passwords'] = [];
	}

	public function test_render_outputs_guided_connect_wizard_controls(): void {
		ob_start();
		ConfigurationPage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'stonewright-admin-shell', $html );
		self::assertStringContainsString( 'stonewright-brand-banner', $html );
		self::assertStringContainsString( 'stonewright-setup-step', $html );
		self::assertStringContainsString( 'stonewright-risk-notice', $html );
		self::assertStringContainsString( 'id="stonewright_enabled"', $html );
		self::assertStringContainsString( 'id="stonewright_mode"', $html );
		self::assertStringContainsString( 'stonewright_generate_application_password', $html );
		self::assertStringContainsString( 'Application Password', $html );
		self::assertStringContainsString( 'Connect MCP Client', $html );
		self::assertStringContainsString( 'Setup diagnostics', $html );
		self::assertStringContainsString( 'Remote HTTP', $html );
		self::assertStringContainsString( 'no Node or companion required', $html );
		self::assertStringContainsString( 'stonewright-remote-http-snippet', $html );
		self::assertStringContainsString( 'stonewright-connect-prompt-full', $html );
		self::assertSame( 1, substr_count( $html, 'class="stonewright-connect-prompt' ) );
		self::assertStringContainsString( 'data-stonewright-text-preview', $html );
		self::assertStringContainsString( 'data-stonewright-text-full', $html );
		self::assertStringContainsString( 'Need the JSON config for a specific client?', $html );
		self::assertStringContainsString( 'Examples: real Stonewright prompts', $html );
		self::assertStringContainsString( 'stonewright-example-prompt-0', $html );
		self::assertStringContainsString( 'stonewright-example-prompt-5', $html );
		self::assertStringContainsString( 'ACF field group', $html );
		self::assertStringContainsString( 'CPT UI', $html );
		self::assertStringContainsString( 'Figma design to Elementor V3', $html );
		self::assertStringContainsString( 'required', $html );
		self::assertStringNotContainsString( 'Leave blank to use "Stonewright".', $html );
		self::assertStringContainsString( 'stonewright_revoke_application_password', $html );
		self::assertStringContainsString( 'Revoke', $html );
		self::assertStringContainsString( 'data-confirm', $html );
		self::assertStringContainsString( 'data-stonewright-secret-toggle', $html );
		self::assertStringContainsString( 'data-stonewright-copy', $html );
		self::assertStringContainsString( 'type="password"', $html );
		self::assertStringContainsString( 'Local WP-CLI bridge (advanced)', $html );
		self::assertStringContainsString( 'Most users can skip this.', $html );
		self::assertStringContainsString( 'Step 3 already runs Stonewright through npx.', $html );
		self::assertStringContainsString( 'Developer launch values', $html );
		self::assertStringContainsString( 'STONEWRIGHT_HTTP_ENABLE=1', $html );
		self::assertStringContainsString( 'COMPANION_BEARER_TOKEN', $html );
		self::assertStringContainsString( 'Copy bridge launch env', $html );
		self::assertStringContainsString( 'Generate token', $html );
		self::assertStringContainsString( 'data-stonewright-generate-token', $html );
		self::assertStringContainsString( 'data-stonewright-bridge-token-source', $html );
	}

	public function test_render_embeds_generated_application_password_once(): void {
		$GLOBALS['stonewright_test_transients']['stonewright_app_password_flash_42'] = [
			'generated' => [
				'password' => 'fresh app password',
				'name'     => 'Stonewright',
				'uuid'     => 'uuid',
				'created'  => 1710000000,
			],
		];

		ob_start();
		ConfigurationPage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'fresh app password', $html );
		self::assertStringContainsString( 'Copy password only', $html );
		self::assertStringContainsString( 'Application password generated.', $html );
		self::assertArrayNotHasKey(
			'stonewright_app_password_flash_42',
			$GLOBALS['stonewright_test_transients']
		);
	}
}
