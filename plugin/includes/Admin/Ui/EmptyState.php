<?php
/**
 * Empty states of the admin UI layer.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Ui;

/**
 * An empty state teaches: what this is, why it is empty, and what to do next. Give it a title, one or two
 * sentences, and at most one primary action plus one link.
 */
final class EmptyState {

	public const VARIANTS = [ 'default', 'first-run', 'inline', 'error', 'no-results' ];

	private const ICONS = [
		'default'    => 'info',
		'first-run'  => 'bolt',
		'inline'     => 'info',
		'error'      => 'alert',
		'no-results' => 'search',
	];

	/**
	 * @phpstan-param array{
	 *     variant?: string,
	 *     icon?: string,
	 *     actions_html?: string,
	 *     heading?: int,
	 *     id?: string,
	 *     class?: string|list<string>
	 * } $args "heading" is the level of the title (2 to 6, default 3). The inline variant has no title element:
	 *         its title and text read as one sentence.
	 */
	public static function render( string $title, string $text = '', array $args = [] ): string {
		$variant = in_array( $args['variant'] ?? 'default', self::VARIANTS, true ) ? (string) ( $args['variant'] ?? 'default' ) : 'default';
		$icon    = isset( $args['icon'] ) && Icon::exists( (string) $args['icon'] ) ? (string) $args['icon'] : self::ICONS[ $variant ];
		$level   = max( 2, min( 6, (int) ( $args['heading'] ?? 3 ) ) );
		$actions = '' !== (string) ( $args['actions_html'] ?? '' ) ? Html::element( 'div', [ 'class' => 'sw-ui-actions' ], (string) $args['actions_html'] ) : '';
		$classes = Html::classes( 'sw-ui-empty', 'default' === $variant ? '' : 'sw-ui-empty--' . $variant, $args['class'] ?? '' );

		if ( 'inline' === $variant ) {
			$copy = Html::element( 'div', [], Html::text( $title ) . ( '' !== $text ? ' ' . Html::text( $text ) : '' ) . $actions );

			return Html::element( 'div', [ 'class' => $classes, 'id' => $args['id'] ?? null ], Icon::render( $icon, [ 'size' => 'lg' ] ) . $copy );
		}

		$inner = Html::element( 'span', [ 'class' => 'sw-ui-empty__icon' ], Icon::render( $icon, [ 'size' => 'lg' ] ) )
			. Html::element( 'h' . $level, [ 'class' => 'sw-ui-empty__title' ], Html::text( $title ) )
			. ( '' !== $text ? Html::element( 'p', [ 'class' => 'sw-ui-empty__text' ], Html::text( $text ) ) : '' )
			. $actions;

		return Html::element( 'div', [ 'class' => $classes, 'id' => $args['id'] ?? null ], $inner );
	}
}
