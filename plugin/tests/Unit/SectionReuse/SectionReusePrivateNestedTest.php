<?php
/**
 * Section reuse from private and other unpublished sources, from nested containers, and the errors for a source without a usable document.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\SectionReuse;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\SectionReuse\SectionReuseExtract;
use Stonewright\WpMcp\Abilities\SectionReuse\SectionReuseFind;
use Stonewright\WpMcp\SectionReuse\NestedContainers;
use Stonewright\WpMcp\SectionReuse\ReferenceCatalog;
use Stonewright\WpMcp\SectionReuse\SignatureCache;
use Stonewright\WpMcp\Security\IncidentStore;

require_once __DIR__ . '/SectionFixtures.php';

/**
 * @covers \Stonewright\WpMcp\Abilities\SectionReuse\SectionReuseExtract
 * @covers \Stonewright\WpMcp\Abilities\SectionReuse\SectionReuseFind
 * @covers \Stonewright\WpMcp\SectionReuse\SectionSource
 * @covers \Stonewright\WpMcp\SectionReuse\SourceScanner
 * @covers \Stonewright\WpMcp\SectionReuse\NestedContainers
 * @covers \Stonewright\WpMcp\SectionReuse\SourceWarnings
 */
final class SectionReusePrivateNestedTest extends TestCase {

	protected function setUp(): void {
		IncidentStore::reset_for_tests();
		SignatureCache::reset_for_tests();
		ReferenceCatalog::set_provider( static fn( string $type, string $id ): ?bool => true );
		$GLOBALS['stonewright_test_options']           = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_posts']             = [];
		$GLOBALS['stonewright_test_post_meta_calls']   = [];
		$GLOBALS['stonewright_test_wpdb_inserts']      = [];
		$GLOBALS['stonewright_test_transients']        = [];
		$GLOBALS['stonewright_test_post_types']        = [
			'post'              => (object) [ 'name' => 'post', 'public' => true ],
			'page'              => (object) [ 'name' => 'page', 'public' => true ],
			'elementor_library' => (object) [ 'name' => 'elementor_library', 'public' => false ],
			'wp_block'          => (object) [ 'name' => 'wp_block', 'public' => false ],
		];
		$GLOBALS['stonewright_test_user_caps']         = [ 'edit_posts' => true, 'read' => true ];
		$GLOBALS['stonewright_test_user_can_callback'] = self::can_edit_everything();
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
		$GLOBALS['stonewright_test_transients']        = [];
		$GLOBALS['stonewright_test_user_caps']         = [];
		$GLOBALS['stonewright_test_user_can_callback'] = null;
		$GLOBALS['stonewright_test_user_logged_in']    = false;
	}

	private static function can_edit_everything(): \Closure {
		return static fn( string $cap, mixed ...$args ): bool => in_array( $cap, [ 'edit_posts', 'read_post', 'edit_post' ], true );
	}

	/** A user who may read every post but edit none of the posts listed. */
	private static function cannot_edit( int ...$ids ): \Closure {
		return static fn( string $cap, mixed ...$args ): bool => match ( $cap ) {
			'edit_posts', 'read_post' => true,
			'edit_post'               => ! in_array( (int) ( $args[0] ?? 0 ), $ids, true ),
			default                   => false,
		};
	}

	/** @param list<array<string, mixed>> $tree */
	private static function elementor_post( int $id, array $tree, string $status = 'publish', string $type = 'page', string $modified = '2026-01-02 03:04:05' ): void {
		$GLOBALS['stonewright_test_posts'][ $id ] = SectionFixtures::post( $id, $type, $status, 'Source ' . $id, '', SectionFixtures::elementor_meta( $tree ), $modified );
	}

	/** @return array<string, mixed>|\WP_Error */
	private static function extract( int $post_id, string $element_id ): array|\WP_Error {
		return ( new SectionReuseExtract() )->execute( [ 'post_id' => $post_id, 'locator' => [ 'kind' => 'element', 'id' => $element_id ] ] );
	}

	/** @return array<string, mixed> */
	private static function extracted( int $post_id, string $element_id ): array {
		$result = self::extract( $post_id, $element_id );
		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_code() . ': ' . $result->get_error_message() : '' );

