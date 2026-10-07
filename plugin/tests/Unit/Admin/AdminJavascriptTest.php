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

	public function test_the_setup_checks_show_a_busy_button_while_they_run_and_clear_it_when_they_end(): void {
		$script = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/admin/admin.js' );

		foreach ( [ 'initConnectionTest', 'initConnectionVerify', 'initCompanionUpdateStatus' ] as $name ) {
			$start = strpos( $script, 'function ' . $name . '()' );
			self::assertNotFalse( $start, $name );
			$next = strpos( $script, "
	function ", (int) $start + 10 );
			$body = substr( $script, (int) $start, false === $next ? null : $next - (int) $start );

			self::assertStringContainsString( "button.setAttribute( 'aria-busy', 'true' )", $body, $name . ' marks the button busy while the request runs.' );
			self::assertStringContainsString( "button.removeAttribute( 'aria-busy' )", $body, $name . ' clears it when the request ends, however it ends.' );
		}
	}

	public function test_notices_are_never_removed_on_a_timer(): void {
		$script = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/admin/admin.js' );

		// An error from another plugin or a core "Settings saved" must stay until it is dismissed (WCAG 2.2.1).
		self::assertStringNotContainsString( 'initAutoDismissNotices', $script );
		self::assertStringNotContainsString( 'is-dismissible', $script );
		self::assertStringNotContainsString( 'removeChild( notice )', $script );
		self::assertStringNotContainsString( "style.transition = 'opacity", $script );
	}

	/** The body of one function of shell.js, so the checks below read that function and nothing else. */
	private static function shell_function( string $name ): string {
		$script = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/admin/shell.js' );
		$start  = strpos( $script, 'function ' . $name . '(' );
		self::assertNotFalse( $start, $name . ' must exist in shell.js' );
		$next = strpos( $script, "\n\tfunction ", (int) $start + 10 );

		return substr( $script, (int) $start, false === $next ? null : $next - (int) $start );
	}

	public function test_the_shell_only_folds_notices_wordpress_printed(): void {
		$body = self::shell_function( 'isForeignNotice' );

		// Everything the plugin renders, and every class the plugin owns, is excluded.
		self::assertStringContainsString( '#sw-main', $body );
		self::assertStringContainsString( '.sw-notice-drawer', $body );
		self::assertMatchesRegularExpression( '/\(sw\|stonewright\)-/', $body, 'sw-* and stonewright-* classes mark plugin content wherever they sit in the class list.' );
		// Only WordPress notice classes count; matching on any class that merely contains "notice" caught plugin markup.
		self::assertStringNotContainsString( '/notice/i', $body );
		self::assertStringContainsString( '.notice, .updated, .error, .update-nag', $body );

		$fold = self::shell_function( 'foldNotices' );
		self::assertStringNotContainsString( '[class*="notice"]', $fold );
	}

	public function test_notices_are_left_where_wordpress_prints_them_until_more_than_three_arrive(): void {
		$script = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/admin/shell.js' );
		$fold   = self::shell_function( 'foldNotices' );

		self::assertStringContainsString( 'var MAX_VISIBLE = 3;', $script );
		self::assertStringContainsString( '> MAX_VISIBLE', $fold, 'Folding starts above three notices.' );
		// The drawer opens by itself when it holds an error or a warning, so those are never hidden behind a click.
		self::assertMatchesRegularExpression( '/counts\.error > 0 \|\| counts\.warning > 0/', $fold );
		self::assertStringContainsString( 'drawer.open = true', $fold );
		// The title counts what is inside by kind, from words the server supplies.
		self::assertStringContainsString( 'data-sw-notice-labels', $fold );
		self::assertStringContainsString( 'severityOf', $fold );
	}

	public function test_the_plugins_own_notices_are_pinned_before_wordpress_moves_notices(): void {
		$script = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/admin/shell.js' );
		$pin    = self::shell_function( 'pinOwnNotices' );

		self::assertStringContainsString( '#sw-main', $pin );
		self::assertStringContainsString( "classList.add('inline')", $pin );
		// WordPress moves every notice that is not `inline` when the page is ready, so the pin runs while the script loads.
		$call  = strpos( $script, 'pinOwnNotices(printedShell)' );
		$ready = strpos( $script, "
	ready(function () {" );
		self::assertNotFalse( $call );
		self::assertNotFalse( $ready );
		self::assertLessThan( (int) $ready, (int) $call, 'The pin is not inside the ready callback.' );
	}

	public function test_the_shell_script_builds_text_with_text_content_only(): void {
		$script = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/admin/shell.js' );

		self::assertStringNotContainsString( 'innerHTML', $script );
		self::assertStringNotContainsString( 'insertAdjacentHTML', $script );
		self::assertStringNotContainsString( 'sw-notice-drawer__count', $script, 'The count lives in the title now.' );
	}

	public function test_the_shell_offset_counts_only_chrome_that_stays_fixed(): void {
		$body = self::shell_function( 'updateShellOffset' );

		// The page header scrolls with the page, so only the admin bar is fixed above the content.
		self::assertStringContainsString( 'wpadminbar', $body );
		self::assertStringNotContainsString( 'sw-shell__header', $body );
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

	public function test_companion_status_renders_typed_release_and_runtime_fields(): void {
		$script = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/admin/admin.js' );
		$start  = strpos( $script, 'function initCompanionUpdateStatus()' );
		$end    = strpos( $script, 'function escapeRegExp', false === $start ? 0 : $start );

		self::assertNotFalse( $start );
		self::assertNotFalse( $end );
		$body = substr( $script, (int) $start, (int) $end - (int) $start );

		self::assertStringContainsString( 'latestRelease.status', $body );
		self::assertStringContainsString( 'latestRelease.error.action', $body );
		self::assertStringContainsString( 'configuredCompanion.version', $body );
		self::assertStringContainsString( 'configuredCompanion.reason', $body );
		self::assertStringContainsString( 'latestRelease.error.message', $body );
		self::assertStringContainsString( "latestRelease.status !== 'available'", $body );
		self::assertStringContainsString( 'prompt.hidden = ! data.update_prompt', $body );
		self::assertStringContainsString( 'runningCompanion.version', $body );
		self::assertStringContainsString( 'data.bridge.state', $body );
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
		$admin = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/admin/admin.js' );
		self::assertStringNotContainsString( 'initRunDiagnostics', $admin, 'The checks belong to the Troubleshoot page script, not the shared one.' );

		$script = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/admin/pages/troubleshoot.js' );

		self::assertStringContainsString( "body.set( 'action', 'stonewright_run_diagnostics' )", $script );
		self::assertStringContainsString( "body.set( 'nonce', config.nonce || '' )", $script );
		self::assertStringContainsString( "body.set( 'mode', mode && mode.value ? mode.value : 'not-sure' )", $script );
		self::assertStringContainsString( "setAttribute( 'aria-busy', 'true' )", $script );
		self::assertStringContainsString( "'aria-busy', on ? 'true' : 'false'", $script );
		self::assertStringContainsString( 'copyHosting', $script );

		$start = strpos( $script, 'function run( event )' );
		self::assertNotFalse( $start );
		$end = strpos( $script, "form.addEventListener( 'submit', run )", $start );
		self::assertNotFalse( $end );
		$body = substr( $script, (int) $start, (int) $end - (int) $start );

		self::assertStringContainsString( 'event.preventDefault()', $body );
		self::assertStringContainsString( 'busy( true )', $body );
		self::assertStringContainsString( 'busy( false )', $body );
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

	public function test_context_toggle_badge_syncs_on_checkbox_change(): void {
		$script = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/admin/admin.js' );

		self::assertStringContainsString( 'initContextToggleBadge', $script );
		self::assertStringContainsString( 'initContextToggleBadge();', $script );
		self::assertStringContainsString( 'data-sw-context-state', $script );
		self::assertStringContainsString( 'stonewright_user_context_enabled', $script );

		$start = strpos( $script, 'function initContextToggleBadge()' );
		$end   = strpos( $script, 'document.addEventListener( \'DOMContentLoaded\'', false === $start ? 0 : $start );
		self::assertNotFalse( $start );
		self::assertNotFalse( $end );
		$body = substr( $script, (int) $start, (int) $end - (int) $start );

		self::assertStringContainsString( "'change'", $body );
		self::assertStringContainsString( 'checkbox.checked', $body );
		self::assertStringContainsString( 'data-context-on', $body );
		self::assertStringContainsString( 'data-context-off', $body );
	}

	public function test_skills_catalog_preserves_server_render_until_rest_load(): void {
		$page = (string) file_get_contents( dirname( __DIR__, 3 ) . '/includes/Admin/SkillsPage.php' );
		$js   = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/admin/skills.js' );

		self::assertStringContainsString( 'render_catalog_panel', $page );
		self::assertStringContainsString( 'data-sw-skills-ssr', $page );
		self::assertStringContainsString( 'data-sw-skills-list', $page );

		self::assertStringContainsString( 'data-sw-skills-ssr', $js );
		self::assertStringContainsString( 'hasServerCatalog', $js );

		$start = strpos( $js, 'function renderCatalog( panel )' );
		$end   = strpos( $js, 'function renderTrash( panel )', false === $start ? 0 : $start );
		self::assertNotFalse( $start );
		self::assertNotFalse( $end );
		$body = substr( $js, (int) $start, (int) $end - (int) $start );

		self::assertStringContainsString( 'hasServerCatalog( panel )', $body );
		self::assertStringContainsString( "pending( panel, 'Loading the catalog…' )", $body );
		self::assertMatchesRegularExpression(
			'/if\s*\(\s*!\s*state\.loaded\s*\)[\s\S]*hasServerCatalog\( panel \)/',
			$body,
			'Catalog should keep server-rendered markup until REST hydration completes.'
		);
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
