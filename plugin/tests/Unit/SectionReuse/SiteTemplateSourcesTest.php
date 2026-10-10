<?php
/**
 * Customized templates and template parts of a block theme are sources and targets of section reuse.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\SectionReuse;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Gutenberg\BlocksBatchMutate;
use Stonewright\WpMcp\Abilities\SectionReuse\SectionReuseExtract;
use Stonewright\WpMcp\Abilities\SectionReuse\SectionReuseFind;
use Stonewright\WpMcp\SectionReuse\ReferenceCatalog;
use Stonewright\WpMcp\SectionReuse\SectionSource;
use Stonewright\WpMcp\SectionReuse\SignatureCache;
use Stonewright\WpMcp\Security\IncidentStore;
use Stonewright\WpMcp\Support\BlockTree;

require_once __DIR__ . '/SectionFixtures.php';

/**
 * @covers \Stonewright\WpMcp\SectionReuse\SourceScanner
 * @covers \Stonewright\WpMcp\SectionReuse\SectionSource
 * @covers \Stonewright\WpMcp\Abilities\SectionReuse\SectionReuseFind
 */
final class SiteTemplateSourcesTest extends TestCase {

	private const PAGE      = 10;
	private const PART      = 50;
	private const TEMPLATE  = 51;
	private const OTHER     = 52;
	private const TARGET    = 701;
	private const THEME     = 'stonewright-theme';

	protected function setUp(): void {
		IncidentStore::reset_for_tests();
		SignatureCache::reset_for_tests();
		ReferenceCatalog::set_provider( static fn( string $type, string $id ): ?bool => true );
		$GLOBALS['stonewright_test_options']              = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_stylesheet']           = self::THEME;
		$GLOBALS['stonewright_test_is_block_theme']       = true;
		$GLOBALS['stonewright_test_post_meta_calls']      = [];
		$GLOBALS['stonewright_test_wp_update_post_calls'] = [];
		$GLOBALS['stonewright_test_wpdb_inserts']         = [];
		// The stub lists every type it is given, so only the public ones go in: the template types are not public.
		$GLOBALS['stonewright_test_post_types']           = [
			'page'     => (object) [ 'name' => 'page', 'public' => true ],
			'wp_block' => (object) [ 'name' => 'wp_block', 'public' => false ],
		];
		$GLOBALS['stonewright_test_user_caps']            = [ 'edit_posts' => true, 'read' => true, 'edit_post' => true, 'read_post' => true ];
		$GLOBALS['stonewright_test_user_can_callback']    = null;
		$GLOBALS['stonewright_test_user_logged_in']       = true;
		$GLOBALS['stonewright_test_current_user_id']      = 1;
		$GLOBALS['stonewright_test_posts']                = [
			self::PAGE     => SectionFixtures::post( self::PAGE, 'page', 'publish', 'About', SectionFixtures::gutenberg_features_content( 'Why us', 3, 'about' ) ),
			self::PART     => self::site_template( self::PART, 'wp_template_part', 'Footer', self::THEME ),
			self::TEMPLATE => self::site_template( self::TEMPLATE, 'wp_template', 'Landing', self::THEME ),
			self::OTHER    => self::site_template( self::OTHER, 'wp_template_part', 'Old footer', 'another-theme' ),
			self::TARGET   => SectionFixtures::post( self::TARGET, 'wp_template_part', 'publish', 'Header', "<!-- wp:paragraph -->\n<p>Header</p>\n<!-- /wp:paragraph -->" ),
		];
		$GLOBALS['stonewright_test_posts'][ self::TARGET ]->stonewright_test_terms = [ 'wp_theme' => [ self::THEME ] ];
	}

	protected function tearDown(): void {
		ReferenceCatalog::set_provider( null );
		SignatureCache::reset_for_tests();
		IncidentStore::reset_for_tests();
		unset( $GLOBALS['stonewright_test_stylesheet'], $GLOBALS['stonewright_test_is_block_theme'] );
		$GLOBALS['stonewright_test_posts']                = [];
		$GLOBALS['stonewright_test_options']              = [];
		$GLOBALS['stonewright_test_post_types']           = [];
		$GLOBALS['stonewright_test_post_meta_calls']      = [];
		$GLOBALS['stonewright_test_wp_update_post_calls'] = [];
		$GLOBALS['stonewright_test_wpdb_inserts']         = [];
		$GLOBALS['stonewright_test_user_caps']            = [];
		$GLOBALS['stonewright_test_user_can_callback']    = null;
		$GLOBALS['stonewright_test_user_logged_in']       = false;
		$GLOBALS['stonewright_test_current_user_id']      = 0;
	}

	private static function site_template( int $id, string $type, string $title, string $theme ): object {
		$content = "<!-- wp:group {\"anchor\":\"site-{$id}\"} -->\n<div id=\"site-{$id}\" class=\"wp-block-group\"><!-- wp:heading -->\n<h2 class=\"wp-block-heading\">{$title}</h2>\n<!-- /wp:heading --></div>\n<!-- /wp:group -->";
		$post    = SectionFixtures::post( $id, $type, 'publish', $title, $content, [], '2026-02-0' . ( $id - 48 ) . ' 00:00:00' );
		$post->stonewright_test_terms = [ 'wp_theme' => [ $theme ] ];

		return $post;
	}

	/** @return array<int, array<string, mixed>> Candidates of the first role by source post id. */
	private static function candidates( array $args = [] ): array {
		$result = ( new SectionReuseFind() )->execute( array_merge( [ 'builder' => 'gutenberg', 'roles' => [ 'other', 'features' ], 'limit_per_role' => 10 ], $args ) );
		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$by_post = [];
		foreach ( $result['roles'] as $role ) {
			foreach ( $role['candidates'] as $candidate ) {
				$by_post[ $candidate['source']['post_id'] ] = $candidate;
			}
		}

		return $by_post;
	}

