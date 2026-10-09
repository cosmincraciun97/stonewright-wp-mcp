<?php
/**
 * A hero is a section with a lead heading near the top of its page, not only the first section.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\SectionReuse;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\SectionReuse\SectionReuseFind;
use Stonewright\WpMcp\SectionReuse\Builder;
use Stonewright\WpMcp\SectionReuse\LayoutSummary;
use Stonewright\WpMcp\SectionReuse\ReferenceCatalog;
use Stonewright\WpMcp\SectionReuse\RoleGuesser;
use Stonewright\WpMcp\SectionReuse\SectionInspector;
use Stonewright\WpMcp\SectionReuse\SignatureCache;
use Stonewright\WpMcp\Security\IncidentStore;
use Stonewright\WpMcp\Support\BlockTree;

require_once __DIR__ . '/SectionFixtures.php';

/**
 * @covers \Stonewright\WpMcp\SectionReuse\RoleGuesser
 * @covers \Stonewright\WpMcp\SectionReuse\SectionInspector
 * @covers \Stonewright\WpMcp\SectionReuse\SignatureCache
 */
final class RoleGuesserHeroTest extends TestCase {

	protected function setUp(): void {
		IncidentStore::reset_for_tests();
		SignatureCache::reset_for_tests();
		ReferenceCatalog::set_provider( static fn( string $type, string $id ): ?bool => true );
		$GLOBALS['stonewright_test_options']           = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_posts']             = [];
		$GLOBALS['stonewright_test_post_types']        = [ 'page' => (object) [ 'name' => 'page', 'public' => true ], 'wp_block' => (object) [ 'name' => 'wp_block', 'public' => false ] ];
		$GLOBALS['stonewright_test_user_caps']         = [ 'edit_posts' => true, 'read' => true ];
		$GLOBALS['stonewright_test_user_can_callback'] = static fn( string $cap, mixed ...$args ): bool => in_array( $cap, [ 'edit_posts', 'read_post', 'edit_post' ], true );
		$GLOBALS['stonewright_test_user_logged_in']    = true;
		$GLOBALS['stonewright_test_current_user_id']   = 7;
	}

	protected function tearDown(): void {
		ReferenceCatalog::set_provider( null );
		SignatureCache::reset_for_tests();
		IncidentStore::reset_for_tests();
		$GLOBALS['stonewright_test_options']           = [];
		$GLOBALS['stonewright_test_posts']             = [];
		$GLOBALS['stonewright_test_post_types']        = [];
		$GLOBALS['stonewright_test_user_caps']         = [];
		$GLOBALS['stonewright_test_user_can_callback'] = null;
		$GLOBALS['stonewright_test_user_logged_in']    = false;
	}

	private static function cover( int $level, bool $button = true ): string {
		$tag = 'h' . $level;
		$btn = $button ? "<!-- wp:buttons -->\n<div class=\"wp-block-buttons\"><!-- wp:button -->\n<div class=\"wp-block-button\"><a class=\"wp-block-button__link wp-element-button\">Start</a></div>\n<!-- /wp:button --></div>\n<!-- /wp:buttons -->" : '';

		return "<!-- wp:cover {\"url\":\"https://example.test/a.jpg\",\"id\":9} -->\n<div class=\"wp-block-cover\"><span></span><img class=\"wp-block-cover__image-background wp-image-9\" src=\"https://example.test/a.jpg\"/><div class=\"wp-block-cover__inner-container\"><!-- wp:heading {\"level\":{$level}} -->\n<{$tag} class=\"wp-block-heading\">Welcome</{$tag}>\n<!-- /wp:heading -->{$btn}</div></div>\n<!-- /wp:cover -->";
	}

	private static function guess( string $builder, array $node, int $index ): string {
		return RoleGuesser::guess( $builder, LayoutSummary::of( $builder, $node ), SectionInspector::inspect( $builder, $node ), $index );
	}

	/** @return array<string, mixed> The block of a Gutenberg section. */
	private static function block( string $content ): array {
		return BlockTree::parse( $content )[0];
	}

	public function test_a_cover_with_an_h1_after_an_intro_is_a_hero(): void {
		self::assertSame( 'hero', self::guess( Builder::GUTENBERG, self::block( self::cover( 1 ) ), 1 ) );
		self::assertSame( 'hero', self::guess( Builder::GUTENBERG, self::block( self::cover( 1 ) ), 2 ) );
	}

	public function test_the_first_section_with_a_lead_heading_is_still_a_hero_whatever_its_level(): void {
		self::assertSame( 'hero', self::guess( Builder::GUTENBERG, self::block( self::cover( 2 ) ), 0 ) );
	}

