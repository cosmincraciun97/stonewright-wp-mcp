<?php
/**
 * The repair tree assembled from audit rows: A, its verification failure, the repair B, and B verified.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Security\ChangeSetLineage;

/**
 * @covers \Stonewright\WpMcp\Security\ChangeSetLineage
 */
final class ChangeSetLineageTest extends TestCase {

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private static function row( int $id, string $change_set, array $overrides = [] ): array {
		return array_merge(
			[
				'id'                  => $id,
				'ability_name'        => 'stonewright/elementor-v3-batch-mutate',
				'change_set_id'       => $change_set,
				'repair_of'           => '',
				'outcome'             => 'SUCCESS',
				'category'            => 'WRITE',
				'verification_status' => 'verified',
				'incident_id'         => '',
				'created_at'          => '2026-10-01 10:00:0' . ( $id % 10 ),
				'redacted_details'    => '{}',
			],
			$overrides
		);
	}

	/** @return list<array<string, mixed>> A verified write, a failed frontend verification, then a verified repair. */
	private static function repaired_rows(): array {
		$incident = str_repeat( 'a1', 32 );
		return [
			self::row( 10, 'cs-A' ),
			self::row( 11, 'cs-A', [ 'ability_name' => 'stonewright/elementor-post-write-verify', 'outcome' => 'FAILED', 'category' => 'VERIFY', 'verification_status' => 'failed', 'incident_id' => $incident ] ),
			self::row( 12, 'cs-B', [ 'repair_of' => 'cs-A' ] ),
		];
	}

	public function test_a_verified_write_whose_frontend_verification_failed_reads_as_failed(): void {
		$graph = ChangeSetLineage::graph( self::repaired_rows() );

		self::assertSame( 'failed', $graph['nodes']['cs-A']['state'] );
		self::assertSame( 'verification_failed', $graph['nodes']['cs-A']['detail'] );
		self::assertSame( str_repeat( 'a1', 32 ), $graph['nodes']['cs-A']['incident_id'] );
		self::assertSame( 2, $graph['nodes']['cs-A']['rows'] );
		self::assertSame( 'verified', $graph['nodes']['cs-B']['state'] );
		self::assertSame( 'cs-A', $graph['nodes']['cs-B']['repair_of'] );
	}

	public function test_the_tree_runs_from_the_failed_change_to_its_verified_repair(): void {
		$graph = ChangeSetLineage::graph( self::repaired_rows() );

		foreach ( [ 'cs-A', 'cs-B' ] as $member ) {
			$tree = ChangeSetLineage::tree( $graph, $member );
			self::assertSame( [ 'cs-A' ], $tree['roots'], 'Tree seen from ' . $member );
			self::assertSame( [ 'cs-A', 'cs-B' ], $tree['order'] );
			self::assertSame( [ 'cs-B' ], $tree['nodes']['cs-A']['children'] );
			self::assertSame( [ 0, 1 ], [ $tree['nodes']['cs-A']['depth'], $tree['nodes']['cs-B']['depth'] ] );
			self::assertSame( [ 'failed', 'verified' ], [ $tree['nodes']['cs-A']['state'], $tree['nodes']['cs-B']['state'] ] );
			self::assertSame( 2, $tree['total'] );
			self::assertSame( [ 'failed' => 1, 'verified' => 1, 'unverified' => 0 ], $tree['counts'] );
			self::assertFalse( $tree['truncated'] );
			self::assertSame( [ '', 'present' ], [ $tree['nodes']['cs-A']['parent'], $tree['nodes']['cs-B']['parent'] ] );
		}
	}

	public function test_a_node_records_when_it_started_and_reached_its_state_how_long_it_took_and_in_which_mode(): void {
		$graph = ChangeSetLineage::graph(
			[
				self::row( 10, 'cs-A', [ 'duration_ms' => 40, 'mode' => 'development', 'created_at' => '2026-10-01 10:00:10' ] ),
				self::row( 11, 'cs-A', [ 'duration_ms' => 1200, 'mode' => 'production-safe', 'outcome' => 'FAILED', 'category' => 'VERIFY', 'verification_status' => 'failed', 'created_at' => '2026-10-01 10:00:12' ] ),
				self::row( 12, 'cs-A', [ 'category' => 'VALIDATION', 'verification_status' => 'planned', 'created_at' => '2026-10-01 10:00:13' ] ),
			]
		);
		$node  = $graph['nodes']['cs-A'];

		self::assertSame( '2026-10-01 10:00:10', $node['started_at'] );
		self::assertSame( '2026-10-01 10:00:12', $node['state_at'], 'The time of the row that decided the state.' );
		self::assertSame( '2026-10-01 10:00:13', $node['created_at'], 'The time of the newest row.' );
		self::assertSame( 1240, $node['duration_ms'] );
		self::assertSame( 'production-safe', $node['mode'] );

		$tree = ChangeSetLineage::tree( $graph, 'cs-A' );
		self::assertSame( '2026-10-01 10:00:10', $tree['started_at'] );
		self::assertSame( '2026-10-01 10:00:13', $tree['ended_at'] );
	}

