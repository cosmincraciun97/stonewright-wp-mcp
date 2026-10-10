<?php
/**
 * Badges, tags, counts and status words of the admin UI layer.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Ui;

/**
 * Four small components, each for one job:
 *
 *  - a badge says a state and is the only one that is colour coded (ok, warn, danger, info, accent, neutral);
 *  - a tag says a fact about the thing (its source, its type) in an outline and never carries a state;
 *  - a count is a number next to a heading or tab;
 *  - a status is a dot and a word for a table cell.
 *
 * The word always carries the meaning. The colour and the dot only repeat it.
 */
final class Badge {

	public const VARIANTS = [ 'neutral', 'ok', 'warn', 'danger', 'info', 'accent' ];

	/**
	 * @param array{variant?: string, dot?: bool, icon?: string, class?: string|list<string>, attrs?: array<string, scalar|list<string>|null>} $args
	 */
	public static function render( string $label, array $args = [] ): string {
		$variant = in_array( $args['variant'] ?? 'neutral', self::VARIANTS, true ) ? (string) ( $args['variant'] ?? 'neutral' ) : 'neutral';
		$classes = Html::classes(
			'sw-ui-badge',
			'neutral' === $variant ? '' : 'sw-ui-badge--' . $variant,
			! empty( $args['dot'] ) ? 'sw-ui-badge--dot' : '',
			$args['class'] ?? ''
		);
		$icon    = '' !== (string) ( $args['icon'] ?? '' ) ? Icon::render( (string) $args['icon'] ) : '';

		return Html::element( 'span', array_merge( [ 'class' => $classes ], Html::without( $args['attrs'] ?? [], [ 'class' ] ) ), $icon . Html::text( $label ) );
	}

	/**
	 * @param array{class?: string|list<string>, attrs?: array<string, scalar|list<string>|null>} $args
	 */
	public static function tag( string $label, array $args = [] ): string {
		return Html::element( 'span', array_merge( [ 'class' => Html::classes( 'sw-ui-tag', $args['class'] ?? '' ) ], Html::without( $args['attrs'] ?? [], [ 'class' ] ) ), Html::text( $label ) );
	}

	/**
	 * @param int|string $number Shown as given; numbers line up in columns.
	 * @param array{class?: string|list<string>} $args
	 */
	public static function count( int|string $number, array $args = [] ): string {
		return Html::element( 'span', [ 'class' => Html::classes( 'sw-ui-count', 'sw-ui-num', $args['class'] ?? '' ) ], Html::text( (string) $number ) );
	}

	/**
	 * @param string $variant ok, warn, danger or neutral.
	 */
	public static function status( string $label, string $variant = 'neutral' ): string {
		$known = in_array( $variant, [ 'ok', 'warn', 'danger' ], true );

		return Html::element( 'span', [ 'class' => Html::classes( 'sw-ui-status', $known ? 'sw-ui-status--' . $variant : '' ) ], Html::text( $label ) );
	}
}
