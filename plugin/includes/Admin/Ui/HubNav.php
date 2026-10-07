<?php
/**
 * The tab bar of a hub of the admin UI layer.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Ui;

/**
 * Links to the pages of one hub, under the page header. It is navigation, not a tab widget: every tab is a plain
 * link to a server-rendered page, so it works without script and the current page is marked with
 * aria-current="page". A hub with one page has no bar.
 *
 * A count beside a tab is a number, and carries words for assistive technology ("3 open incidents"): the number
 * alone would read as a bare "3".
 */
final class HubNav {

	/**
	 * @param list<array{label: string, url: string, current: bool, count: int|null, count_label: string}> $links
	 * @param string $label The name of the navigation landmark, for example "Knowledge sections".
	 */
	public static function render( array $links, string $label ): string {
		if ( count( $links ) < 2 ) {
			return '';
		}

		$items = '';
		foreach ( $links as $link ) {
			$count = '';
			if ( null !== $link['count'] ) {
				$count = ' ' . Badge::count( $link['count'] );
				if ( '' !== $link['count_label'] ) {
					$count .= Html::element( 'span', [ 'class' => 'sw-ui-visually-hidden' ], ' ' . Html::text( $link['count_label'] ) );
				}
			}
			$items .= Html::element(
				'li',
				[],
				Html::element(
					'a',
					[
						'class'        => 'sw-ui-hubnav__link',
						'href'         => $link['url'],
						'aria-current' => $link['current'] ? 'page' : null,
					],
					Html::text( $link['label'] ) . $count
				)
			);
		}

		return Html::element( 'nav', [ 'aria-label' => $label ], Html::element( 'ul', [ 'class' => 'sw-ui-hubnav' ], $items ) );
	}
}
