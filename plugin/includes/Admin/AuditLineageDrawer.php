<?php
/**
 * Audit Log change-set lineage: the Change set cell, the filter chip and the drawer.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

use Stonewright\WpMcp\Admin\Ui\Badge;
use Stonewright\WpMcp\Admin\Ui\Button;
use Stonewright\WpMcp\Admin\Ui\Html;
use Stonewright\WpMcp\Admin\Ui\Icon;
use Stonewright\WpMcp\Security\AuditLog;
use Stonewright\WpMcp\Security\ChangeSetLineage;

/**
 * Mounts the repair lineage on the Audit Log through two page hooks.
 *
 * `stonewright_audit_log_toolbar` runs under the filter form with the filters, the
 * rows of the page and the incident states: it prints the active change set chip
 * and the drawer. `stonewright_audit_log_change_set_cell` runs once per row that
 * names a change set and prints the Change set cell. The page renderer knows
 * nothing else about lineage. The drawer is a native modal dialog filled with
 * server-rendered panels, one per chain of change sets; a script only opens it.
 */
final class AuditLineageDrawer {

	public const DRAWER_ID = 'sw-audit-lineage-drawer';

	/** Children a branch shows before it folds into a details element. */
	public const BRANCH_LIMIT = 5;

	/** Nodes a panel draws before the rest wait behind "Show more". */
	public const MAX_NODES = 50;

	/** Distinct chains one page prints a panel for. */
	public const MAX_PANELS = 25;

	/** Nesting levels that indent; deeper nodes keep the third level's indent and say which level they are. */
	public const INDENT_LEVELS = 3;

	/** Longest id the Change set cell shows whole. */
	private const SHORT_ID_LIMIT = 14;

	/**
	 * What the toolbar hook prepared for the cells that follow it.
	 *
	 * @var array{filters: array<string, mixed>, panels: array<string, string>, trees: array<string, array<string, mixed>>, states: array<string, string>}
	 */
	private static array $context = [
		'filters' => [],
		'panels'  => [],
		'trees'   => [],
		'states'  => [],
	];

	public static function register(): void {
		add_action( 'stonewright_audit_log_toolbar', [ self::class, 'render_toolbar' ], 10, 3 );
		add_action( 'stonewright_audit_log_change_set_cell', [ self::class, 'render_cell' ], 10, 1 );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
	}

