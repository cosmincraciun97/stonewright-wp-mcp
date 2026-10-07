<?php
/**
 * Layout-only signatures of sections.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\SectionReuse;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\SectionReuse\Builder;
use Stonewright\WpMcp\SectionReuse\LayoutSummary;
use Stonewright\WpMcp\Support\BlockTree;

require_once __DIR__ . '/SectionFixtures.php';

/**
 * @covers \Stonewright\WpMcp\SectionReuse\LayoutSummary
 */
final class LayoutSummaryTest extends TestCase {

	/** @return array<string, mixed> */
	private static function v3( array $section ): array {
		return LayoutSummary::of( Builder::ELEMENTOR_V3, $section );
	}

	/** @return array<string, mixed> */
	private static function gutenberg( string $content ): array {
		$blocks = BlockTree::parse( $content );
		return LayoutSummary::of( Builder::GUTENBERG, $blocks[0] );
	}

	public function test_the_signature_is_deterministic(): void {
		$one = self::v3( SectionFixtures::v3_features() );
		$two = self::v3( SectionFixtures::v3_features() );

		self::assertSame( $one, $two );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{16}$/', $one['signature'] );
	}

	public function test_text_media_ids_and_style_values_do_not_change_the_v3_signature(): void {
		$base    = self::v3( SectionFixtures::v3_features( 'a', 3, 'Why choose us', '#112233' ) );
		$changed = self::v3( SectionFixtures::v3_features( 'z', 3, 'A completely different headline', '#ffcc00' ) );

		self::assertSame( $base['signature'], $changed['signature'], 'Element ids, text and colors are not layout.' );
	}

	public function test_a_layout_defining_setting_changes_the_v3_signature(): void {
		$base = SectionFixtures::v3_features();
		$flip = $base;
		$flip['elements'][1]['settings']['flex_direction'] = 'column';

		self::assertNotSame( self::v3( $base )['signature'], self::v3( $flip )['signature'] );
	}

	public function test_another_widget_type_or_child_count_changes_the_signature(): void {
		$base     = SectionFixtures::v3_features( 'a', 3 );
		$retyped  = $base;
		$retyped['elements'][0]['widgetType'] = 'text-editor';

		self::assertNotSame( self::v3( $base )['signature'], self::v3( $retyped )['signature'] );
		self::assertNotSame( self::v3( $base )['signature'], self::v3( SectionFixtures::v3_features( 'a', 4 ) )['signature'] );
	}

	public function test_the_v3_summary_counts_columns_depth_elements_types_and_repeated_children(): void {
		$summary = self::v3( SectionFixtures::v3_features( 'a', 3 ) );

		self::assertSame( 3, $summary['columns'] );
		self::assertSame( 4, $summary['depth'] );
		self::assertSame( 9, $summary['elements'], 'Root, heading, row, three cards and three icon boxes.' );
		self::assertSame( [ 'container' => 5, 'icon-box' => 3, 'heading' => 1 ], $summary['types'] );
		self::assertSame( [ 'type' => 'container', 'count' => 3, 'parent' => 'container' ], $summary['repeated'] );
		self::assertFalse( $summary['capped'] );
	}

	public function test_a_section_without_repetition_reports_none(): void {
		$summary = self::v3( SectionFixtures::v3_hero() );

		self::assertNull( $summary['repeated'] );
		self::assertSame( 2, $summary['columns'], 'A row container with two children.' );
	}

	public function test_text_and_media_do_not_change_the_hero_signature_either(): void {
		self::assertSame(
			self::v3( SectionFixtures::v3_hero( 'a', 'First', 41 ) )['signature'],
			self::v3( SectionFixtures::v3_hero( 'b', 'Second', 99 ) )['signature']
		);
	}

	public function test_a_legacy_section_counts_its_columns(): void {
		$section = [
			'id'       => 's000001',
			'elType'   => 'section',
			'settings' => [ 'structure' => '30' ],
			'elements' => [
				[ 'id' => 'c000001', 'elType' => 'column', 'settings' => [ '_column_size' => 33 ], 'elements' => [] ],
				[ 'id' => 'c000002', 'elType' => 'column', 'settings' => [ '_column_size' => 33 ], 'elements' => [] ],
				[ 'id' => 'c000003', 'elType' => 'column', 'settings' => [ '_column_size' => 33 ], 'elements' => [] ],
			],
		];

		self::assertSame( 3, self::v3( $section )['columns'] );
	}

