<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\V4;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\V4\AtomicReadbackVerifier;

/**
 * @covers \Stonewright\WpMcp\Elementor\V4\AtomicReadbackVerifier
 */
final class AtomicReadbackVerifierTest extends TestCase {

	public function test_an_identical_nested_tree_verifies_and_counts_every_node(): void {
		$tree = self::tree();

		$report = AtomicReadbackVerifier::verify( $tree, $tree );

		self::assertTrue( $report['ok'] );
		self::assertSame( 4, $report['checked'] );
		self::assertSame( [], $report['problems'] );
		self::assertSame( 0, $report['problems_count'] );
	}

	public function test_a_dropped_deeply_nested_child_is_a_problem_not_a_success(): void {
		$actual = self::tree();
		array_pop( $actual[0]['elements'][0]['elements'] );

		$report = AtomicReadbackVerifier::verify( self::tree(), $actual );

		self::assertFalse( $report['ok'] );
		self::assertSame( 'element_missing', $report['problems'][0]['code'] );
		self::assertSame( 'b3', $report['problems'][0]['id'] );
		self::assertSame( '/a1/b1/b3', $report['problems'][0]['path'] );
	}

	public function test_a_dropped_subtree_reports_the_subtree_root_and_every_node_below_it_as_unverified(): void {
		$actual = self::tree();
		array_pop( $actual[0]['elements'] );

		$report = AtomicReadbackVerifier::verify( self::tree(), $actual );

		self::assertFalse( $report['ok'] );
		self::assertSame( 'element_missing', $report['problems'][0]['code'] );
		self::assertSame( 'b1', $report['problems'][0]['id'] );
		self::assertSame( 3, $report['problems_count'], 'b1 and its two children' );
	}

	public function test_a_changed_type_or_nested_setting_is_a_problem(): void {
		$actual = self::tree();
		$actual[0]['elements'][0]['elements'][0]['widgetType'] = 'e-paragraph';
		$actual[0]['elements'][0]['elements'][1]['settings']['title']['value'] = 'Changed';

		$report = AtomicReadbackVerifier::verify( self::tree(), $actual );

		self::assertSame( [ 'type_mismatch', 'settings_mismatch' ], array_column( $report['problems'], 'code' ) );
		self::assertSame( [ 'b2', 'b3' ], array_column( $report['problems'], 'id' ) );
	}

	public function test_extra_settings_added_by_the_runtime_are_allowed_but_missing_ones_are_not(): void {
		$actual = self::tree();
		$actual[0]['elements'][0]['elements'][1]['settings']['added_by_runtime'] = [ '$$type' => 'string', 'value' => 'x' ];
		self::assertTrue( AtomicReadbackVerifier::verify( self::tree(), $actual )['ok'] );

		unset( $actual[0]['elements'][0]['elements'][1]['settings']['title'] );
		$report = AtomicReadbackVerifier::verify( self::tree(), $actual );

		self::assertFalse( $report['ok'] );
		self::assertSame( 'settings_mismatch', $report['problems'][0]['code'] );
	}

	public function test_exact_children_mode_reports_unexpected_and_reordered_children(): void {
		$actual = self::tree();
		$actual[0]['elements'][0]['elements'][] = [ 'id' => 'extra', 'elType' => 'widget', 'widgetType' => 'e-heading', 'settings' => [], 'elements' => [] ];

		self::assertTrue( AtomicReadbackVerifier::verify( self::tree(), $actual )['ok'], 'subset mode tolerates siblings' );
		$report = AtomicReadbackVerifier::verify( self::tree(), $actual, [ 'exact_children' => true ] );
		self::assertFalse( $report['ok'] );
		self::assertSame( 'unexpected_child', $report['problems'][0]['code'] );

		$reordered = self::tree();
		$reordered[0]['elements'][0]['elements'] = array_reverse( $reordered[0]['elements'][0]['elements'] );
		$report    = AtomicReadbackVerifier::verify( self::tree(), $reordered );
		self::assertFalse( $report['ok'] );
		self::assertSame( 'child_order_mismatch', $report['problems'][0]['code'] );
	}

	public function test_an_expected_node_found_under_another_parent_is_a_problem(): void {
		$actual = self::tree();
		$moved  = array_pop( $actual[0]['elements'][0]['elements'] );
		$actual[0]['elements'][] = $moved;

		$report = AtomicReadbackVerifier::verify( self::tree(), $actual );

		self::assertFalse( $report['ok'] );
		self::assertSame( 'wrong_parent', $report['problems'][0]['code'] );
		self::assertSame( 'b3', $report['problems'][0]['id'] );
	}

	public function test_the_expected_tree_is_found_anywhere_in_a_larger_document(): void {
		$expected = self::tree()[0]['elements'];
		$document = [ [ 'id' => 'root0', 'elType' => 'e-div-block', 'settings' => [], 'elements' => [ self::tree()[0] ] ] ];

		self::assertTrue( AtomicReadbackVerifier::verify( $expected, $document )['ok'] );
	}

