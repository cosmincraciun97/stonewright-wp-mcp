<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/**
 * Static guarantees for the Design Studio front-end assets.
 *
 * `AdminBootstrap::enqueue_assets()` calls `wp_localize_script()`, which the
 * unit harness does not stub, so the enqueue path cannot be executed here.
 * These checks read the shipped files instead, the same way
 * `AdminCssNoRawHexTest` and `AdminJavascriptTest` do.
 *
 * @coversNothing
 */
final class DesignStudioAssetsTest extends TestCase {

	private static function plugin_dir(): string {
		return dirname( __DIR__, 3 );
	}

	private static function css(): string {
		return (string) file_get_contents( self::plugin_dir() . '/assets/admin/design-studio.css' );
	}

	private static function js(): string {
		return (string) file_get_contents( self::plugin_dir() . '/assets/admin/design-studio.js' );
	}

	private static function bootstrap(): string {
		return (string) file_get_contents( self::plugin_dir() . '/includes/Admin/AdminBootstrap.php' );
	}

	public function test_both_assets_ship_with_the_plugin(): void {
		self::assertFileExists( self::plugin_dir() . '/assets/admin/design-studio.css' );
		self::assertFileExists( self::plugin_dir() . '/assets/admin/design-studio.js' );
	}

	public function test_the_stylesheet_only_uses_shared_semantic_colour_tokens(): void {
		$css = preg_replace( '#/\*.*?\*/#s', '', self::css() );
		$css = is_string( $css ) ? $css : '';

		self::assertDoesNotMatchRegularExpression( '/#[0-9a-fA-F]{3,8}\b/', $css, 'Design Studio CSS must not hard-code colours.' );
		self::assertDoesNotMatchRegularExpression( '/\brgba?\s*\(\s*\d/', $css, 'Design Studio CSS must not hard-code colour channels.' );
		self::assertStringContainsString( 'var(--sw-surface', $css );
		self::assertStringContainsString( 'var(--sw-border', $css );
		self::assertStringContainsString( 'var(--sw-text', $css );
	}

	public function test_the_stylesheet_keeps_focus_visible_and_honours_reduced_motion(): void {
		$css = self::css();

		self::assertStringContainsString( ':focus-visible', $css );
		self::assertStringContainsString( 'outline:', $css );
		self::assertStringContainsString( '@media (prefers-reduced-motion: reduce)', $css );
	}

	public function test_the_stylesheet_is_single_column_before_it_is_two_column(): void {
		$css = self::css();

		self::assertStringContainsString( '@media (min-width: 960px)', $css, 'The editor becomes two-column at a min-width breakpoint, so the 375px layout is the default.' );
		self::assertDoesNotMatchRegularExpression( '/\bmin-width:\s*(?:[4-9]\d{2}|\d{4,})px\s*;/', $css, 'No element may claim a minimum width that overflows a 375px viewport.' );
	}

	public function test_the_stylesheet_carries_no_decorative_glyphs(): void {
		self::assertDoesNotMatchRegularExpression(
			'/[\x{1F300}-\x{1FAFF}\x{2190}-\x{27BF}\x{FE0F}]/u',
			self::css(),
			'Design Studio CSS must not inject emoji or dingbat content.'
		);
	}

	public function test_the_script_is_a_strict_mode_module_with_no_dependencies(): void {
		$js = self::js();

		self::assertStringContainsString( "'use strict'", $js );
		self::assertStringNotContainsString( 'jQuery', $js );
		self::assertStringNotContainsString( '$(', $js );
	}

	public function test_the_script_boots_from_the_localized_payload(): void {
		$js = self::js();

		self::assertStringContainsString( 'window.stonewrightDesignStudio', $js );
		self::assertStringContainsString( 'restRoot', $js );
		self::assertStringContainsString( 'activeDirection', $js );
		self::assertStringContainsString( 'data-sw-design-studio', $js );
	}

	public function test_every_write_request_sends_the_rest_nonce(): void {
		$js = self::js();

		self::assertStringContainsString( "'X-WP-Nonce'", $js );
		self::assertStringContainsString( "credentials: 'same-origin'", $js );
		self::assertStringContainsString( "method: 'POST'", $js );
	}

	public function test_the_script_never_uses_native_browser_dialogs(): void {
		$js = self::js();

		self::assertStringNotContainsString( 'window.confirm', $js );
		self::assertStringNotContainsString( 'window.alert', $js );
		self::assertDoesNotMatchRegularExpression( '/(?<![\w.])confirm\s*\(/', $js );
		self::assertDoesNotMatchRegularExpression( '/(?<![\w.])alert\s*\(/', $js );
		self::assertDoesNotMatchRegularExpression( '/(?<![\w.])prompt\s*\(/', $js );
	}

