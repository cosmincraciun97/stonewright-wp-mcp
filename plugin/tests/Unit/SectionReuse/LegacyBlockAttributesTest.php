<?php
/**
 * An older stored block attribute that WordPress now keeps elsewhere does not block a whole Gutenberg section.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\SectionReuse;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Gutenberg\BlocksBatchMutate;
use Stonewright\WpMcp\Abilities\SectionReuse\SectionReuseExtract;
use Stonewright\WpMcp\SectionReuse\LegacyBlockAttributes;
use Stonewright\WpMcp\SectionReuse\ReferenceCatalog;
use Stonewright\WpMcp\Security\IncidentStore;
use Stonewright\WpMcp\Support\BlockTree;

require_once __DIR__ . '/SectionFixtures.php';

/**
 * @covers \Stonewright\WpMcp\SectionReuse\LegacyBlockAttributes
 * @covers \Stonewright\WpMcp\SectionReuse\PortableSection
 * @covers \Stonewright\WpMcp\SectionReuse\GutenbergSectionInserter
 */
final class LegacyBlockAttributesTest extends TestCase {

	private const TARGET  = 701;
	private const SOURCE  = 30;
	private const PATTERN = 40;

	private const INTRO = "<!-- wp:paragraph -->\n<p>Intro</p>\n<!-- /wp:paragraph -->";

	/** A group around a heading that an older WordPress saved with its alignment as a `textAlign` attribute. */
	private const LEGACY_SECTION = "<!-- wp:group {\"anchor\":\"hello\"} -->\n<div id=\"hello\" class=\"wp-block-group\"><!-- wp:heading {\"textAlign\":\"center\"} -->\n<h2 class=\"wp-block-heading has-text-align-center\">Hello</h2>\n<!-- /wp:heading --></div>\n<!-- /wp:group -->";

	protected function setUp(): void {
		IncidentStore::reset_for_tests();
		ReferenceCatalog::set_provider( static fn( string $type, string $id ): ?bool => true );
		$GLOBALS['stonewright_test_options']              = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_post_meta_calls']      = [];
		$GLOBALS['stonewright_test_wp_update_post_calls'] = [];
		$GLOBALS['stonewright_test_wpdb_inserts']         = [];
		$GLOBALS['stonewright_test_user_caps']            = [ 'edit_post' => true, 'read_post' => true, 'edit_posts' => true, 'read' => true ];
		$GLOBALS['stonewright_test_user_can_callback']    = null;
		$GLOBALS['stonewright_test_user_logged_in']       = true;
		$GLOBALS['stonewright_test_current_user_id']      = 1;
		$GLOBALS['stonewright_test_registered_blocks']    = self::current_blocks();
		$GLOBALS['stonewright_test_posts']                = [
			self::TARGET  => SectionFixtures::post( self::TARGET, 'page', 'draft', 'New page', self::INTRO ),
			self::SOURCE  => SectionFixtures::post( self::SOURCE, 'page', 'publish', 'Source page', self::LEGACY_SECTION ),
			self::PATTERN => SectionFixtures::post( self::PATTERN, 'wp_block', 'publish', 'Banner', "<!-- wp:heading {\"textAlign\":\"right\"} -->\n<h2 class=\"wp-block-heading has-text-align-right\">Pattern</h2>\n<!-- /wp:heading -->" ),
		];
	}

	protected function tearDown(): void {
		ReferenceCatalog::set_provider( null );
		IncidentStore::reset_for_tests();
		unset( $GLOBALS['stonewright_test_registered_blocks'] );
		$GLOBALS['stonewright_test_posts']                = [];
		$GLOBALS['stonewright_test_options']              = [];
		$GLOBALS['stonewright_test_post_meta_calls']      = [];
		$GLOBALS['stonewright_test_wp_update_post_calls'] = [];
		$GLOBALS['stonewright_test_wpdb_inserts']         = [];
		$GLOBALS['stonewright_test_user_caps']            = [];
		$GLOBALS['stonewright_test_user_can_callback']    = null;
		$GLOBALS['stonewright_test_user_logged_in']       = false;
		$GLOBALS['stonewright_test_current_user_id']      = 0;
	}

