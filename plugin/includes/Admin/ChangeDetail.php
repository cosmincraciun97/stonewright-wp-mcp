<?php
/**
 * The drawer of the Changes page: one change, its diff, its facts and its rollbacks.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

use Stonewright\WpMcp\Admin\Ui\Badge;
use Stonewright\WpMcp\Admin\Ui\Button;
use Stonewright\WpMcp\Admin\Ui\DiffView;
use Stonewright\WpMcp\Admin\Ui\Html;
use Stonewright\WpMcp\Admin\Ui\KvList;
use Stonewright\WpMcp\Admin\Ui\Notice;
use Stonewright\WpMcp\Admin\Ui\Tabs;
use Stonewright\WpMcp\Admin\Ui\UtcTime;
use Stonewright\WpMcp\Security\ChangeLedger;
use Stonewright\WpMcp\Support\Diff\ChangeDiff;

/**
 * Builds the dialog for one ledger row. It is server-rendered for one change per request: the diff is computed
 * here, through ChangeDiff, and no other row's content is read. It prints what the diff engines return and facts
 * of the row; it never prints an image, a blob name or a full hash.
 *
 * The three views (Diff, Details, History) are all in the dialog. The tabs are links, so without script a tab
 * loads the page with that view shown; with script sw-ui.js switches in place and keeps the choice in the address.
 *
 * Undo and Redo are in the footer, with the confirmation dialog that ChangeUndo builds beside the drawer.
 */
final class ChangeDetail {

	public const DRAWER_ID = 'sw-changes-drawer';

	private const PREFIX = 'sw-changes';

	public const VIEWS = [ 'diff', 'details', 'history' ];

	/** How many levels of rollbacks and redos under a change the History tab lists. */
	private const HISTORY_DEPTH = 5;

	/** How many rollbacks and redos the History tab lists in all. */
	private const HISTORY_NODES = 40;

	/**
	 * @param array<string, mixed>  $row   A row of ChangeLedger.
	 * @param array<string, string> $carry The filters of the list, kept in every link so closing the drawer returns to the same list.
	 * @param bool                  $undo_open Whether the address asked for the Undo dialog, which is then printed open.
	 */
	public static function render( array $row, string $view, array $carry, bool $undo_open = false ): string {
		$id   = (string) $row['change_id'];
		$view = in_array( $view, self::VIEWS, true ) ? $view : 'diff';

		$tabs = Tabs::list(
			[
				[ 'id' => 'diff', 'label' => __( 'Diff', 'stonewright' ), 'href' => ChangesPage::url( $carry + [ 'change' => $id ] ) ],
				[ 'id' => 'details', 'label' => __( 'Details', 'stonewright' ), 'href' => ChangesPage::url( $carry + [ 'change' => $id, 'view' => 'details' ] ) ],
				[ 'id' => 'history', 'label' => __( 'History', 'stonewright' ), 'href' => ChangesPage::url( $carry + [ 'change' => $id, 'view' => 'history' ] ) ],
			],
			$view,
			[ 'label' => __( 'Views of this change', 'stonewright' ), 'prefix' => self::PREFIX, 'param' => 'view' ]
		);
		$panels = Tabs::panel( self::PREFIX, 'diff', self::diff_panel( $row ), 'diff' === $view )
			. Tabs::panel( self::PREFIX, 'details', self::details_panel( $row ), 'details' === $view )
			. Tabs::panel( self::PREFIX, 'history', self::history_panel( $row, $carry ), 'history' === $view );

		$title  = sprintf( /* translators: %s: short change id */ __( 'Change %s', 'stonewright' ), ChangeLabels::short_id( $id ) );
		$header = Html::element(
			'div',
			[ 'class' => 'sw-ui-dialog__header sw-ui-dialog__header--bar' ],
			Html::element( 'h2', [ 'class' => 'sw-ui-dialog__title', 'id' => self::DRAWER_ID . '-title' ], Html::text( $title ) )
			. Button::render(
				__( 'Close', 'stonewright' ),
				[
					'size'      => 'sm',
					'icon'      => 'x',
					'icon_only' => true,
					'context'   => __( 'change details', 'stonewright' ),
					'href'      => ChangesPage::url( $carry ),
					'attrs'     => [ 'data-sw-ui-dialog-close' => true, 'autofocus' => true ],
				]
			)
		);
		$body   = Html::element( 'div', [ 'class' => 'sw-ui-dialog__body' ], self::summary( $row ) . $tabs . $panels );
		$undo   = ChangeUndo::parts( $row, $carry, $undo_open );
		$footer = Html::element( 'div', [ 'class' => 'sw-ui-dialog__footer' ], $undo['control'] );

		return Html::element(
			'dialog',
			[
				'id'                       => self::DRAWER_ID,
				'class'                    => 'sw-ui-dialog sw-ui-drawer',
				'aria-labelledby'          => self::DRAWER_ID . '-title',
				'data-sw-ui-light-dismiss' => true,
				'data-sw-changes-drawer'   => true,
				'data-sw-changes-id'       => $id,
				'open'                     => true,
			],
			$header . $body . $footer
		) . $undo['dialog'];
	}