	public function test_onboarding_uses_the_shared_tooltip_engine_and_ships_readiness_controls(): void {
		$js    = self::js();
		$css   = self::css();
		$shell = (string) file_get_contents( self::plugin_dir() . '/assets/admin/shell.js' );

		self::assertStringContainsString( 'data-sw-tooltip', $js );
		self::assertStringNotContainsString( 'function initTooltips()', $js );
		self::assertStringNotContainsString( 'sw-ds-tooltip', $js );
		self::assertStringContainsString( 'Ready for use', $js );
		self::assertStringContainsString( 'Ready to sync globals', $js );
		self::assertStringContainsString( 'readinessIssues', $js );
		self::assertStringContainsString( '.sw-ds-toggle', $css );
		self::assertStringNotContainsString( '.sw-ds-tooltip', $css );
		self::assertStringContainsString( 'function initTooltips()', $shell );
	}

	public function test_confirmation_happens_in_an_accessible_review_drawer(): void {
		$js = self::js();

		self::assertStringContainsString( 'data-sw-ds-drawer', $js );
		self::assertStringContainsString( "'dialog'", $js );
		self::assertStringContainsString( 'aria-modal', $js );
		self::assertStringContainsString( "'Escape'", $js );
		self::assertStringContainsString( 'trapFocus', $js );
		self::assertStringContainsString( 'restoreFocus', $js );
	}

	public function test_the_script_builds_dom_nodes_instead_of_writing_markup(): void {
		$js = self::js();

		self::assertStringNotContainsString( 'innerHTML', $js, 'Direction names, evidence, and repair hints are user content — build nodes and set textContent.' );
		self::assertStringNotContainsString( 'outerHTML', $js );
		self::assertStringNotContainsString( 'insertAdjacentHTML', $js );
		self::assertStringNotContainsString( 'document.' . 'write', $js );
		self::assertStringContainsString( 'textContent', $js );
	}

	public function test_view_switching_keeps_the_url_and_the_keyboard_in_sync(): void {
		$js = self::js();

		self::assertStringContainsString( 'history.pushState', $js );
		self::assertStringContainsString( "'popstate'", $js );
		self::assertStringContainsString( "'ArrowRight'", $js );
		self::assertStringContainsString( "'ArrowLeft'", $js );
		self::assertStringContainsString( "'Home'", $js );
		self::assertStringContainsString( "'End'", $js );
		self::assertStringContainsString( 'aria-selected', $js );
	}

	public function test_the_editor_recovers_drafts_and_guards_unsaved_changes(): void {
		$js = self::js();

		self::assertStringContainsString( 'sessionStorage', $js );
		self::assertStringContainsString( "'beforeunload'", $js );
		self::assertStringContainsString( 'isDirty', $js );
	}

	public function test_the_script_announces_state_changes_through_the_live_region(): void {
		$js = self::js();

		self::assertStringContainsString( 'data-sw-ds-status', $js );
		self::assertStringContainsString( 'function announce', $js );
	}

	public function test_the_script_emits_the_documented_dom_events(): void {
		$js = self::js();

		foreach ( [ 'stonewright:direction-saved', 'stonewright:direction-activated', 'stonewright:quality-selected' ] as $event ) {
			self::assertStringContainsString( $event, $js );
		}
		self::assertStringContainsString( 'CustomEvent', $js );
	}

	public function test_the_script_reaches_wordpress_only_through_the_design_studio_routes(): void {
		$js = self::js();

		self::assertStringNotContainsString( 'admin-ajax.php', $js );
		self::assertStringNotContainsString( '/wp/v2/', $js );
		self::assertStringNotContainsString( 'abilities/run', $js );
		self::assertStringContainsString( "'/directions'", $js );
		self::assertStringContainsString( "'/quality'", $js );
	}

	public function test_direction_reads_explicitly_request_revision_history(): void {
		self::assertMatchesRegularExpression(
			'/get\(\s*DIRECTIONS_ROUTE\s*\+\s*\'\/\'\s*\+\s*encodeURIComponent\( String\( id \) \),\s*\{\s*include_versions:\s*true\s*\}\s*\)/s',
			self::js(),
			'History must opt in to versions because design-direction-get omits them by default.'
		);
	}

	public function test_the_assets_are_page_scoped_to_the_design_studio(): void {
		$bootstrap = self::bootstrap();

		self::assertStringContainsString( "'stonewright-design-studio' => 'design-studio.css'", $bootstrap );
		self::assertStringContainsString( 'stonewright-admin-design-studio', $bootstrap );
		self::assertStringContainsString( 'assets/admin/design-studio.js', $bootstrap );
		self::assertStringContainsString( "'stonewrightDesignStudio'", $bootstrap );
		self::assertStringContainsString( 'DesignStudioPage::boot_payload()', $bootstrap );
	}

	public function test_the_script_is_only_localized_on_the_design_studio_page(): void {
		$bootstrap = self::bootstrap();

		self::assertMatchesRegularExpression(
			'/if \( DesignStudioPage::SLUG === \$page \) \{.*?wp_enqueue_script\(.*?design-studio\.js.*?wp_localize_script\(.*?stonewrightDesignStudio.*?\}/s',
			$bootstrap,
			'The Design Studio script and its payload load only on the Design Studio page.'
		);
	}
}
