<?php
/**
 * The pure parts of inserting a portable section: ids, style remapping, anchors and markup structure.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\SectionReuse;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\V4\AtomicSchemaRepository;
use Stonewright\WpMcp\SectionReuse\Builder;
use Stonewright\WpMcp\SectionReuse\ElementorSectionInserter;
use Stonewright\WpMcp\SectionReuse\GutenbergSectionInserter;
use Stonewright\WpMcp\SectionReuse\MarkupSkeleton;
use Stonewright\WpMcp\SectionReuse\PortableSection;
use Stonewright\WpMcp\SectionReuse\ReferenceCatalog;
use Stonewright\WpMcp\SectionReuse\SectionSource;
use Stonewright\WpMcp\Support\BlockTree;

require_once __DIR__ . '/SectionFixtures.php';

/**
 * @covers \Stonewright\WpMcp\SectionReuse\ElementorSectionInserter
 * @covers \Stonewright\WpMcp\SectionReuse\GutenbergSectionInserter
 * @covers \Stonewright\WpMcp\SectionReuse\MarkupSkeleton
 * @covers \Stonewright\WpMcp\SectionReuse\PortableSection
 */
final class SectionInserterUnitTest extends TestCase {

	protected function setUp(): void {
		AtomicSchemaRepository::invalidate();
		ReferenceCatalog::set_provider( static fn( string $type, string $id ): ?bool => true );
		$GLOBALS['stonewright_test_posts']             = [];
		$GLOBALS['stonewright_test_user_can_callback'] = static fn( string $cap, mixed ...$args ): bool => true;
	}

	protected function tearDown(): void {
		ReferenceCatalog::set_provider( null );
		AtomicSchemaRepository::invalidate();
		$GLOBALS['stonewright_test_posts']             = [];
		$GLOBALS['stonewright_test_user_can_callback'] = null;
	}

	/** @return array<string, mixed> A validated payload of a V3 features section. */
	private static function v3_payload(): array {
		$GLOBALS['stonewright_test_posts'][10] = SectionFixtures::post( 10, 'page', 'publish', 'Source', '', SectionFixtures::elementor_meta( [ SectionFixtures::v3_features( 'f' ) ] ) );
		$extracted = PortableSection::extract( $GLOBALS['stonewright_test_posts'][10], SectionSource::find( $GLOBALS['stonewright_test_posts'][10], [ 'kind' => 'element', 'id' => 'f000001' ] ) );
		self::assertIsArray( $extracted );
		$payload = PortableSection::validate( $extracted['section'], Builder::ELEMENTOR_V3 );
		self::assertIsArray( $payload );

		return $payload;
	}

	/** @return list<string> */
	private static function ids( array $element ): array {
		$ids = [ (string) $element['id'] ];
		foreach ( $element['elements'] ?? [] as $child ) {
			$ids = array_merge( $ids, self::ids( $child ) );
		}

		return $ids;
	}

	public function test_an_id_that_is_already_used_or_was_just_generated_is_never_handed_out_again(): void {
		$sequence = [ 'aaaaaaa', 'aaaaaaa', 'taken01', 'bbbbbbb', 'bbbbbbb', 'ccccccc', 'ddddddd', 'eeeeeee', 'fffffff', 'ggggggg', 'hhhhhhh', 'iiiiiii', 'jjjjjjj' ];
		$cursor   = 0;
		$tree     = [ [ 'id' => 'taken01', 'elType' => 'container', 'settings' => [], 'elements' => [] ] ];

		$built = ElementorSectionInserter::instantiate(
			self::v3_payload(),
			Builder::ELEMENTOR_V3,
			$tree,
			[ 0 ],
			[ 'id_generator' => static function () use ( &$cursor, $sequence ): string {
				return $sequence[ $cursor++ ];
			} ]
		);

		self::assertIsArray( $built, $built instanceof \WP_Error ? $built->get_error_message() : '' );
		$ids = self::ids( $built['element'] );
		self::assertCount( 9, $ids );
		self::assertSame( $ids, array_values( array_unique( $ids ) ), 'No two new elements share an id.' );
		self::assertNotContains( 'taken01', $ids, 'An id already in the document is skipped.' );
		self::assertSame( [ 'aaaaaaa', 'bbbbbbb', 'ccccccc', 'ddddddd', 'eeeeeee', 'fffffff', 'ggggggg', 'hhhhhhh', 'iiiiiii' ], $ids );
		self::assertSame( 'aaaaaaa', $built['id_map']['ph-1'] );
		self::assertSame( 'bbbbbbb', $built['id_map']['ph-2'] );
		self::assertTrue( $built['element']['isInner'], 'Inserted under a parent, so inner.' );
	}