	public function test_find_lists_customized_templates_and_parts_of_the_active_block_theme_with_their_post_type(): void {
		$found = self::candidates();

		self::assertArrayHasKey( self::PART, $found );
		self::assertArrayHasKey( self::TEMPLATE, $found );
		self::assertSame( 'wp_template_part', $found[ self::PART ]['source']['type'] );
		self::assertSame( 'wp_template', $found[ self::TEMPLATE ]['source']['type'] );
		self::assertSame( SectionSource::KIND_SITE_TEMPLATE, $found[ self::PART ]['source']['kind'] );
		self::assertSame( 'Footer', $found[ self::PART ]['source']['title'] );
		self::assertSame( [ 'kind' => 'block', 'path' => [ 0 ], 'anchor' => 'site-' . self::PART ], $found[ self::PART ]['locator'] );
		self::assertArrayHasKey( self::PAGE, $found, 'Pages are still listed.' );
		self::assertSame( 'page', $found[ self::PAGE ]['source']['kind'] );
	}

	public function test_a_template_of_another_theme_is_not_a_source(): void {
		self::assertArrayNotHasKey( self::OTHER, self::candidates() );
	}

	public function test_a_classic_theme_has_no_template_sources(): void {
		$GLOBALS['stonewright_test_is_block_theme'] = false;

		$found = self::candidates();

		self::assertArrayNotHasKey( self::PART, $found );
		self::assertArrayNotHasKey( self::TEMPLATE, $found );
		self::assertArrayHasKey( self::PAGE, $found );
	}

	public function test_only_users_who_may_edit_templates_see_them(): void {
		$GLOBALS['stonewright_test_user_can_callback'] = static fn( string $cap, mixed ...$args ): bool => match ( $cap ) {
			'edit_posts', 'read_post' => true,
			'edit_post'               => ! in_array( (int) ( $args[0] ?? 0 ), [ self::PART, self::TEMPLATE, self::OTHER, self::TARGET ], true ),
			default                   => false,
		};

		$found = self::candidates();

		self::assertArrayNotHasKey( self::PART, $found );
		self::assertArrayNotHasKey( self::TEMPLATE, $found );
		self::assertArrayHasKey( self::PAGE, $found );
	}

	public function test_an_elementor_find_never_lists_them(): void {
		$GLOBALS['stonewright_test_posts'][ self::PAGE ] = SectionFixtures::post( self::PAGE, 'page', 'publish', 'About', '', SectionFixtures::elementor_meta( [ SectionFixtures::v3_features( 'f' ) ] ) );

		$found = self::candidates( [ 'builder' => 'elementor-v3', 'roles' => [ 'features' ] ] );

		self::assertSame( [ self::PAGE ], array_keys( $found ) );
	}

	public function test_the_target_template_is_never_offered_to_itself(): void {
		self::assertArrayNotHasKey( self::PART, self::candidates( [ 'target_post_id' => self::PART ] ) );
	}

	public function test_extract_and_insert_keep_working_on_a_template_part(): void {
		$GLOBALS['stonewright_test_user_can_callback'] = static fn( string $cap, mixed ...$args ): bool => in_array( $cap, [ 'edit_posts', 'read_post', 'edit_post' ], true );
		$extracted = ( new SectionReuseExtract() )->execute( [ 'post_id' => self::PAGE, 'locator' => [ 'kind' => 'block', 'path' => [ 0 ] ] ] );
		$GLOBALS['stonewright_test_user_can_callback'] = null;
		self::assertIsArray( $extracted, $extracted instanceof \WP_Error ? $extracted->get_error_message() : '' );
		$ops  = [ [ 'action' => 'insert_section', 'op_id' => 'feat', 'path' => [], 'position' => 1, 'section' => $extracted['section'] ] ];

		$plan   = ( new BlocksBatchMutate() )->execute( [ 'post_id' => self::TARGET, 'dry_run' => true, 'operations' => $ops ] );
		self::assertIsArray( $plan, $plan instanceof \WP_Error ? $plan->get_error_message() . wp_json_encode( $plan->get_error_data() ) : '' );
		$result = ( new BlocksBatchMutate() )->execute( [ 'post_id' => self::TARGET, 'expected_content_hash' => $plan['before_hash'], 'operations' => $ops ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() . wp_json_encode( $result->get_error_data() ) : '' );
		self::assertSame( 'verified', $result['verification_status'] );
		self::assertNotSame( '', $result['snapshot_id'] );
		self::assertSame( [ 'core/paragraph', 'core/group' ], array_column( BlockTree::parse( (string) get_post( self::TARGET )->post_content ), 'blockName' ) );
		self::assertSame( [ self::PAGE ], array_column( $result['change_set']['reuse_source'], 'post_id' ) );
	}

	public function test_extract_from_a_template_part_returns_its_section(): void {
		$GLOBALS['stonewright_test_user_can_callback'] = static fn( string $cap, mixed ...$args ): bool => in_array( $cap, [ 'edit_posts', 'read_post', 'edit_post' ], true );

		$result = ( new SectionReuseExtract() )->execute( [ 'post_id' => self::PART, 'locator' => [ 'kind' => 'block', 'path' => [ 0 ] ] ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertSame( 'wp_template_part', $result['source']['type'] );
		self::assertSame( 'gutenberg', $result['builder'] );
		self::assertSame( 'core/group', $result['section']['blocks'][0]['blockName'] );
	}
}
