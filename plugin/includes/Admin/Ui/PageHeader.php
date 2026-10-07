<?php
/**
 * The page header of the admin UI layer.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Ui;

/**
 * Title, an optional one-line explanation, and a place on the right for status badges and the primary action.
 * One row, about 64px tall: the page content starts at the top of the screen, not 400px down.
 */
final class PageHeader {

	/**
	 * @phpstan-param array{
	 *     eyebrow?: string,
	 *     lede?: string,
	 *     aside_html?: string,
	 *     heading?: int,
	 *     id?: string,
	 *     class?: string|list<string>
	 * } $args "eyebrow" is the small line above the title (the product name). "heading" is the level of the
	 *         title, 1 by default; a page that already prints its own h1 passes 2.
	 */
	public static function render( string $title, array $args = [] ): string {
		$level   = max( 1, min( 6, (int) ( $args['heading'] ?? 1 ) ) );
		$eyebrow = '' !== (string) ( $args['eyebrow'] ?? '' )
			? Html::element( 'div', [ 'class' => 'sw-ui-page-header__eyebrow' ], Html::element( 'span', [ 'class' => 'sw-ui-page-header__logo', 'aria-hidden' => 'true' ], '' ) . Html::text( (string) $args['eyebrow'] ) )
			: '';
		$lede    = '' !== (string) ( $args['lede'] ?? '' ) ? Html::element( 'p', [ 'class' => 'sw-ui-page-lede' ], Html::text( (string) $args['lede'] ) ) : '';
		$main    = Html::element( 'div', [ 'class' => 'sw-ui-page-header__main' ], $eyebrow . Html::element( 'h' . $level, [ 'class' => 'sw-ui-page-title' ], Html::text( $title ) ) . $lede );
		$aside   = '' !== (string) ( $args['aside_html'] ?? '' ) ? Html::element( 'div', [ 'class' => 'sw-ui-page-header__aside' ], (string) $args['aside_html'] ) : '';

		return Html::element( 'header', [ 'class' => Html::classes( 'sw-ui-page-header', $args['class'] ?? '' ), 'id' => $args['id'] ?? null ], $main . $aside );
	}
}
