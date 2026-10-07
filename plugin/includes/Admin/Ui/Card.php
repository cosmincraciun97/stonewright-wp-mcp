<?php
/**
 * Cards of the admin UI layer.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Ui;

/**
 * A card is one bordered surface with a heading, so a screen reader can jump to it by region.
 * It is flat: no shadow, no coloured stripe. Status shows as a badge in the header, never as a side stripe.
 */
final class Card {

	/**
	 * @param string $body_html Markup built with the other helpers or escaped by the caller.
	 * @phpstan-param array{
	 *     desc?: string,
	 *     actions_html?: string,
	 *     footer_html?: string,
	 *     heading?: int,
	 *     id?: string,
	 *     flush?: bool,
	 *     compact?: bool,
	 *     class?: string|list<string>
	 * } $args "flush" removes the body padding (a table or list that goes edge to edge).
	 */
	public static function render( string $title, string $body_html, array $args = [] ): string {
		$level    = max( 2, min( 6, (int) ( $args['heading'] ?? 2 ) ) );
		$title_id = Html::unique_id( 'card-title' );

		$heading = Html::element( 'h' . $level, [ 'class' => 'sw-ui-card__title', 'id' => $title_id ], Html::text( $title ) )
			. ( '' !== (string) ( $args['desc'] ?? '' ) ? Html::element( 'p', [ 'class' => 'sw-ui-card__desc' ], Html::text( (string) $args['desc'] ) ) : '' );
		$header  = Html::element(
			'div',
			[ 'class' => 'sw-ui-card__header' ],
			Html::element( 'div', [], $heading ) . ( '' !== (string) ( $args['actions_html'] ?? '' ) ? Html::element( 'div', [ 'class' => 'sw-ui-actions' ], (string) $args['actions_html'] ) : '' )
		);
		$body    = Html::element( 'div', [ 'class' => Html::classes( 'sw-ui-card__body', ! empty( $args['flush'] ) ? 'sw-ui-card__body--flush' : '' ) ], $body_html );
		$footer  = '' !== (string) ( $args['footer_html'] ?? '' ) ? Html::element( 'div', [ 'class' => 'sw-ui-card__footer' ], (string) $args['footer_html'] ) : '';

		return Html::element(
			'section',
			[
				'class'           => Html::classes( 'sw-ui-card', ! empty( $args['compact'] ) ? 'sw-ui-card--compact' : '', $args['class'] ?? '' ),
				'id'              => $args['id'] ?? null,
				'aria-labelledby' => $title_id,
			],
			$header . $body . $footer
		);
	}
}