	public function test_a_grid_container_counts_its_declared_columns(): void {
		$section = [
			'id'       => 'g000001',
			'elType'   => 'container',
			'settings' => [ 'container_type' => 'grid', 'grid_columns_grid' => [ 'unit' => 'fr', 'size' => 4 ] ],
			'elements' => [
				[ 'id' => 'g000002', 'elType' => 'widget', 'widgetType' => 'image', 'settings' => [], 'elements' => [] ],
			],
		];

		self::assertSame( 4, self::v3( $section )['columns'] );
	}

	public function test_the_v4_signature_ignores_style_values_but_follows_layout_props(): void {
		$base    = LayoutSummary::of( Builder::ELEMENTOR_V4, SectionFixtures::v4_features( 'a', 3, 'One', '#112233' ) );
		$same    = LayoutSummary::of( Builder::ELEMENTOR_V4, SectionFixtures::v4_features( 'b', 3, 'Two', '#abcdef' ) );
		$stacked = LayoutSummary::of( Builder::ELEMENTOR_V4, SectionFixtures::v4_features( 'a', 3, 'One', '#112233', 'column' ) );

		self::assertSame( $base['signature'], $same['signature'], 'Text, ids and colors are not layout.' );
		self::assertNotSame( $base['signature'], $stacked['signature'], 'flex-direction is layout.' );
		self::assertSame( 3, $base['columns'] );
		self::assertSame( [ 'type' => 'e-div-block', 'count' => 3, 'parent' => 'e-div-block' ], $base['repeated'] );
	}

	public function test_the_gutenberg_signature_follows_block_structure_not_text(): void {
		$base = self::gutenberg( SectionFixtures::gutenberg_features_content( 'Why choose us', 3, 'features' ) );
		$same = self::gutenberg( SectionFixtures::gutenberg_features_content( 'Other words', 3, 'extras' ) );
		$wide = self::gutenberg( SectionFixtures::gutenberg_features_content( 'Why choose us', 4, 'features' ) );

		self::assertSame( $base['signature'], $same['signature'], 'Text and the anchor are not layout.' );
		self::assertNotSame( $base['signature'], $wide['signature'] );
		self::assertSame( 3, $base['columns'] );
		self::assertSame( 4, $wide['columns'] );
		self::assertSame( [ 'type' => 'core/column', 'count' => 3, 'parent' => 'core/columns' ], $base['repeated'] );
		self::assertArrayHasKey( 'core/heading', $base['types'] );
	}

	public function test_the_most_common_types_are_listed_first_and_ties_break_by_name(): void {
		$summary = self::v3( SectionFixtures::v3_features( 'a', 3 ) );

		self::assertSame( [ 'container', 'icon-box', 'heading' ], array_keys( $summary['types'] ) );
	}

	public function test_a_section_over_the_element_cap_is_summarized_and_reported_capped(): void {
		$summary = LayoutSummary::of( Builder::ELEMENTOR_V3, SectionFixtures::v3_features( 'a', 10 ), [ 'max_elements' => 6, 'max_depth' => 32 ] );

		self::assertTrue( $summary['capped'] );
		self::assertSame( 6, $summary['elements'], 'Counting stops at the cap.' );
	}

	public function test_a_section_over_the_depth_cap_is_reported_capped(): void {
		$section = [ 'id' => 'd1', 'elType' => 'container', 'settings' => [], 'elements' => [] ];
		$node    = &$section;
		for ( $i = 2; $i <= 8; $i++ ) {
			$node['elements'][] = [ 'id' => 'd' . $i, 'elType' => 'container', 'settings' => [], 'elements' => [] ];
			$node               = &$node['elements'][0];
		}
		unset( $node );

		$summary = LayoutSummary::of( Builder::ELEMENTOR_V3, $section, [ 'max_elements' => 100, 'max_depth' => 4 ] );

		self::assertTrue( $summary['capped'] );
		self::assertSame( 4, $summary['depth'] );
	}
}
