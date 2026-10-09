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
 * One native form-table row: the label, a switch, one sentence of help and an info callout that says what the
 * setting does. The callout sits in one polite live region: the page renders it while the saved value is on, and
 * the switch fills or empties the region as it is toggled (from the template that always carries the callout),
 * so it is announced when it appears. Without script it shows the saved value. The setting itself changes when the
 * form is saved.
 *
 * The option is saved with the rest of the form through the Settings API (nonce and manage_options are checked by
 * options.php); the sanitize callback and the audit row live in SectionReuseSetting.
 */
final class SectionReuseRow {

	public static function html(): string {
		$option  = SectionReuseSetting::OPTION;
		$enabled = SectionReuseSetting::is_enabled();
		$help_id = $option . '_help';

		$callout = static fn( array $attributes ): string => Notice::callout(
			'info',
			__( 'Agents may offer to reuse sections', 'stonewright' ),
			__( 'When you ask an agent to build a page, it may offer to copy a section that already exists on another page and adapt it. It only offers content you can read and edit, it never changes the page it copies from, and the copy is written with the same snapshot, readback and audit trail as any other change.', 'stonewright' ),
			$attributes
		);
		$region_id   = $option . '_callout_region';
		$template_id = $option . '_callout_template';

		$help = __( 'Let agents offer to copy a section from another page of this site into the page they are building. Turn it off and every section is built from scratch. The setting changes when you save.', 'stonewright' );
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
						'data-sw-ui-live-fill'      => '#' . $region_id,
						'data-sw-ui-live-fill-from' => '#' . $template_id,
					]
				) . Html::element( 'span', [ 'class' => 'sw-ui-switch__track', 'aria-hidden' => 'true' ], '' )
			)
			. Html::element( 'p', [ 'class' => 'sw-ui-field__help', 'id' => $help_id ], Html::text( $help ) )
			. Html::element( 'div', [ 'id' => $region_id, 'aria-live' => 'polite' ], $enabled ? $callout( [ 'id' => $option . '_callout' ] ) : '' )
			// The id is only in the live page: the copy the script makes is the same callout.
			. Html::element( 'template', [ 'id' => $template_id ], $callout( [] ) );

		return Html::element(
			'tr',
			[ 'class' => 'stonewright-section-reuse-row' ],
			Html::element( 'th', [ 'scope' => 'row' ], Html::element( 'label', [ 'for' => $option ], Html::text( __( 'Reuse saved sections', 'stonewright' ) ) ) )
			. Html::element( 'td', [], $switch )
		);
	}
}
