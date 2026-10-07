<?php
/**
 * Key and value facts of the admin UI layer.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Ui;

/**
 * A description list: audit details, the facts on a consent screen, a receipt. Terms are short labels,
 * values are text (escaped here) or markup built with the other helpers (value_html).
 */
final class KvList {

	/**
	 * @param list<array{label: string, value?: string, value_html?: string}> $items
	 * @param array{inline?: bool, class?: string|list<string>, label?: string} $args "label" names the list for assistive technology.
	 */
	public static function render( array $items, array $args = [] ): string {
		$inline = ! empty( $args['inline'] );
		$body   = '';
		foreach ( $items as $item ) {
			$value = isset( $item['value_html'] ) ? (string) $item['value_html'] : Html::text( (string) ( $item['value'] ?? '' ) );
			$pair  = Html::element( 'dt', [], Html::text( (string) $item['label'] ) ) . Html::element( 'dd', [], $value );
			$body .= $inline ? Html::element( 'div', [], $pair ) : $pair;
		}

		return Html::element(
			'dl',
			[
				'class'      => Html::classes( 'sw-ui-kv', $inline ? 'sw-ui-kv--inline' : '', $args['class'] ?? '' ),
				'aria-label' => '' !== (string) ( $args['label'] ?? '' ) ? (string) $args['label'] : null,
			],
			$body
		);
	}
}
