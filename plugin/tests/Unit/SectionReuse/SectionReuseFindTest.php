<?php
/**
 * stonewright/section-reuse-find: candidates, permissions, the option gate and the bounded scan.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\SectionReuse;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\SectionReuse\SectionReuseFind;
use Stonewright\WpMcp\SectionReuse\ReferenceCatalog;
use Stonewright\WpMcp\SectionReuse\SectionReuseSetting;
use Stonewright\WpMcp\SectionReuse\SignatureCache;
use Stonewright\WpMcp\Security\IncidentStore;

require_once __DIR__ . '/SectionFixtures.php';

/**
 * @covers \Stonewright\WpMcp\Abilities\SectionReuse\SectionReuseFind
 * @covers \Stonewright\WpMcp\SectionReuse\SourceScanner
 * @covers \Stonewright\WpMcp\SectionReuse\SignatureCache
 * @covers \Stonewright\WpMcp\SectionReuse\SectionSource
 * @covers \Stonewright\WpMcp\SectionReuse\RoleOutline
 * @covers \Stonewright\WpMcp\SectionReuse\ReuseWarnings
 */
final class SectionReuseFindTest extends TestCase {

	protected function setUp(): void {
		IncidentStore::reset_for_tests();
		SignatureCache::reset_for_tests();
		ReferenceCatalog::set_provider( static fn( string $type, string $id ): ?bool => true );
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_post_types']      = [
			'post'              => (object) [ 'name' => 'post', 'public' => true ],
			'page'              => (object) [ 'name' => 'page', 'public' => true ],
			'elementor_library' => (object) [ 'name' => 'elementor_library', 'public' => false ],
			'wp_block'          => (object) [ 'name' => 'wp_block', 'public' => false ],
		];
		$GLOBALS['stonewright_test_user_caps']       = [ 'edit_posts' => true, 'read' => true ];
		$GLOBALS['stonewright_test_user_can_callback'] = static fn( string $cap, mixed ...$args ): bool => in_array( $cap, [ 'edit_posts', 'read_post', 'edit_post' ], true );
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 7;
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

	/**
	 * @param list<array<string, mixed>> $tree
	 */
	private static function add_elementor_page( int $id, array $tree, string $modified = '2026-01-02 03:04:05', string $status = 'publish', string $type = 'page', string $version = '3.30.0', string $template_type = '' ): void {
		$GLOBALS['stonewright_test_posts'][ $id ] = SectionFixtures::post( $id, $type, $status, 'Page ' . $id, '', SectionFixtures::elementor_meta( $tree, $version, $template_type ), $modified );
	}

	private static function add_block_page( int $id, string $content, string $modified = '2026-01-02 03:04:05', string $status = 'publish', string $type = 'page', array $meta = [] ): void {
		$GLOBALS['stonewright_test_posts'][ $id ] = SectionFixtures::post( $id, $type, $status, 'Page ' . $id, $content, $meta, $modified );
	}

	/** @return array<string, mixed> */
	private static function find( array $args ): array {
		$result = ( new SectionReuseFind() )->execute( $args );
		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );

		return $result;
	}

	/** @return list<int> Source post ids of the candidates of the first role. */
	private static function source_ids( array $result, int $role_index = 0 ): array {
		return array_map( static fn( array $candidate ): int => $candidate['source']['post_id'], $result['roles'][ $role_index ]['candidates'] );
	}

	public function test_while_the_setting_is_off_the_answer_is_only_the_instruction(): void {
		self::add_elementor_page( 10, [ SectionFixtures::v3_features() ] );
		$GLOBALS['stonewright_test_options'][ SectionReuseSetting::OPTION ] = 'off';

		$result = ( new SectionReuseFind() )->execute( [ 'builder' => 'elementor-v3', 'roles' => [ 'features' ] ] );

		self::assertSame( [ 'enabled' => false, 'instruction' => 'Section reuse is off. Do not ask the user about reusing sections.' ], $result );
		self::assertSame( [ 'hits' => 0, 'misses' => 0 ], SignatureCache::stats(), 'Nothing was scanned.' );
	}

	public function test_a_candidate_carries_source_locator_role_layout_similarity_outline_and_warnings(): void {
		self::add_elementor_page( 10, [ SectionFixtures::v3_hero( 'h', 'Welcome home' ), SectionFixtures::v3_features( 'f' ) ] );

		$result = self::find( [ 'builder' => 'elementor-v3', 'sections' => [ [ 'role' => 'features' ] ] ] );

		self::assertTrue( $result['enabled'] );
		self::assertSame( 'features', $result['roles'][0]['role'] );
		self::assertCount( 1, $result['roles'][0]['candidates'] );
		$candidate = $result['roles'][0]['candidates'][0];
		self::assertSame( 10, $candidate['source']['post_id'] );
		self::assertSame( 'page', $candidate['source']['type'] );
		self::assertSame( 'page', $candidate['source']['kind'] );
		self::assertSame( 'Page 10', $candidate['source']['title'] );
		self::assertSame( 'publish', $candidate['source']['status'] );
		self::assertStringContainsString( 'post=10', $candidate['source']['edit_url'] );
		self::assertSame( [ 'kind' => 'element', 'id' => 'f000001' ], $candidate['locator'] );
		self::assertSame( 'elementor-v3', $candidate['builder'] );
		self::assertSame( 'features', $candidate['role'] );
		self::assertSame( 3, $candidate['layout']['columns'] );
		self::assertSame( 4, $candidate['layout']['depth'] );
		self::assertSame( 1.0, $candidate['similarity'] );
		self::assertSame( 'Why choose us', $candidate['outline']['heading'] );
		self::assertSame( [], $candidate['warnings'] );
		self::assertSame( 1, $result['scan']['sources_scanned'] );
		self::assertFalse( $result['scan']['truncated'] );
	}

	public function test_candidates_come_only_from_posts_the_user_may_read_and_edit(): void {
		self::add_elementor_page( 10, [ SectionFixtures::v3_features( 'a' ) ] );
		self::add_elementor_page( 11, [ SectionFixtures::v3_features( 'b' ) ] );
		self::add_elementor_page( 12, [ SectionFixtures::v3_features( 'c' ) ] );
		self::add_elementor_page( 13, [ SectionFixtures::v3_features( 'd' ) ] );
		$GLOBALS['stonewright_test_user_can_callback'] = static function ( string $cap, mixed ...$args ): bool {
			$id = (int) ( $args[0] ?? 0 );
			return match ( $cap ) {
				'edit_posts' => true,
				'edit_post'  => 11 !== $id,
				'read_post'  => 12 !== $id,
				default      => false,
			};
		};

		$result = self::find( [ 'builder' => 'elementor-v3', 'roles' => [ 'features' ] ] );

		self::assertEqualsCanonicalizing( [ 10, 13 ], self::source_ids( $result ), 'No edit right (11) and no read right (12) are both left out.' );
		self::assertSame( 2, $result['scan']['sources_scanned'], 'A post the user may not use is not counted either.' );
	}

	public function test_the_target_post_is_never_offered(): void {
		self::add_elementor_page( 10, [ SectionFixtures::v3_features( 'a' ) ] );
		self::add_elementor_page( 11, [ SectionFixtures::v3_features( 'b' ) ] );

		$result = self::find( [ 'builder' => 'elementor-v3', 'roles' => [ 'features' ], 'target_post_id' => 10 ] );

		self::assertSame( [ 11 ], self::source_ids( $result ) );
		self::assertSame( 10, $result['target_post_id'] );
	}

	public function test_only_published_and_draft_sources_and_never_trash_autosaves_or_revisions(): void {
		self::add_elementor_page( 10, [ SectionFixtures::v3_features( 'a' ) ], '2026-01-05 00:00:00', 'publish' );
		self::add_elementor_page( 11, [ SectionFixtures::v3_features( 'b' ) ], '2026-01-04 00:00:00', 'draft' );
		self::add_elementor_page( 12, [ SectionFixtures::v3_features( 'c' ) ], '2026-01-03 00:00:00', 'trash' );
		self::add_elementor_page( 13, [ SectionFixtures::v3_features( 'd' ) ], '2026-01-03 00:00:00', 'auto-draft' );
		self::add_elementor_page( 14, [ SectionFixtures::v3_features( 'e' ) ], '2026-01-03 00:00:00', 'inherit', 'revision' );
		self::add_elementor_page( 15, [ SectionFixtures::v3_features( 'f' ) ], '2026-01-03 00:00:00', 'private' );

		$result = self::find( [ 'builder' => 'elementor-v3', 'roles' => [ 'features' ] ] );

		self::assertSame( [ 10, 11 ], self::source_ids( $result ), 'Newest first.' );
	}

	public function test_saved_section_templates_count_but_other_template_types_do_not(): void {
		self::add_elementor_page( 20, [ SectionFixtures::v3_features( 'a' ) ], '2026-01-02 00:00:00', 'publish', 'elementor_library', '3.30.0', 'section' );
		self::add_elementor_page( 21, [ SectionFixtures::v3_features( 'b' ) ], '2026-01-02 00:00:00', 'publish', 'elementor_library', '3.30.0', 'container' );
		self::add_elementor_page( 22, [ SectionFixtures::v3_features( 'c' ) ], '2026-01-02 00:00:00', 'publish', 'elementor_library', '3.30.0', 'header' );
		self::add_elementor_page( 23, [ SectionFixtures::v3_features( 'd' ) ], '2026-01-02 00:00:00', 'publish', 'elementor_library', '3.30.0', 'page' );

		$result = self::find( [ 'builder' => 'elementor-v3', 'roles' => [ 'features' ] ] );

		self::assertEqualsCanonicalizing( [ 20, 21 ], self::source_ids( $result ) );
		self::assertSame( 'template', $result['roles'][0]['candidates'][0]['source']['kind'] );
	}

	public function test_gutenberg_pages_and_patterns_are_sources(): void {
		self::add_block_page( 30, SectionFixtures::gutenberg_features_content( 'One', 3, 'a' ) );
		self::add_block_page( 31, SectionFixtures::gutenberg_features_content( 'Two', 3, 'b' ), '2026-01-01 00:00:00', 'publish', 'wp_block', [ 'wp_pattern_sync_status' => 'unsynced' ] );
		self::add_elementor_page( 32, [ SectionFixtures::v3_features( 'e' ) ] );

		$result = self::find( [ 'builder' => 'gutenberg', 'roles' => [ 'features' ] ] );

		self::assertEqualsCanonicalizing( [ 30, 31 ], self::source_ids( $result ), 'An Elementor page is not a Gutenberg source.' );
		$by_post = [];
		foreach ( $result['roles'][0]['candidates'] as $candidate ) {
			$by_post[ $candidate['source']['post_id'] ] = $candidate;
		}
		self::assertSame( [ 'kind' => 'block', 'path' => [ 0 ], 'anchor' => 'a' ], $by_post[30]['locator'] );
		self::assertSame( 'pattern', $by_post[31]['source']['kind'] );
		self::assertSame( 'pattern', $by_post[31]['locator']['kind'] );
	}

	public function test_v3_and_v4_sections_are_kept_apart_and_a_mixed_section_is_in_neither(): void {
		$mixed                       = SectionFixtures::v3_features( 'm' );
		$mixed['elements'][]         = SectionFixtures::v4_features( 'x' );
		self::add_elementor_page( 40, [ SectionFixtures::v3_features( 'a' ), SectionFixtures::v4_features( 'v' ), $mixed ] );

		$v3 = self::find( [ 'builder' => 'elementor-v3', 'roles' => [ 'features' ] ] );
		$v4 = self::find( [ 'builder' => 'elementor-v4', 'roles' => [ 'features' ] ] );

		self::assertCount( 1, $v3['roles'][0]['candidates'] );
		self::assertSame( 'a000001', $v3['roles'][0]['candidates'][0]['locator']['id'] );
		self::assertCount( 1, $v4['roles'][0]['candidates'] );
		self::assertSame( 'v000001', $v4['roles'][0]['candidates'][0]['locator']['id'] );
	}

	public function test_the_limit_per_role_defaults_to_three_and_is_capped_at_ten(): void {
		for ( $i = 1; $i <= 12; $i++ ) {
			self::add_elementor_page( 100 + $i, [ SectionFixtures::v3_features( 'p' . $i ) ], sprintf( '2026-01-%02d 00:00:00', $i ) );
		}

		self::assertCount( 3, self::find( [ 'builder' => 'elementor-v3', 'roles' => [ 'features' ] ] )['roles'][0]['candidates'] );
		self::assertCount( 5, self::find( [ 'builder' => 'elementor-v3', 'roles' => [ 'features' ], 'limit_per_role' => 5 ] )['roles'][0]['candidates'] );
		self::assertCount( 10, self::find( [ 'builder' => 'elementor-v3', 'roles' => [ 'features' ], 'limit_per_role' => 50 ] )['roles'][0]['candidates'], 'More than ten is clamped.' );
	}

	public function test_ranking_is_deterministic_by_similarity_then_recency_then_id(): void {
		self::add_elementor_page( 51, [ SectionFixtures::v3_features( 'a', 4 ) ], '2026-01-09 00:00:00' );
		self::add_elementor_page( 52, [ SectionFixtures::v3_features( 'b', 3 ) ], '2026-01-01 00:00:00' );
		self::add_elementor_page( 53, [ SectionFixtures::v3_features( 'c', 3 ) ], '2026-01-05 00:00:00' );

		$first  = self::find( [ 'builder' => 'elementor-v3', 'roles' => [ 'features' ] ] );
		SignatureCache::reset_for_tests();
		$second = self::find( [ 'builder' => 'elementor-v3', 'roles' => [ 'features' ] ] );

		self::assertSame( [ 53, 52, 51 ], self::source_ids( $first ), 'Three columns match the features profile exactly, the newer first; four columns score lower.' );
		self::assertSame( $first['roles'], $second['roles'] );
	}

	public function test_text_media_and_style_do_not_change_similarity(): void {
		self::add_elementor_page( 60, [ SectionFixtures::v3_features( 'a', 3, 'First', '#000000' ) ] );
		self::add_elementor_page( 61, [ SectionFixtures::v3_features( 'b', 3, 'Second', '#ffffff' ) ] );

		$result = self::find( [ 'builder' => 'elementor-v3', 'sections' => [ [ 'role' => 'features', 'layout' => [ 'columns' => 2, 'items' => 4 ] ] ] ] );

		$scores = array_column( $result['roles'][0]['candidates'], 'similarity' );
		self::assertCount( 2, $scores );
		self::assertSame( $scores[0], $scores[1] );
	}

	public function test_a_layout_hint_can_bring_in_a_section_of_another_role(): void {
		self::add_elementor_page( 70, [ SectionFixtures::v3_features( 'a', 3 ) ] );

		$without = self::find( [ 'builder' => 'elementor-v3', 'roles' => [ 'pricing' ] ] );
		$with    = self::find( [ 'builder' => 'elementor-v3', 'sections' => [ [ 'role' => 'pricing', 'layout' => [ 'columns' => 3, 'items' => 3, 'depth' => 4 ] ] ] ] );

		self::assertSame( [], $without['roles'][0]['candidates'] );
		self::assertSame( [ 70 ], self::source_ids( $with ) );
		self::assertSame( 'features', $with['roles'][0]['candidates'][0]['role'] );
	}

	public function test_an_outline_names_the_roles(): void {
		self::add_elementor_page( 80, [ SectionFixtures::v3_hero(), SectionFixtures::v3_features( 'a', 3 ) ] );

		$result = self::find( [ 'builder' => 'elementor-v3', 'outline' => 'hero, 3 feature cards and a contact form' ] );

		self::assertSame( [ 'hero', 'features', 'contact' ], array_column( $result['roles'], 'role' ) );
		self::assertSame( [ 80 ], self::source_ids( $result, 0 ) );
		self::assertSame( [ 80 ], self::source_ids( $result, 1 ) );
		self::assertSame( [], self::source_ids( $result, 2 ) );
	}

	public function test_a_request_that_names_no_role_is_refused(): void {
		$result = ( new SectionReuseFind() )->execute( [ 'builder' => 'elementor-v3', 'outline' => 'nothing recognizable here' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertStringContainsString( 'roles_required', (string) $result->get_error_code() );
	}

	public function test_warnings_report_dynamic_tags_forms_third_party_widgets_and_missing_references(): void {
		$section = SectionFixtures::v3_features( 'a', 3 );
		$section['elements'][0]['settings']['__dynamic__'] = [ 'title' => '[elementor-tag id="x1" name="post-title" settings="%7B%7D"]' ];
		$section['elements'][0]['settings']['__globals__'] = [ 'title_color' => 'globals/colors?id=gone' ];
		$section['elements'][1]['elements'][] = [ 'id' => 'a000099', 'elType' => 'widget', 'widgetType' => 'acme-widget', 'settings' => [], 'elements' => [] ];
		self::add_elementor_page( 90, [ $section ] );
		ReferenceCatalog::set_provider( static fn( string $type, string $id ): ?bool => 'gone' === $id ? false : true );

		$warnings = self::find( [ 'builder' => 'elementor-v3', 'roles' => [ 'features' ] ] )['roles'][0]['candidates'][0]['warnings'];

		self::assertSame( [ 'dynamic_tags', 'third_party_widgets', 'missing_global_colors' ], array_column( $warnings, 'code' ) );
		self::assertSame( [ 'gone' ], $warnings[2]['items'] );
	}

	public function test_a_scan_of_more_than_two_hundred_sources_is_truncated_and_says_so(): void {
		for ( $i = 1; $i <= 205; $i++ ) {
			self::add_block_page( 1000 + $i, '<!-- wp:paragraph --><p>Page ' . $i . '</p><!-- /wp:paragraph -->', sprintf( '2026-%02d-%02d 00:00:00', 1 + intdiv( $i, 28 ), 1 + ( $i % 28 ) ) );
		}

		$result = self::find( [ 'builder' => 'gutenberg', 'roles' => [ 'features' ] ] );

		self::assertTrue( $result['scan']['truncated'] );
		self::assertSame( 200, $result['scan']['sources_scanned'] );
		self::assertSame( 200, $result['scan']['sources_limit'] );
		self::assertGreaterThan( 0, $result['scan']['limits']['max_elements'] );
		self::assertGreaterThan( 0, $result['scan']['limits']['max_depth'] );
	}

	public function test_a_scan_of_two_hundred_sources_or_fewer_is_not_truncated(): void {
		for ( $i = 1; $i <= 200; $i++ ) {
			self::add_block_page( 1000 + $i, '<!-- wp:paragraph --><p>Page ' . $i . '</p><!-- /wp:paragraph -->' );
		}

		$result = self::find( [ 'builder' => 'gutenberg', 'roles' => [ 'features' ] ] );

		self::assertFalse( $result['scan']['truncated'] );
		self::assertSame( 200, $result['scan']['sources_scanned'] );
	}

	public function test_signatures_are_cached_by_modification_time_and_builder_version(): void {
		self::add_elementor_page( 10, [ SectionFixtures::v3_features( 'a' ) ], '2026-01-02 03:04:05', 'publish', 'page', '3.30.0' );

		$cold = self::find( [ 'builder' => 'elementor-v3', 'roles' => [ 'features' ] ] );
		self::assertSame( [ 'hits' => 0, 'misses' => 1 ], $cold['scan']['cache'] );
		self::assertArrayHasKey( SignatureCache::OPTION, $GLOBALS['stonewright_test_options'], 'The cache is stored in its own option.' );

		SignatureCache::reset_for_tests();
		$warm = self::find( [ 'builder' => 'elementor-v3', 'roles' => [ 'features' ] ] );
		self::assertSame( [ 'hits' => 1, 'misses' => 0 ], $warm['scan']['cache'] );
		self::assertSame( $cold['roles'], $warm['roles'] );

		$GLOBALS['stonewright_test_posts'][10]->post_modified_gmt = '2026-02-01 00:00:00';
		SignatureCache::reset_for_tests();
		self::assertSame( [ 'hits' => 0, 'misses' => 1 ], self::find( [ 'builder' => 'elementor-v3', 'roles' => [ 'features' ] ] )['scan']['cache'], 'A newer modification time forces a new signature.' );

		$GLOBALS['stonewright_test_posts'][10]->meta['_elementor_version'] = '4.0.0';
		SignatureCache::reset_for_tests();
		self::assertSame( [ 'hits' => 0, 'misses' => 1 ], self::find( [ 'builder' => 'elementor-v3', 'roles' => [ 'features' ] ] )['scan']['cache'], 'A different builder version forces a new signature.' );
	}

	public function test_a_cached_signature_does_not_read_the_source_again(): void {
		self::add_elementor_page( 10, [ SectionFixtures::v3_features( 'a' ) ] );
		self::find( [ 'builder' => 'elementor-v3', 'roles' => [ 'features' ] ] );
		SignatureCache::reset_for_tests();
		// A source whose data can no longer be read still answers from its cache while its modification time is unchanged.
		$GLOBALS['stonewright_test_posts'][10]->meta['_elementor_data'] = 'not json';

		$result = self::find( [ 'builder' => 'elementor-v3', 'roles' => [ 'features' ] ] );

		self::assertSame( [ 10 ], self::source_ids( $result ) );
	}

	public function test_the_scan_never_changes_a_source_post(): void {
		self::add_elementor_page( 10, [ SectionFixtures::v3_features( 'a' ) ] );
		self::add_block_page( 11, SectionFixtures::gutenberg_features_content() );
		$before = serialize( $GLOBALS['stonewright_test_posts'] );

		self::find( [ 'builder' => 'elementor-v3', 'roles' => [ 'features' ] ] );
		self::find( [ 'builder' => 'gutenberg', 'roles' => [ 'features' ] ] );

		self::assertSame( $before, serialize( $GLOBALS['stonewright_test_posts'] ) );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );
	}

	public function test_the_ability_is_read_only_and_checks_a_real_permission(): void {
		$ability = new SectionReuseFind();

		$GLOBALS['stonewright_test_user_can_callback'] = null;
		$GLOBALS['stonewright_test_user_caps'] = [];
		self::assertFalse( $ability->permission_callback( [ 'builder' => 'gutenberg' ] ) );
		$GLOBALS['stonewright_test_user_caps'] = [ 'edit_posts' => true ];
		self::assertTrue( $ability->permission_callback( [ 'builder' => 'gutenberg' ] ) );
		self::assertSame( 'stonewright/section-reuse-find', $ability->name() );
	}
}
