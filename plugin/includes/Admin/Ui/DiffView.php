<?php
/**
 * Before and after of one change, as the admin UI layer draws it.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Ui;

/**
 * Prints a result of the diff engine (Support\Diff: TextDiff, BlockDiff, ElementorTreeDiff, FieldDiff).
 *
 * - text: a table of lines (a line is a span, not a code element: the shell's chip style for inline code would
 *   repaint it) on the layer's dark surface with the old and the new line number, a marker (+, -
 *   or none) and the line. The marker is also words for assistive technology, so colour is never the only cue.
 *   The table scrolls inside its own focusable, named region; the page never scrolls sideways.
 * - blocks: one list item per added, removed, moved or changed block, a changed block with its attribute
 *   changes and the text diff of its own markup.
 * - elementor: one disclosure per element with a table of its setting changes.
 * - fields: one table of key paths with the value before and after.
 *
 * Every string is text and is escaped here: a line, a value, a block name, a path and the title. The result is
 * already masked by the engine; this class prints what it is given and never reads the image behind it. A
 * result that was cut or masked says so in a callout above the diff, and a result that could not be computed
 * says why instead of printing a table. A result of another kind prints nothing.
 */
final class DiffView {

	/**
	 * @phpstan-param array<string, mixed> $result A result of one of the diff classes.
	 * @phpstan-param array{title?: string, nested?: bool} $args "title" names the diff (a file, a section);
	 *        "nested" drops the callouts, for a diff printed inside a block item that has its own.
	 */
	public static function render( array $result, array $args = [] ): string {
		$kind   = is_string( $result['kind'] ?? null ) ? $result['kind'] : '';
		$title  = (string) ( $args['title'] ?? '' );
		$nested = ! empty( $args['nested'] );
		if ( ! in_array( $kind, [ 'text', 'blocks', 'elementor', 'fields' ], true ) ) {
			return '';
		}

		$notes = $nested ? '' : self::notes( $result );
		$status = (string) ( $result['status'] ?? 'ok' );
		if ( 'ok' !== $status ) {
			return self::wrap( $kind, 'sw-ui-diff-view', $notes . self::status_note( $status, $result, $kind, $title ), true );
		}

		$body = match ( $kind ) {
			'text'      => self::text( $result, $title ),
			'blocks'    => self::blocks( $result, $title ),
			'elementor' => self::elements( $result, $title ),
			default     => self::fields( $result, $title ),
		};
		return self::wrap( $kind, 'sw-ui-diff-view', $notes . $body, false );
	}

	/** The callouts for what was cut and what was masked: [] when the result is complete. */
	private static function notes( array $result ): string {
		$html = '';
		if ( ! empty( $result['truncated'] ) && 'ok' === (string) ( $result['status'] ?? 'ok' ) ) {
			$cut  = is_array( $result['cut'] ?? null ) ? $result['cut'] : [];
			$text = __( 'Only part of the diff is shown to keep the page small.', 'stonewright' );
			$left = [];
			foreach ( [
				'hunks'         => [ __( '%d hunk', 'stonewright' ), __( '%d hunks', 'stonewright' ) ],
				'changed_lines' => [ __( '%d changed line', 'stonewright' ), __( '%d changed lines', 'stonewright' ) ],
				'items'         => [ __( '%d block change', 'stonewright' ), __( '%d block changes', 'stonewright' ) ],
				'attrs'         => [ __( '%d attribute change', 'stonewright' ), __( '%d attribute changes', 'stonewright' ) ],
				'elements'      => [ __( '%d element', 'stonewright' ), __( '%d elements', 'stonewright' ) ],
				'fields'        => [ __( '%d field', 'stonewright' ), __( '%d fields', 'stonewright' ) ],
			] as $key => $forms ) {
				$count = (int) ( $cut[ $key ] ?? 0 );
				if ( $count > 0 ) {
					$left[] = sprintf( 1 === $count ? $forms[0] : $forms[1], $count );
				}
			}
			if ( [] !== $left ) {
				/* translators: %s: what was left out, for example "2 hunks and 40 changed lines" */
				$text .= ' ' . sprintf( __( '%s were left out.', 'stonewright' ), self::join_words( $left ) );
			}
			$html .= Notice::callout( 'warn', __( 'Not everything is shown', 'stonewright' ), $text );
		}
		if ( (int) ( $result['masked'] ?? 0 ) > 0 ) {
			$html .= Notice::callout( 'info', __( 'Values were masked', 'stonewright' ), __( 'A value that looks like a password, key or token is replaced by [redacted]. Stonewright never shows it.', 'stonewright' ) );
		}
		return $html;
	}

