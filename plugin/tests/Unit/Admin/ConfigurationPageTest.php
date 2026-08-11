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
		self::assertStringContainsString( 'id="stonewright_mcp_surface"', $html );
		self::assertStringContainsString( 'Essential is the recommended default for real work.', $html );
		self::assertStringNotContainsString( 'Bootstrap is the recommended default for new clients.', $html );
		self::assertStringContainsString( 'data-sw-apply-mcp-surface', $html );
		self::assertStringContainsString( 'Apply now', $html );
		self::assertStringContainsString( 'stonewright_generate_application_password', $html );
		self::assertStringContainsString( 'Application Password', $html );
		self::assertStringContainsString( 'Connect Your AI Client', $html );
		self::assertStringContainsString( 'Setup diagnostics', $html );
		self::assertStringContainsString( 'Remote Streamable HTTP', $html );
		self::assertStringContainsString( 'Local companion (stdio)', $html );
		self::assertStringContainsString( 'communicates with it through standard input/output', $html );
		self::assertStringContainsString( 'required for Direct mode and local WP-CLI', $html );
		self::assertStringContainsString( 'does not run a local companion', $html );
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
		self::assertStringContainsString( 'Keep Stonewright current', $html );
		self::assertStringContainsString( 'Update the WordPress plugin', $html );
		self::assertStringContainsString( 'Update the local companion', $html );
		self::assertStringContainsString( 'Copy current companion URL', $html );
		self::assertStringContainsString( 'Updates preserve memory, user skills, audit, and Direct state.', $html );
		self::assertStringContainsString( 'Never commit credentials.', $html );
		self::assertSame( 1, substr_count( $html, 'class="stonewright-connect-prompt' ) );
		self::assertStringContainsString( 'data-stonewright-text-preview', $html );
		self::assertStringContainsString( 'data-stonewright-text-full', $html );
		self::assertStringContainsString( 'stonewright-prompts', $html );
		self::assertStringContainsString( 'Open the Prompt Library tab', $html );
		self::assertStringContainsString( 'data-sw-tooltip', $html );
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
		self::assertStringContainsString( 'data-stonewright-companion-status', $html );
		self::assertStringContainsString( 'stonewright/v1/admin/companion-update-status', $html );
		self::assertStringContainsString( 'Copy update prompt', $html );
		self::assertStringContainsString( 'Download official companion', $html );
		self::assertStringContainsString( 'browser cannot replace an stdio process', $html );
		self::assertStringContainsString( 'live authenticated MCP loopback', $html );
		self::assertStringNotContainsString( 'Run connection test', $html );
		self::assertStringNotContainsString( 'stonewright-badge--ok', $html );
		self::assertStringNotContainsString( 'stonewright-badge--neutral', $html );
	}

	public function test_render_recommends_oauth_and_preserves_application_password_fallback(): void {
		ob_start();
		ConfigurationPage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Choose your authentication method', $html );
		self::assertStringContainsString( 'data-stonewright-auth-method="oauth"', $html );
		self::assertStringContainsString( 'Recommended for your setup', $html );
		self::assertStringContainsString( 'data-stonewright-auth-method="application-password"', $html );
		self::assertStringContainsString( '/wp-json/mcp/stonewright-oauth', $html );
		self::assertStringContainsString( 'data-stonewright-oauth-connect', $html );
		self::assertStringContainsString( 'Manage connected apps', $html );
		self::assertStringContainsString( 'Generate application password', $html );
		self::assertStringNotContainsString( 'Novamira', $html );
	}

	public function test_oauth_ready_setup_does_not_require_an_application_password_to_reach_connect_step(): void {
		$GLOBALS['stonewright_test_options']['stonewright_enabled'] = true;
		$GLOBALS['stonewright_test_app_passwords']                  = [];

		ob_start();
		ConfigurationPage::render();
		$html = (string) ob_get_clean();

		self::assertMatchesRegularExpression(
			'/class="sw-stepper__step sw-stepper__step--done" data-step="2" data-state="done"/',
			$html
		);
		self::assertMatchesRegularExpression(
			'/class="sw-stepper__step sw-stepper__step--current" data-step="3" data-state="current"/',
			$html
		);
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

	public function test_v4_toggle_posts_explicit_false_and_true_values(): void {
		ob_start();
		ConfigurationPage::render();
		$html = (string) ob_get_clean();

		self::assertMatchesRegularExpression(
			'/<input[^>]+type="hidden"[^>]+name="stonewright_elementor_v4_atomic"[^>]+value="0"[^>]*>.*'
			. '<input[^>]+type="checkbox"[^>]+name="stonewright_elementor_v4_atomic"[^>]+value="1"/s',
			$html
		);
	}

	public function test_v4_toggle_reflects_enabled_option(): void {
		$GLOBALS['stonewright_test_options']['stonewright_elementor_v4_atomic'] = true;

		ob_start();
		ConfigurationPage::render();
		$html = (string) ob_get_clean();

		self::assertMatchesRegularExpression(
			'/name="stonewright_elementor_v4_atomic"[^>]+value="1"[^>]+checked/',
			$html
		);
	}

	public function test_render_never_reads_or_embeds_a_persisted_application_password(): void {
		ob_start();
		ConfigurationPage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '&lt;your-application-password&gt;', $html );
		self::assertStringNotContainsString( 'test-fresh-app-password', $html );
		self::assertStringNotContainsString( 'stonewright_app_password_flash_', $html );
	}

	public function test_nojs_generate_source_never_persists_plaintext(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/includes/Admin/ConfigurationPage.php' );

		self::assertStringNotContainsString( 'set_transient(', $source );
		self::assertStringNotContainsString( 'application_password_flash', $source );
		self::assertStringContainsString( 'Cache-Control: no-store, private, max-age=0', $source );
		self::assertStringContainsString( 'render_application_password_once( $name, $password )', $source );
	}

	public function test_domain_lock_clear_hidden_during_mismatch(): void {
		$GLOBALS['stonewright_test_options']['stonewright_enabled'] = true;
		$GLOBALS['stonewright_test_home_url'] = 'https://example.test/';
		\Stonewright\WpMcp\Security\DomainLock::lock();
		$GLOBALS['stonewright_test_home_url'] = 'https://cloned.example/';
		\Stonewright\WpMcp\Security\DomainLock::record_mismatch();

		ob_start();
		ConfigurationPage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'stonewright-domain-lock', $html );
		self::assertStringContainsString( 'Mismatch — abilities are BLOCKED', $html );
		self::assertStringContainsString( 'stonewright_rebind_domain_lock', $html );
		self::assertStringNotContainsString( 'stonewright_reset_domain_lock', $html );
		self::assertStringContainsString(
			'Clear domain lock is disabled during a mismatch',
			$html
		);

		delete_option( 'stonewright_locked_domain' );
		delete_option( 'stonewright_domain_mismatch' );
		delete_option( 'stonewright_domain_lock_prior' );
		unset( $GLOBALS['stonewright_test_home_url'] );
	}
}