	/** @param array<string, mixed> $row */
	private static function summary( array $row ): string {
		$line = ChangeLabels::status_badge( (string) $row['status'] ) . ' '
			. Badge::tag( ChangeLabels::label_for_family( (string) $row['family'] ) ) . ' '
			. Html::element( 'strong', [], ChangeLabels::resource_html( $row ) );
		$text = '' !== (string) $row['summary'] ? Html::element( 'p', [ 'class' => 'sw-changes-summary' ], Html::text( (string) $row['summary'] ) ) : '';

		return Html::element( 'div', [ 'class' => 'sw-changes-head' ], $line ) . $text;
	}

	// -----------------------------------------------------------------------------------------------
	// Diff
	// -----------------------------------------------------------------------------------------------

	/** @param array<string, mixed> $row */
	private static function diff_panel( array $row ): string {
		$html = '';
		if ( ! $row['restorable'] ) {
			$html .= Notice::render( 'warn', sprintf( /* translators: %s: why, for example "the content was too large to store" */ __( 'Not restorable: %s.', 'stonewright' ), ChangeLabels::reason( (string) $row['restorable_reason'] ) ) );
		}

		$diff = ChangeDiff::for_row( $row );
		if ( 'ok' !== $diff['status'] ) {
			$html .= Notice::render( 'info', __( 'There is no diff to show', 'stonewright' ), $diff['message'] );

			return Html::element( 'div', [ 'class' => 'sw-changes-diff' ], $html );
		}
		if ( $diff['deleted'] ) {
			$html .= Html::element( 'p', [ 'class' => 'sw-changes-deleted' ], Html::text( __( 'Deleted. This is the content the change removed.', 'stonewright' ) ) );
		}
		foreach ( $diff['sections'] as $section ) {
			$html .= DiffView::render( $section['result'], [ 'title' => $section['title'] ] );
		}

		return Html::element( 'div', [ 'class' => 'sw-changes-diff' ], $html );
	}

	// -----------------------------------------------------------------------------------------------
	// Details
	// -----------------------------------------------------------------------------------------------