	public function test_the_root_is_not_inner_at_the_top_of_the_document(): void {
		$built = ElementorSectionInserter::instantiate( self::v3_payload(), Builder::ELEMENTOR_V3, [], [] );

		self::assertIsArray( $built );
		self::assertFalse( $built['element']['isInner'] );
	}

	public function test_a_legacy_section_only_goes_to_the_top_level_and_a_widget_is_never_a_v3_section(): void {
		$payload                         = self::v3_payload();
		$payload['element']['elType']    = 'section';

		$nested = ElementorSectionInserter::instantiate( $payload, Builder::ELEMENTOR_V3, [ [ 'id' => 'p', 'elType' => 'container', 'settings' => [], 'elements' => [] ] ], [ 0 ] );
		self::assertInstanceOf( \WP_Error::class, $nested );
		self::assertSame( 'stonewright_section_parent_invalid', $nested->get_error_code() );

		$payload['element']['elType']    = 'widget';
		$payload['element']['widgetType'] = 'heading';
		$widget = ElementorSectionInserter::instantiate( $payload, Builder::ELEMENTOR_V3, [], [] );
		self::assertInstanceOf( \WP_Error::class, $widget );
		self::assertSame( 'stonewright_section_parent_invalid', $widget->get_error_code() );
	}

	public function test_v4_local_styles_get_new_ids_that_name_their_element_and_every_class_list_follows(): void {
		$GLOBALS['stonewright_test_posts'][20] = SectionFixtures::post( 20, 'page', 'publish', 'Source', '', SectionFixtures::elementor_meta( [ SectionFixtures::v4_features( 'v', 3 ) ] ) );
		$extracted = PortableSection::extract( $GLOBALS['stonewright_test_posts'][20], SectionSource::find( $GLOBALS['stonewright_test_posts'][20], [ 'kind' => 'element', 'id' => 'v000001' ] ) );
		self::assertIsArray( $extracted );
		$payload = PortableSection::validate( $extracted['section'], Builder::ELEMENTOR_V4 );
		self::assertIsArray( $payload );

		$first  = ElementorSectionInserter::instantiate( $payload, Builder::ELEMENTOR_V4, [], [] );
		$second = ElementorSectionInserter::instantiate( $payload, Builder::ELEMENTOR_V4, [], [] );

		self::assertIsArray( $first, $first instanceof \WP_Error ? $first->get_error_message() : '' );
		self::assertIsArray( $second );
		$collect = static function ( array $element ) use ( &$collect ): array {
			$out = [];
			foreach ( array_keys( $element['styles'] ?? [] ) as $style_id ) {
				$out[ (string) $style_id ] = (string) $element['id'];
				self::assertSame( $style_id, $element['styles'][ $style_id ]['id'] );
			}
			foreach ( $element['elements'] ?? [] as $child ) {
				$out += $collect( $child );
			}

			return $out;
		};
		$styles_one = $collect( $first['element'] );
		$styles_two = $collect( $second['element'] );
		self::assertCount( 5, $styles_one );
		foreach ( $styles_one as $style_id => $element_id ) {
			self::assertStringStartsWith( 'e-' . $element_id . '-', $style_id );
		}
		self::assertSame( [], array_intersect_key( $styles_one, $styles_two ), 'Two copies never share a style id.' );
		self::assertMatchesRegularExpression( '/^e-[a-f0-9]{7}-[a-f0-9]{7}$/', array_key_first( $styles_one ) );
		self::assertSame( [ array_key_first( $first['element']['styles'] ) ], $first['element']['settings']['classes']['value'] );
	}