	/**
	 * @param array<string, mixed> $result
	 */
	private static function status_note( string $status, array $result, string $kind, string $title ): string {
		unset( $kind );
		$message = trim( (string) ( $result['message'] ?? '' ) );
		if ( 'identical' === $status ) {
			return Html::element( 'p', [ 'class' => 'sw-ui-diff__empty' ], Html::text( '' !== $title ? sprintf( /* translators: %s: name of the part that was compared, for example a file name */ __( 'No difference in %s.', 'stonewright' ), $title ) : __( 'No difference between the two versions.', 'stonewright' ) ) );
		}
		$variant = in_array( $status, [ 'eol_only', 'binary' ], true ) ? 'info' : 'warn';
		$heading = match ( $status ) {
			'eol_only' => __( 'Only line endings changed', 'stonewright' ),
			'binary'   => __( 'Not shown: binary content', 'stonewright' ),
			default    => __( 'The diff is not shown', 'stonewright' ),
		};
		return Notice::callout( $variant, $heading, '' !== $message ? $message : __( 'The two versions could not be compared.', 'stonewright' ) );
	}

	private static function wrap( string $kind, string $class, string $inner, bool $empty ): string {
		return Html::element( 'div', [ 'class' => Html::classes( $class, $empty ? 'sw-ui-diff-view--empty' : '' ), 'data-sw-ui-diff-view' => $kind ], $inner );
	}

	// -----------------------------------------------------------------------------------------------
	// Text
	// -----------------------------------------------------------------------------------------------

