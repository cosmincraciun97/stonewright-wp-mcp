<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Assets;

use PHPUnit\Framework\TestCase;

/**
 * Asset-level contracts that cannot be expressed as PHP unit tests on classes:
 * exactly one tooltip engine, a visible primary hover, and no dark mode.
 */
final class AdminAssetContractTest extends TestCase {

	private static function asset( string $file ): string {
		$path = dirname( __DIR__, 3 ) . '/assets/admin/' . $file;
		self::assertFileExists( $path, $file . ' must exist' );

		return (string) file_get_contents( $path );
	}

	public function test_only_shell_js_owns_the_tooltip_engine(): void {
		self::assertStringContainsString( 'function initTooltips()', self::asset( 'shell.js' ) );
		self::assertStringNotContainsString( 'function initTooltips()', self::asset( 'design-studio.js' ) );
		self::assertStringNotContainsString( 'function initTooltips()', self::asset( 'visual-workspace.js' ) );
	}

	public function test_no_page_stylesheet_redeclares_the_tooltip_surface(): void {
		self::assertStringNotContainsString( '.sw-ds-tooltip', self::asset( 'design-studio.css' ) );
		self::assertStringNotContainsString( '.sw-tooltip', self::asset( 'visual-workspace.css' ) );
		self::assertStringContainsString( '.sw-tooltip {', self::asset( 'shell.css' ) );
	}

	public function test_no_page_script_creates_a_tooltip_node(): void {
		self::assertStringNotContainsString( 'sw-ds-tooltip', self::asset( 'design-studio.js' ) );
		self::assertStringNotContainsString( 'sw-visual-tooltip', self::asset( 'visual-workspace.js' ) );
	}

	/**
	 * The declarations of one CSS rule, selected by its exact selector text.
	 *
	 * Asserting that a token appears somewhere in the file would pass even if the
	 * token were declared in an unrelated rule, which is the bug this suite exists
	 * to catch: the hover state has to change the button that is being hovered.
	 */
	private static function rule_body( string $file, string $selector ): string {
		$css   = self::asset( $file );
		$start = strpos( $css, $selector . ' {' );
		self::assertIsInt( $start, $file . ' must declare a rule for ' . $selector );

		$open  = (int) strpos( $css, '{', $start );
		$close = strpos( $css, '}', $open );
		self::assertIsInt( $close, $selector . ' must be a closed rule block' );

		return substr( $css, $open + 1, $close - $open - 1 );
	}

	public function test_primary_button_hover_repaints_the_primary_button(): void {
		$body = self::rule_body( 'visual-workspace.css', '.sw-button--primary:hover:not([disabled])' );

		// The unreadable-hover bug was a hover that changed the background without
		// keeping the label legible against it, so both are part of the contract.
		self::assertStringContainsString( 'background: var(--sw-brand-fill-hover)', $body );
		self::assertStringContainsString( 'color: var(--sw-on-brand)', $body );
	}

	public function test_generic_hover_excludes_primary_and_disabled_buttons(): void {
		$css = self::asset( 'visual-workspace.css' );

		// Both hover rules must opt disabled buttons out explicitly. A disabled
		// button that still lights up on hover advertises an action that no click
		// will perform.
		self::assertStringContainsString( '.sw-button:not(.sw-button--primary):hover:not([disabled]) {', $css );
		self::assertStringContainsString( '.sw-button--primary:hover:not([disabled]) {', $css );
		self::assertStringNotContainsString( '.sw-button:hover {', $css );
	}

	public function test_sandbox_primary_actions_keep_white_text_on_brand_background(): void {
		$body = self::rule_body( 'sandbox.css', '.stonewright-sandbox-page .button.button-primary' );

		self::assertStringContainsString( 'color: var(--sw-on-brand)', $body );
	}

	public function test_audit_payload_becomes_full_width_in_responsive_rows(): void {
		$css = self::asset( 'audit.css' );

		self::assertStringContainsString( '.sw-audit-table-scroll {', $css );
		self::assertStringContainsString( 'grid-column: 1 / -1', $css );
		self::assertStringContainsString( 'content: attr(data-label)', $css );
		self::assertStringContainsString( 'overflow-wrap: anywhere', $css );
	}
}