	/** @param array<string, mixed> $row */
	private static function details_panel( array $row ): string {
		$id        = (string) $row['change_id'];
		$settled   = null === $row['settled_at'] ? '' : UtcTime::render( (string) $row['settled_at'] );
		$audit_url = add_query_arg( [ 'page' => AuditLogPage::SLUG, 'change_set_id' => $id ], admin_url( 'admin.php' ) );

		$items = [
			[ 'label' => __( 'Change ID', 'stonewright' ), 'value_html' => Html::element( 'code', [], Html::text( $id ) ) ],
			[ 'label' => __( 'Kind', 'stonewright' ), 'value' => ChangeLabels::kind( (string) $row['kind'] ) ],
			[ 'label' => __( 'Family', 'stonewright' ), 'value' => ChangeLabels::label_for_family( (string) $row['family'] ) ],
			[ 'label' => __( 'Resource', 'stonewright' ), 'value_html' => Html::text( (string) $row['resource_type'] ) . ' ' . ChangeLabels::resource_html( $row ) ],
			[ 'label' => __( 'Ability', 'stonewright' ), 'value_html' => Html::element( 'code', [], Html::text( (string) $row['ability'] ) ) ],
			[ 'label' => __( 'User', 'stonewright' ), 'value' => ChangeLabels::user( (int) $row['actor'] ) ],
			[ 'label' => __( 'Client', 'stonewright' ), 'value' => '' !== (string) $row['client'] ? (string) $row['client'] : __( 'Not recorded', 'stonewright' ) ],
			[ 'label' => __( 'Status', 'stonewright' ), 'value_html' => ChangeLabels::status_badge( (string) $row['status'] ) ],
			[ 'label' => __( 'Recorded', 'stonewright' ), 'value_html' => UtcTime::render( (string) $row['created_at'] ) ],
			[ 'label' => __( 'Settled', 'stonewright' ), 'value_html' => '' !== $settled ? $settled : Html::text( __( 'Not settled', 'stonewright' ) ) ],
			[ 'label' => __( 'Content before', 'stonewright' ), 'value_html' => self::image_fact( (int) $row['before_bytes'], (string) $row['before_sha256'] ) ],
			[ 'label' => __( 'Content after', 'stonewright' ), 'value_html' => self::image_fact( (int) $row['after_bytes'], (string) $row['after_sha256'] ) ],
			[ 'label' => __( 'Restorable', 'stonewright' ), 'value' => $row['restorable'] ? __( 'Yes', 'stonewright' ) : sprintf( /* translators: %s: why */ __( 'No: %s', 'stonewright' ), ChangeLabels::reason( (string) $row['restorable_reason'] ) ) ],
			[ 'label' => __( 'Change set', 'stonewright' ), 'value_html' => '' !== (string) $row['change_set_id'] ? Html::element( 'code', [], Html::text( (string) $row['change_set_id'] ) ) : Html::text( __( 'None', 'stonewright' ) ) ],
			[ 'label' => __( 'Audit log', 'stonewright' ), 'value_html' => Html::element( 'a', [ 'class' => 'sw-ui-link', 'href' => $audit_url ], Html::text( __( 'View audit events of this change', 'stonewright' ) ) ) ],
		];

		return KvList::render( $items, [ 'label' => __( 'Facts of this change', 'stonewright' ) ] );
	}

	/** The size of an image and the start of its hash, or that there is none. Never more than twelve characters of the hash. */
	private static function image_fact( int $bytes, string $sha256 ): string {
		if ( '' === $sha256 ) {
			return Html::text( __( 'Not stored', 'stonewright' ) );
		}

		return Html::text( sprintf( /* translators: %d: size in bytes */ _n( '%d byte', '%d bytes', $bytes, 'stonewright' ), $bytes ) . ', sha256 ' )
			. Html::element( 'code', [], Html::text( substr( $sha256, 0, 12 ) ) );
	}

	// -----------------------------------------------------------------------------------------------
	// History
	// -----------------------------------------------------------------------------------------------

	/**
	 * The parent chain down to this change, then the rollbacks and redos of it and of each of them in turn, as one nested list.
	 *
	 * @param array<string, mixed>  $row
	 * @param array<string, string> $carry
	 */
	private static function history_panel( array $row, array $carry ): string {
		$id    = (string) $row['change_id'];
		$chain = ChangeLedger::chain( $id );
		if ( [] === $chain || (string) $chain[ count( $chain ) - 1 ]['change_id'] !== $id ) {
			$chain = [ $row ];
		}

		// The rollbacks and redos under this change, and the redo of a rollback under that rollback, to a fixed depth.
		$budget = self::HISTORY_NODES;
		$cut    = false;
		$inner  = self::descendants( $id, $id, $carry, 1, $budget, $cut );

		$notes = '';
		if ( 1 === count( $chain ) ) {
			$notes .= Html::element( 'p', [ 'class' => 'sw-ui-diff__where' ], Html::text( __( 'This change has no parent.', 'stonewright' ) ) );
		}
		if ( '' === $inner ) {
			$notes .= Html::element( 'p', [ 'class' => 'sw-ui-diff__where' ], Html::text( __( 'Nothing has rolled this change back or redone it.', 'stonewright' ) ) );
		}
		if ( $cut ) {
			$notes .= Html::element( 'p', [ 'class' => 'sw-ui-diff__where' ], Html::text( __( 'More rollbacks and redos exist than are shown here. Open the last one listed to see the rest.', 'stonewright' ) ) );
		}

		// The list is built from the inside out: the descendants sit under this change, and each ancestor wraps the next.
		$html = '';
		for ( $depth = count( $chain ) - 1; $depth >= 0; --$depth ) {
			$html  = self::node( $chain[ $depth ], $id, $carry, $inner );
			$inner = Html::element( 'ol', [], $html );
		}

		return $notes . Html::element( 'ol', [ 'class' => 'sw-ui-lineage', 'aria-label' => __( 'Rollbacks and redos of this change', 'stonewright' ) ], $html );
	}

