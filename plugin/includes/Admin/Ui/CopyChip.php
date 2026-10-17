<?php
/**
 * A value inside a sentence, set apart and copyable.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Ui;

/**
 * The inline form of CopyField, for a value a step tells the reader to type or paste: a name, a URL, a short
 * command. The value is a code element on a tint, followed by a small icon button that copies it. The "Copied"
 * line is a visually hidden polite status, so the sentence does not move; the icon turns into a check for a
 * moment. sw-ui.js owns the behaviour. Without script the value is still selectable text. A long value wraps
 * inside its box.
 */
final class CopyChip {

	/**
	 * @phpstan-param array{
	 *     id?: string,
	 *     label?: string,
	 *     copy_label?: string,
	 *     trail?: string,
	 *     class?: string|list<string>
	 * } $args "label" names what is copied ("server URL"); the button is read as "Copy server URL". "trail" is the
	 *         punctuation that follows the chip in the sentence: it is printed with the chip so a line never breaks
	 *         between them and leaves a lone full stop on the next line.
	 */
	public static function render( string $value, array $args = [] ): string {
		$id   = '' !== (string) ( $args['id'] ?? '' ) ? (string) $args['id'] : Html::unique_id( 'chip' );
		$what = (string) ( $args['label'] ?? '' );

		/* translators: %s: what is copied, for example "server URL" */
		$copy_label = (string) ( $args['copy_label'] ?? ( '' !== $what ? sprintf( __( 'Copy %s', 'stonewright' ), $what ) : __( 'Copy', 'stonewright' ) ) );
		$status     = $id . '-status';

		$copy = Html::element(
			'button',
			[
				'type'                         => 'button',
				'class'                        => 'sw-ui-btn sw-ui-btn--tertiary sw-ui-btn--xs sw-ui-btn--icon',
				'aria-label'                   => $copy_label,
				'data-sw-ui-copy'              => '#' . $id,
				'data-sw-ui-copy-status'       => '#' . $status,
				'data-sw-ui-copied-label'      => __( 'Copied', 'stonewright' ),
				'data-sw-ui-copy-failed-label' => __( 'Press Ctrl+C', 'stonewright' ),
			],
			Icon::render( 'copy', [ 'class' => 'sw-ui-copy__icon-copy' ] ) . Icon::render( 'check', [ 'class' => 'sw-ui-copy__icon-done' ] )
		);

		$chip = Html::element(
			'span',
			[ 'class' => Html::classes( 'sw-ui-copy-chip', $args['class'] ?? '' ) ],
			Html::element( 'code', [ 'class' => 'sw-ui-copy-chip__value', 'id' => $id ], Html::text( $value ) )
			. $copy
			. Html::element( 'span', [ 'class' => 'sw-ui-visually-hidden', 'role' => 'status', 'id' => $status ], '' )
		);
		$trail = (string) ( $args['trail'] ?? '' );

		return '' === $trail ? $chip : Html::element( 'span', [ 'class' => 'sw-ui-copy-chip-group' ], $chip . Html::text( $trail ) );
	}
}