	public function test_top_level_expected_nodes_can_be_pinned_to_a_parent(): void {
		$document = self::tree();
		$expected = [ $document[0]['elements'][0] ];

		self::assertTrue( AtomicReadbackVerifier::verify( $expected, $document, [ 'root_parent' => 'a1' ] )['ok'] );
		self::assertSame( 'wrong_parent', AtomicReadbackVerifier::verify( $expected, $document, [ 'root_parent' => 'zzz' ] )['problems'][0]['code'] );
		self::assertSame( 'wrong_parent', AtomicReadbackVerifier::verify( $expected, $document, [ 'root_parent' => '' ] )['problems'][0]['code'], 'b1 is not a document root' );
		self::assertTrue( AtomicReadbackVerifier::verify( [ $document[0] ], $document, [ 'root_parent' => '' ] )['ok'], 'a1 is a document root' );
	}

	public function test_the_report_is_bounded_but_keeps_the_true_count(): void {
		$expected = [];
		for ( $index = 0; $index < 100; ++$index ) {
			$expected[] = [ 'id' => 'n' . $index, 'elType' => 'e-div-block', 'settings' => [], 'elements' => [] ];
		}

		$report = AtomicReadbackVerifier::verify( $expected, [] );

		self::assertCount( 20, $report['problems'] );
		self::assertSame( 100, $report['problems_count'] );
		self::assertTrue( $report['problems_truncated'] );
	}

	public function test_nodes_without_an_id_are_not_silently_accepted(): void {
		$report = AtomicReadbackVerifier::verify( [ [ 'elType' => 'e-div-block', 'elements' => [] ] ], [ [ 'id' => 'x', 'elType' => 'e-div-block', 'elements' => [] ] ] );

		self::assertFalse( $report['ok'] );
		self::assertSame( 'expected_node_without_id', $report['problems'][0]['code'] );
	}

	public function test_expected_structure_is_read_from_resolved_xml(): void {
		$expected = AtomicReadbackVerifier::structure_from_xml( '<e-div-block configuration-id="box" id="r1"><e-heading configuration-id="h" id="c1"/><e-flexbox id="c2"><e-paragraph id="d1"/></e-flexbox></e-div-block><e-div-block id="r2"/>' );

		self::assertIsArray( $expected );
		self::assertSame( [ 'r1', 'r2' ], array_column( $expected, 'id' ) );
		self::assertSame( 'e-div-block', $expected[0]['type'] );
		self::assertSame( [ 'c1', 'c2' ], array_column( $expected[0]['elements'], 'id' ) );
		self::assertSame( 'e-paragraph', $expected[0]['elements'][1]['elements'][0]['type'] );
		self::assertSame( [], $expected[1]['elements'] );
	}

	public function test_malformed_or_oversized_xml_is_an_error(): void {
		self::assertInstanceOf( \WP_Error::class, AtomicReadbackVerifier::structure_from_xml( '<e-div-block id="r1">' ) );
		self::assertInstanceOf( \WP_Error::class, AtomicReadbackVerifier::structure_from_xml( '' ) );
		self::assertInstanceOf( \WP_Error::class, AtomicReadbackVerifier::structure_from_xml( '<e-div-block>' . str_repeat( '<e-heading id="x"/>', 20000 ) . '</e-div-block>' ) );
		self::assertInstanceOf( \WP_Error::class, AtomicReadbackVerifier::structure_from_xml( '<!DOCTYPE x [<!ENTITY a "b">]><e-div-block id="r1">&a;</e-div-block>' ) );
	}

	public function test_a_mismatch_becomes_a_structured_error_naming_the_first_problems(): void {
		$actual = self::tree();
		array_pop( $actual[0]['elements'][0]['elements'] );
		$report = AtomicReadbackVerifier::verify( self::tree(), $actual );

		$error = AtomicReadbackVerifier::error( $report, 'native_composition' );

		self::assertSame( 'stonewright_atomic_readback_mismatch', $error->get_error_code() );
		$data = $error->get_error_data();
		self::assertSame( 409, $data['status'] );
		self::assertSame( 'failed', $data['verification_status'] );
		self::assertSame( 'native_composition', $data['readback_context'] );
		self::assertSame( 'b3', $data['problems'][0]['id'] );
	}

	/** @return list<array<string,mixed>> */
	private static function tree(): array {
		return [
			[
				'id' => 'a1', 'elType' => 'e-div-block', 'settings' => [], 'elements' => [
					[
						'id' => 'b1', 'elType' => 'e-flexbox', 'settings' => [ 'tag' => [ '$$type' => 'string', 'value' => 'section' ] ], 'elements' => [
							[ 'id' => 'b2', 'elType' => 'widget', 'widgetType' => 'e-heading', 'settings' => [ 'title' => [ '$$type' => 'string', 'value' => 'Hello' ] ], 'elements' => [] ],
							[ 'id' => 'b3', 'elType' => 'widget', 'widgetType' => 'e-paragraph', 'settings' => [ 'title' => [ '$$type' => 'string', 'value' => 'World' ] ], 'elements' => [] ],
						],
					],
				],
			],
		];
	}
}