	public function test_a_v4_type_the_site_does_not_have_names_the_missing_feature_and_a_custom_type_is_never_remapped(): void {
		$GLOBALS['stonewright_test_posts'][20] = SectionFixtures::post( 20, 'page', 'publish', 'Source', '', SectionFixtures::elementor_meta( [ SectionFixtures::v4_features( 'v', 1 ) ] ) );
		$extracted = PortableSection::extract( $GLOBALS['stonewright_test_posts'][20], SectionSource::find( $GLOBALS['stonewright_test_posts'][20], [ 'kind' => 'element', 'id' => 'v000001' ] ) );
		$payload   = PortableSection::validate( $extracted['section'], Builder::ELEMENTOR_V4 );
		$payload['element']['elements'][1]['elType'] = 'e-future-block';

		$result = ElementorSectionInserter::instantiate( $payload, Builder::ELEMENTOR_V4, [], [] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'atomic_type:e-future-block', $result->get_error_data()['missing_feature'] );
	}

	public function test_a_gutenberg_anchor_is_renamed_to_the_next_free_name_everywhere_it_appears(): void {
		$GLOBALS['stonewright_test_posts'][30] = SectionFixtures::post( 30, 'page', 'publish', 'Source', '', [], '2026-01-01 00:00:00' );
		$GLOBALS['stonewright_test_posts'][30]->post_content = SectionFixtures::gutenberg_features_content( 'Why', 2, 'features' );
		$blocks  = BlockTree::parse( (string) $GLOBALS['stonewright_test_posts'][30]->post_content );
		$payload = [ 'schema' => 'SectionPortableV1', 'builder' => 'gutenberg', 'blocks' => $blocks ];
		$existing = BlockTree::parse( SectionFixtures::gutenberg_features_content( 'Here', 2, 'features' ) . SectionFixtures::gutenberg_features_content( 'Here', 2, 'features-2' ) );

		$built = GutenbergSectionInserter::instantiate( $payload, $existing );

		self::assertIsArray( $built );
		self::assertSame( [ 'features' => 'features-3' ], $built['anchors_renamed'] );
		self::assertSame( 'features-3', $built['blocks'][0]['attrs']['anchor'] );
		self::assertStringContainsString( 'id="features-3"', $built['blocks'][0]['innerContent'][0] );
		self::assertSame( implode( '', array_filter( $built['blocks'][0]['innerContent'], 'is_string' ) ), $built['blocks'][0]['innerHTML'], 'innerHTML stays equal to the string pieces of innerContent.' );
		self::assertSame( [ 'anchors_renamed' ], array_column( $built['warnings'], 'code' ) );
	}

	public function test_an_anchor_nobody_uses_is_left_alone(): void {
		$blocks = BlockTree::parse( SectionFixtures::gutenberg_features_content( 'Why', 2, 'features' ) );

		$built = GutenbergSectionInserter::instantiate( [ 'blocks' => $blocks ], BlockTree::parse( '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' ) );

		self::assertIsArray( $built );
		self::assertSame( [], $built['anchors_renamed'] );
		self::assertSame( $blocks, $built['blocks'] );
	}

