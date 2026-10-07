<?php
/**
 * What a section contains: outline, references and reuse flags.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\SectionReuse;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\SectionReuse\Builder;
use Stonewright\WpMcp\SectionReuse\SectionInspector;
use Stonewright\WpMcp\Support\BlockTree;

require_once __DIR__ . '/SectionFixtures.php';

/**
 * @covers \Stonewright\WpMcp\SectionReuse\SectionInspector
 */
final class SectionInspectorTest extends TestCase {

	/**
	 * @param array<string, mixed> $inspection
	 * @return list<array<string, mixed>>
	 */
	private static function refs( array $inspection, string $type ): array {
		return array_values( array_filter( $inspection['references'], static fn( array $ref ): bool => $ref['type'] === $type ) );
	}

	public function test_the_outline_counts_headings_images_buttons_and_forms_and_truncates_the_first_heading(): void {
		$long    = str_repeat( 'Long headline ', 12 );
		$section = SectionFixtures::v3_hero( 'h', $long );
		$section['elements'][] = [ 'id' => 'h000007', 'elType' => 'widget', 'widgetType' => 'form', 'settings' => [ 'form_name' => 'Contact' ], 'elements' => [] ];

		$outline = SectionInspector::inspect( Builder::ELEMENTOR_V3, $section )['outline'];

		self::assertSame( 1, $outline['images'] );
		self::assertSame( 1, $outline['buttons'] );
		self::assertSame( 1, $outline['forms'] );
		self::assertSame( 1, $outline['headings'] );
		self::assertLessThanOrEqual( 60, mb_strlen( $outline['heading'] ) );
		self::assertStringStartsWith( 'Long headline', $outline['heading'] );
	}

	public function test_global_colors_and_fonts_and_dynamic_tags_are_references(): void {
		$section = SectionFixtures::v3_hero();
		$section['elements'][0]['elements'][0]['settings']['__globals__'] = [
			'title_color'            => 'globals/colors?id=primary',
			'typography_typography'  => 'globals/typography?id=accent',
		];
		$section['elements'][0]['elements'][1]['settings']['__dynamic__'] = [ 'editor' => '[elementor-tag id="abc1234" name="post-title" settings="%7B%7D"]' ];

		$inspection = SectionInspector::inspect( Builder::ELEMENTOR_V3, $section );

		self::assertSame( [ 'primary' ], array_column( self::refs( $inspection, 'global_color' ), 'id' ) );
		self::assertSame( [ 'accent' ], array_column( self::refs( $inspection, 'global_font' ), 'id' ) );
		self::assertSame( [ 'post-title' ], array_column( self::refs( $inspection, 'dynamic_tag' ), 'id' ) );
		self::assertContains( 'dynamic_tags', $inspection['flags'] );
	}

	public function test_media_forms_global_widgets_templates_and_third_party_widgets_are_references(): void {
		$section = SectionFixtures::v3_hero( 'h', 'Title', 41 );
		$section['elements'][] = [ 'id' => 'h000008', 'elType' => 'widget', 'widgetType' => 'global', 'templateID' => 77, 'settings' => [], 'elements' => [] ];
		$section['elements'][] = [ 'id' => 'h000009', 'elType' => 'widget', 'widgetType' => 'template', 'settings' => [ 'template_id' => '88' ], 'elements' => [] ];
		$section['elements'][] = [ 'id' => 'h000010', 'elType' => 'widget', 'widgetType' => 'form', 'settings' => [ 'form_name' => 'Contact', 'form_id' => 'cf-1' ], 'elements' => [] ];
		$section['elements'][] = [ 'id' => 'h000011', 'elType' => 'widget', 'widgetType' => 'acme-pricing-grid', 'settings' => [], 'elements' => [] ];

		$inspection = SectionInspector::inspect( Builder::ELEMENTOR_V3, $section );

		self::assertSame( [ '41' ], array_column( self::refs( $inspection, 'media' ), 'id' ) );
		self::assertSame( [ '77' ], array_column( self::refs( $inspection, 'global_widget' ), 'id' ) );
		self::assertSame( [ '88' ], array_column( self::refs( $inspection, 'nested_template' ), 'id' ) );
		self::assertSame( [ 'Contact' ], array_column( self::refs( $inspection, 'form' ), 'id' ) );
		self::assertSame( [ 'acme-pricing-grid' ], array_column( self::refs( $inspection, 'third_party_widget' ), 'id' ) );
		foreach ( [ 'forms', 'global_widgets', 'third_party_widgets' ] as $flag ) {
			self::assertContains( $flag, $inspection['flags'], $flag );
		}
	}

