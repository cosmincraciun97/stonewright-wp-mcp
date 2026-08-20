<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class AdminJavascriptTest extends TestCase {

	public function test_copy_buttons_have_clipboard_rejection_fallback(): void {
		$script = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/admin/admin.js' );

		self::assertStringContainsString( 'navigator.clipboard.writeText', $script );
		self::assertStringContainsString( '.catch( fallbackCopy )', $script );
		self::assertStringContainsString( "document.execCommand( 'copy' )", $script );
		self::assertStringContainsString( 'showCopyFallbackModal', $script );
		self::assertStringContainsString( 'Press Ctrl/Cmd+C', $script );

		$start = strpos( $script, 'function initCopyButtons()' );
		$end   = strpos( $script, 'function initSecretToggles()', false === $start ? 0 : $start );
		self::assertNotFalse( $start );
		self::assertNotFalse( $end );
		$body = substr( $script, (int) $start, (int) $end - (int) $start );
		self::assertStringContainsString( 'showCopyFallbackModal', $body );
		self::assertStringNotContainsString( 'Copy failed', $body );
	}

	public function test_declarative_button_handlers_prevent_default_form_submission(): void {
		$script = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/admin/admin.js' );

		foreach ( [
			'data-stonewright-copy',
			'data-stonewright-secret-toggle',
			'data-stonewright-generate-token',
			'data-stonewright-text-toggle',
			'data-stonewright-text-collapse',
			'data-stonewright-toggle-target',
			'data-stonewright-hide-target',
			'data-stonewright-row-toggle',
			'data-stonewright-skill-toggle',
		] as $attribute ) {
			self::assertMatchesRegularExpression(
				'/' . preg_quote( $attribute, '/' ) . '.*?addEventListener\( \'click\', function \( event \).*?event\.preventDefault\(\);/s',
				$script,
				$attribute . ' click handler should prevent accidental form submission.'
			);
		}
	}

	public function test_bridge_token_generator_uses_browser_crypto(): void {
		$script = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/admin/admin.js' );

		self::assertStringContainsString( 'data-stonewright-generate-token', $script );
		self::assertStringContainsString( 'crypto.getRandomValues', $script );
		self::assertStringContainsString( 'data-stonewright-bridge-token-source', $script );
		self::assertStringContainsString( 'COMPANION_BEARER_TOKEN=', $script );
	}

	public function test_connection_verify_posts_to_loopback_endpoint(): void {
		$script = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/admin/admin.js' );

		self::assertStringContainsString( 'data-stonewright-connection-verify', $script );
		self::assertStringContainsString( 'data-stonewright-companion-status', $script );
		self::assertStringContainsString( 'data-stonewright-companion-prompt', $script );
		self::assertStringContainsString( 'Not visible from WordPress', $script );
		self::assertStringContainsString( "data.companion_status === 'mismatch'", $script );
		self::assertStringContainsString( 'initConnectionVerify', $script );
		self::assertStringContainsString( "method: 'POST'", $script );
		self::assertStringContainsString( 'MCP loopback verified', $script );
		self::assertStringContainsString( 'normalizeChecklistStatus', $script );
	}

	public function test_explicit_companion_check_bypasses_browser_caches(): void {
		$script = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/admin/admin.js' );
		$start  = strpos( $script, 'function initCompanionUpdateStatus()' );
		$end    = strpos( $script, 'function escapeRegExp', false === $start ? 0 : $start );

		self::assertNotFalse( $start );
		self::assertNotFalse( $end );
		$companion_update_script = substr( $script, (int) $start, (int) $end - (int) $start );

		self::assertStringContainsString( "refreshUrl.searchParams.set( 'force', '1' )", $companion_update_script );
		self::assertStringContainsString( "cache: 'no-store'", $companion_update_script );

		$connection_test_end = strpos( $script, 'function initCompanionUpdateStatus()' );
		self::assertNotFalse( $connection_test_end );
		$connection_test_script = substr( $script, 0, (int) $connection_test_end );
		self::assertStringNotContainsString( "refreshUrl.searchParams.set( 'force', '1' )", $connection_test_script );
	}

	public function test_setup_client_and_method_pickers_are_wired(): void {
		$script = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/admin/admin.js' );

		self::assertStringContainsString( 'initClientCards', $script );
		self::assertStringContainsString( 'initMethodPicker', $script );
		self::assertStringContainsString( 'persistSetupPreference', $script );
		self::assertStringContainsString( 'data-stonewright-method-picker', $script );
		self::assertStringContainsString( 'data-stonewright-method-snippet', $script );
		self::assertStringContainsString( "body.set( 'method', method )", $script );
		self::assertStringContainsString( "body.set( 'client', client )", $script );
		self::assertStringNotContainsString( 'initClientTabs', $script );
		self::assertStringNotContainsString( 'data-stonewright-client-tab', $script );
	}

	public function test_apply_mcp_surface_button_is_wired(): void {
		$script = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/admin/admin.js' );

		self::assertStringContainsString( 'initApplyMcpSurface', $script );
		self::assertStringContainsString( 'data-sw-apply-mcp-surface', $script );
		self::assertStringContainsString( 'stonewright_apply_mcp_surface', $script );
		self::assertStringContainsString( 'transport_truth', $script );
	}

	public function test_run_diagnostics_posts_ajax_without_page_refresh(): void {
		$script = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/admin/admin.js' );

		self::assertStringContainsString( 'initRunDiagnostics', $script );
		self::assertStringContainsString( 'initRunDiagnostics();', $script );
		self::assertStringContainsString( 'data-stonewright-run-diagnostics', $script );
		self::assertStringContainsString( "body.set( 'action', 'stonewright_run_diagnostics' )", $script );
		self::assertStringContainsString( "body.set( 'nonce', window.stonewrightSetup.nonce || '' )", $script );
		self::assertStringContainsString( "body.set( 'mode', mode )", $script );
		self::assertStringContainsString( 'sw-diag-card', $script );
		self::assertStringContainsString( 'is-loading', $script );
		self::assertStringContainsString( "setAttribute( 'aria-busy', 'true' )", $script );
		self::assertStringContainsString( 'Copy ticket', $script );
		self::assertStringContainsString( 'scrollIntoView', $script );

		$start = strpos( $script, 'function initRunDiagnostics()' );
		self::assertNotFalse( $start );
		$end = strpos( $script, "document.addEventListener( 'DOMContentLoaded'", $start );
		self::assertNotFalse( $end );
		$body = substr( $script, (int) $start, (int) $end - (int) $start );

		self::assertStringContainsString( 'event.preventDefault()', $body );
		self::assertStringContainsString( 'button.disabled = true', $body );
		self::assertStringContainsString( 'button.disabled = false', $body );
		self::assertStringContainsString( "setAttribute( 'aria-busy', 'false' )", $body );
		self::assertStringContainsString( 'classList.add( \'is-loading\' )', $body );
		self::assertStringContainsString( 'classList.remove( \'is-loading\' )', $body );
		self::assertStringNotContainsString( 'admin-post.php', $body );
		self::assertStringNotContainsString( 'location.reload', $body );
		self::assertStringNotContainsString( 'form.submit()', $body );
	}

	public function test_app_password_memory_restores_snippet_placeholders(): void {
		$script = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/admin/admin.js' );

		self::assertStringContainsString( 'appPasswordSnippetTemplates', $script );
		self::assertStringContainsString( 'captureAppPasswordSnippetTemplates', $script );
		self::assertStringContainsString( 'restoreAppPasswordSnippetPlaceholders', $script );
		self::assertStringContainsString( 'restoreAppPasswordSnippetPlaceholders();', $script );
		self::assertStringContainsString( "entry.template.split( '<your-application-password>' ).join( password )", $script );

		// clearAppPasswordMemory must restore templates so secrets never linger and
		// a second generate can re-insert from the original placeholder text.
		$clear_start = strpos( $script, 'function clearAppPasswordMemory()' );
		$clear_end   = strpos( $script, 'function showAppPasswordLive', false === $clear_start ? 0 : $clear_start );
		self::assertNotFalse( $clear_start );
		self::assertNotFalse( $clear_end );
		$clear_body = substr( $script, (int) $clear_start, (int) $clear_end - (int) $clear_start );
		self::assertStringContainsString( 'restoreAppPasswordSnippetPlaceholders()', $clear_body );
	}

	public function test_password_inventory_creates_table_when_empty_state(): void {
		$script = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/admin/admin.js' );

		self::assertStringContainsString( 'function ensurePasswordInventoryTable()', $script );
		self::assertStringContainsString( 'function refreshPasswordInventory( passwords )', $script );
		self::assertStringContainsString( 'ensurePasswordInventoryTable()', $script );
		self::assertStringContainsString( 'stonewright-app-password-table', $script );
		self::assertStringContainsString( 'updatePasswordInventorySummary', $script );

		// Must not early-return solely because tbody is missing (empty list markup).
		$refresh_start = strpos( $script, 'function refreshPasswordInventory( passwords )' );
		$refresh_end   = strpos( $script, 'function revokeAppPassword', false === $refresh_start ? 0 : $refresh_start );
		self::assertNotFalse( $refresh_start );
		self::assertNotFalse( $refresh_end );
		$refresh_body = substr( $script, (int) $refresh_start, (int) $refresh_end - (int) $refresh_start );
		self::assertStringNotContainsString( 'if ( ! tbody || ! Array.isArray( passwords ) )', $refresh_body );
		self::assertStringContainsString( 'ensurePasswordInventoryTable()', $refresh_body );
	}
}