		return $result;
	}

	/** @return array<string, mixed> */
	private static function find( string $builder = 'elementor-v3', array $extra = [] ): array {
		$result = ( new SectionReuseFind() )->execute( array_merge( [ 'builder' => $builder, 'roles' => [ 'features' ] ], $extra ) );
		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );

		return $result;
	}

	/** @return list<int> */
	private static function source_ids( array $result ): array {
		return array_map( static fn( array $candidate ): int => $candidate['source']['post_id'], $result['roles'][0]['candidates'] );
	}

	/** @return list<string> */
	private static function inner_ids( array $candidate ): array {
		return array_map( static fn( array $entry ): string => $entry['locator']['id'], $candidate['inner'] ?? [] );
	}

	// ---------------------------------------------------------------- private and other unpublished sources

	public function test_a_private_page_is_extracted_when_the_user_may_edit_it(): void {
		self::elementor_post( 10, [ SectionFixtures::v3_features() ], 'private' );

		$result = self::extracted( 10, 'f000001' );

		self::assertSame( 'private', $result['source']['status'] );
		self::assertSame( 'ph-1', $result['section']['element']['id'] );
		self::assertContains( 'private_source', array_column( $result['warnings'], 'code' ), 'The text is not public, and the result says so.' );
	}

	public function test_a_private_source_is_refused_without_the_right_to_edit_it_and_looks_like_a_missing_post(): void {
		self::elementor_post( 10, [ SectionFixtures::v3_features() ], 'private' );
		$GLOBALS['stonewright_test_user_can_callback'] = self::cannot_edit( 10 );
		$ability                                       = new SectionReuseExtract();

		self::assertFalse( $ability->permission_callback( [ 'post_id' => 10, 'locator' => [ 'kind' => 'element', 'id' => 'f000001' ] ] ) );
		$private = self::extract( 10, 'f000001' );
		$absent  = self::extract( 999, 'f000001' );

		self::assertInstanceOf( \WP_Error::class, $private );
		self::assertSame( 'stonewright_not_found', $private->get_error_code() );
		self::assertSame( $absent->get_error_message(), $private->get_error_message(), 'The answer does not tell a private post from one that is not there.' );
		self::assertStringNotContainsString( 'Source 10', $private->get_error_message() );
	}

	public function test_a_private_pattern_and_a_private_template_are_sources_too(): void {
		self::elementor_post( 20, [ SectionFixtures::v3_features() ], 'private', 'elementor_library' );
		$GLOBALS['stonewright_test_posts'][20]->meta['_elementor_template_type'] = 'container';
		$GLOBALS['stonewright_test_posts'][21] = SectionFixtures::post( 21, 'wp_block', 'private', 'Pattern', SectionFixtures::gutenberg_features_content( 'Pattern', 3, 'p' ), [ 'wp_pattern_sync_status' => 'unsynced' ] );

		self::assertSame( 'template', self::extracted( 20, 'f000001' )['source']['kind'] );
		$pattern = ( new SectionReuseExtract() )->execute( [ 'post_id' => 21, 'locator' => [ 'kind' => 'pattern' ] ] );
		self::assertIsArray( $pattern );
		self::assertSame( 'pattern', $pattern['source']['kind'] );
		self::assertSame( 'private', $pattern['source']['status'] );
	}

	public function test_pending_and_scheduled_sources_follow_the_same_rule(): void {
		self::elementor_post( 11, [ SectionFixtures::v3_features( 'a' ) ], 'pending' );
		self::elementor_post( 12, [ SectionFixtures::v3_features( 'b' ) ], 'future' );

		self::assertContains( 'pending_source', array_column( self::extracted( 11, 'a000001' )['warnings'], 'code' ) );
		self::assertContains( 'scheduled_source', array_column( self::extracted( 12, 'b000001' )['warnings'], 'code' ) );

		$GLOBALS['stonewright_test_user_can_callback'] = self::cannot_edit( 11, 12 );
		self::assertSame( 'stonewright_not_found', self::extract( 11, 'a000001' )->get_error_code() );
		self::assertSame( 'stonewright_not_found', self::extract( 12, 'b000001' )->get_error_code() );
	}

	public function test_trashed_posts_revisions_and_auto_drafts_stay_refused(): void {
		foreach ( [ 'trash', 'auto-draft', 'inherit' ] as $offset => $status ) {
			self::elementor_post( 30 + $offset, [ SectionFixtures::v3_features() ], $status, 'inherit' === $status ? 'revision' : 'page' );
			$result = self::extract( 30 + $offset, 'f000001' );

			self::assertInstanceOf( \WP_Error::class, $result, $status );
			self::assertSame( 'stonewright_not_found', $result->get_error_code(), $status );
		}
	}

	public function test_the_not_found_text_says_which_sources_are_allowed_and_never_to_change_a_status(): void {
		$result = self::extract( 999, 'x' );

		self::assertInstanceOf( \WP_Error::class, $result );
		$message = $result->get_error_message();
		foreach ( [ 'publish', 'draft', 'pending', 'future', 'private', 'edit', 'status' ] as $word ) {
			self::assertStringContainsString( $word, $message );
		}
		self::assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_find_lists_private_sources_only_to_users_who_may_edit_them_and_marks_the_status(): void {
		self::elementor_post( 10, [ SectionFixtures::v3_features( 'a' ) ], 'publish', 'page', '2026-01-05 00:00:00' );
		self::elementor_post( 11, [ SectionFixtures::v3_features( 'b' ) ], 'private', 'page', '2026-01-04 00:00:00' );
		self::elementor_post( 12, [ SectionFixtures::v3_features( 'c' ) ], 'pending', 'page', '2026-01-03 00:00:00' );
		self::elementor_post( 13, [ SectionFixtures::v3_features( 'd' ) ], 'future', 'page', '2026-01-02 00:00:00' );
		self::elementor_post( 14, [ SectionFixtures::v3_features( 'e' ) ], 'trash', 'page', '2026-01-01 00:00:00' );

		$editor = self::find( 'elementor-v3', [ 'limit_per_role' => 10 ] );
		self::assertSame( [ 10, 11, 12, 13 ], self::source_ids( $editor ) );
		$statuses = array_column( array_column( $editor['roles'][0]['candidates'], 'source' ), 'status' );
		self::assertSame( [ 'publish', 'private', 'pending', 'future' ], $statuses );
		self::assertContains( 'private_source', array_column( $editor['roles'][0]['candidates'][1]['warnings'], 'code' ) );

		$GLOBALS['stonewright_test_user_can_callback'] = self::cannot_edit( 11, 12 );
		SignatureCache::reset_for_tests();
		$other = self::find( 'elementor-v3', [ 'limit_per_role' => 10 ] );
		self::assertSame( [ 10, 13 ], self::source_ids( $other ), 'The private and pending pages are not listed to a user who cannot edit them, even though they are cached.' );
		self::assertSame( 2, $other['scan']['sources_scanned'] );
		self::assertStringNotContainsString( 'Source 11', (string) wp_json_encode( $other ) );
	}

	public function test_what_find_stores_is_never_served_to_a_user_who_may_not_edit_the_source(): void {
		self::elementor_post( 11, [ SectionFixtures::v3_features( 'b', 3, 'Private heading' ) ], 'private' );
		self::find();
		self::assertArrayHasKey( SignatureCache::OPTION, $GLOBALS['stonewright_test_options'] );

		$GLOBALS['stonewright_test_user_can_callback'] = self::cannot_edit( 11 );
		SignatureCache::reset_for_tests();
		$other = self::find();

		self::assertSame( [], $other['roles'][0]['candidates'] );
		self::assertSame( [], $GLOBALS['stonewright_test_transients'], 'No transient holds a copy.' );
		self::assertStringNotContainsString( 'Private heading', (string) wp_json_encode( $other ) );
		self::assertSame( [ 'hits' => 0, 'misses' => 0 ], $other['scan']['cache'], 'The cache is not even read for a source the user may not use.' );
	}

	public function test_the_scan_is_not_truncated_by_sources_the_user_may_not_use(): void {
		for ( $i = 1; $i <= 201; $i++ ) {
			self::elementor_post( 100 + $i, [ SectionFixtures::v3_features( 'p' . $i ) ], 'private', 'page', sprintf( '2026-01-01 00:%02d:%02d', intdiv( $i, 60 ), $i % 60 ) );
		}
		$GLOBALS['stonewright_test_user_can_callback'] = self::cannot_edit( ...range( 101, 301 ) );

		$result = self::find();

		self::assertFalse( $result['scan']['truncated'] );
		self::assertSame( 0, $result['scan']['sources_scanned'] );
	}

	// ---------------------------------------------------------------- nested containers

	public function test_a_v3_container_nested_below_the_top_level_is_extracted_by_its_id(): void {
		self::elementor_post( 10, [ SectionFixtures::v3_hero(), SectionFixtures::v3_features( 'f', 3 ) ] );

		$result  = self::extracted( 10, 'f000003' );
		$payload = $result['section'];

		self::assertSame( 'elementor-v3', $result['builder'] );
		self::assertSame( [ 'post_id' => 10, 'locator' => [ 'kind' => 'element', 'id' => 'f000003' ] ], $payload['source'] );
		self::assertSame( 'ph-1', $payload['element']['id'] );
		self::assertCount( 3, $payload['element']['elements'], 'The three cards come with it.' );
		self::assertSame( 'ph-2', $payload['element']['elements'][0]['id'] );
		self::assertSame( 7, $result['stats']['placeholders'], 'The row, three cards and three icon boxes.' );
		self::assertSame( 3, $result['layout']['columns'] );
		self::assertDoesNotMatchRegularExpression( '/"f(?:00000[0-9]|c[0-9]|i[0-9])"/', (string) wp_json_encode( $payload['element'] ), 'No source element id is left.' );
	}

	public function test_a_container_nested_several_levels_deep_is_extracted(): void {
		$deep = [ 'id' => 'deep005', 'elType' => 'container', 'isInner' => true, 'settings' => [ 'flex_direction' => 'row' ], 'elements' => [ [ 'id' => 'deepw01', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Deep' ], 'elements' => [] ] ] ];
		foreach ( [ 'deep004', 'deep003', 'deep002' ] as $id ) {
			$deep = [ 'id' => $id, 'elType' => 'container', 'isInner' => true, 'settings' => [], 'elements' => [ $deep ] ];
		}
		self::elementor_post( 10, [ [ 'id' => 'deep001', 'elType' => 'container', 'isInner' => false, 'settings' => [], 'elements' => [ $deep ] ] ] );

		$result = self::extracted( 10, 'deep005' );

		self::assertSame( 2, $result['stats']['placeholders'] );
		self::assertSame( 'Deep', $result['section']['element']['elements'][0]['settings']['title'] );
	}

	public function test_a_v4_layout_element_nested_in_a_section_is_extracted_with_its_local_styles_renamed(): void {
		self::elementor_post( 11, [ SectionFixtures::v4_features( 'v', 3 ) ] );

		$result = self::extracted( 11, 'vcard2' );
		$json   = (string) wp_json_encode( $result['section']['element'] );

		self::assertSame( 'elementor-v4', $result['builder'] );
		self::assertSame( 'ph-1', $result['section']['element']['id'] );
		self::assertSame( [ 'ls-1' ], array_keys( $result['section']['element']['styles'] ) );
		self::assertStringNotContainsString( 'vcard2', $json );
		self::assertStringNotContainsString( 'a1b2c3d', $json );
		self::assertSame( 'g-card', $result['section']['element']['settings']['classes']['value'][1], 'A global class is kept.' );
	}

	public function test_a_nested_element_that_is_not_a_copy_root_is_refused_with_its_own_code(): void {
		$legacy = [ 'id' => 'sec0001', 'elType' => 'section', 'isInner' => false, 'settings' => [], 'elements' => [
			[ 'id' => 'col0001', 'elType' => 'column', 'isInner' => false, 'settings' => [ '_column_size' => 100 ], 'elements' => [
				[ 'id' => 'wid0001', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'X' ], 'elements' => [] ],
				[ 'id' => 'inn0001', 'elType' => 'section', 'isInner' => true, 'settings' => [], 'elements' => [] ],
			] ],
		] ];
		self::elementor_post( 10, [ $legacy, SectionFixtures::v3_features( 'f' ) ] );
		self::elementor_post( 11, [ SectionFixtures::v4_features( 'v' ) ] );
		$mixed                       = SectionFixtures::v3_features( 'm' );
		$mixed['elements'][1]['elements'][0]['elements'][] = SectionFixtures::v4_features( 'x' );
		self::elementor_post( 12, [ $mixed ] );

		$cases = [
			[ 10, 'wid0001', 'widget' ],
			[ 10, 'col0001', 'column' ],
			[ 10, 'fi1', 'widget' ],
			[ 11, 'v000002', 'widget' ],
			[ 12, 'mc1', 'mixed_builders' ],
		];
		foreach ( $cases as [ $post_id, $element_id, $reason ] ) {
			$result = self::extract( $post_id, $element_id );

			self::assertInstanceOf( \WP_Error::class, $result, $element_id );
			self::assertSame( 'stonewright_section_not_copyable', $result->get_error_code(), $element_id );
			self::assertSame( $reason, $result->get_error_data()['reason'], $element_id );
			self::assertSame( 422, $result->get_error_data()['status'] );
			self::assertStringContainsString( 'container', $result->get_error_message() );
		}
		self::assertIsArray( self::extract( 10, 'inn0001' ), 'A legacy inner section is a section.' );
	}

	public function test_a_top_level_element_keeps_working_as_before(): void {
		self::elementor_post( 10, [ SectionFixtures::v3_features() ] );

		self::assertSame( 9, self::extracted( 10, 'f000001' )['stats']['placeholders'] );
	}

	public function test_a_nested_container_over_the_element_cap_is_refused_with_its_size(): void {
		$widgets = [];
		for ( $i = 0; $i < 2100; $i++ ) {
			$widgets[] = [ 'id' => 'w' . $i, 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [], 'elements' => [] ];
		}
		self::elementor_post( 10, [ [ 'id' => 'big0001', 'elType' => 'container', 'settings' => [], 'elements' => [ [ 'id' => 'big0002', 'elType' => 'container', 'isInner' => true, 'settings' => [], 'elements' => $widgets ] ] ] ] );

		$result = self::extract( 10, 'big0002' );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_section_too_large', $result->get_error_code() );
	}

	public function test_an_unknown_id_names_what_the_locator_may_be(): void {
		self::elementor_post( 10, [ SectionFixtures::v3_features() ] );

		$result = self::extract( 10, 'nope' );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_section_not_found', $result->get_error_code() );
		foreach ( [ 'nested', 'id', 'top-level', 'stonewright-section-reuse-find' ] as $word ) {
			self::assertStringContainsString( $word, $result->get_error_message() );
		}
	}

	public function test_extracting_a_nested_container_changes_nothing(): void {
		self::elementor_post( 10, [ SectionFixtures::v3_features() ], 'private' );
		$before = serialize( $GLOBALS['stonewright_test_posts'] );

		self::extracted( 10, 'f000003' );

		self::assertSame( $before, serialize( $GLOBALS['stonewright_test_posts'] ) );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );
	}

	// ---------------------------------------------------------------- a source without a usable document

	public function test_a_page_without_an_elementor_document_says_so(): void {
		$GLOBALS['stonewright_test_posts'][10] = SectionFixtures::post( 10, 'page', 'publish', 'Plain', "<!-- wp:paragraph -->\n<p>Hi</p>\n<!-- /wp:paragraph -->" );
		$GLOBALS['stonewright_test_posts'][11] = SectionFixtures::post( 11, 'page', 'publish', 'Broken', '', [ '_elementor_edit_mode' => 'builder', '_elementor_data' => '{not json' ] );
		$GLOBALS['stonewright_test_posts'][12] = SectionFixtures::post( 12, 'page', 'publish', 'Empty', '', [ '_elementor_edit_mode' => 'builder' ] );
		$GLOBALS['stonewright_test_posts'][13] = SectionFixtures::post( 13, 'page', 'publish', 'Unmarked', '', [ '_elementor_data' => (string) wp_json_encode( [ SectionFixtures::v3_features() ] ) ] );

		$expected = [ 10 => 'not_built_with_elementor', 11 => 'document_empty_or_unreadable', 12 => 'document_empty_or_unreadable', 13 => 'elementor_mode_missing' ];
		foreach ( $expected as $post_id => $reason ) {
			$result = self::extract( $post_id, 'f000001' );

			self::assertInstanceOf( \WP_Error::class, $result, (string) $post_id );
			self::assertSame( 'stonewright_no_elementor_document', $result->get_error_code(), (string) $post_id );
			self::assertSame( $reason, $result->get_error_data()['reason'], (string) $post_id );
			self::assertSame( 422, $result->get_error_data()['status'] );
			self::assertStringContainsString( 'no valid Elementor document', $result->get_error_message() );
		}
	}

	public function test_a_section_template_of_another_type_says_why_nothing_matches(): void {
		self::elementor_post( 10, [ SectionFixtures::v3_features() ], 'publish', 'elementor_library' );
		$GLOBALS['stonewright_test_posts'][10]->meta['_elementor_template_type'] = 'header';

		$result = self::extract( 10, 'f000001' );

		self::assertSame( 'stonewright_section_not_found', $result->get_error_code() );
		self::assertSame( 'template_type', $result->get_error_data()['reason'] );
		self::assertStringContainsString( 'section or container', $result->get_error_message() );
	}

	public function test_a_gutenberg_locator_on_an_elementor_page_is_a_builder_question_not_a_missing_document(): void {
		self::elementor_post( 10, [ SectionFixtures::v3_features() ] );

		$result = ( new SectionReuseExtract() )->execute( [ 'post_id' => 10, 'locator' => [ 'kind' => 'block', 'path' => [ 0 ] ] ] );

		self::assertSame( 'stonewright_section_not_found', $result->get_error_code() );
	}

	// ---------------------------------------------------------------- find reports nested containers

	public function test_a_candidate_lists_its_nested_containers_with_ids_and_each_one_can_be_extracted(): void {
		self::elementor_post( 10, [ SectionFixtures::v3_features( 'f', 3 ) ] );

		$candidate = self::find()['roles'][0]['candidates'][0];

		self::assertSame( [ 'f000003', 'fc1', 'fc2', 'fc3' ], self::inner_ids( $candidate ) );
		$row = $candidate['inner'][0];
		self::assertSame( [ 'kind' => 'element', 'id' => 'f000003' ], $row['locator'] );
		self::assertSame( 2, $row['depth'] );
		self::assertSame( 'container', $row['type'] );
		self::assertSame( 3, $row['children'] );
		self::assertSame( 7, $row['elements'] );
		self::assertSame( [ 'type' => 'container', 'count' => 3 ], $row['repeated'], 'A row of cards is marked as a group.' );
		self::assertArrayNotHasKey( 'inner_truncated', $candidate );
		foreach ( $candidate['inner'] as $entry ) {
			self::assertIsArray( self::extract( 10, $entry['locator']['id'] ), $entry['locator']['id'] );
		}
	}

	public function test_nested_containers_in_a_candidate_are_bounded_in_number_and_depth_and_say_when_more_exist(): void {
		$rows = [];
		for ( $i = 1; $i <= 9; $i++ ) {
			$rows[] = [ 'id' => 'row000' . $i, 'elType' => 'container', 'isInner' => true, 'settings' => [], 'elements' => [ [ 'id' => 'hd0000' . $i, 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Row ' . $i ], 'elements' => [] ] ] ];
		}
		$deep = [ 'id' => 'lvl0006', 'elType' => 'container', 'isInner' => true, 'settings' => [], 'elements' => [ [ 'id' => 'wd00006', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [], 'elements' => [] ] ] ];
		foreach ( [ 'lvl0005', 'lvl0004', 'lvl0003', 'lvl0002' ] as $id ) {
			$deep = [ 'id' => $id, 'elType' => 'container', 'isInner' => true, 'settings' => [], 'elements' => [ $deep ] ];
		}
		self::elementor_post( 10, [ [ 'id' => 'top0001', 'elType' => 'container', 'isInner' => false, 'settings' => [], 'elements' => $rows ] ] );
		self::elementor_post( 11, [ [ 'id' => 'top0002', 'elType' => 'container', 'isInner' => false, 'settings' => [], 'elements' => [ $deep ] ] ], 'publish', 'page', '2026-01-01 00:00:00' );

		$candidates = self::find( 'elementor-v3', [ 'roles' => [ 'features', 'other' ] ] );
		$by_post    = [];
		foreach ( $candidates['roles'] as $role ) {
			foreach ( $role['candidates'] as $candidate ) {
				$by_post[ $candidate['source']['post_id'] ] = $candidate;
			}
		}

		self::assertCount( NestedContainers::MAX_LISTED, $by_post[10]['inner'] );
		self::assertTrue( $by_post[10]['inner_truncated'] );
		self::assertSame( [ 'row0001', 'row0002', 'row0003', 'row0004', 'row0005', 'row0006' ], self::inner_ids( $by_post[10] ), 'Shallower containers come first, then document order.' );
		self::assertSame( [ 'lvl0002', 'lvl0003', 'lvl0004' ], self::inner_ids( $by_post[11] ), 'Nothing deeper than the listing depth is listed; deeper ids still extract.' );
		self::assertIsArray( self::extract( 11, 'lvl0006' ) );
	}

	public function test_a_candidate_without_nested_containers_and_a_gutenberg_candidate_list_none(): void {
		self::elementor_post( 10, [ SectionFixtures::v3_hero() ] );
		$GLOBALS['stonewright_test_posts'][20] = SectionFixtures::post( 20, 'page', 'publish', 'Blocks', SectionFixtures::gutenberg_features_content() );

		$elementor = self::find( 'elementor-v3', [ 'roles' => [ 'hero' ] ] )['roles'][0]['candidates'][0];
		$blocks    = self::find( 'gutenberg' )['roles'][0]['candidates'][0];

		self::assertSame( [ 'h000002' ], self::inner_ids( $elementor ) );
		self::assertArrayNotHasKey( 'inner', $blocks );
	}
}
