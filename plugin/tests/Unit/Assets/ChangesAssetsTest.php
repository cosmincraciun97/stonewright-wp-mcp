<?php
/**
 * The assets of the Changes page: the diff component comes from the shared layer, the page file only places things
 * and the script only opens the drawer the server rendered.
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
final class ChangesAssetsTest extends TestCase {

	private static function asset( string $file ): string {
		$path = dirname( __DIR__, 3 ) . '/assets/admin/' . $file;
		self::assertFileExists( $path, $file . ' must exist' );

		return (string) file_get_contents( $path );
	}

	public function test_the_page_stylesheet_only_places_things_and_is_listed_with_the_other_migrated_pages(): void {
		$css = self::asset( 'pages/changes.css' );

		self::assertStringStartsWith( '/* SPDX-License-Identifier: GPL-2.0-or-later */', $css );
		self::assertStringContainsString( 'placement only', strtolower( $css ) );
		self::assertSame( 1, preg_match( "/'admin\/pages\/changes\.css'\s*=>\s*'sw-changes'/", (string) file_get_contents( __DIR__ . '/MigratedPageCssTest.php' ) ), 'MigratedPageCssTest holds the page to the placement-only rules.' );
		self::assertStringNotContainsString( 'sw-ui-diff', $css, 'The diff component is styled by the layer, not by the page.' );
	}

	public function test_without_script_the_server_rendered_drawer_is_a_fixed_panel_at_the_inline_end(): void {
		$css = self::asset( 'pages/changes.css' );

		self::assertMatchesRegularExpression( '/\.sw-changes-page \.sw-ui-drawer:not\(:modal\) \{[^}]*position: fixed;[^}]*inset-block: 0;[^}]*inset-inline-end: 0;[^}]*z-index: var\(--sw-z-modal\);/', $css );
	}

	public function test_the_filter_row_and_the_table_never_scroll_the_page_sideways(): void {
		$css = self::asset( 'pages/changes.css' );

		self::assertDoesNotMatchRegularExpression( '/overflow-x:\s*(?:auto|scroll)/', $css );
		self::assertDoesNotMatchRegularExpression( '/white-space:\s*nowrap/', $css );
		self::assertMatchesRegularExpression( '/\.sw-changes-filters__row \{[^}]*flex-wrap: wrap;/', $css );
		self::assertMatchesRegularExpression( '/\.sw-changes-page \{[^}]*min-width: 0;/', $css, 'The page may shrink inside the shell.' );
	}

	public function test_the_script_opens_the_drawer_through_the_layer_and_makes_no_request(): void {
		$js = self::asset( 'pages/changes.js' );

		self::assertStringContainsString( "'use strict'", $js );
		self::assertStringContainsString( 'typeof drawer.showModal', $js, 'Without the native dialog the server-rendered panel stays as it is.' );
		self::assertStringContainsString( 'data-sw-changes-drawer', $js );
		self::assertStringContainsString( 'data-sw-changes-open', $js );
		self::assertStringContainsString( 'openDialog', $js, 'The layer opens the dialog, traps focus and gives it back to the opener.' );
		self::assertStringContainsString( "removeAttribute( 'open' )", $js, 'The server prints the dialog open; showModal() needs it closed.' );
		self::assertStringContainsString( "[ 'change', 'view', 'undo', 'undone', 'from' ]", $js );
		self::assertStringContainsString( 'searchParams.delete( name )', $js );
		self::assertStringContainsString( 'replaceState', $js );
		self::assertStringNotContainsString( 'opener.focus', $js, 'The layer gives focus back.' );
	}

	public function test_the_script_has_no_inline_style_html_injection_or_network_surface(): void {
		$js = self::asset( 'pages/changes.js' );

		self::assertDoesNotMatchRegularExpression( '/\.style\b|cssText|setAttribute\(\s*[\'"]style/', $js );
		self::assertDoesNotMatchRegularExpression( '/innerHTML|outerHTML|insertAdjacentHTML|document\.write|eval\(|new Function/', $js );
		self::assertDoesNotMatchRegularExpression( '/alert\(|confirm\(|prompt\(/', $js );
		self::assertDoesNotMatchRegularExpression( '/fetch\(|XMLHttpRequest|sendBeacon|WebSocket|admin-ajax/', $js, 'The diff is server-rendered for the one change asked for; the script requests nothing.' );
	}

	public function test_the_diff_component_is_in_the_layer(): void {
		$layer = self::asset( 'sw-ui.css' );

		foreach ( [ '.sw-ui-diff', '.sw-ui-diff__body', '.sw-ui-diff__line--add', '.sw-ui-diff__line--del' ] as $selector ) {
			self::assertStringContainsString( $selector, $layer, $selector );
		}
		self::assertFileDoesNotExist( dirname( __DIR__, 3 ) . '/assets/admin/pages/diff.css', 'No second diff style.' );
	}
}
