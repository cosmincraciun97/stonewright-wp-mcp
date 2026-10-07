<?php
/**
 * Element-level comparison of two Elementor trees: what changed that the plan did not ask for.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Write;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\Write\ElementTreeDiff;

/**
 * @covers \Stonewright\WpMcp\Elementor\Write\ElementTreeDiff
 */
final class ElementTreeDiffTest extends TestCase {

	/** @return list<array<string, mixed>> */
	private static function tree(): array {
		return [
			[
				'id'       => 'root',
				'elType'   => 'container',
				'settings' => [ 'flex_direction' => 'column' ],
				'elements' => [
					[
						'id'         => 'hero',
						'elType'     => 'widget',
						'widgetType' => 'heading',
						'settings'   => [ 'title' => 'Welcome' ],
						'elements'   => [],
					],
					[
						'id'       => 'row',
						'elType'   => 'container',
						'settings' => [],
						'elements' => [
							[ 'id' => 'cta', 'elType' => 'widget', 'widgetType' => 'button', 'settings' => [ 'text' => 'Go' ], 'elements' => [] ],
							[ 'id' => 'note', 'elType' => 'widget', 'widgetType' => 'text-editor', 'settings' => [ 'editor' => 'Hi' ], 'elements' => [] ],
						],
					],
				],
			],
		];
	}

	/** @param list<array<string, mixed>> $tree */
	private static function with_title( array $tree, string $id, string $title ): array {
		$tree[0]['elements'] = array_map(
			static function ( array $element ) use ( $id, $title ): array {
				if ( $id === $element['id'] ) {
					$element['settings']['title'] = $title;
				}
				return $element;
			},
			$tree[0]['elements']
		);
		return $tree;
	}

	public function test_identical_trees_have_nothing_unexpected(): void {
		self::assertSame( [], ElementTreeDiff::unexpected( self::tree(), self::tree(), [] ) );
	}

	public function test_a_change_to_an_element_the_plan_did_not_touch_is_unexpected(): void {
		$after = self::with_title( self::tree(), 'hero', 'Changed' );

		self::assertSame( [ [ 'ref' => 'hero', 'action' => 'update' ] ], ElementTreeDiff::unexpected( self::tree(), $after, [ 'cta' ] ) );
	}

	public function test_a_change_to_a_touched_element_is_expected(): void {
		$after = self::with_title( self::tree(), 'hero', 'Changed' );

		self::assertSame( [], ElementTreeDiff::unexpected( self::tree(), $after, [ 'hero' ] ) );
	}

	public function test_an_element_that_appeared_without_being_planned_is_unexpected(): void {
		$after                         = self::tree();
		$after[0]['elements'][1]['elements'][] = [ 'id' => 'stray', 'elType' => 'widget', 'widgetType' => 'divider', 'settings' => [], 'elements' => [] ];

		self::assertSame( [ [ 'ref' => 'stray', 'action' => 'add' ] ], ElementTreeDiff::unexpected( self::tree(), $after, [ 'row' ] ) );
		self::assertSame( [], ElementTreeDiff::unexpected( self::tree(), $after, [ 'stray' ] ) );
	}

	public function test_a_planned_removal_covers_the_whole_subtree_and_an_unplanned_one_is_unexpected(): void {
		$after = self::tree();
		unset( $after[0]['elements'][1] );
		$after[0]['elements'] = array_values( $after[0]['elements'] );

		self::assertSame( [], ElementTreeDiff::unexpected( self::tree(), $after, [], [ 'row' ] ), 'Removing a container removes its children.' );

		$unplanned = ElementTreeDiff::unexpected( self::tree(), $after, [] );
		self::assertEqualsCanonicalizing(
			[ [ 'ref' => 'row', 'action' => 'remove' ], [ 'ref' => 'cta', 'action' => 'remove' ], [ 'ref' => 'note', 'action' => 'remove' ] ],
			$unplanned
		);
	}

	public function test_moving_an_element_changes_its_parent_but_not_its_siblings_order_alone(): void {
		$moved = self::tree();
		$cta   = $moved[0]['elements'][1]['elements'][0];
		array_shift( $moved[0]['elements'][1]['elements'] );
		$moved[0]['elements'][] = $cta;

		self::assertSame( [ [ 'ref' => 'cta', 'action' => 'update' ] ], ElementTreeDiff::unexpected( self::tree(), $moved, [] ) );
		self::assertSame( [], ElementTreeDiff::unexpected( self::tree(), $moved, [ 'cta' ] ) );

		$reordered              = self::tree();
		$reordered[0]['elements'] = array_reverse( $reordered[0]['elements'] );
		self::assertSame( [], ElementTreeDiff::unexpected( self::tree(), $reordered, [] ), 'Sibling order is not an element change.' );
	}

	public function test_a_parent_is_not_changed_by_what_its_children_do(): void {
		$after                                 = self::tree();
		$after[0]['elements'][1]['elements'][0]['settings']['text'] = 'Changed';

		self::assertSame( [ [ 'ref' => 'cta', 'action' => 'update' ] ], ElementTreeDiff::unexpected( self::tree(), $after, [] ) );
	}

	public function test_elements_without_an_id_are_ignored(): void {
		$before = [ [ 'elType' => 'container', 'settings' => [], 'elements' => [] ] ];
		$after  = [ [ 'elType' => 'container', 'settings' => [ 'x' => 1 ], 'elements' => [] ] ];

		self::assertSame( [], ElementTreeDiff::unexpected( $before, $after, [] ) );
	}

	public function test_the_report_is_bounded(): void {
		$after = self::tree();
		for ( $i = 0; $i < 80; ++$i ) {
			$after[0]['elements'][] = [ 'id' => 'extra-' . $i, 'elType' => 'widget', 'widgetType' => 'divider', 'settings' => [], 'elements' => [] ];
		}

		self::assertCount( ElementTreeDiff::MAX_REPORTED, ElementTreeDiff::unexpected( self::tree(), $after, [] ) );
	}

	/** @return array<string, array{0: mixed, 1: array<int, mixed>}> */
	public static function stored_values(): array {
		$tree = [ [ 'id' => 'a', 'elType' => 'container', 'settings' => [], 'elements' => [] ] ];
		return [
			'json string'    => [ json_encode( $tree ), $tree ],
			'array'          => [ $tree, $tree ],
			'double encoded' => [ json_encode( json_encode( $tree ) ), $tree ],
			'empty string'   => [ '', [] ],
			'garbage'        => [ '{not json', [] ],
			'scalar json'    => [ '42', [] ],
			'null'           => [ null, [] ],
		];
	}

	/** @dataProvider stored_values */
	public function test_a_stored_tree_is_decoded_the_way_elementor_data_reads_it( mixed $raw, array $expected ): void {
		self::assertSame( $expected, ElementTreeDiff::tree_from_meta( $raw ) );
	}
}