	public function test_a_change_set_with_no_relatives_is_a_tree_of_one_and_an_unknown_one_is_empty(): void {
		$graph = ChangeSetLineage::graph( [ self::row( 1, 'cs-solo' ) ] );
		$tree  = ChangeSetLineage::tree( $graph, 'cs-solo' );

		self::assertSame( [ 'cs-solo' ], $tree['order'] );
		self::assertSame( [ 'cs-solo' ], $tree['roots'] );
		self::assertSame( [], $tree['nodes']['cs-solo']['children'] );
		self::assertSame( '', $tree['nodes']['cs-solo']['parent'] );

		$none = ChangeSetLineage::tree( $graph, 'cs-unknown' );
		self::assertSame( [], $none['order'] );
		self::assertSame( [], $none['roots'] );
		self::assertSame( 0, $none['total'] );
	}

	public function test_a_repair_whose_parent_left_the_log_is_a_root_that_says_so(): void {
		$graph = ChangeSetLineage::graph( [ self::row( 5, 'cs-B', [ 'repair_of' => 'cs-gone' ] ) ] );
		$tree  = ChangeSetLineage::tree( $graph, 'cs-B' );

		self::assertSame( [ 'cs-B' ], $tree['roots'] );
		self::assertSame( 'missing', $tree['nodes']['cs-B']['parent'] );
		self::assertSame( 'cs-gone', $tree['nodes']['cs-B']['repair_of'] );
	}

	public function test_two_repairs_of_one_change_are_its_children_in_the_order_they_were_written(): void {
		$graph = ChangeSetLineage::graph(
			[
				self::row( 1, 'cs-A', [ 'outcome' => 'FAILED', 'verification_status' => 'failed' ] ),
				self::row( 3, 'cs-B2', [ 'repair_of' => 'cs-A' ] ),
				self::row( 2, 'cs-B1', [ 'repair_of' => 'cs-A', 'outcome' => 'FAILED', 'verification_status' => 'failed' ] ),
			]
		);

		$tree = ChangeSetLineage::tree( $graph, 'cs-B2' );

		self::assertSame( [ 'cs-A' ], $tree['roots'] );
		self::assertSame( [ 'cs-B1', 'cs-B2' ], $tree['nodes']['cs-A']['children'] );
		self::assertSame( [ 'cs-A', 'cs-B1', 'cs-B2' ], $tree['order'] );
		self::assertSame( [ 'failed' => 2, 'verified' => 1, 'unverified' => 0 ], $tree['counts'] );
	}

	public function test_a_repair_of_a_repair_nests_one_level_deeper_each_time(): void {
		$graph = ChangeSetLineage::graph(
			[
				self::row( 1, 'cs-A', [ 'outcome' => 'FAILED', 'verification_status' => 'failed' ] ),
				self::row( 2, 'cs-B', [ 'repair_of' => 'cs-A', 'outcome' => 'FAILED', 'verification_status' => 'failed' ] ),
				self::row( 3, 'cs-C', [ 'repair_of' => 'cs-B' ] ),
			]
		);

		$tree = ChangeSetLineage::tree( $graph, 'cs-C' );

		self::assertSame( [ 'cs-A', 'cs-B', 'cs-C' ], $tree['order'] );
		self::assertSame( [ 0, 1, 2 ], array_map( static fn ( string $id ): int => $tree['nodes'][ $id ]['depth'], $tree['order'] ) );
	}

	public function test_an_identical_retry_is_one_change_set_not_a_chain(): void {
		$graph = ChangeSetLineage::graph(
			[
				self::row( 1, 'cs-A', [ 'outcome' => 'FAILED', 'verification_status' => 'failed' ] ),
				self::row( 2, 'cs-A', [ 'repair_of' => 'cs-A' ] ),
			]
		);

		$tree = ChangeSetLineage::tree( $graph, 'cs-A' );

		self::assertSame( [ 'cs-A' ], $tree['order'] );
		self::assertSame( '', $tree['nodes']['cs-A']['repair_of'], 'A change set does not repair itself.' );
		self::assertSame( '', $tree['nodes']['cs-A']['parent'] );
		self::assertSame( 'verified', $tree['nodes']['cs-A']['state'] );
	}

	public function test_a_cycle_in_the_links_terminates_with_every_change_set_listed_once(): void {
		$graph = ChangeSetLineage::graph(
			[
				self::row( 1, 'cs-A', [ 'repair_of' => 'cs-B' ] ),
				self::row( 2, 'cs-B', [ 'repair_of' => 'cs-A' ] ),
			]
		);

		$tree = ChangeSetLineage::tree( $graph, 'cs-A' );

		self::assertSame( [ 'cs-A', 'cs-B' ], $tree['order'] );
		self::assertSame( [ 'cs-A' ], $tree['roots'], 'The oldest member of a cycle stands in for the root.' );
	}

