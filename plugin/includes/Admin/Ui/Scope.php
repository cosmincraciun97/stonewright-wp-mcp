<?php
/**
 * The scope root of the admin UI layer.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Ui;

/**
 * Components and tokens of the layer apply only inside `.sw-ui`. A page that renders its whole content with
 * the helpers, or a page that adds one component to markup of the older kind, wraps that markup here so the
 * layer's tokens and rules reach it and nothing else.
 */
final class Scope {

	/**
	 * @param string $html Markup built with the helpers or escaped by the caller.
	 * @param array{page?: bool, class?: string|list<string>, id?: string} $args "page" also applies the page width.
	 */
	public static function wrap( string $html, array $args = [] ): string {
		return Html::element(
			'div',
			[ 'class' => Html::classes( 'sw-ui', ! empty( $args['page'] ) ? 'sw-ui-page' : '', $args['class'] ?? '' ), 'id' => $args['id'] ?? null ],
			$html
		);
	}
}