	/**
	 * @param array<string, mixed> $result
	 */
	private static function text( array $result, string $title ): string {
		$summary = is_array( $result['summary'] ?? null ) ? $result['summary'] : [];
		$changed = (int) ( $summary['changed'] ?? 0 );
		$plus    = (int) ( $summary['added'] ?? 0 ) + $changed;
		$minus   = (int) ( $summary['removed'] ?? 0 ) + $changed;

		$head = Html::element( 'span', [ 'class' => 'sw-ui-diff__title' ], Html::text( $title ) )
			. Html::element(
				'span',
				[ 'class' => 'sw-ui-diff__stats' ],
				self::stat( 'add', '+' . $plus, sprintf( /* translators: %d: number of lines */ _n( '%d line added', '%d lines added', $plus, 'stonewright' ), $plus ) )
				. self::stat( 'del', '-' . $minus, sprintf( /* translators: %d: number of lines */ _n( '%d line removed', '%d lines removed', $minus, 'stonewright' ), $minus ) )
			);

		$rows = '';
		foreach ( is_array( $result['hunks'] ?? null ) ? $result['hunks'] : [] as $hunk ) {
			if ( ! is_array( $hunk ) ) {
				continue;
			}
			$header = sprintf(
				'@@ -%d,%d +%d,%d @@',
				(int) ( $hunk['old_start'] ?? 0 ),
				(int) ( $hunk['old_lines'] ?? 0 ),
				(int) ( $hunk['new_start'] ?? 0 ),
				(int) ( $hunk['new_lines'] ?? 0 )
			);
			$rows .= Html::element( 'tr', [], Html::element( 'td', [ 'class' => 'sw-ui-diff__hunk-cell', 'colspan' => '4' ], Html::text( $header ) ) );
			foreach ( is_array( $hunk['lines'] ?? null ) ? $hunk['lines'] : [] as $line ) {
				if ( is_array( $line ) ) {
					$rows .= self::line( $line );
				}
			}
			if ( ! empty( $hunk['truncated'] ) ) {
				$rows .= Html::element( 'tr', [], Html::element( 'td', [ 'class' => 'sw-ui-diff__hunk-cell', 'colspan' => '4' ], Html::text( __( '... more lines of this hunk are not shown', 'stonewright' ) ) ) );
			}
		}

		$header_row = '';
		foreach ( [ __( 'Old line', 'stonewright' ), __( 'New line', 'stonewright' ), __( 'Change', 'stonewright' ), __( 'Text', 'stonewright' ) ] as $label ) {
			$header_row .= Html::element( 'th', [ 'scope' => 'col' ], Html::text( $label ) );
		}
		$table = Html::element(
			'table',
			[ 'class' => 'sw-ui-diff__table' ],
			Html::element( 'caption', [ 'class' => 'sw-ui-visually-hidden' ], Html::text( '' !== $title ? sprintf( /* translators: %s: name of the compared file or section */ __( 'Changed lines of %s', 'stonewright' ), $title ) : __( 'Changed lines', 'stonewright' ) ) )
			. Html::element( 'thead', [ 'class' => 'sw-ui-visually-hidden' ], Html::element( 'tr', [], $header_row ) )
			. Html::element( 'tbody', [], $rows )
		);
		$body  = Html::element(
			'div',
			[
				'class'      => 'sw-ui-diff__body',
				'role'       => 'region',
				'tabindex'   => '0',
				'aria-label' => '' !== $title ? sprintf( /* translators: %s: name of the compared file or section */ __( '%s: changed lines', 'stonewright' ), $title ) : __( 'Changed lines', 'stonewright' ),
			],
			$table
		);

		return Html::element(
			'div',
			[ 'class' => 'sw-ui-diff sw-ui-diff--text', 'data-sw-ui-diff' => 'text' ],
			Html::element( 'div', [ 'class' => 'sw-ui-diff__head' ], $head ) . $body
		);
	}

	private static function stat( string $kind, string $shown, string $words ): string {
		return Html::element(
			'span',
			[ 'class' => 'sw-ui-diff__stat--' . $kind ],
			Html::element( 'span', [ 'aria-hidden' => 'true' ], Html::text( $shown ) ) . Html::element( 'span', [ 'class' => 'sw-ui-visually-hidden' ], Html::text( $words ) )
		);
	}

	/**
	 * @param array<string, mixed> $line
	 */
	private static function line( array $line ): string {
		$op = (string) ( $line['op'] ?? 'eq' );
		if ( 'add' === $op ) {
			$kind   = 'add';
			$marker = '+';
		} elseif ( 'del' === $op ) {
			$kind   = 'del';
			$marker = '-';
		} else {
			$kind   = 'ctx';
			$marker = '';
		}
		$old = isset( $line['old'] ) && is_int( $line['old'] ) ? (string) $line['old'] : '';
		$new = isset( $line['new'] ) && is_int( $line['new'] ) ? (string) $line['new'] : '';
		$at  = 'del' === $kind ? $old : $new;
		$words = match ( $kind ) {
			'add'   => sprintf( /* translators: %s: line number */ __( 'Added line %s: ', 'stonewright' ), $at ),
			'del'   => sprintf( /* translators: %s: line number */ __( 'Removed line %s: ', 'stonewright' ), $at ),
			default => sprintf( /* translators: %s: line number */ __( 'Unchanged line %s: ', 'stonewright' ), $at ),
		};
		$mark = ( '' !== $marker ? Html::element( 'span', [ 'aria-hidden' => 'true' ], Html::text( $marker ) ) : '' )
			. Html::element( 'span', [ 'class' => 'sw-ui-visually-hidden' ], Html::text( $words ) );
		$text = Html::element( 'span', [ 'class' => 'sw-ui-diff__code' ], Html::text( (string) ( $line['text'] ?? '' ) ) );
		if ( ! empty( $line['no_eol'] ) ) {
			$text .= Html::element( 'span', [ 'class' => 'sw-ui-diff__eol' ], Html::text( __( 'No newline at end of file', 'stonewright' ) ) );
		}

		return Html::element(
			'tr',
			[ 'class' => Html::classes( 'sw-ui-diff__line', 'ctx' === $kind ? '' : 'sw-ui-diff__line--' . $kind ) ],
			Html::element( 'td', [ 'class' => 'sw-ui-diff__num', 'aria-hidden' => 'true' ], Html::text( $old ) )
			. Html::element( 'td', [ 'class' => 'sw-ui-diff__num', 'aria-hidden' => 'true' ], Html::text( $new ) )
			. Html::element( 'td', [ 'class' => 'sw-ui-diff__mark' ], $mark )
			. Html::element( 'td', [ 'class' => 'sw-ui-diff__text' ], $text )
		);
	}

