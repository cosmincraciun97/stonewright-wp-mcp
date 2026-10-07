<?php
/**
 * The assets of the Audit Log: the drawer and the lineage list come from the shared layer, the page file only places
 * things, and the scripts only toggle attributes.
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

	/** The CSS inside the first @media block with this query. */
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

	public function test_the_drawer_and_the_lineage_list_are_layer_components_not_page_styles(): void {
		$layer = self::asset( 'sw-ui.css' );

		self::assertFileDoesNotExist( dirname( __DIR__, 3 ) . '/assets/admin/pages/audit-lineage.css', 'No second drawer style.' );
		self::assertFileDoesNotExist( dirname( __DIR__, 3 ) . '/assets/admin/audit.css' );
		foreach ( [ '.sw-ui-lineage__item', '.sw-ui-lineage__meta', '.sw-ui-lineage__item[aria-current="true"]', '.sw-ui-drawer[open]', '.sw-ui-dialog__header--bar', '.sw-ui-chip-filter[aria-current="true"]' ] as $selector ) {
			self::assertStringContainsString( $selector, $layer, $selector . ' belongs to the layer.' );
		}
		self::assertStringContainsString( '/* 16a. Drawer layout, filter links and lineage detail (Activity pages)', $layer, 'The block is named.' );
	}

	public function test_a_drawer_keeps_its_header_and_scrolls_its_body(): void {
		$layer = self::asset( 'sw-ui.css' );

		self::assertMatchesRegularExpression( '/:where\(\.sw-ui\) \.sw-ui-drawer\[open\] \{[^}]*display: flex;[^}]*flex-direction: column;/', $layer );
		self::assertMatchesRegularExpression( '/:where\(\.sw-ui \.sw-ui-drawer\) \.sw-ui-dialog__body \{[^}]*min-height: 0;[^}]*overflow-y: auto;/', $layer );
	}

	public function test_the_lineage_list_indents_three_levels_then_stops_and_never_scrolls_sideways(): void {
		$layer = self::asset( 'sw-ui.css' );

		self::assertMatchesRegularExpression( '/\.sw-ui \.sw-ui-lineage :where\(ol\) \{[^}]*padding-inline-start: var\(--sw-space-3\);/', $layer );
		self::assertMatchesRegularExpression( '/\.sw-ui \.sw-ui-lineage :where\(ol ol ol ol\) \{[^}]*padding-inline-start: 0;[^}]*border-inline-start: 0;/', $layer, 'From the fourth nested list the indent stops.' );
		self::assertMatchesRegularExpression( '/\.sw-ui \.sw-ui-lineage__item \{[^}]*overflow-wrap: anywhere;/', $layer, 'Identifiers wrap.' );
		$start   = (int) strpos( $layer, '/* 16. Lineage' );
		$lineage = substr( $layer, $start, (int) strpos( $layer, '/* 17. Narrow screens' ) - $start );
		self::assertDoesNotMatchRegularExpression( '/overflow-x:\s*(?:auto|scroll)/', $lineage );
		self::assertDoesNotMatchRegularExpression( '/white-space:\s*nowrap/', $lineage );
	}

	public function test_a_drawer_fills_the_screen_at_782_pixels_and_below(): void {
		$layer = self::asset( 'sw-ui.css' );
		$small = self::media( $layer, '(max-width: 782px)' );

		self::assertMatchesRegularExpression( '/\.sw-ui \.sw-ui-drawer \{[^}]*width: 100%;/', $small );
		self::assertMatchesRegularExpression( '/\.sw-ui \.sw-ui-btn--sm \{[^}]*min-height: 40px;/', $small, 'Touch targets reach the 40px tier.' );
	}

	public function test_the_page_stylesheet_places_things_with_tokens_only_and_stays_under_three_kilobytes(): void {
		$css = self::asset( 'pages/audit.css' );

		self::assertLessThanOrEqual( 3072, strlen( $css ), 'Page CSS stays under 3 KB.' );
		self::assertStringNotContainsString( '!important', $css );
		self::assertDoesNotMatchRegularExpression( '/#[0-9a-fA-F]{3,8}\b/', $css, 'Raw colours belong in the layer.' );
		self::assertDoesNotMatchRegularExpression( '/\b(?:rgba?|hsla?|hwb|lab|lch)\(/', $css );
		self::assertDoesNotMatchRegularExpression( '/font-size:\s*+(?!var\(--sw-fs-)/', $css, 'Font sizes come from the type scale.' );
		self::assertDoesNotMatchRegularExpression( '/border-radius|box-shadow|transition|animation/', $css, 'The page file defines no component look.' );
		self::assertDoesNotMatchRegularExpression( '/overflow-x:\s*(?:auto|scroll)/', $css );
		self::assertStringContainsString( 'var(--sw-', $css );
	}

	public function test_the_lineage_script_relies_on_the_layer_for_opening_closing_and_focus(): void {
		$js = self::asset( 'pages/audit-lineage.js' );

		self::assertStringContainsString( "'use strict'", $js );
		self::assertStringNotContainsString( 'showModal()', $js, 'The layer opens the dialog, traps focus and gives focus back.' );
		self::assertStringNotContainsString( 'opener.focus', $js );
		foreach ( [ 'data-sw-lineage-drawer', 'data-sw-lineage-open', 'data-sw-lineage-more', 'data-sw-lineage-panel', 'data-sw-lineage-node' ] as $hook ) {
			self::assertStringContainsString( $hook, $js );
		}
		self::assertStringContainsString( "setAttribute( 'aria-current', 'true' )", $js );
		self::assertStringContainsString( 'typeof drawer.showModal', $js, 'The buttons stay hidden where the native dialog is missing.' );
	}

	/** @return array<string, array{0: string}> */
	public static function scripts(): array {
		return [
			'lineage' => [ 'pages/audit-lineage.js' ],
			'audit'   => [ 'pages/audit.js' ],
		];
	}

	/** @dataProvider scripts */
	public function test_the_scripts_have_no_inline_style_or_html_injection_surface( string $file ): void {
		$js = self::asset( $file );

		self::assertDoesNotMatchRegularExpression( '/\.style\b|cssText|setAttribute\(\s*[\'"]style/', $js, 'Styles live in the stylesheet.' );
		self::assertDoesNotMatchRegularExpression( '/innerHTML|outerHTML|insertAdjacentHTML|document\.write|eval\(|new Function/', $js );
		self::assertDoesNotMatchRegularExpression( '/alert\(|confirm\(|prompt\(/', $js );
		self::assertStringNotContainsString( 'fetch(', $js, 'The panels are server-rendered; the scripts make no request.' );
	}
}
