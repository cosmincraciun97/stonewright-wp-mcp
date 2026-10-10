<?php
/**
 * Deterministic layout similarity and role guessing.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\SectionReuse;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\SectionReuse\Builder;
use Stonewright\WpMcp\SectionReuse\LayoutSummary;
use Stonewright\WpMcp\SectionReuse\RoleGuesser;
use Stonewright\WpMcp\SectionReuse\SectionInspector;
use Stonewright\WpMcp\SectionReuse\Similarity;

require_once __DIR__ . '/SectionFixtures.php';

/**
 * @covers \Stonewright\WpMcp\SectionReuse\Similarity
 * @covers \Stonewright\WpMcp\SectionReuse\RoleGuesser
 */
final class SimilarityTest extends TestCase {

	/** @return array<string, mixed> A summary with three columns, three repeated children and depth four. */
	private static function features_summary(): array {
		return LayoutSummary::of( Builder::ELEMENTOR_V3, SectionFixtures::v3_features( 'a', 3 ) );
	}

	public function test_an_exact_layout_match_scores_one_and_each_component_is_a_ratio(): void {
		$summary = self::features_summary();

		self::assertSame( 1.0, Similarity::score( $summary, [ 'columns' => 3, 'items' => 3, 'depth' => 4 ] ) );
		self::assertSame( 0.75, Similarity::score( $summary, [ 'columns' => 4 ] ), '1 - |3 - 4| / 4' );
		self::assertSame( 0.5, Similarity::score( $summary, [ 'items' => 6 ] ), '1 - |3 - 6| / 6' );
		self::assertSame( 0.0, Similarity::score( $summary, [ 'items' => 0 ] ), 'Three repeated cards against none at all.' );
		self::assertSame( 0.875, Similarity::score( $summary, [ 'columns' => 4, 'items' => 3 ] ), 'The mean of the components that were asked for.' );
	}

	public function test_node_types_are_compared_as_a_set(): void {
		$summary = self::features_summary();

		self::assertSame( 0.667, Similarity::score( $summary, [ 'types' => [ 'heading', 'icon-box' ] ] ), '2 of the 3 types in the union (container, icon-box, heading).' );
		self::assertSame( 1.0, Similarity::score( $summary, [ 'types' => [ 'container', 'icon-box', 'heading' ] ] ) );
		self::assertSame( 0.0, Similarity::score( $summary, [ 'types' => [ 'video' ] ] ) );
	}

	public function test_the_score_ignores_text_media_and_style(): void {
		$one = LayoutSummary::of( Builder::ELEMENTOR_V3, SectionFixtures::v3_features( 'a', 3, 'First', '#000000' ) );
		$two = LayoutSummary::of( Builder::ELEMENTOR_V3, SectionFixtures::v3_features( 'q', 3, 'Second', '#ffffff' ) );
		$want = [ 'columns' => 2, 'items' => 5, 'depth' => 3 ];

		self::assertSame( Similarity::score( $one, $want ), Similarity::score( $two, $want ) );
	}

	public function test_without_a_hint_the_role_profile_is_the_target(): void {
		$features = self::features_summary();

		self::assertSame( 1.0, Similarity::score( $features, Similarity::profile( 'features' ) ) );
		self::assertLessThan( 1.0, Similarity::score( LayoutSummary::of( Builder::ELEMENTOR_V3, SectionFixtures::v3_hero() ), Similarity::profile( 'features' ) ) );
		self::assertSame( [], Similarity::profile( 'other' ) );
		self::assertSame( 0.5, Similarity::score( $features, [] ), 'No hint and no profile: neutral.' );
	}

	public function test_the_role_guess_uses_builder_native_structure(): void {
		$cases = [
			'hero'         => [ Builder::ELEMENTOR_V3, SectionFixtures::v3_hero(), 0 ],
			'features'     => [ Builder::ELEMENTOR_V3, SectionFixtures::v3_features( 'a', 3 ), 2 ],
			'testimonials' => [ Builder::ELEMENTOR_V3, self::section_of( 'testimonial', 3 ), 3 ],
			'pricing'      => [ Builder::ELEMENTOR_V3, self::section_of( 'price-table', 3 ), 3 ],
			'faq'          => [ Builder::ELEMENTOR_V3, self::section_of( 'accordion', 1 ), 3 ],
			'gallery'      => [ Builder::ELEMENTOR_V3, self::section_of( 'image-gallery', 1 ), 3 ],
			'contact'      => [ Builder::ELEMENTOR_V3, self::section_of( 'form', 1 ), 3 ],
			'cta'          => [ Builder::ELEMENTOR_V3, self::section_of( 'call-to-action', 1 ), 4 ],
		];
		foreach ( $cases as $role => [ $builder, $section, $index ] ) {
			$summary = LayoutSummary::of( $builder, $section );
			$outline = SectionInspector::inspect( $builder, $section );
			self::assertSame( $role, RoleGuesser::guess( $builder, $summary, $outline, $index ), $role );
		}
	}

	public function test_without_an_h1_the_hero_needs_the_first_position(): void {
		$summary = LayoutSummary::of( Builder::ELEMENTOR_V3, SectionFixtures::v3_hero() );
		$outline = SectionInspector::inspect( Builder::ELEMENTOR_V3, SectionFixtures::v3_hero() );

		self::assertSame( 'hero', RoleGuesser::guess( Builder::ELEMENTOR_V3, $summary, $outline, 0 ) );
		self::assertNotSame( 'hero', RoleGuesser::guess( Builder::ELEMENTOR_V3, $summary, $outline, 3 ) );
	}

	public function test_gutenberg_and_v4_sections_get_a_role_too(): void {
		$blocks = \Stonewright\WpMcp\Support\BlockTree::parse( SectionFixtures::gutenberg_features_content() );
		$summary = LayoutSummary::of( Builder::GUTENBERG, $blocks[0] );
		$outline = SectionInspector::inspect( Builder::GUTENBERG, $blocks[0] );
		self::assertSame( 'features', RoleGuesser::guess( Builder::GUTENBERG, $summary, $outline, 2 ) );

		$v4 = SectionFixtures::v4_features();
		self::assertSame( 'features', RoleGuesser::guess( Builder::ELEMENTOR_V4, LayoutSummary::of( Builder::ELEMENTOR_V4, $v4 ), SectionInspector::inspect( Builder::ELEMENTOR_V4, $v4 ), 2 ) );
	}

	public function test_an_unrecognized_section_is_other(): void {
		$section = [ 'id' => 'o000001', 'elType' => 'container', 'settings' => [], 'elements' => [ [ 'id' => 'o000002', 'elType' => 'widget', 'widgetType' => 'divider', 'settings' => [], 'elements' => [] ] ] ];

		self::assertSame( 'other', RoleGuesser::guess( Builder::ELEMENTOR_V3, LayoutSummary::of( Builder::ELEMENTOR_V3, $section ), SectionInspector::inspect( Builder::ELEMENTOR_V3, $section ), 5 ) );
	}

	/**
	 * A container holding $count widgets of one type.
	 *
	 * @return array<string, mixed>
	 */
	private static function section_of( string $widget, int $count ): array {
		$children = [];
		for ( $i = 1; $i <= $count; $i++ ) {
			$children[] = [ 'id' => 'w00000' . $i, 'elType' => 'widget', 'widgetType' => $widget, 'settings' => [], 'elements' => [] ];
		}

		return [ 'id' => 's000001', 'elType' => 'container', 'settings' => [ 'flex_direction' => 'row' ], 'elements' => $children ];
	}
}
