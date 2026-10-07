<?php
/**
 * The hidden nonce field of a form, without an id.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Ui;

/**
 * `wp_nonce_field()` prints an id on every field it makes, so a page with one form per row repeats the same id
 * dozens of times. A handler reads the field by its name, never by its id, so this prints the same field without
 * the id. The nonce value is the one `wp_create_nonce()` makes, which is what `check_admin_referer()` and
 * `wp_verify_nonce()` check.
 */
final class Nonce {

	public static function field( string $action, string $name = '_wpnonce' ): string {
		return Html::void(
			'input',
			[
				'type'  => 'hidden',
				'name'  => $name,
				'value' => wp_create_nonce( $action ),
			]
		);
	}
}