	// -----------------------------------------------------------------------------------------------
	// Blocks
	// -----------------------------------------------------------------------------------------------

	/**
	 * @param array<string, mixed> $result
	 */
	private static function blocks( array $result, string $title ): string {
		$items = '';
		foreach ( is_array( $result['items'] ?? null ) ? $result['items'] : [] as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$op     = (string) ( $item['op'] ?? 'changed' );
			$where  = self::block_path( is_array( $item['path'] ?? null ) ? $item['path'] : [] );
			$detail = '';
			if ( 'moved' === $op && is_array( $item['from'] ?? null ) ) {
				$where .= ' ' . sprintf( /* translators: %s: where the block was, for example "Block 3" */ __( '(was %s)', 'stonewright' ), self::block_path( $item['from'] ) );
			}
			if ( in_array( $op, [ 'added', 'removed' ], true ) && (int) ( $item['descendants'] ?? 0 ) > 0 ) {
				$count   = (int) $item['descendants'];
				$where  .= ' ' . sprintf( /* translators: %d: number of blocks inside */ _n( 'with %d block inside', 'with %d blocks inside', $count, 'stonewright' ), $count );
			}
			if ( 'changed' === $op ) {
				if ( is_array( $item['attrs'] ?? null ) && [] !== $item['attrs'] ) {
					$detail .= self::field_table( $item['attrs'], sprintf( /* translators: %s: block name */ __( 'Attribute changes of %s', 'stonewright' ), (string) ( $item['name'] ?? '' ) ) );
				}
				if ( is_array( $item['text'] ?? null ) && 'text' === ( $item['text']['kind'] ?? '' ) ) {
					$detail .= self::render( $item['text'], [ 'title' => __( 'Block markup', 'stonewright' ), 'nested' => true ] );
				}
			}
			$items .= Html::element(
				'li',
				[ 'class' => 'sw-ui-diff__item sw-ui-diff__item--' . $op ],
				Html::element(
					'div',
					[ 'class' => 'sw-ui-diff__summary' ],
					self::op_badge( $op ) . ' ' . Html::element( 'code', [], Html::text( (string) ( $item['name'] ?? '' ) ) ) . ' ' . Html::element( 'span', [ 'class' => 'sw-ui-diff__where' ], Html::text( $where ) )
				) . $detail
			);
		}

		return Html::element(
			'div',
			[ 'class' => 'sw-ui-diff', 'data-sw-ui-diff' => 'blocks' ],
			self::list_head( $title, $result ) . Html::element( 'ol', [ 'class' => 'sw-ui-diff__items' ], $items )
		);
	}

	/** @param array<int, mixed> $path */
	private static function block_path( array $path ): string {
		$parts = [];
		foreach ( array_values( $path ) as $depth => $index ) {
			$number  = (int) $index + 1;
			$parts[] = 0 === $depth
				? sprintf( /* translators: %d: position of the block in the content, from 1 */ __( 'Block %d', 'stonewright' ), $number )
				: sprintf( /* translators: %d: position of the inner block, from 1 */ __( 'inner block %d', 'stonewright' ), $number );
		}
		return [] === $parts ? __( 'Block', 'stonewright' ) : implode( ', ', $parts );
	}