	public function test_a_reference_lists_where_it_is_used_and_how_often(): void {
		$section = SectionFixtures::v3_features( 'a', 3 );
		foreach ( [ 0, 1, 2 ] as $index ) {
			$section['elements'][1]['elements'][ $index ]['elements'][0]['settings']['__globals__'] = [ 'title_color' => 'globals/colors?id=primary' ];
		}

		$refs = self::refs( SectionInspector::inspect( Builder::ELEMENTOR_V3, $section ), 'global_color' );

		self::assertCount( 1, $refs, 'One entry per referenced thing.' );
		self::assertSame( 3, $refs[0]['count'] );
		self::assertSame( [ 'ai1', 'ai2', 'ai3' ], $refs[0]['at'] );
	}

	public function test_v4_global_classes_variables_dynamic_tags_and_media_are_references_but_local_styles_are_not(): void {
		$section = SectionFixtures::v4_features( 'v', 3 );
		$section['elements'][0]['settings']['classes'] = [ '$$type' => 'classes', 'value' => [ 'g-hero' ] ];
		$section['styles'][ array_key_first( $section['styles'] ) ]['variants'][0]['props']['color'] = [ '$$type' => 'global-color-variable', 'value' => 'e-gv-brand' ];
		$section['elements'][0]['settings']['link'] = [ '$$type' => 'dynamic', 'value' => [ 'name' => 'post-url', 'group' => 'post', 'settings' => [] ] ];
		$section['elements'][1]['settings']['image'] = [ '$$type' => 'image', 'value' => [ 'src' => [ '$$type' => 'image-src', 'value' => [ 'id' => [ '$$type' => 'image-attachment-id', 'value' => 52 ], 'url' => null ] ] ] ];

		$inspection = SectionInspector::inspect( Builder::ELEMENTOR_V4, $section );

		self::assertEqualsCanonicalizing( [ 'g-card', 'g-hero' ], array_column( self::refs( $inspection, 'global_class' ), 'id' ) );
		self::assertSame( [ 'e-gv-brand' ], array_column( self::refs( $inspection, 'variable' ), 'id' ) );
		self::assertSame( [ 'post-url' ], array_column( self::refs( $inspection, 'dynamic_tag' ), 'id' ) );
		self::assertSame( [ '52' ], array_column( self::refs( $inspection, 'media' ), 'id' ) );
		self::assertNotContains( 'e-v000001-f00ba12', array_column( $inspection['references'], 'id' ), 'A local style id is not a reference.' );
	}

	public function test_gutenberg_synced_patterns_media_forms_and_bindings_are_references(): void {
		$content = '<!-- wp:group {"anchor":"intro"} --><div id="intro" class="wp-block-group">'
			. '<!-- wp:block {"ref":123} /-->'
			. '<!-- wp:image {"id":61} --><figure class="wp-block-image"><img src="https://example.test/a.jpg" alt="" class="wp-image-61"/></figure><!-- /wp:image -->'
			. '<!-- wp:acme/contact-form {"formId":"f1"} /-->'
			. '<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"core/post-meta","args":{"key":"tagline"}}}}} --><p>x</p><!-- /wp:paragraph -->'
			. '<!-- wp:template-part {"slug":"cta-strip"} /-->'
			. '</div><!-- /wp:group -->';
		$blocks = BlockTree::parse( $content );

		$inspection = SectionInspector::inspect( Builder::GUTENBERG, $blocks[0] );

		self::assertSame( [ '123' ], array_column( self::refs( $inspection, 'synced_pattern' ), 'id' ) );
		self::assertSame( [ '61' ], array_column( self::refs( $inspection, 'media' ), 'id' ) );
		self::assertSame( [ 'acme/contact-form' ], array_column( self::refs( $inspection, 'form' ), 'id' ) );
		self::assertSame( [ 'acme/contact-form' ], array_column( self::refs( $inspection, 'third_party_widget' ), 'id' ) );
		self::assertSame( [ 'core/post-meta' ], array_column( self::refs( $inspection, 'dynamic_tag' ), 'id' ) );
		self::assertSame( [ 'cta-strip' ], array_column( self::refs( $inspection, 'nested_template' ), 'id' ) );
		self::assertContains( 'synced_patterns', $inspection['flags'] );
		self::assertSame( [ [ 'path' => [ 0 ], 'anchor' => 'intro' ] ], $inspection['anchors'] );
	}

	public function test_a_plain_section_has_no_references_or_flags(): void {
		$inspection = SectionInspector::inspect( Builder::ELEMENTOR_V3, [ 'id' => 'p000001', 'elType' => 'container', 'settings' => [], 'elements' => [ [ 'id' => 'p000002', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Hi' ], 'elements' => [] ] ] ] );

		self::assertSame( [], $inspection['references'] );
		self::assertSame( [], $inspection['flags'] );
	}
}
