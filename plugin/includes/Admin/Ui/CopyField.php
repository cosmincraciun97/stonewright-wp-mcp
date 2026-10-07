<?php
/**
 * A value with a copy button, for URLs, commands and tokens.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Ui;

/**
 * The value, an icon button that copies it, and a polite status line that says "Copied" (sw-ui.js owns the
 * behaviour; without script the value is still selectable text). A secret is rendered masked in a read-only
 * password field with a Show and Hide toggle; the helper never logs or stores what it is given.
 */
final class CopyField {

	/**
	 * @phpstan-param array{
	 *     secret?: bool,
	 *     id?: string,
	 *     label?: string,
	 *     copy_label?: string,
	 *     class?: string|list<string>
	 * } $args "label" names what is copied ("MCP server URL"); the button is read as "Copy MCP server URL".
	 */
	public static function render( string $value, array $args = [] ): string {
		$secret = ! empty( $args['secret'] );
		$id     = '' !== (string) ( $args['id'] ?? '' ) ? (string) $args['id'] : Html::unique_id( 'copy' );
		$what   = (string) ( $args['label'] ?? '' );

		/* translators: %s: what is copied, for example "MCP server URL" */
		$copy_label = (string) ( $args['copy_label'] ?? ( '' !== $what ? sprintf( __( 'Copy %s', 'stonewright' ), $what ) : __( 'Copy', 'stonewright' ) ) );

		if ( $secret ) {
			$field = Html::void(
				'input',
				[
					'type'         => 'password',
					'class'        => 'sw-ui-input sw-ui-copy__value',
					'id'           => $id,
					'value'        => $value,
					'readonly'     => true,
					'autocomplete' => 'off',
					'aria-label'   => '' !== $what ? $what : __( 'Value', 'stonewright' ),
				]
			);
		} else {
			$field = Html::element( 'code', [ 'class' => 'sw-ui-copy__value', 'id' => $id ], Html::text( $value ) );
		}

		$copy = Html::element(
			'button',
			[
				'type'                         => 'button',
				'class'                        => 'sw-ui-btn sw-ui-btn--icon',
				'aria-label'                   => $copy_label,
				'data-sw-ui-copy'              => '#' . $id,
				'data-sw-ui-copied-label'      => __( 'Copied', 'stonewright' ),
				'data-sw-ui-copy-failed-label' => __( 'Press Ctrl+C', 'stonewright' ),
			],
			Icon::render( 'copy', [ 'class' => 'sw-ui-copy__icon-copy' ] ) . Icon::render( 'check', [ 'class' => 'sw-ui-copy__icon-done' ] )
		);

		$reveal = '';
		if ( $secret ) {
			$show   = __( 'Show value', 'stonewright' );
			$reveal = Html::element(
				'button',
				[
					'type'                  => 'button',
					'class'                 => 'sw-ui-btn sw-ui-btn--icon',
					'aria-label'            => $show,
					'aria-pressed'          => 'false',
					'data-sw-ui-reveal'     => '#' . $id,
					'data-sw-ui-show-label' => $show,
					'data-sw-ui-hide-label' => __( 'Hide value', 'stonewright' ),
				],
				Icon::render( 'eye' )
			);
		}

		return Html::element(
			'div',
			[ 'class' => Html::classes( 'sw-ui-copy', $secret ? 'sw-ui-copy--secret' : '', $args['class'] ?? '' ) ],
			$field . $reveal . $copy . Html::element( 'span', [ 'class' => 'sw-ui-copy__status', 'role' => 'status' ], '' )
		);
	}
}
