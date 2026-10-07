<?php
/**
 * Repair lineage assembled from audit rows.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

/**
 * Turns audit rows into change-set nodes and the tree of repairs they form.
 *
 * A node is one change set: its rows are the write, the later verification and
 * any retry. Its state is decided by the newest row that decides anything: a
 * failure (including a failed verification) makes it `failed`, a verified success
 * makes it `verified`; planned, blocked and retryable rows decide nothing. A
 * change set is the child of the one its `repair_of` names. Pure: it reads rows,
 * never the database.
 */
final class ChangeSetLineage {

	/** Most change sets one tree holds; a longer chain is cut and reported as truncated. */
	public const MAX_COMPONENT = 100;

	/**
	 * @param list<mixed> $rows Audit rows, in any order.
	 * @return array{nodes: array<string, array<string, mixed>>}
	 */
	public static function graph( array $rows ): array {
		$clean = array_values( array_filter( $rows, static fn ( mixed $row ): bool => is_array( $row ) && '' !== (string) ( $row['change_set_id'] ?? '' ) ) );
		usort( $clean, static fn ( array $a, array $b ): int => (int) ( $a['id'] ?? 0 ) <=> (int) ( $b['id'] ?? 0 ) );

		$nodes = [];
		foreach ( $clean as $row ) {
			$id      = (string) $row['change_set_id'];
			$created = (string) ( $row['created_at'] ?? '' );
			if ( ! isset( $nodes[ $id ] ) ) {
				$nodes[ $id ] = [
					'change_set_id' => $id,
					'ability'       => (string) ( $row['ability_name'] ?? '' ),
					'state'         => ChangeSet::STATUS_UNVERIFIED,
					'detail'        => '',
					'repair_of'     => '',
					'supersedes'    => '',
					'first_row'     => (int) ( $row['id'] ?? 0 ),
					'last_row'      => (int) ( $row['id'] ?? 0 ),
					'started_at'    => $created,
					'state_at'      => '',
					'created_at'    => '',
					'duration_ms'   => 0,
					'mode'          => '',
					'incident_id'   => '',
					'rows'          => 0,
				];
			}
			$node                 =& $nodes[ $id ];
			$node['rows']        += 1;
			$node['last_row']     = (int) ( $row['id'] ?? $node['last_row'] );
			$node['created_at']   = '' !== $created ? $created : $node['created_at'];
			$node['duration_ms'] += max( 0, (int) ( $row['duration_ms'] ?? 0 ) );
			$mode                 = (string) ( $row['mode'] ?? '' );
			$node['mode']         = '' !== $mode ? mb_substr( $mode, 0, 32 ) : $node['mode'];
			$outcome              = strtoupper( (string) ( $row['outcome'] ?? '' ) );
			$verification         = strtolower( (string) ( $row['verification_status'] ?? '' ) );
			if ( 'FAILED' === $outcome || in_array( $verification, [ 'failed', 'missing' ], true ) ) {
				$node['state']    = ChangeSet::STATUS_FAILED;
				$node['detail']   = 'failed' === $verification || 'VERIFY' === strtoupper( (string) ( $row['category'] ?? '' ) ) ? 'verification_failed' : 'failed';
				$node['state_at'] = $created;
				$incident         = strtolower( (string) ( $row['incident_id'] ?? '' ) );
				if ( 1 === preg_match( '/^[a-f0-9]{64}$/', $incident ) ) {
					$node['incident_id'] = $incident;
				}
			} elseif ( 'SUCCESS' === $outcome && in_array( $verification, [ 'verified', 'passed' ], true ) ) {
				$node['state']    = ChangeSet::STATUS_VERIFIED;
				$node['detail']   = 'verified';
				$node['state_at'] = $created;
			}
			$repair_of = (string) ( $row['repair_of'] ?? '' );
			if ( '' === $node['repair_of'] && '' !== $repair_of && $repair_of !== $id ) {
				$node['repair_of'] = $repair_of;
			}
			if ( '' === $node['supersedes'] ) {
				$details = $row['redacted_details'] ?? '';
				$details = is_string( $details ) ? json_decode( $details, true ) : $details;
				if ( is_array( $details ) && is_string( $details['supersedes'] ?? null ) && $details['supersedes'] !== $id ) {
					$node['supersedes'] = mb_substr( $details['supersedes'], 0, 96 );
				}
			}
			unset( $node );
		}
		foreach ( $nodes as &$settled ) {
			if ( '' === $settled['state_at'] ) {
				$settled['state_at'] = $settled['created_at'];
			}
		}
		unset( $settled );

		return [ 'nodes' => $nodes ];
	}

