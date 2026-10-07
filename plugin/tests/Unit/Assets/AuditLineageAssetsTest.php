<?php
/**
 * The assets of the Audit Log lineage drawer: tokens only, no inline styles, a full-screen sheet on small screens.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Assets;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class AuditLineageAssetsTest extends TestCase {

	private static function asset( string $file ): string {
		$path = dirname( __DIR__, 3 ) . '/assets/admin/' . $file;
		self::assertFileExists( $path, $file . ' must exist' );

		return (string) file_get_contents( $path );
	}

	/**
	 * The declarations of the last rule outside the @media blocks whose selector text ends
	 * with $selector. A selector that also appears in a group or in a media block is read
	 * from its own rule.
	 */
	private static function rule( string $css, string $selector ): string {
		$css   = (string) preg_replace( '/@media[^{]+\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}/', '', $css );
		$start = strrpos( $css, $selector . ' {' );
		self::assertIsInt( $start, 'The stylesheet must declare a rule for ' . $selector );
		$open  = (int) strpos( $css, '{', $start );
		$close = strpos( $css, '}', $open );
		self::assertIsInt( $close );

		return substr( $css, $open + 1, $close - $open - 1 );
	}

	/** The CSS inside the @media block with this query. */
	private static function media( string $css, string $query ): string {
		$start = strpos( $css, '@media ' . $query . ' {' );
		self::assertIsInt( $start, 'The stylesheet must declare a media block for ' . $query );
		$open  = (int) strpos( $css, '{', $start );
		$depth = 1;
		$end   = $open + 1;
		for ( $length = strlen( $css ); $end < $length && $depth > 0; ++$end ) {
			if ( '{' === $css[ $end ] ) {
				++$depth;
			} elseif ( '}' === $css[ $end ] ) {
				--$depth;
			}
		}

		return substr( $css, $open + 1, $end - $open - 2 );
	}

	public function test_the_lineage_styles_live_in_their_own_page_stylesheet(): void {
		$page  = self::asset( 'pages/audit-lineage.css' );
		$audit = self::asset( 'audit.css' );

		self::assertStringContainsString( '.sw-audit-lineage-drawer', $page );
		self::assertStringContainsString( '.sw-audit-lineage-tree', $page );
		self::assertStringContainsString( '.sw-audit-lineage-chip', $page );
		self::assertStringNotContainsString( 'sw-lineage', $audit, 'audit.css does not carry the lineage rules.' );
		self::assertLessThanOrEqual( 7000, strlen( $page ), 'Page CSS stays small.' );
	}

	public function test_the_lineage_stylesheet_uses_tokens_and_never_important(): void {
		$css = self::asset( 'pages/audit-lineage.css' );

		self::assertStringNotContainsString( '!important', $css );
		self::assertDoesNotMatchRegularExpression( '/#[0-9a-fA-F]{3,8}\b/', $css, 'Raw colors belong in shell.css.' );
		self::assertDoesNotMatchRegularExpression( '/\b(?:rgba?|hsla?|hwb|lab|lch)\(/', $css );
		self::assertDoesNotMatchRegularExpression( '/font-size:\s*+(?!var\(--sw-text-)/', $css, 'Font sizes come from the type scale.' );
		self::assertDoesNotMatchRegularExpression( '/border-radius:[^;]*\d+px/', $css, 'Radii come from the radius scale.' );
		self::assertDoesNotMatchRegularExpression( '/\bstyle=/', $css );
		self::assertStringContainsString( 'var(--sw-', $css );
	}

	public function test_the_drawer_is_a_fixed_sheet_that_fills_the_screen_at_782_pixels_and_below(): void {
		$css    = self::asset( 'pages/audit-lineage.css' );
		$drawer = self::rule( $css, '.sw-audit-lineage-drawer' );

		self::assertStringContainsString( 'position: fixed', $drawer );
		self::assertStringContainsString( 'max-width: 100%', $drawer );
		self::assertStringContainsString( 'inset-block: 0', $drawer );
		self::assertStringContainsString( 'inset-inline: auto 0', $drawer, 'On a wide screen the drawer sits on the inline end, in either text direction.' );

		$small = self::media( $css, 'screen and (max-width: 782px)' );
		self::assertStringContainsString( '.sw-audit-lineage-drawer {', $small );
		self::assertStringContainsString( 'inset: 0;', $small );
		self::assertStringContainsString( 'width: 100%;', $small );
		self::assertStringContainsString( 'min-height: 40px', $small, 'Touch targets reach the 40 px tier.' );
	}

	public function test_the_tree_indents_three_levels_then_stops_and_never_scrolls_sideways(): void {
		$css = self::asset( 'pages/audit-lineage.css' );

		self::assertStringContainsString( 'padding-inline-start: var(--sw-space-4)', self::rule( $css, '.sw-audit-lineage-tree ol' ) );
		self::assertStringContainsString( 'padding-inline-start: 0', self::rule( $css, '.sw-audit-lineage-tree ol ol ol ol' ), 'From the fourth nested list the indent stops.' );
		self::assertDoesNotMatchRegularExpression( '/overflow-x:\s*(?:auto|scroll)/', $css );
		self::assertDoesNotMatchRegularExpression( '/white-space:\s*nowrap/', $css, 'Identifiers must wrap at 400 px.' );
		self::assertDoesNotMatchRegularExpression( '/min-width:\s*(?!0\b)\d{3,}px/', $css, 'No fixed minimum width that forces a scrollbar.' );
		self::assertStringContainsString( 'overflow-wrap: anywhere', $css );
	}

	public function test_hidden_panels_and_lists_stay_hidden_even_though_their_rules_set_display(): void {
		$css = self::asset( 'pages/audit-lineage.css' );

		foreach ( [ '.sw-audit-lineage-panel[hidden]', '.sw-audit-lineage-tree[hidden]', '.sw-audit-lineage-cell [hidden]' ] as $selector ) {
			self::assertMatchesRegularExpression(
				'/' . preg_quote( $selector, '/' ) . '[^{}]*\{[^}]*display:\s*none/',
				$css,
				$selector . ' must stay hidden when its attribute says so.'
			);
		}
	}

	public function test_the_lineage_script_opens_a_modal_dialog_and_returns_focus_when_it_closes(): void {
		$js = self::asset( 'pages/audit-lineage.js' );

		self::assertStringContainsString( "'use strict'", $js );
		self::assertStringContainsString( 'showModal', $js, 'The native modal dialog traps focus and closes on Escape.' );
		self::assertStringContainsString( "addEventListener( 'close'", $js, 'Escape, the Close button and a click outside all end in the close event.' );
		self::assertMatchesRegularExpression( "/addEventListener\( 'close'.*?opener\.focus\(\)/s", $js, 'Focus goes back to the control that opened the drawer.' );
		foreach ( [ 'data-sw-lineage-drawer', 'data-sw-lineage-open', 'data-sw-lineage-close', 'data-sw-lineage-more', 'data-sw-lineage-panel', 'data-sw-lineage-node' ] as $hook ) {
			self::assertStringContainsString( $hook, $js );
		}
		self::assertStringContainsString( "setAttribute( 'aria-current', 'true' )", $js );
	}

	public function test_the_lineage_script_has_no_inline_style_or_html_injection_surface(): void {
		$js = self::asset( 'pages/audit-lineage.js' );

		self::assertDoesNotMatchRegularExpression( '/\.style\b|cssText|setAttribute\(\s*[\'"]style/', $js, 'Styles live in the stylesheet.' );
		self::assertDoesNotMatchRegularExpression( '/innerHTML|outerHTML|insertAdjacentHTML|document\.write|eval\(|new Function/', $js );
		self::assertDoesNotMatchRegularExpression( '/alert\(|confirm\(|prompt\(/', $js );
		self::assertStringNotContainsString( 'fetch(', $js, 'The panels are server-rendered; the script makes no request.' );
	}
}
