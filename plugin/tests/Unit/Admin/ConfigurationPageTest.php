<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\ConfigurationPage;

/**
 * @covers \Stonewright\WpMcp\Admin\ConfigurationPage
 */
final class ConfigurationPageTest extends TestCase {

	/**
	 * Form field names that must never change without an intentional migration.
	 * Snapshot guard against functional regression of POST handlers.
	 *
	 * @var list<string>
	 */
	private const FORM_FIELD_NAMES = [
		'stonewright_enabled',
		'stonewright_mode',
		'stonewright_mcp_surface',
		'stonewright_elementor_v4_atomic',
		'stonewright_companion_url',
		'stonewright_companion_token',
		'stonewright_app_password_name',
		'stonewright_app_password_uuid',
	];

	/**
	 * Hidden action values always present when app passwords exist for the user.
	 * Domain lock reset is conditional and not part of this snapshot.
	 *
	 * @var list<string>
	 */
	private const FORM_ACTION_VALUES = [
		'stonewright_generate_application_password',
		'stonewright_revoke_application_password',
	];

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
		self::assertStringNotContainsString( 'stonewright-brand-banner', $html );
		self::assertStringContainsString( 'sw-setup-page', $html );
		self::assertStringContainsString( 'sw-stepper', $html );
		self::assertStringContainsString( 'sw-checklist', $html );
		self::assertStringContainsString( 'sw-client-cards', $html );
		self::assertStringContainsString( 'data-stonewright-client-picker', $html );
		self::assertStringContainsString( 'data-stonewright-method-picker', $html );
		self::assertStringContainsString( 'sw-method-picker', $html );
		self::assertStringContainsString( 'stonewright-setup-step', $html );
		self::assertStringContainsString( 'stonewright-risk-notice', $html );
		self::assertStringContainsString( 'id="stonewright_enabled"', $html );
		self::assertStringContainsString( 'id="stonewright_mode"', $html );
		self::assertStringContainsString( 'stonewright_generate_application_password', $html );
		self::assertStringContainsString( 'Application Password', $html );
		self::assertStringContainsString( 'Connect MCP Client', $html );
		self::assertStringContainsString( 'Setup diagnostics', $html );
		self::assertStringContainsString( 'Remote Streamable HTTP', $html );
		self::assertStringContainsString( 'Local companion (stdio)', $html );
		self::assertStringContainsString( 'No Node or companion required', $html );
		self::assertStringContainsString( 'data-stonewright-method="stdio"', $html );
		self::assertStringContainsString( 'data-stonewright-method="http"', $html );
		self::assertStringContainsString( 'data-stonewright-method-snippet="stdio"', $html );
		self::assertStringContainsString( 'data-stonewright-method-snippet="http"', $html );
		self::assertStringNotContainsString( 'stonewright-client-tabs', $html );
		self::assertStringNotContainsString( 'data-stonewright-client-tab', $html );
		self::assertStringNotContainsString( 'Need the JSON config for a specific client?', $html );
		self::assertStringNotContainsString( 'stonewright-remote-http-snippet', $html );
		self::assertStringContainsString( 'stonewright-connect-prompt-full', $html );
		self::assertSame( 1, substr_count( $html, 'class="stonewright-connect-prompt' ) );
		self::assertStringContainsString( 'data-stonewright-text-preview', $html );
		self::assertStringContainsString( 'data-stonewright-text-full', $html );
		self::assertStringContainsString( 'stonewright-prompt-library', $html );
		self::assertStringContainsString( 'stonewright-example-prompts', $html );
		self::assertStringContainsString( 'data-stonewright-prompt-grid', $html );
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
		self::assertStringContainsString( 'data-stonewright-connection-test', $html );
		self::assertStringContainsString( 'Run preflight', $html );
		self::assertStringContainsString( 'data-stonewright-connection-verify', $html );
		self::assertStringContainsString( 'Verify connection', $html );
		self::assertStringContainsString( 'stonewright/v1/admin/connection-verify', $html );
		self::assertStringContainsString( 'live authenticated MCP loopback', $html );
		self::assertStringNotContainsString( 'Run connection test', $html );
		self::assertStringNotContainsString( 'stonewright-badge--ok', $html );
		self::assertStringNotContainsString( 'stonewright-badge--neutral', $html );
	}

	public function test_render_includes_stepper_checklist_and_client_cards(): void {
		ob_start();
		ConfigurationPage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'sw-stepper__step--current', $html );
		self::assertStringContainsString( 'data-step="1"', $html );
		self::assertStringContainsString( 'data-step="2"', $html );
		self::assertStringContainsString( 'data-step="3"', $html );
		self::assertGreaterThanOrEqual( 5, substr_count( $html, 'sw-checklist__item' ) );
		self::assertStringContainsString( 'data-stonewright-client-card="claude-code"', $html );
		self::assertStringContainsString( 'data-stonewright-client-card="claude-desktop"', $html );
		self::assertStringContainsString( 'data-stonewright-client-card="cursor"', $html );
		self::assertStringContainsString( 'data-stonewright-client-card="codex"', $html );
		self::assertStringContainsString( 'data-stonewright-client-card="antigravity"', $html );
		self::assertStringContainsString( 'data-stonewright-client-card="vscode-copilot"', $html );
		self::assertStringContainsString( 'data-stonewright-client-card="generic-mcp"', $html );
		self::assertStringNotContainsString( 'data-stonewright-client-card="other"', $html );
		self::assertGreaterThanOrEqual( 16, substr_count( $html, 'data-stonewright-client-card="' ) );
		self::assertStringContainsString( 'sw-client-snippet-claude-desktop-stdio', $html );
		self::assertStringContainsString( 'sw-client-snippet-claude-desktop-http', $html );
		self::assertStringContainsString( 'STONEWRIGHT_WP_URL', $html );
		self::assertStringContainsString( 'data-stonewright-connection-test', $html );
		self::assertStringContainsString( 'data-stonewright-connection-verify', $html );
	}

	public function test_form_field_name_snapshot_is_stable(): void {
		ob_start();
		ConfigurationPage::render();
		$html = (string) ob_get_clean();

		foreach ( self::FORM_FIELD_NAMES as $name ) {
			self::assertMatchesRegularExpression(
				'/\bname=["\']' . preg_quote( $name, '/' ) . '["\']/',
				$html,
				'Missing form field name: ' . $name
			);
		}

		foreach ( self::FORM_ACTION_VALUES as $action ) {
			self::assertStringContainsString(
				'value="' . $action . '"',
				$html,
				'Missing form action value: ' . $action
			);
		}

		// Settings form still posts to options.php (Settings API group stonewright_settings).
		self::assertStringContainsString( 'action="options.php"', $html );
		self::assertStringContainsString( 'stonewright-settings-form', $html );
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
