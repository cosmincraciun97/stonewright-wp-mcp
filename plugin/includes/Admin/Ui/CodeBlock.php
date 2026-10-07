<?php
/**
 * A block of code or a command with a copy button.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Ui;

/**
 * A dark block with a head (what it is, where it goes, a copy button and a polite status line) and a body that
 * keeps its line breaks. The body is a focusable, named scroll region, so a keyboard user can read a long line;
 * the code is text and never markup. sw-ui.js owns the copy behaviour. Without script the code is still
 * selectable text.
 */
final class CodeBlock {

	/**
	 * @phpstan-param array{
	 *     id?: string,
	 *     title: string,
	 *     where?: string,
	 *     copy_label: string,
	 *     class?: string|list<string>,
	 *     body_class?: string|list<string>,
	 *     body_attrs?: array<string, scalar|null>
	 * } $args "title" says what the code is ("Terminal"), "where" where it goes (".mcp.json"), "copy_label" names
	 *         what the button copies; it is read as "Copy <copy_label>".
	 */
	public static function render( string $code, array $args ): string {
		$id     = '' !== (string) ( $args['id'] ?? '' ) ? (string) $args['id'] : Html::unique_id( 'code' );
		$title  = Html::text( $args['title'] );
		$where  = '' !== (string) ( $args['where'] ?? '' ) ? ' ' . Html::element( 'span', [ 'class' => 'sw-ui-code__where' ], Html::text( (string) $args['where'] ) ) : '';
		$status = $id . '-status';

		$copy = Button::render(
			__( 'Copy', 'stonewright' ),
			[
				'size'    => 'xs',
				'context' => $args['copy_label'],
				'attrs'   => [
					'data-sw-ui-copy'              => '#' . $id,
					'data-sw-ui-copy-status'       => '#' . $status,
					'data-sw-ui-copied-label'      => __( 'Copied', 'stonewright' ),
					'data-sw-ui-copy-failed-label' => __( 'Press Ctrl+C', 'stonewright' ),
				],
			]
		);

		$head = Html::element(
			'div',
			[ 'class' => 'sw-ui-code__head' ],
			Html::element( 'span', [], $title . $where ) . Html::element( 'span', [ 'class' => 'sw-ui-copy__status', 'role' => 'status', 'id' => $status ], '' ) . $copy
		);
		$body = Html::element(
			'pre',
			array_merge(
				[
					'class'      => Html::classes( 'sw-ui-code__body', $args['body_class'] ?? '' ),
					'id'         => $id,
					'tabindex'   => '0',
					'aria-label' => $args['title'] . ': ' . $args['copy_label'],
				],
				Html::without( $args['body_attrs'] ?? [], [ 'class', 'id', 'tabindex', 'aria-label' ] )
			),
			Html::element( 'code', [], Html::text( $code ) )
		);

		return Html::element( 'div', [ 'class' => Html::classes( 'sw-ui-code', $args['class'] ?? '' ) ], $head . $body );
	}
}
