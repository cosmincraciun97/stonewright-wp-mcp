<?php
/**
 * Data tables of the admin UI layer.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Ui;

/**
 * A real <table> with a caption, column headers and one primary cell per row. Below 783px the table stacks:
 * each row becomes a card and every cell shows its column label (the data-label the helper sets), so nothing
 * scrolls sideways and no word breaks one letter per line.
 *
 * Cells are text (escaped here) unless a cell is given as ['html' => ...], markup built with the other helpers.
 */
final class Table {

	/**
	 * @param list<array{key: string, label: string, primary?: bool, secondary?: bool, numeric?: bool, actions?: bool, hide_label?: bool}> $columns
	 *        "primary" is the name cell (one per table). "secondary" columns are hidden at 1024px and below.
	 *        "actions" columns are right aligned and their header is visually hidden.
	 * @param list<array<string, string|array{text?: string, meta?: string, html?: string}>> $rows Cells by column key. A row may carry an "_id", printed as the id of its tr.
	 * @param array{caption: string, stack?: bool, comfortable?: bool, empty?: string, id?: string, class?: string|list<string>} $args
	 *        "caption" names the table; it is visually hidden but always present.
	 */
	public static function render( array $columns, array $rows, array $args ): string {
		$caption = Html::element( 'caption', [ 'class' => 'sw-ui-visually-hidden' ], Html::text( (string) ( $args['caption'] ?? '' ) ) );

		$head = '';
		foreach ( $columns as $column ) {
			$hidden = ! empty( $column['actions'] ) || ! empty( $column['hide_label'] );
			$label  = $hidden
				? Html::element( 'span', [ 'class' => 'sw-ui-visually-hidden' ], Html::text( $column['label'] ) )
				: Html::text( $column['label'] );
			$head  .= Html::element( 'th', [ 'scope' => 'col', 'class' => self::cell_classes( $column, false ) ], $label );
		}

		$body = '';
		foreach ( $rows as $row ) {
			$cells = '';
			foreach ( $columns as $column ) {
				$cells .= self::cell( $column, $row[ $column['key'] ] ?? '' );
			}
			$body .= Html::element( 'tr', [ 'id' => isset( $row['_id'] ) && is_string( $row['_id'] ) ? $row['_id'] : null ], $cells );
		}
		if ( [] === $rows && '' !== (string) ( $args['empty'] ?? '' ) ) {
			$body = Html::element( 'tr', [], Html::element( 'td', [ 'colspan' => (string) count( $columns ) ], Html::text( (string) $args['empty'] ) ) );
		}

		$classes = Html::classes(
			'sw-ui-table',
			( $args['stack'] ?? true ) ? 'sw-ui-table--stack' : '',
			! empty( $args['comfortable'] ) ? 'sw-ui-table--comfortable' : '',
			$args['class'] ?? ''
		);

		return Html::element(
			'table',
			[ 'class' => $classes, 'id' => $args['id'] ?? null ],
			$caption . Html::element( 'thead', [], Html::element( 'tr', [], $head ) ) . Html::element( 'tbody', [], $body )
		);
	}

	/**
	 * @param array{key: string, label: string, primary?: bool, secondary?: bool, numeric?: bool, actions?: bool} $column
	 * @param string|array{text?: string, meta?: string, html?: string} $value
	 */
	private static function cell( array $column, string|array $value ): string {
		$classes = self::cell_classes( $column, true );

		if ( is_array( $value ) && isset( $value['html'] ) ) {
			$inner = (string) $value['html'];
		} elseif ( is_array( $value ) ) {
			$inner = '';
			if ( isset( $value['text'] ) ) {
				$inner .= Html::element( 'span', [ 'class' => ! empty( $column['primary'] ) ? 'sw-ui-table__primary' : null ], Html::text( (string) $value['text'] ) );
			}
			if ( isset( $value['meta'] ) && '' !== $value['meta'] ) {
				$inner .= Html::element( 'span', [ 'class' => 'sw-ui-table__meta' ], Html::text( (string) $value['meta'] ) );
			}
		} elseif ( ! empty( $column['primary'] ) ) {
			$inner = Html::element( 'span', [ 'class' => 'sw-ui-table__primary' ], Html::text( $value ) );
		} else {
			$inner = Html::text( $value );
		}

		// The primary cell is the card title when the row stacks, so it has no label of its own.
		$label = ! empty( $column['primary'] ) || ! empty( $column['actions'] ) ? null : $column['label'];

		return Html::element( 'td', [ 'class' => $classes, 'data-label' => $label ], $inner );
	}

	/** @param array{primary?: bool, secondary?: bool, numeric?: bool, actions?: bool} $column */
	private static function cell_classes( array $column, bool $is_cell ): string {
		return Html::classes(
			$is_cell && ! empty( $column['primary'] ) ? 'sw-ui-table__primary-cell' : '',
			! empty( $column['secondary'] ) ? 'sw-ui-col--secondary' : '',
			! empty( $column['numeric'] ) ? 'sw-ui-table__num' : '',
			! empty( $column['actions'] ) ? 'sw-ui-table__actions' : ''
		);
	}
}