	/**
	 * The change sets linked to $change_set_id by repair links, as a tree.
	 *
	 * Each node carries its `children` (the repairs of it, oldest first), its
	 * `depth` and `parent`: '' when it repairs nothing, `present` when its parent
	 * is in the tree, `missing` when the parent is not in the log and `cut` when it
	 * is in the log but beyond the tree limit. `order` lists every node
	 * depth-first, oldest first, which is the order a reader walks them.
	 *
	 * @param array{nodes: array<string, array<string, mixed>>} $graph
	 * @return array{
	 *     roots: list<string>,
	 *     nodes: array<string, array<string, mixed>>,
	 *     order: list<string>,
	 *     total: int,
	 *     counts: array{failed: int, verified: int, unverified: int},
	 *     started_at: string,
	 *     ended_at: string,
	 *     truncated: bool
	 * }
	 */
	public static function tree( array $graph, string $change_set_id ): array {
		$tree  = [
			'roots'      => [],
			'nodes'      => [],
			'order'      => [],
			'total'      => 0,
			'counts'     => [ 'failed' => 0, 'verified' => 0, 'unverified' => 0 ],
			'started_at' => '',
			'ended_at'   => '',
			'truncated'  => false,
		];
		$nodes = $graph['nodes'];
		if ( ! isset( $nodes[ $change_set_id ] ) ) {
			return $tree;
		}

		$children = [];
		foreach ( $nodes as $id => $node ) {
			$parent = (string) $node['repair_of'];
			if ( '' !== $parent && isset( $nodes[ $parent ] ) ) {
				$children[ $parent ][] = (string) $id;
			}
		}

		$component = [ $change_set_id => true ];
		$queue     = [ $change_set_id ];
		while ( [] !== $queue ) {
			$id        = (string) array_shift( $queue );
			$neighbors = $children[ $id ] ?? [];
			$parent    = (string) $nodes[ $id ]['repair_of'];
			if ( '' !== $parent && isset( $nodes[ $parent ] ) ) {
				$neighbors[] = $parent;
			}
			foreach ( $neighbors as $neighbor ) {
				$neighbor = (string) $neighbor;
				if ( isset( $component[ $neighbor ] ) ) {
					continue;
				}
				if ( count( $component ) >= self::MAX_COMPONENT ) {
					$tree['truncated'] = true;
					continue;
				}
				$component[ $neighbor ] = true;
				$queue[]                = $neighbor;
			}
		}

		$oldest_first = static fn ( string $a, string $b ): int => (int) $nodes[ $a ]['first_row'] <=> (int) $nodes[ $b ]['first_row'];
		$members      = array_map( 'strval', array_keys( $component ) );
		usort( $members, $oldest_first );

		$roots = [];
		foreach ( $members as $id ) {
			$parent = (string) $nodes[ $id ]['repair_of'];
			if ( '' === $parent || ! isset( $component[ $parent ] ) ) {
				$roots[] = $id;
			}
		}

		$visited = [];
		$order   = [];
		$walk    = static function ( string $id, int $depth ) use ( &$walk, &$visited, &$order, &$tree, $nodes, $children, $component, $oldest_first ): void {
			$visited[ $id ] = true;
			$order[]        = $id;
			$parent         = (string) $nodes[ $id ]['repair_of'];
			$kids           = array_values( array_filter( $children[ $id ] ?? [], static fn ( string $kid ): bool => isset( $component[ $kid ] ) && ! isset( $visited[ $kid ] ) ) );
			usort( $kids, $oldest_first );
			$tree['nodes'][ $id ] = $nodes[ $id ] + [
				'depth'    => $depth,
				'children' => $kids,
				'parent'   => match ( true ) {
					'' === $parent                 => '',
					isset( $component[ $parent ] ) => 'present',
					isset( $nodes[ $parent ] )     => 'cut',
					default                        => 'missing',
				},
			];
			foreach ( $kids as $kid ) {
				if ( ! isset( $visited[ $kid ] ) ) {
					$walk( $kid, $depth + 1 );
				}
			}
		};
		foreach ( $roots as $root ) {
			$walk( $root, 0 );
		}
		// A cycle has no root: its oldest member stands in for one.
		foreach ( $members as $id ) {
			if ( ! isset( $visited[ $id ] ) ) {
				$roots[] = $id;
				$walk( $id, 0 );
			}
		}

		$tree['roots'] = $roots;
		$tree['order'] = $order;
		$tree['total'] = count( $order );
		foreach ( $order as $id ) {
			$node  = $tree['nodes'][ $id ];
			$state = ChangeSet::STATUS_FAILED === $node['state'] || ChangeSet::STATUS_VERIFIED === $node['state'] ? (string) $node['state'] : 'unverified';
			++$tree['counts'][ $state ];
			if ( '' !== $node['started_at'] && ( '' === $tree['started_at'] || strcmp( (string) $node['started_at'], $tree['started_at'] ) < 0 ) ) {
				$tree['started_at'] = (string) $node['started_at'];
			}
			if ( '' !== $node['created_at'] && strcmp( (string) $node['created_at'], $tree['ended_at'] ) > 0 ) {
				$tree['ended_at'] = (string) $node['created_at'];
			}
		}

		return $tree;
	}
}
