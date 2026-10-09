<?php
/**
 * A password field for a stored secret and the control that removes it.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Setup;

use Stonewright\WpMcp\Admin\Ui\Html;

/**
 * The two controls of a secret setting. The stored value is never put into the page: the field stays empty and a
 * placeholder says that a value is stored, with an explicit control to remove it when the form is saved.
 */
final class SecretField {

	/** The empty password field. A stored secret only adds the placeholder and a marker for the page script. */
	public static function input( string $option, bool $stored, string $describedby = '' ): string {
		return Html::void(
			'input',
			[
				'type'                           => 'password',
				'class'                          => 'sw-ui-input',
				'name'                           => $option,
				'id'                             => $option,
				'value'                          => '',
				'data-stonewright-secret-stored' => $stored ? '1' : null,
				'placeholder'                    => $stored ? __( 'Stored. Leave empty to keep it, or type a new value.', 'stonewright' ) : null,
				'autocomplete'                   => 'new-password',
				'aria-describedby'               => '' !== $describedby ? $describedby : null,
			]
		);
	}

	/** The checkbox that removes the stored value on save. Nothing when no value is stored. */
	public static function clear( string $option, bool $stored ): string {
		if ( ! $stored ) {
			return '';
		}

		return Html::element(
			'label',
			[ 'class' => 'sw-ui-checkbox' ],
			Html::void( 'input', [ 'type' => 'checkbox', 'name' => 'stonewright_clear_secrets[]', 'value' => $option ] )
			. Html::text( __( 'Remove the stored value when saving', 'stonewright' ) )
		);
	}
}