	// -----------------------------------------------------------------------------------------------
	// Elementor elements
	// -----------------------------------------------------------------------------------------------

	/**
	 * @param array<string, mixed> $result
	 */
	private static function elements( array $result, string $title ): string {
		$items = '';
		foreach ( is_array( $result['elements'] ?? null ) ? $result['elements'] : [] as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$op      = (string) ( $element['op'] ?? 'changed' );
			$label   = '' !== (string) ( $element['label'] ?? '' ) ? ' ' . Html::element( 'span', [ 'class' => 'sw-ui-diff__where' ], Html::text( '"' . (string) $element['label'] . '"' ) ) : '';
			$summary = self::op_badge( $op ) . ' ' . Html::element( 'code', [], Html::text( (string) ( $element['type'] ?? '' ) ) ) . $label
				. ' ' . Html::element( 'span', [ 'class' => 'sw-ui-diff__where' ], Html::text( sprintf( /* translators: %s: Elementor element id */ __( 'id %s', 'stonewright' ), (string) ( $element['id'] ?? '' ) ) ) );
			if ( 'moved' === $op ) {
				$summary .= ' ' . Html::element( 'span', [ 'class' => 'sw-ui-diff__where' ], Html::text( 'parent' === ( $element['reason'] ?? '' ) ? __( 'moved to another parent', 'stonewright' ) : __( 'moved within its parent', 'stonewright' ) ) );
			}

			$detail = '';
			foreach ( [ 'settings' => __( 'Settings', 'stonewright' ), 'other' => __( 'Other properties', 'stonewright' ) ] as $key => $label_text ) {
				if ( is_array( $element[ $key ] ?? null ) && [] !== $element[ $key ] ) {
					$detail .= self::field_table( $element[ $key ], sprintf( /* translators: 1: kind of properties, 2: element id */ __( '%1$s of element %2$s', 'stonewright' ), $label_text, (string) ( $element['id'] ?? '' ) ) );
				}
			}
			if ( (int) ( $element['fields_cut'] ?? 0 ) > 0 ) {
				$cut     = (int) $element['fields_cut'];
				$detail .= Html::element( 'p', [ 'class' => 'sw-ui-diff__where' ], Html::text( sprintf( /* translators: %d: number of changes left out */ _n( '%d more change is not shown.', '%d more changes are not shown.', $cut, 'stonewright' ), $cut ) ) );
			}

			if ( '' === $detail ) {
				$items .= Html::element( 'li', [ 'class' => 'sw-ui-diff__item sw-ui-diff__item--' . $op ], Html::element( 'div', [ 'class' => 'sw-ui-diff__summary' ], $summary ) );
				continue;
			}
			$items .= Html::element(
				'li',
				[ 'class' => 'sw-ui-diff__item sw-ui-diff__item--' . $op ],
				Html::element(
					'details',
					[ 'class' => 'sw-ui-disclosure sw-ui-diff__element', 'open' => true ],
					Html::element( 'summary', [], Icon::render( 'chev-r' ) . $summary ) . Html::element( 'div', [ 'class' => 'sw-ui-disclosure__body' ], $detail )
				)
			);
		}

		return Html::element(
			'div',
			[ 'class' => 'sw-ui-diff', 'data-sw-ui-diff' => 'elementor' ],
			self::list_head( $title, $result ) . Html::element( 'ol', [ 'class' => 'sw-ui-diff__items' ], $items )
		);
	}

	// -----------------------------------------------------------------------------------------------
	// Fields
	// -----------------------------------------------------------------------------------------------

	/**
	 * @param array<string, mixed> $result
	 */
	private static function fields( array $result, string $title ): string {
		return Html::element(
			'div',
			[ 'class' => 'sw-ui-diff', 'data-sw-ui-diff' => 'fields' ],
			self::list_head( $title, $result ) . self::field_table( is_array( $result['fields'] ?? null ) ? $result['fields'] : [], '' !== $title ? $title : __( 'Changed fields', 'stonewright' ) )
		);
	}