	public function test_without_an_h1_a_later_section_is_not_a_hero(): void {
		self::assertNotSame( 'hero', self::guess( Builder::GUTENBERG, self::block( self::cover( 2 ) ), 1 ) );
	}

	public function test_an_h1_far_down_the_page_is_not_a_hero(): void {
		self::assertNotSame( 'hero', self::guess( Builder::GUTENBERG, self::block( self::cover( 1 ) ), 3 ) );
		self::assertNotSame( 'hero', self::guess( Builder::GUTENBERG, self::block( self::cover( 1 ) ), 9 ) );
	}

	public function test_an_h1_section_with_no_button_or_image_is_not_a_hero(): void {
		$text = "<!-- wp:group -->\n<div class=\"wp-block-group\"><!-- wp:heading {\"level\":1} -->\n<h1 class=\"wp-block-heading\">Title</h1>\n<!-- /wp:heading --></div>\n<!-- /wp:group -->";

		self::assertNotSame( 'hero', self::guess( Builder::GUTENBERG, self::block( $text ), 1 ) );
	}

	public function test_a_group_with_an_h1_and_a_button_is_a_hero(): void {
		$group = "<!-- wp:group -->\n<div class=\"wp-block-group\"><!-- wp:heading {\"level\":1} -->\n<h1 class=\"wp-block-heading\">Title</h1>\n<!-- /wp:heading -->\n\n<!-- wp:button -->\n<div class=\"wp-block-button\"><a class=\"wp-block-button__link\">Go</a></div>\n<!-- /wp:button --></div>\n<!-- /wp:group -->";

		self::assertSame( 'hero', self::guess( Builder::GUTENBERG, self::block( $group ), 2 ) );
	}

	public function test_elementor_h1_headings_count_too(): void {
		$v3 = SectionFixtures::v3_hero();
		$v3['elements'][0]['elements'][0]['settings']['header_size'] = 'h1';

		self::assertSame( 'hero', self::guess( Builder::ELEMENTOR_V3, $v3, 1 ) );
		self::assertNotSame( 'hero', self::guess( Builder::ELEMENTOR_V3, SectionFixtures::v3_hero(), 1 ), 'The same section without an h1 is not.' );

		$v4 = SectionFixtures::v4_features( 'v', 3 );
		$v4['elements'][0]['settings']['tag'] = [ '$$type' => 'string', 'value' => 'h1' ];
		$v4['elements'][] = [ 'id' => 'vbtn', 'version' => '0.0', 'elType' => 'widget', 'widgetType' => 'e-button', 'isInner' => false, 'settings' => [], 'editor_settings' => [], 'interactions' => [], 'styles' => [], 'elements' => [] ];
		$inspection = SectionInspector::inspect( Builder::ELEMENTOR_V4, $v4 );
		self::assertSame( 1, $inspection['signals']['h1'] );
	}

	public function test_find_offers_a_late_cover_as_a_hero(): void {
		$intro   = "<!-- wp:paragraph -->\n<p>Intro</p>\n<!-- /wp:paragraph -->";
		$GLOBALS['stonewright_test_posts'][10] = SectionFixtures::post( 10, 'page', 'publish', 'Landing', $intro . "\n\n" . self::cover( 1 ) );

		$result = ( new SectionReuseFind() )->execute( [ 'builder' => 'gutenberg', 'roles' => [ 'hero' ] ] );

		self::assertIsArray( $result );
		self::assertSame( [ [ 'kind' => 'block', 'path' => [ 1 ] ] ], array_column( $result['roles'][0]['candidates'], 'locator' ) );
	}

	public function test_a_signature_cached_by_an_older_version_of_the_rules_is_analyzed_again(): void {
		$GLOBALS['stonewright_test_posts'][10] = SectionFixtures::post( 10, 'page', 'publish', 'Landing', "<!-- wp:paragraph -->\n<p>Intro</p>\n<!-- /wp:paragraph -->\n\n" . self::cover( 1 ) );
		update_option( SignatureCache::OPTION, [ '10' => [ 'm' => '2026-01-02 03:04:05', 'v' => 'gutenberg:' . get_bloginfo( 'version' ), 's' => 1, 'sections' => [] ] ] );

		$result = ( new SectionReuseFind() )->execute( [ 'builder' => 'gutenberg', 'roles' => [ 'hero' ] ] );

		self::assertIsArray( $result );
		self::assertSame( 1, $result['scan']['cache']['misses'], 'An entry written before the rules changed is not trusted.' );
		self::assertCount( 1, $result['roles'][0]['candidates'] );
	}
}