	public static function enqueue( string $hook_suffix = '' ): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( (string) wp_unslash( (string) $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( AuditLogPage::SLUG !== $page && ! str_contains( $hook_suffix, AuditLogPage::SLUG ) ) {
			return;
		}
		$version = defined( 'STONEWRIGHT_VERSION' ) ? (string) STONEWRIGHT_VERSION : '0.1.0';
		$base    = defined( 'STONEWRIGHT_URL' ) ? (string) STONEWRIGHT_URL : '';
		wp_enqueue_script(
			'stonewright-admin-audit-lineage',
			$base . 'assets/admin/pages/audit-lineage.js',
			[ 'stonewright-ui' ],
			$version,
			true
		);
	}

	/**
	 * @param array<string, mixed>             $filters         Filters in force on the page.
	 * @param array<int, array<string, mixed>> $rows            Rows the page lists.
	 * @param array<string, string>            $incident_states Incident state by incident id.
	 */
	public static function render_toolbar( array $filters = [], array $rows = [], array $incident_states = [] ): void {
		self::$context = self::prepare( $filters, $rows, $incident_states );
		self::render_chip( $filters );
		if ( [] !== self::$context['trees'] ) {
			self::render_drawer();
		}
	}

	/**
	 * @param array<string, mixed> $row Audit row that names a change set.
	 */
	public static function render_cell( array $row = [] ): void {
		$id = (string) ( $row['change_set_id'] ?? '' );
		if ( '' === $id ) {
			return;
		}
		$named = sprintf( /* translators: %s: change set id */ __( 'change set ID %s', 'stonewright' ), $id );
		$of    = sprintf( /* translators: %s: change set id */ __( 'of change set %s', 'stonewright' ), $id );
		$html  = '<strong>' . esc_html__( 'Change set:', 'stonewright' ) . '</strong>'
			. '<code title="' . esc_attr( $id ) . '">' . esc_html( self::short_id( $id ) ) . '</code>'
			. Button::render( __( 'Copy', 'stonewright' ), [ 'size' => 'xs', 'variant' => 'tertiary', 'context' => $named, 'attrs' => [ 'data-sw-ui-copy-text' => $id ] ] );
		if ( (string) ( self::$context['filters']['change_set_id'] ?? '' ) !== $id ) {
			$html .= Button::render( __( 'Show rows', 'stonewright' ), [ 'size' => 'xs', 'variant' => 'tertiary', 'href' => self::rows_url( $id ), 'context' => $of ] );
		}
		$panel = self::$context['panels'][ $id ] ?? '';
		if ( '' !== $panel ) {
			$html .= Button::render(
				__( 'Lineage', 'stonewright' ),
				[
					'size'    => 'xs',
					'context' => $of,
					'attrs'   => [
						'hidden'                 => true,
						'data-sw-lineage-open'   => self::panel_id( $panel ),
						'data-sw-lineage-for'    => $id,
						'data-sw-lineage-title'  => sprintf( /* translators: %s: short change set id */ __( 'Change set %s', 'stonewright' ), self::short_id( $id ) ),
						'data-sw-ui-dialog-open' => '#' . self::DRAWER_ID,
						'aria-haspopup'          => 'dialog',
						'aria-controls'          => self::DRAWER_ID,
					],
				]
			);
		}
		echo '<div class="sw-audit-lineage-cell" data-sw-lineage-cell>' . $html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup built by the Ui helpers and escaped above.
	}

	/**
	 * The lineage of every change set the page lists, one panel per chain.
	 *
	 * @param array<string, mixed>             $filters
	 * @param array<int, array<string, mixed>> $rows
	 * @param array<string, string>            $incident_states
	 * @return array{filters: array<string, mixed>, panels: array<string, string>, trees: array<string, array<string, mixed>>, states: array<string, string>}
	 */
	private static function prepare( array $filters, array $rows, array $incident_states ): array {
		$context = [
			'filters' => $filters,
			'panels'  => [],
			'trees'   => [],
			'states'  => $incident_states,
		];
		$graph   = self::graph_for( $rows );
		foreach ( $rows as $row ) {
			$id = (string) ( $row['change_set_id'] ?? '' );
			if ( '' === $id || isset( $context['panels'][ $id ] ) ) {
				continue;
			}
			$context['panels'][ $id ] = '';
			$tree                     = ChangeSetLineage::tree( $graph, $id );
			$node                     = $tree['nodes'][ $id ] ?? null;
			if ( null === $node || ! self::has_lineage( $tree, $node ) ) {
				continue;
			}
			$key = substr( md5( implode( '|', $tree['order'] ) ), 0, 10 );
			if ( ! isset( $context['trees'][ $key ] ) ) {
				if ( count( $context['trees'] ) >= self::MAX_PANELS ) {
					continue;
				}
				$context['trees'][ $key ] = $tree;
			}
			$context['panels'][ $id ] = $key;
		}

		return $context;
	}

	/**
	 * Whether a change set has anything to show beyond itself: relatives, a change it
	 * supersedes or a parent that left the log.
	 *
	 * @param array<string, mixed> $tree
	 * @param array<string, mixed> $node
	 */
	private static function has_lineage( array $tree, array $node ): bool {
		return (int) $tree['total'] > 1 || '' !== (string) $node['supersedes'] || ( '' !== (string) $node['parent'] && 'present' !== $node['parent'] );
	}

	/**
	 * The change sets linked to the rows on the page by repair links.
	 *
	 * One bounded lookup per hop, three hops at most; a page whose rows name no
	 * change set asks for nothing.
	 *
	 * @param array<int, array<string, mixed>> $rows
	 * @return array{nodes: array<string, array<string, mixed>>}
	 */
	private static function graph_for( array $rows ): array {
		$frontier = [];
		foreach ( $rows as $row ) {
			foreach ( [ 'change_set_id', 'repair_of' ] as $key ) {
				$id = (string) ( $row[ $key ] ?? '' );
				if ( '' !== $id ) {
					$frontier[ $id ] = $id;
				}
			}
		}
		$queried = [];
		$found   = [];
		for ( $hop = 0; $hop < 3 && [] !== $frontier; ++$hop ) {
			$batch    = array_slice( array_values( $frontier ), 0, 100 );
			$queried += array_fill_keys( $batch, true );
			foreach ( AuditLog::lineage_rows( $batch ) as $linked ) {
				$found[ (int) ( $linked['id'] ?? 0 ) ] = $linked;
			}
			$frontier = [];
			foreach ( $found as $linked ) {
				foreach ( [ 'change_set_id', 'repair_of' ] as $key ) {
					$id = (string) ( $linked[ $key ] ?? '' );
					if ( '' !== $id && ! isset( $queried[ $id ] ) ) {
						$frontier[ $id ] = $id;
					}
				}
			}
		}

		return ChangeSetLineage::graph( array_values( $found ) );
	}

	/**
	 * @param array<string, mixed> $filters
	 */
	private static function render_chip( array $filters ): void {
		$id = (string) ( $filters['change_set_id'] ?? '' );
		if ( '' === $id ) {
			return;
		}
		$remaining = array_diff_key( $filters, [ 'change_set_id' => true ] );
		$remove    = add_query_arg( array_merge( [ 'page' => AuditLogPage::SLUG ], $remaining ), admin_url( 'admin.php' ) );

		echo '<div class="sw-audit-lineage-chips" role="group" aria-label="' . esc_attr( __( 'Active change set filter', 'stonewright' ) ) . '">';
		echo Badge::render( __( 'Change set', 'stonewright' ), [ 'variant' => 'accent', 'class' => 'sw-audit-lineage-chip' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup built by the Ui helpers.
		echo ' <code title="' . esc_attr( $id ) . '">' . esc_html( self::short_id( $id ) ) . '</code>';
		echo Button::render( __( 'Remove change set filter', 'stonewright' ), [ 'size' => 'xs', 'icon' => 'x', 'icon_only' => true, 'href' => $remove ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup built by the Ui helpers.
		echo '</div>';
	}

	private static function render_drawer(): void {
		echo '<dialog id="' . esc_attr( self::DRAWER_ID ) . '" class="sw-ui-dialog sw-ui-drawer" aria-labelledby="sw-audit-lineage-title" data-sw-ui-light-dismiss data-sw-lineage-drawer>';
		echo '<div class="sw-ui-dialog__header sw-ui-dialog__header--bar">';
		echo '<h2 class="sw-ui-dialog__title" id="sw-audit-lineage-title">' . esc_html__( 'Change set lineage', 'stonewright' ) . '</h2>';
		echo Button::render( __( 'Close', 'stonewright' ), [ 'size' => 'sm', 'icon' => 'x', 'icon_only' => true, 'context' => __( 'change set lineage', 'stonewright' ), 'attrs' => [ 'data-sw-ui-dialog-close' => true, 'autofocus' => true ] ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup built by the Ui helpers.
		echo '</div>';
		echo '<div class="sw-ui-dialog__body">';
		foreach ( self::$context['trees'] as $key => $tree ) {
			self::render_panel( (string) $key, $tree );
		}
		echo '</div>';
		echo '</dialog>';
	}

	/**
	 * @param array<string, mixed> $tree
	 */
	private static function render_panel( string $key, array $tree ): void {
		$day = substr( (string) $tree['started_at'], 0, 10 );

		echo '<section id="' . esc_attr( self::panel_id( $key ) ) . '" class="sw-audit-lineage-panel" data-sw-lineage-panel hidden>';
		self::render_summary( $tree );
		if ( $tree['truncated'] ) {
			echo '<p class="sw-ui-field__help">' . esc_html__( 'This chain is longer than the view, so its oldest or newest change sets are not listed. Show the rows of a change set to read them.', 'stonewright' ) . '</p>';
		}

		$state = [ 'budget' => self::MAX_NODES, 'shown' => [] ];
		echo '<ol class="sw-ui-lineage" aria-label="' . esc_attr( __( 'Change set lineage', 'stonewright' ) ) . '">';
		foreach ( $tree['roots'] as $root ) {
			if ( $state['budget'] < 1 ) {
				break;
			}
			self::render_node( (string) $root, $tree, $day, $state, true );
		}
		echo '</ol>';

		$waiting = array_values( array_filter( $tree['order'], static fn ( string $id ): bool => ! isset( $state['shown'][ $id ] ) ) );
		if ( [] !== $waiting ) {
			$list_id = 'sw-audit-lineage-more-' . $key;
			$count   = count( $waiting );
			echo '<p class="sw-audit-lineage-more">' . Button::render( sprintf( /* translators: %d: number of change sets not drawn yet */ _n( 'Show %d more', 'Show %d more', $count, 'stonewright' ), $count ), [ 'size' => 'sm', 'attrs' => [ 'data-sw-lineage-more' => $list_id, 'aria-expanded' => 'false', 'aria-controls' => $list_id ] ] ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup built by the Ui helpers.
			echo '<ol id="' . esc_attr( $list_id ) . '" class="sw-ui-lineage" aria-label="' . esc_attr( __( 'More change sets', 'stonewright' ) ) . '" hidden>';
			$rest = [ 'budget' => $count, 'shown' => [] ];
			foreach ( $waiting as $id ) {
				self::render_node( $id, $tree, $day, $rest, false );
			}
			echo '</ol>';
		}
		echo '</section>';
	}

	/**
	 * @param array<string, mixed> $tree
	 */
	private static function render_summary( array $tree ): void {
		echo '<p class="sw-audit-lineage-summary">';
		foreach ( [ 'failed', 'verified', 'unverified' ] as $kind ) {
			$count = (int) $tree['counts'][ $kind ];
			if ( $count < 1 ) {
				continue;
			}
			$label = match ( $kind ) {
				'failed'   => sprintf( /* translators: %d: number of change sets */ __( '%d failed', 'stonewright' ), $count ),
				'verified' => sprintf( /* translators: %d: number of change sets */ __( '%d verified', 'stonewright' ), $count ),
				default    => sprintf( /* translators: %d: number of change sets */ __( '%d not verified', 'stonewright' ), $count ),
			};
			echo self::badge( $kind, $label ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup built by the Ui helpers.
		}

		$total = (int) $tree['total'];
		$parts = [ sprintf( /* translators: %d: number of change sets */ _n( '%d change set', '%d change sets', $total, 'stonewright' ), $total ) ];
		$span  = self::span_text( (string) $tree['started_at'], (string) $tree['ended_at'] );
		if ( '' !== $span ) {
			$parts[] = $span;
		}
		$mode = self::newest_mode( $tree );
		if ( '' !== $mode ) {
			$parts[] = sprintf( /* translators: %s: site mode such as development */ __( 'Mode: %s', 'stonewright' ), $mode );
		}
		echo '<span class="sw-audit-lineage-summary__text">' . esc_html( implode( ', ', $parts ) ) . '</span>';
		echo '</p>';
	}

	/**
	 * One change set as a list item: what happened, to what, when, and whose repair it is.
	 *
	 * @param array<string, mixed>                                          $tree
	 * @param array{budget: int, shown: array<string, true>}                $state Nodes still allowed and nodes drawn.
	 */
	private static function render_node( string $id, array $tree, string $day, array &$state, bool $nest ): void {
		$node = $tree['nodes'][ $id ];
		--$state['budget'];
		$state['shown'][ $id ] = true;
		$kind                  = self::state_kind( $node );
		$when                  = '' !== (string) $node['state_at'] ? (string) $node['state_at'] : (string) $node['created_at'];
		$duration              = self::duration_text( (int) $node['duration_ms'] );

		echo '<li class="sw-ui-lineage__item" data-sw-lineage-node="' . esc_attr( $id ) . '">';
		echo '<div class="sw-ui-lineage__node">';
		echo self::badge( $kind, self::state_label( $node ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup built by the Ui helpers.
		echo '<span class="sw-ui-visually-hidden">, </span>';
		echo '<strong class="sw-audit-lineage-node__op">' . esc_html( self::operation_label( (string) $node['ability'] ) ) . '</strong>';
		if ( '' !== $when ) {
			echo '<span class="sw-ui-visually-hidden">, </span>';
			self::render_time( $when, $day );
		}
		if ( '' !== $duration ) {
			echo '<span class="sw-ui-visually-hidden">, </span>';
			echo '<span class="sw-ui-field__help">' . esc_html( $duration ) . '</span>';
		}
		echo '</div>';

		echo '<p class="sw-ui-lineage__meta">';
		echo '<span class="sw-audit-lineage-node__current" data-sw-lineage-current hidden>' . esc_html__( 'This change set', 'stonewright' ) . '</span> ';
		echo '<code class="sw-audit-lineage-node__ability">' . esc_html( (string) $node['ability'] ) . '</code> ';
		echo '<code title="' . esc_attr( $id ) . '">' . esc_html( self::short_id( $id ) ) . '</code> ';
		$relation = self::relation_text( $node );
		if ( '' !== $relation ) {
			echo '<span>' . esc_html( $relation ) . '</span> ';
		}
		if ( '' !== (string) $node['supersedes'] ) {
			echo '<span>' . esc_html( sprintf( /* translators: %s: change set id */ __( 'Supersedes %s', 'stonewright' ), self::short_id( (string) $node['supersedes'] ) ) ) . '</span> ';
		}
		$incident_state = (string) ( self::$context['states'][ (string) $node['incident_id'] ] ?? '' );
		if ( '' !== $incident_state ) {
			echo '<span>' . esc_html( sprintf( /* translators: %s: incident state such as open or resolved */ __( 'Incident %s', 'stonewright' ), $incident_state ) ) . '</span> ';
		}
		if ( (int) $node['depth'] > self::INDENT_LEVELS ) {
			echo '<span class="sw-audit-lineage-node__depth"><span aria-hidden="true">&hellip;</span> ' . esc_html( sprintf( /* translators: %d: nesting level of the change set */ __( 'Level %d', 'stonewright' ), (int) $node['depth'] + 1 ) ) . '</span> ';
		}
		echo '<a class="sw-ui-link" href="' . esc_url( self::rows_url( $id ) ) . '" aria-label="' . esc_attr( sprintf( /* translators: %s: change set id */ __( 'Show rows of change set %s', 'stonewright' ), $id ) ) . '">' . esc_html__( 'Show rows', 'stonewright' ) . '</a>';
		echo '</p>';

		if ( $nest && [] !== $node['children'] && $state['budget'] > 0 ) {
			$count = count( $node['children'] );
			$fold  = $count > self::BRANCH_LIMIT;
			if ( $fold ) {
				echo '<details class="sw-ui-disclosure sw-ui-lineage__branch"><summary>' . Icon::render( 'chev-r' ) . esc_html( sprintf( /* translators: 1: number of repairs, 2: change set id */ _n( '%1$d repair of %2$s', '%1$d repairs of %2$s', $count, 'stonewright' ), $count, self::short_id( $id ) ) ) . '</summary>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icon::render() returns escaped markup.
			}
			echo '<ol>';
			foreach ( $node['children'] as $child ) {
				if ( $state['budget'] < 1 ) {
					break;
				}
				self::render_node( (string) $child, $tree, $day, $state, true );
			}
			echo '</ol>';
			if ( $fold ) {
				echo '</details>';
			}
		}
		echo '</li>';
	}

	private static function render_time( string $at, string $day ): void {
		if ( 1 !== preg_match( '/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}:\d{2})$/', $at, $parts ) ) {
			echo '<span>' . esc_html( $at ) . '</span>';
			return;
		}
		echo '<time datetime="' . esc_attr( $parts[1] . 'T' . $parts[2] . 'Z' ) . '">' . esc_html( $parts[1] === $day ? $parts[2] : $at ) . '</time>';
	}

	/**
	 * @param array<string, mixed> $node
	 */
	private static function relation_text( array $node ): string {
		$parent = (string) $node['repair_of'];
		return match ( (string) $node['parent'] ) {
			'present' => sprintf( /* translators: %s: change set id */ __( 'Repair of %s', 'stonewright' ), self::short_id( $parent ) ),
			'cut'     => sprintf( /* translators: %s: change set id */ __( 'Repair of %s (not shown)', 'stonewright' ), self::short_id( $parent ) ),
			'missing' => sprintf( /* translators: %s: change set id */ __( 'Repair of %s (not in this log)', 'stonewright' ), self::short_id( $parent ) ),
			default   => '',
		};
	}

	/**
	 * @param array<string, mixed> $node
	 */
	private static function state_kind( array $node ): string {
		return match ( (string) $node['state'] ) {
			'failed'   => 'failed',
			'verified' => 'verified',
			default    => 'unverified',
		};
	}

	/**
	 * @param array<string, mixed> $node
	 */
	private static function state_label( array $node ): string {
		return match ( true ) {
			'failed' === $node['state'] && 'verification_failed' === $node['detail'] => __( 'Verification failed', 'stonewright' ),
			'failed' === $node['state']                                              => __( 'Failed', 'stonewright' ),
			'verified' === $node['state']                                            => __( 'Verified', 'stonewright' ),
			default                                                                  => __( 'Not verified', 'stonewright' ),
		};
	}

	private static function badge( string $kind, string $label ): string {
		return match ( $kind ) {
			'failed'   => Badge::render( $label, [ 'variant' => 'danger', 'icon' => 'x' ] ),
			'verified' => Badge::render( $label, [ 'variant' => 'ok', 'icon' => 'check' ] ),
			default    => Badge::render( $label ),
		};
	}

	/** "stonewright/elementor-v3-batch-mutate" reads as "Elementor v3 batch mutate". */
	private static function operation_label( string $ability ): string {
		$name = (string) preg_replace( '#^stonewright/#', '', $ability );
		$name = trim( (string) preg_replace( '/[-_\s]+/', ' ', $name ) );
		return '' === $name ? __( 'Change', 'stonewright' ) : ucfirst( $name );
	}

	private static function duration_text( int $milliseconds ): string {
		if ( $milliseconds < 1 ) {
			return '';
		}
		if ( $milliseconds < 1000 ) {
			return sprintf( /* translators: %d: milliseconds */ __( '%d ms', 'stonewright' ), $milliseconds );
		}
		if ( $milliseconds < 60000 ) {
			return sprintf( /* translators: %s: seconds, possibly with one decimal */ __( '%s s', 'stonewright' ), rtrim( rtrim( number_format( $milliseconds / 1000, 1, '.', '' ), '0' ), '.' ) );
		}
		return sprintf( /* translators: 1: minutes, 2: seconds */ __( '%1$d min %2$d s', 'stonewright' ), intdiv( $milliseconds, 60000 ), (int) round( ( $milliseconds % 60000 ) / 1000 ) );
	}

	private static function span_text( string $start, string $end ): string {
		if ( '' === $start ) {
			return '';
		}
		if ( '' === $end || $end === $start ) {
			return sprintf( /* translators: %s: UTC time */ __( '%s UTC', 'stonewright' ), $start );
		}
		$end_text = substr( $end, 0, 10 ) === substr( $start, 0, 10 ) ? substr( $end, 11 ) : $end;
		return sprintf( /* translators: 1: start time, 2: end time */ __( '%1$s to %2$s UTC', 'stonewright' ), $start, $end_text );
	}

	/**
	 * The mode recorded on the newest row of the tree.
	 *
	 * @param array<string, mixed> $tree
	 */
	private static function newest_mode( array $tree ): string {
		$mode   = '';
		$newest = -1;
		foreach ( $tree['nodes'] as $node ) {
			if ( '' !== (string) $node['mode'] && (int) $node['last_row'] > $newest ) {
				$newest = (int) $node['last_row'];
				$mode   = (string) $node['mode'];
			}
		}
		return $mode;
	}

	private static function panel_id( string $key ): string {
		return 'sw-audit-lineage-panel-' . $key;
	}

	private static function rows_url( string $change_set_id ): string {
		return add_query_arg( [ 'page' => AuditLogPage::SLUG, 'change_set_id' => $change_set_id ], admin_url( 'admin.php' ) );
	}

	private static function short_id( string $id ): string {
		return mb_strlen( $id ) > self::SHORT_ID_LIMIT ? mb_substr( $id, 0, self::SHORT_ID_LIMIT - 2 ) . '…' : $id;
	}
}