	/**
	 * The rollbacks and redos that act on a change, each with those that act on it in turn, as a nested list. Bounded by
	 * depth and by the number of rows listed in all; $cut is set when something was left out.
	 *
	 * @param array<string, string> $carry
	 */
	private static function descendants( string $parent, string $current, array $carry, int $depth, int &$budget, bool &$cut ): string {
		$children = ChangeLedger::children( $parent, max( 1, $budget + 1 ) );
		$items    = '';
		foreach ( $children as $child ) {
			if ( $budget < 1 ) {
				$cut = true;
				break;
			}
			--$budget;
			$nested = '';
			if ( $depth < self::HISTORY_DEPTH ) {
				$nested = self::descendants( (string) $child['change_id'], $current, $carry, $depth + 1, $budget, $cut );
			} elseif ( [] !== ChangeLedger::children( (string) $child['change_id'], 1 ) ) {
				$cut = true;
			}
			$items .= self::node( $child, $current, $carry, $nested );
		}

		return '' === $items ? '' : Html::element( 'ol', [], $items );
	}

	/**
	 * One change in the history: its kind and status, when and by whom, and a link to its own diff.
	 *
	 * @param array<string, mixed>  $node
	 * @param array<string, string> $carry
	 */
	private static function node( array $node, string $current, array $carry, string $nested ): string {
		$id      = (string) $node['change_id'];
		$is_here = $id === $current;
		$parent  = (string) $node['parent_id'];

		$head = ChangeLabels::status_badge( (string) $node['status'] )
			. '<span class="sw-ui-visually-hidden">, </span>'
			. Html::element( 'strong', [], Html::text( ChangeLabels::kind( (string) $node['kind'] ) ) )
			. '<span class="sw-ui-visually-hidden">, </span>'
			. UtcTime::render( (string) $node['created_at'] );

		$meta = Html::element( 'code', [], Html::text( (string) $node['ability'] ) ) . ' '
			. Html::element( 'code', [ 'title' => $id ], Html::text( ChangeLabels::short_id( $id ) ) ) . ' '
			. Html::element( 'span', [], Html::text( ChangeLabels::user( (int) $node['actor'] ) ) );
		if ( '' !== $parent ) {
			$of    = 'redo' === $node['kind'] ? __( 'Redo of %s', 'stonewright' ) : __( 'Rollback of %s', 'stonewright' );
			$meta .= ' ' . Html::element( 'span', [], Html::text( sprintf( $of, ChangeLabels::short_id( $parent ) ) ) );
		}
		if ( $is_here ) {
			$meta .= ' ' . Html::element( 'strong', [], Html::text( __( 'This change', 'stonewright' ) ) );
		} else {
			$meta .= ' ' . Html::element( 'a', [ 'class' => 'sw-ui-link', 'href' => ChangesPage::url( $carry + [ 'change' => $id ] ) ], Html::text( __( 'View diff', 'stonewright' ) ) );
		}

		return Html::element(
			'li',
			[ 'class' => 'sw-ui-lineage__item', 'data-sw-changes-node' => $id, 'aria-current' => $is_here ? 'true' : null ],
			Html::element( 'div', [ 'class' => 'sw-ui-lineage__node' ], $head ) . Html::element( 'p', [ 'class' => 'sw-ui-lineage__meta' ], $meta ) . $nested
		);
	}
}