	/**
	 * Block types as a current WordPress registers them: the alignment is a typography support that keeps its
	 * value in `style`, and a block has no `textAlign` attribute of its own.
	 *
	 * @return array<string, object>
	 */
	private static function current_blocks(): array {
		$typography = (object) [ 'attributes' => [ 'content' => [ 'type' => 'rich-text' ], 'level' => [ 'type' => 'number' ] ], 'supports' => [ 'anchor' => true, 'typography' => [ 'textAlign' => true, 'fontSize' => true ] ] ];

		return [
			'core/group'     => (object) [ 'attributes' => [], 'supports' => [ 'anchor' => true, 'layout' => true ] ],
			'core/heading'   => $typography,
			'core/button'    => (object) [ 'attributes' => [ 'text' => [ 'type' => 'string' ] ], 'supports' => [ 'typography' => [ 'textAlign' => true ] ] ],
			'core/paragraph' => (object) [ 'attributes' => [ 'content' => [ 'type' => 'rich-text' ] ], 'supports' => [ 'typography' => [ 'textAlign' => true ] ] ],
			'core/block'     => (object) [ 'attributes' => [ 'ref' => [ 'type' => 'number' ] ], 'supports' => [] ],
		];
	}

	/** @return array<string, mixed> The extract result. */
	private static function extract( string $content, array $locator = [ 'kind' => 'block', 'path' => [ 0 ] ] ): array {
		$GLOBALS['stonewright_test_posts'][ self::SOURCE ]->post_content = $content;
		$GLOBALS['stonewright_test_user_can_callback'] = static fn( string $cap, mixed ...$args ): bool => in_array( $cap, [ 'edit_posts', 'read_post', 'edit_post' ], true );
		$result = ( new SectionReuseExtract() )->execute( [ 'post_id' => self::SOURCE, 'locator' => $locator ] );
		$GLOBALS['stonewright_test_user_can_callback'] = null;
		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );

		return $result;
	}

	/** @return array<string, mixed>|\WP_Error */
	private static function insert( array $section, array $extra = [], bool $dry_run = false ): array|\WP_Error {
		$ops  = [ array_merge( [ 'action' => 'insert_section', 'op_id' => 'new', 'path' => [], 'position' => 1, 'section' => $section ], $extra ) ];
		$plan = ( new BlocksBatchMutate() )->execute( [ 'post_id' => self::TARGET, 'dry_run' => true, 'operations' => $ops ] );
		if ( $dry_run || $plan instanceof \WP_Error ) {
			return $plan;
		}

		return ( new BlocksBatchMutate() )->execute( [ 'post_id' => self::TARGET, 'expected_content_hash' => $plan['before_hash'], 'operations' => $ops ] );
	}

	private static function error_code( array|\WP_Error $result ): string {
		return $result instanceof \WP_Error ? (string) ( $result->get_error_data()['items'][0]['error']['code'] ?? $result->get_error_code() ) : '';
	}

	/** @return array<string, mixed> The warning with that code. */
	private static function warning( array $result, string $code ): array {
		foreach ( $result['warnings'] as $warning ) {
			if ( $code === $warning['code'] ) {
				return $warning;
			}
		}
		self::fail( 'No warning ' . $code . ' in ' . wp_json_encode( $result['warnings'] ) );
	}

	public function test_the_strict_insert_refuses_the_legacy_attribute_when_nothing_migrates_it(): void {
		$section = [ 'schema' => 'SectionPortableV1', 'builder' => 'gutenberg', 'blocks' => BlockTree::parse( self::LEGACY_SECTION ) ];

		$result = self::insert( $section, [], true );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_unknown_block_attributes', self::error_code( $result ) );
		self::assertSame( [ 'textAlign' ], $result->get_error_data()['items'][0]['error']['data']['offending_keys'] );
	}

	public function test_extract_moves_text_align_to_the_current_form_and_names_it_in_a_warning(): void {
		$result = self::extract( self::LEGACY_SECTION );

		$heading = $result['section']['blocks'][0]['innerBlocks'][0];
		self::assertSame( [ 'style' => [ 'typography' => [ 'textAlign' => 'center' ] ] ], $heading['attrs'] );
		self::assertStringContainsString( 'has-text-align-center', $heading['innerHTML'], 'The saved markup is carried as it is.' );
		$warning = self::warning( $result, 'legacy_attributes' );
		self::assertSame( 1, $warning['count'] );
		self::assertSame( [ 'core/heading: textAlign -> style.typography.textAlign' ], $warning['items'] );
	}

	public function test_the_migrated_section_inserts_and_keeps_its_markup(): void {
		$section = self::extract( self::LEGACY_SECTION )['section'];

		$result = self::insert( $section );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() . wp_json_encode( $result->get_error_data() ) : '' );
		self::assertSame( 'verified', $result['verification_status'] );
		$content = (string) get_post( self::TARGET )->post_content;
		self::assertStringContainsString( '"style":{"typography":{"textAlign":"center"}}', $content );
		self::assertStringNotContainsString( '{"textAlign":"center"} -->', $content );
		self::assertStringContainsString( 'has-text-align-center', $content );
	}

	public function test_a_value_already_in_style_is_kept_beside_the_migrated_one(): void {
		$content = "<!-- wp:heading {\"textAlign\":\"left\",\"style\":{\"typography\":{\"fontSize\":\"1.5rem\"}}} -->\n<h2 class=\"wp-block-heading has-text-align-left\">Hi</h2>\n<!-- /wp:heading -->";

		$heading = self::extract( $content )['section']['blocks'][0];

		self::assertSame( [ 'typography' => [ 'fontSize' => '1.5rem', 'textAlign' => 'left' ] ], $heading['attrs']['style'] );
		self::assertArrayNotHasKey( 'textAlign', $heading['attrs'] );
	}

	public function test_only_blocks_that_core_migrates_are_changed_and_the_rest_stay_refused_by_key(): void {
		$content = "<!-- wp:group -->\n<div class=\"wp-block-group\"><!-- wp:button {\"textAlign\":\"right\"} -->\n<div class=\"wp-block-button\"><a class=\"wp-block-button__link has-text-align-right wp-element-button\">Go</a></div>\n<!-- /wp:button -->\n\n<!-- wp:paragraph {\"textAlign\":\"center\"} -->\n<p class=\"has-text-align-center\">Not on the list</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:group -->";

		$result = self::extract( $content );

		$inner = $result['section']['blocks'][0]['innerBlocks'];
		self::assertSame( [ 'style' => [ 'typography' => [ 'textAlign' => 'right' ] ] ], $inner[0]['attrs'], 'core/button is on the list.' );
		self::assertSame( [ 'textAlign' => 'center' ], $inner[1]['attrs'], 'core/paragraph is not, so it is carried untouched.' );
		self::assertSame( [ 'core/button: textAlign -> style.typography.textAlign' ], self::warning( $result, 'legacy_attributes' )['items'] );

		$refused = self::insert( $result['section'], [], true );
		self::assertInstanceOf( \WP_Error::class, $refused );
		self::assertSame( 'stonewright_unknown_block_attributes', self::error_code( $refused ) );
		self::assertSame( [ 'textAlign' ], $refused->get_error_data()['items'][0]['error']['data']['offending_keys'] );
	}

	public function test_a_site_whose_block_still_declares_the_attribute_is_left_alone(): void {
		$GLOBALS['stonewright_test_registered_blocks']['core/heading'] = (object) [
			'attributes' => [ 'content' => [ 'type' => 'string' ], 'textAlign' => [ 'type' => 'string' ] ],
			'supports'   => [ 'anchor' => true ],
		];

		$result = self::extract( self::LEGACY_SECTION );

		self::assertSame( [ 'textAlign' => 'center' ], $result['section']['blocks'][0]['innerBlocks'][0]['attrs'] );
		self::assertSame( [], $result['warnings'], 'Nothing was changed, so nothing is reported.' );
	}

	public function test_a_block_that_is_not_registered_here_is_left_alone(): void {
		unset( $GLOBALS['stonewright_test_registered_blocks']['core/heading'] );

		$result = self::extract( self::LEGACY_SECTION );

		self::assertSame( [ 'textAlign' => 'center' ], $result['section']['blocks'][0]['innerBlocks'][0]['attrs'] );
		self::assertSame( [], $result['warnings'] );
	}

	public function test_a_section_with_nothing_to_migrate_has_no_warning(): void {
		$result = self::extract( SectionFixtures::gutenberg_features_content() );

		self::assertSame( [], $result['warnings'] );
	}

	public function test_an_empty_or_non_text_value_is_not_touched(): void {
		$blocks = [
			[ 'blockName' => 'core/heading', 'attrs' => [ 'textAlign' => '' ], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => [] ],
			[ 'blockName' => 'core/heading', 'attrs' => [ 'textAlign' => [ 'x' ] ], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => [] ],
		];

		$result = LegacyBlockAttributes::migrate( $blocks );

		self::assertSame( $blocks, $result['blocks'] );
		self::assertSame( [], $result['migrated'] );
	}

	public function test_a_detached_pattern_copy_is_migrated_and_reported_by_the_insert(): void {
		$content = "<!-- wp:group {\"anchor\":\"promo\"} -->\n<div id=\"promo\" class=\"wp-block-group\"><!-- wp:block {\"ref\":40} /--></div>\n<!-- /wp:group -->";
		$section = self::extract( $content )['section'];

		$kept = self::insert( $section, [ 'detach_patterns' => false ], true );
		self::assertIsArray( $kept, 'A reference carries no attributes of its own.' );

		$result = self::insert( $section, [ 'detach_patterns' => true ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() . wp_json_encode( $result->get_error_data() ) : '' );
		self::assertSame( 'verified', $result['verification_status'] );
		self::assertStringContainsString( '"style":{"typography":{"textAlign":"right"}}', (string) get_post( self::TARGET )->post_content );
		$codes = array_column( $result['items'][0]['warnings'], 'code' );
		self::assertContains( 'legacy_attributes', $codes );
	}
}
