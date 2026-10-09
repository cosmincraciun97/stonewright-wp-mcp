<?php
/**
 * The band at the top of every Stonewright page.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Ui;

/**
 * A dark two-row panel: the product mark and name, then one link to every page, grouped by hub. The current page is
 * marked with aria-current="page". Every link is a plain link to a server-rendered page, so the band works without
 * script, and it is redrawn on each page.
 *
 * A hub with two links or more has its name in front of its links (shown in capitals by the stylesheet) and a thin
 * rule; a hub with one link has neither. A link to a page that is still changing carries a small "EXP" marker, a
 * tooltip that sw-ui.js shows on hover and focus (`data-sw-ui-tip`), and the same words as hidden text, so assistive
 * technology reads them without the tooltip. A count beside a link is a number with words for assistive technology.
 *
 * The data comes from MenuRegistry::band_groups().
 *
 * @phpstan-type BandLink array{label: string, url: string, current: bool, count: int|null, count_label: string, beta: bool}
 * @phpstan-type BandGroup array{hub: string, label: string, links: list<BandLink>}
 */
final class Band {

	/** The marker next to a page that is still changing. It is a translatable string, not a constant. */
	public static function exp_text(): string {
		return __( 'EXP', 'stonewright' );
	}

	/** What the marker means. It is the tooltip, and hidden text for assistive technology. */
	public static function exp_hint(): string {
		return __( 'This feature is experimental.', 'stonewright' );
	}

	/**
	 * @param list<BandGroup> $groups
	 * @param array{logo_url?: string} $args "logo_url" is the address of the 256 x 256 mark, shown at 28 x 28.
	 */
	public static function render( array $groups, array $args = [] ): string {
		$logo = '';
		if ( '' !== (string) ( $args['logo_url'] ?? '' ) ) {
			$logo = Html::void(
				'img',
				[
					'class'  => 'sw-ui-band__logo',
					'src'    => (string) $args['logo_url'],
					'alt'    => __( 'Stonewright', 'stonewright' ),
					'width'  => 28,
					'height' => 28,
				]
			);
		}
		$brand = Html::element(
			'div',
			[ 'class' => 'sw-ui-band__brand' ],
			$logo . Html::element( 'span', [ 'class' => 'sw-ui-band__name' ], Html::text( __( 'Stonewright', 'stonewright' ) ) )
		);

		$nav = '';
		foreach ( $groups as $group ) {
			$nav .= self::group( $group );
		}
		if ( '' !== $nav ) {
			$nav = Html::element( 'nav', [ 'class' => 'sw-ui-band__nav', 'aria-label' => __( 'Stonewright admin', 'stonewright' ) ], $nav );
		}

		return Html::element( 'header', [ 'class' => 'sw-ui-band', 'role' => 'banner', 'data-sw-ui-band' => true ], $brand . $nav );
	}

	/** @param BandGroup $group */
	private static function group( array $group ): string {
		$labelled = count( $group['links'] ) > 1;
		$inner    = $labelled ? Html::element( 'span', [ 'class' => 'sw-ui-band__label', 'aria-hidden' => 'true' ], Html::text( $group['label'] ) ) : '';
		foreach ( $group['links'] as $link ) {
			$inner .= self::link( $link );
		}

		return Html::element( 'div', [ 'class' => Html::classes( 'sw-ui-band__group', $labelled ? 'sw-ui-band__group--labelled' : '' ) ], $inner );
	}

	/** @param BandLink $link */
	private static function link( array $link ): string {
		$inner = Html::text( $link['label'] );
		if ( null !== $link['count'] ) {
			$inner .= ' ' . Html::element( 'span', [ 'class' => 'sw-ui-band__count sw-ui-num' ], Html::text( (string) $link['count'] ) );
			if ( '' !== $link['count_label'] ) {
				$inner .= Html::element( 'span', [ 'class' => 'sw-ui-visually-hidden' ], ' ' . Html::text( $link['count_label'] ) );
			}
		}
		if ( $link['beta'] ) {
			$inner .= Html::element( 'span', [ 'class' => 'sw-ui-band__exp', 'aria-hidden' => 'true' ], Html::text( self::exp_text() ) )
				. Html::element( 'span', [ 'class' => 'sw-ui-visually-hidden' ], ' ' . Html::text( self::exp_hint() ) );
		}

		return Html::element(
			'a',
			[
				'class'          => Html::classes( 'sw-ui-band__link', $link['beta'] ? 'sw-ui-band__link--exp' : '' ),
				'href'           => $link['url'],
				'aria-current'   => $link['current'] ? 'page' : null,
				'data-sw-ui-tip' => $link['beta'] ? self::exp_hint() : null,
			],
			$inner
		);
	}
}