	public function test_markup_with_the_same_tags_has_the_same_skeleton_whatever_the_words_or_the_image(): void {
		$figure = '<figure class="wp-block-image size-large"><img src="https://example.test/a.jpg" alt="Old" class="wp-image-61"/><figcaption>One</figcaption></figure>';

		self::assertTrue( MarkupSkeleton::same( '<h2 class="wp-block-heading">Old words</h2>', '<h2 class="wp-block-heading">New words, longer</h2>' ) );
		self::assertTrue( MarkupSkeleton::same( $figure, '<figure class="size-large wp-block-image"><img src="https://example.test/b.jpg" alt="New alt" class="wp-image-88"/><figcaption>Two</figcaption></figure>' ), 'A new source, alt and image id are allowed; the class order does not matter.' );
		self::assertTrue( MarkupSkeleton::same( '<a href="https://example.test/a" rel="noopener">Go</a>', '<a href="https://example.test/b" rel="noopener noreferrer">Go on</a>' ) );
	}

	public function test_markup_with_other_tags_or_attributes_has_another_skeleton(): void {
		$heading = '<h2 class="wp-block-heading">Words</h2>';

		self::assertFalse( MarkupSkeleton::same( $heading, '<h3 class="wp-block-heading">Words</h3>' ), 'A different tag.' );
		self::assertFalse( MarkupSkeleton::same( $heading, '<h2 class="wp-block-heading has-text-align-center">Words</h2>' ), 'A different class.' );
		self::assertFalse( MarkupSkeleton::same( $heading, '<h2 class="wp-block-heading" onclick="x()">Words</h2>' ), 'An added attribute.' );
		self::assertFalse( MarkupSkeleton::same( $heading, '<h2 class="wp-block-heading">Words</h2><script>x()</script>' ), 'An added element.' );
		self::assertFalse( MarkupSkeleton::same( $heading, '<h2 class="wp-block-heading" style="color:red">Words</h2>' ), 'A style attribute.' );
		self::assertFalse( MarkupSkeleton::same( $heading, '<h2 class="wp-block-heading"></h2><h2 class="wp-block-heading"></h2>' ), 'A second element.' );
	}

	public function test_a_payload_must_be_a_portable_section_of_the_right_builder_and_within_its_limits(): void {
		$deep = [ 'id' => 'a', 'elType' => 'container', 'settings' => [], 'elements' => [] ];
		$node = &$deep;
		for ( $i = 0; $i < 40; $i++ ) {
			$node['elements'][] = [ 'id' => 'n' . $i, 'elType' => 'container', 'settings' => [], 'elements' => [] ];
			$node               = &$node['elements'][0];
		}
		unset( $node );

		$too_deep = PortableSection::validate( [ 'schema' => 'SectionPortableV1', 'builder' => 'elementor-v3', 'element' => $deep ], Builder::ELEMENTOR_V3 );
		$dup      = PortableSection::validate( [ 'schema' => 'SectionPortableV1', 'builder' => 'elementor-v3', 'element' => [ 'id' => 'a', 'elType' => 'container', 'settings' => [], 'elements' => [ [ 'id' => 'a', 'elType' => 'container', 'settings' => [], 'elements' => [] ] ] ] ], Builder::ELEMENTOR_V3 );
		$bad_id   = PortableSection::validate( [ 'schema' => 'SectionPortableV1', 'builder' => 'elementor-v3', 'element' => [ 'id' => 'a b', 'elType' => 'container', 'settings' => [], 'elements' => [] ] ], Builder::ELEMENTOR_V3 );
		$mixed    = PortableSection::validate( [ 'schema' => 'SectionPortableV1', 'builder' => 'elementor-v3', 'element' => [ 'id' => 'a', 'elType' => 'container', 'settings' => [], 'elements' => [ [ 'id' => 'b', 'elType' => 'e-div-block', 'settings' => [], 'elements' => [] ] ] ] ], Builder::ELEMENTOR_V3 );

		self::assertSame( 'stonewright_section_too_large', $too_deep->get_error_code() );
		self::assertSame( 'stonewright_section_invalid', $dup->get_error_code(), 'A repeated id is refused.' );
		self::assertSame( 'stonewright_section_invalid', $bad_id->get_error_code() );
		self::assertSame( 'stonewright_section_builder_mismatch', $mixed->get_error_code(), 'A V4 node in a V3 section is a builder mismatch.' );
	}
}
