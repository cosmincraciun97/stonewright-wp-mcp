<?php
/**
 * The "Reuse saved sections" row of the settings form.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Setup;

use Stonewright\WpMcp\Admin\Ui\Html;
use Stonewright\WpMcp\Admin\Ui\Notice;
use Stonewright\WpMcp\SectionReuse\SectionReuseSetting;

/**
 * One native form-table row: the label, a switch, one sentence of help and, while the setting is on, an info
 * callout that says what it does (announced politely when it appears).
 *
 * The option is saved with the rest of the form through the Settings API (nonce and manage_options are checked by
 * options.php); the sanitize callback and the audit row live in SectionReuseSetting.
 */
final class SectionReuseRow {

	public static function html(): string {
		$option  = SectionReuseSetting::OPTION;
		$enabled = SectionReuseSetting::is_enabled();
		$help_id = $option . '_help';

		$callout = '';
		if ( $enabled ) {
			$callout = Notice::callout(
				'info',
				__( 'Agents may offer to reuse sections', 'stonewright' ),
				__( 'When you ask an agent to build a page, it may offer to copy a section that already exists on another page and adapt it. It only offers content you can read and edit, it never changes the page it copies from, and the copy is written with the same snapshot, readback and audit trail as any other change.', 'stonewright' ),
				[ 'id' => $option . '_callout' ]
			);
		}

		$help = __( 'Let agents offer to copy a section from another page of this site into the page they are building. Turn it off and every section is built from scratch.', 'stonewright' );
		if ( 'production-safe' === (string) get_option( 'stonewright_mode', 'development' ) ) {
			$help .= ' ' . __( 'In production-safe mode an Elementor V3 copy needs a confirmation token like any other write, a Gutenberg copy needs one only when the same change removes a block, and copying an Elementor V4 section stays blocked.', 'stonewright' );
		}

		$switch = Html::void( 'input', [ 'type' => 'hidden', 'name' => $option, 'value' => 'off' ] )
			. Html::element(
				'span',
				[ 'class' => 'sw-ui-switch' ],
				Html::void(
					'input',
					[
						'type'             => 'checkbox',
						'role'             => 'switch',
						'id'               => $option,
						'name'             => $option,
						'value'            => 'ask',
						'checked'          => $enabled ? true : null,
						'aria-describedby' => $help_id,
					]
				) . Html::element( 'span', [ 'class' => 'sw-ui-switch__track', 'aria-hidden' => 'true' ], '' )
			)
			. Html::element( 'p', [ 'class' => 'sw-ui-field__help', 'id' => $help_id ], Html::text( $help ) )
			. Html::element( 'div', [ 'aria-live' => 'polite' ], $callout );

		return Html::element(
			'tr',
			[ 'class' => 'stonewright-section-reuse-row' ],
			Html::element( 'th', [ 'scope' => 'row' ], Html::element( 'label', [ 'for' => $option ], Html::text( __( 'Reuse saved sections', 'stonewright' ) ) ) )
			. Html::element( 'td', [], $switch )
		);
	}
}
