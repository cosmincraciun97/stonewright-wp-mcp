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

	public function test_primary_button_has_its_own_hover_rule(): void {
		$css = self::asset( 'visual-workspace.css' );
		self::assertStringContainsString( '.sw-button--primary:hover:not([disabled])', $css );
		self::assertStringContainsString( '--sw-brand-fill-hover', $css );
	}

	public function test_generic_hover_does_not_apply_to_primary_buttons(): void {
		$css = self::asset( 'visual-workspace.css' );
		self::assertStringContainsString( '.sw-button:not(.sw-button--primary):hover', $css );
	}
}