	/**
	 * A table of key path changes: the shape of FieldDiff fields, also used for attributes and settings.
	 *
	 * @param array<int|string, mixed> $fields
	 */
	private static function field_table( array $fields, string $caption ): string {
		$rows = [];
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$op     = (string) ( $field['op'] ?? 'changed' );
			$rows[] = [
				'path'   => [ 'html' => Html::element( 'code', [], Html::text( (string) ( $field['path'] ?? '' ) ) ) ],
				'change' => [ 'html' => self::op_badge( $op ) ],
				'before' => [ 'html' => self::value( $field['before'] ?? null ) ],
				'after'  => [ 'html' => self::value( $field['after'] ?? null ) ],
			];
		}

		return Table::render(
			[
				[ 'key' => 'path', 'label' => __( 'Field', 'stonewright' ), 'primary' => true ],
				[ 'key' => 'change', 'label' => __( 'Change', 'stonewright' ) ],
				[ 'key' => 'before', 'label' => __( 'Before', 'stonewright' ) ],
				[ 'key' => 'after', 'label' => __( 'After', 'stonewright' ) ],
			],
			$rows,
			[ 'caption' => $caption ]
		);
	}

	private static function value( mixed $value ): string {
		if ( null === $value ) {
			return Html::element( 'span', [ 'class' => 'sw-ui-diff__none' ], Html::text( __( 'Not set', 'stonewright' ) ) );
		}
		return Html::element( 'code', [ 'class' => 'sw-ui-diff__value' ], Html::text( is_scalar( $value ) ? (string) $value : '' ) );
	}

	// -----------------------------------------------------------------------------------------------
	// Shared
	// -----------------------------------------------------------------------------------------------

	/**
	 * @param array<string, mixed> $result
	 */
	private static function list_head( string $title, array $result ): string {
		$summary = is_array( $result['summary'] ?? null ) ? $result['summary'] : [];
		$parts   = [];
		foreach ( [
			'added'   => [ __( '%d added', 'stonewright' ), __( '%d added', 'stonewright' ) ],
			'removed' => [ __( '%d removed', 'stonewright' ), __( '%d removed', 'stonewright' ) ],
			'moved'   => [ __( '%d moved', 'stonewright' ), __( '%d moved', 'stonewright' ) ],
			'changed' => [ __( '%d changed', 'stonewright' ), __( '%d changed', 'stonewright' ) ],
		] as $key => $forms ) {
			$count = (int) ( $summary[ $key ] ?? 0 );
			if ( $count > 0 ) {
				$parts[] = sprintf( $forms[0], $count );
			}
		}
		return Html::element(
			'div',
			[ 'class' => 'sw-ui-diff__head' ],
			Html::element( 'span', [ 'class' => 'sw-ui-diff__title' ], Html::text( $title ) ) . Html::element( 'span', [ 'class' => 'sw-ui-diff__where' ], Html::text( implode( ', ', $parts ) ) )
		);
	}

	private static function op_badge( string $op ): string {
		return match ( $op ) {
			'added'   => Badge::render( __( 'Added', 'stonewright' ), [ 'variant' => 'ok', 'icon' => 'plus' ] ),
			'removed' => Badge::render( __( 'Removed', 'stonewright' ), [ 'variant' => 'danger', 'icon' => 'x' ] ),
			'moved'   => Badge::render( __( 'Moved', 'stonewright' ), [ 'variant' => 'info', 'icon' => 'refresh' ] ),
			default   => Badge::render( __( 'Changed', 'stonewright' ), [ 'variant' => 'warn', 'icon' => 'edit' ] ),
		};
	}

	/** @param list<string> $parts */
	private static function join_words( array $parts ): string {
		if ( count( $parts ) < 2 ) {
			return (string) ( $parts[0] ?? '' );
		}
		$last = (string) array_pop( $parts );
		/* translators: 1: list of items, 2: last item, for example "2 hunks and 40 changed lines" */
		return sprintf( __( '%1$s and %2$s', 'stonewright' ), implode( ', ', $parts ), $last );
	}
}