	public function test_a_long_chain_is_cut_at_the_component_limit_and_says_so(): void {
		$rows = [ self::row( 1, 'cs-0', [ 'outcome' => 'FAILED', 'verification_status' => 'failed' ] ) ];
		for ( $i = 1; $i < 130; ++$i ) {
			$rows[] = self::row( $i + 1, 'cs-' . $i, [ 'repair_of' => 'cs-' . ( $i - 1 ), 'outcome' => 'FAILED', 'verification_status' => 'failed' ] );
		}
		$graph = ChangeSetLineage::graph( $rows );

		$oldest = ChangeSetLineage::tree( $graph, 'cs-0' );
		self::assertSame( ChangeSetLineage::MAX_COMPONENT, $oldest['total'] );
		self::assertTrue( $oldest['truncated'] );
		self::assertSame( [ 'cs-0' ], $oldest['roots'] );

		$newest = ChangeSetLineage::tree( $graph, 'cs-129' );
		self::assertSame( ChangeSetLineage::MAX_COMPONENT, $newest['total'] );
		self::assertTrue( $newest['truncated'] );
		self::assertContains( 'cs-129', $newest['order'] );
		self::assertCount( 1, $newest['roots'] );
		self::assertSame( 'cs-30', $newest['roots'][0], 'The oldest change set that fits.' );
		self::assertSame( 'cut', $newest['nodes']['cs-30']['parent'], 'Its parent exists but is not shown.' );
	}

	public function test_a_wide_fan_out_keeps_every_child_in_document_order(): void {
		$rows = [ self::row( 1, 'cs-A', [ 'outcome' => 'FAILED', 'verification_status' => 'failed' ] ) ];
		for ( $i = 1; $i <= 7; ++$i ) {
			$rows[] = self::row( $i + 1, 'cs-r' . $i, [ 'repair_of' => 'cs-A', 'outcome' => 'FAILED', 'verification_status' => 'failed' ] );
		}

		$tree = ChangeSetLineage::tree( ChangeSetLineage::graph( $rows ), 'cs-A' );

		self::assertCount( 7, $tree['nodes']['cs-A']['children'] );
		self::assertSame( [ 'cs-A', 'cs-r1', 'cs-r2', 'cs-r3', 'cs-r4', 'cs-r5', 'cs-r6', 'cs-r7' ], $tree['order'] );
	}

	public function test_a_later_failure_overrides_an_earlier_verification_and_a_later_success_overrides_a_failure(): void {
		$graph = ChangeSetLineage::graph(
			[
				self::row( 1, 'cs-X', [ 'outcome' => 'FAILED', 'category' => 'WRITE', 'verification_status' => '' ] ),
				self::row( 2, 'cs-X' ),
				self::row( 3, 'cs-Y' ),
				self::row( 4, 'cs-Y', [ 'outcome' => 'FAILED', 'verification_status' => 'failed', 'category' => 'VERIFY' ] ),
			]
		);

		self::assertSame( 'verified', $graph['nodes']['cs-X']['state'] );
		self::assertSame( 'failed', $graph['nodes']['cs-Y']['state'] );
		self::assertSame( 'verification_failed', $graph['nodes']['cs-Y']['detail'] );
	}

	public function test_rows_that_decide_nothing_leave_the_change_set_unverified(): void {
		$graph = ChangeSetLineage::graph(
			[
				self::row( 1, 'cs-D', [ 'category' => 'VALIDATION', 'verification_status' => 'planned' ] ),
				self::row( 2, 'cs-D', [ 'outcome' => 'BLOCKED', 'category' => 'SAFETY', 'verification_status' => '' ] ),
				self::row( 3, 'cs-D', [ 'outcome' => 'RETRYABLE', 'category' => 'TRANSIENT', 'verification_status' => '' ] ),
			]
		);

		self::assertSame( 'unverified', $graph['nodes']['cs-D']['state'] );
	}

	public function test_a_write_failure_that_is_not_a_verification_failure_reads_as_failed(): void {
		$graph = ChangeSetLineage::graph( [ self::row( 1, 'cs-W', [ 'outcome' => 'FAILED', 'category' => 'WRITE', 'verification_status' => '' ] ) ] );

		self::assertSame( 'failed', $graph['nodes']['cs-W']['state'] );
		self::assertSame( 'failed', $graph['nodes']['cs-W']['detail'] );
	}

	public function test_rows_without_a_change_set_and_malformed_rows_are_ignored(): void {
		$graph = ChangeSetLineage::graph( [ self::row( 1, '' ), 'not a row', self::row( 2, 'cs-ok' ) ] );

		self::assertSame( [ 'cs-ok' ], array_keys( $graph['nodes'] ) );
	}

	public function test_supersedes_is_read_from_the_recorded_details(): void {
		$graph = ChangeSetLineage::graph( [ self::row( 1, 'cs-new', [ 'redacted_details' => '{"supersedes":"cs-old"}' ] ) ] );

		self::assertSame( 'cs-old', $graph['nodes']['cs-new']['supersedes'] );
	}
}
