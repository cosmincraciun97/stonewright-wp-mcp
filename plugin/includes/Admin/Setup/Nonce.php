<?php
/**
 * Nonce fields for the forms of Setup.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Setup;

/**
 * WordPress prints every nonce field with the id "_wpnonce", and Setup has several forms, so the page would carry
 * the same id more than once. The field keeps its name and value (the handlers read `_wpnonce`); it only loses
 * the id, which nothing refers to.
 */
final class Nonce {

	public static function field( string $action ): string {
		$html = wp_nonce_field( $action, '_wpnonce', true, false );

		return (string) preg_replace( '/ id="[^"]*"/', '', $html );
	}
}
