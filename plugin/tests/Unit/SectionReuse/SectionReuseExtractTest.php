<?php
/**
 * stonewright/section-reuse-extract: the portable payload and its references.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\SectionReuse;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\SectionReuse\SectionReuseExtract;
use Stonewright\WpMcp\SectionReuse\ReferenceCatalog;
use Stonewright\WpMcp\SectionReuse\SectionReuseSetting;
use Stonewright\WpMcp\Security\IncidentStore;

require_once __DIR__ . '/SectionFixtures.php';

/**
 * @covers \Stonewright\WpMcp\Abilities\SectionReuse\SectionReuseExtract
 * @covers \Stonewright\WpMcp\SectionReuse\PortableSection
 */
final class SectionReuseExtractTest extends TestCase {

	protected function setUp(): void {
		IncidentStore::reset_for_tests();
		ReferenceCatalog::set_provider( static fn( string $type, string $id ): ?bool => true );
		$GLOBALS['stonewright_test_options']           = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_posts']             = [];
		$GLOBALS['stonewright_test_post_meta_calls']   = [];
		$GLOBALS['stonewright_test_wpdb_inserts']      = [];
		$GLOBALS['stonewright_test_user_caps']         = [ 'edit_posts' => true, 'read' => true ];
		$GLOBALS['stonewright_test_user_can_callback'] = static fn( string $cap, mixed ...$args ): bool => in_array( $cap, [ 'edit_posts', 'read_post', 'edit_post' ], true );
		$GLOBALS['stonewright_test_user_logged_in']    = true;
		$GLOBALS['stonewright_test_current_user_id']   = 7;
	}

	protected function tearDown(): void {
		ReferenceCatalog::set_provider( null );
		IncidentStore::reset_for_tests();
		$GLOBALS['stonewright_test_options']           = [];
		$GLOBALS['stonewright_test_posts']             = [];
		$GLOBALS['stonewright_test_user_caps']         = [];
		$GLOBALS['stonewright_test_user_can_callback'] = null;
		$GLOBALS['stonewright_test_user_logged_in']    = false;
	}

	/** @return array<string, mixed> */
	private static function extract( array $args ): array {
		$result = ( new SectionReuseExtract() )->execute( $args );
		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_code() . ': ' . $result->get_error_message() : '' );

		return $result;
	}

	/** @param list<array<string, mixed>> $tree */
	private static function elementor_post( int $id, array $tree, string $type = 'page', string $template_type = '' ): void {
		$GLOBALS['stonewright_test_posts'][ $id ] = SectionFixtures::post( $id, $type, 'publish', 'Source ' . $id, '', SectionFixtures::elementor_meta( $tree, '3.30.0', $template_type ) );
	}

	/** @return list<string> Every string value in a payload. */
	private static function strings( mixed $value ): array {
		$out = [];
		array_walk_recursive( $value, static function ( mixed $leaf, mixed $key ) use ( &$out ): void {
			$out[] = (string) $key;
			if ( is_string( $leaf ) ) {
				$out[] = $leaf;
			}
		} );

		return $out;
	}

	public function test_while_the_setting_is_off_nothing_is_extracted(): void {
		self::elementor_post( 10, [ SectionFixtures::v3_features() ] );
		$GLOBALS['stonewright_test_options'][ SectionReuseSetting::OPTION ] = 'off';

		$result = ( new SectionReuseExtract() )->execute( [ 'post_id' => 10, 'locator' => [ 'kind' => 'element', 'id' => 'f000001' ] ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_section_reuse_off', $result->get_error_code() );
		self::assertSame( 'Section reuse is off. Do not ask the user about reusing sections.', $result->get_error_message() );
	}

	public function test_a_v3_section_comes_back_in_its_own_format_without_element_ids(): void {
		$section = SectionFixtures::v3_features( 'f', 3, 'Why choose us', '#112233' );
		$section['elements'][0]['settings']['acme_vendor_setting'] = [ 'keep' => 'me' ];
		self::elementor_post( 10, [ SectionFixtures::v3_hero(), $section ] );

		$result  = self::extract( [ 'post_id' => 10, 'locator' => [ 'kind' => 'element', 'id' => 'f000001' ] ] );
		$payload = $result['section'];

		self::assertSame( 'SectionPortableV1', $payload['schema'] );
		self::assertSame( 'elementor-v3', $payload['builder'] );
		self::assertSame( [ 'post_id' => 10, 'locator' => [ 'kind' => 'element', 'id' => 'f000001' ] ], $payload['source'] );
		self::assertSame( 'ph-1', $payload['element']['id'] );
		self::assertSame( 'ph-2', $payload['element']['elements'][0]['id'] );
		self::assertSame( 9, $result['stats']['placeholders'] );
		foreach ( self::strings( $payload['element'] ) as $string ) {
			self::assertDoesNotMatchRegularExpression( '/^f(?:00000[0-9]|c[0-9]|i[0-9])$/', $string, 'No source element id is left.' );
		}
		self::assertSame( 'icon-box', $payload['element']['elements'][1]['elements'][0]['elements'][0]['widgetType'], 'The widget type is untouched.' );
		self::assertSame( [ 'keep' => 'me' ], $payload['element']['elements'][0]['settings']['acme_vendor_setting'], 'An unknown setting is carried as it is.' );
		self::assertSame( 'Why choose us', $payload['element']['elements'][0]['settings']['title'] );
		self::assertSame( 3, $result['layout']['columns'] );
		self::assertSame( 'elementor-v3', $result['builder'] );
		self::assertSame( 10, $result['source']['post_id'] );
	}

	public function test_a_v4_section_loses_element_ids_and_local_style_ids_but_keeps_global_classes(): void {
		self::elementor_post( 11, [ SectionFixtures::v4_features( 'v', 3 ) ] );

		$result  = self::extract( [ 'post_id' => 11, 'locator' => [ 'kind' => 'element', 'id' => 'v000001' ] ] );
		$element = $result['section']['element'];
		$json    = (string) wp_json_encode( $result['section']['element'] );

		self::assertSame( 'elementor-v4', $result['builder'] );
		self::assertStringNotContainsString( 'f00ba12', $json, 'A local style id suffix does not survive.' );
		self::assertStringNotContainsString( 'e-v000001', $json );
		self::assertDoesNotMatchRegularExpression( '/"v(card|ctitle|000)/', $json, 'No source element id survives.' );
		self::assertSame( [ 'ls-1' ], array_keys( $element['styles'] ) );
		self::assertSame( 'ls-1', $element['styles']['ls-1']['id'] );
		self::assertSame( [ 'ls-1' ], $element['settings']['classes']['value'] );

		$card = $element['elements'][1]['elements'][0];
		self::assertSame( 'ph-4', $card['id'] );
		self::assertSame( [ 'ls-3', 'g-card' ], $card['settings']['classes']['value'], 'A local style is remapped, a global class is kept.' );
		self::assertSame( [ 'ls-3' ], array_keys( $card['styles'] ) );
		self::assertSame( 'e-heading', $card['elements'][0]['widgetType'] );
	}

	public function test_the_reference_list_says_what_exists_here(): void {
		$section = SectionFixtures::v3_hero( 'h', 'Title', 41 );
		$section['elements'][0]['elements'][0]['settings']['__globals__'] = [ 'title_color' => 'globals/colors?id=primary' ];
		$section['elements'][0]['elements'][1]['settings']['__dynamic__'] = [ 'editor' => '[elementor-tag id="a" name="post-excerpt" settings="%7B%7D"]' ];
		self::elementor_post( 12, [ $section ] );
		ReferenceCatalog::set_provider( static fn( string $type, string $id ): ?bool => 'primary' === $id ? false : ( 'media' === $type ? true : null ) );

		$result = self::extract( [ 'post_id' => 12, 'locator' => [ 'id' => 'h000001' ] ] );

		$by_type = [];
		foreach ( $result['references'] as $reference ) {
			$by_type[ $reference['type'] ][ $reference['id'] ] = $reference['exists'];
		}
		self::assertSame( [ 'primary' => false ], $by_type['global_color'] );
		self::assertSame( [ '41' => true ], $by_type['media'] );
		self::assertSame( [ 'post-excerpt' => null ], $by_type['dynamic_tag'] );
		self::assertSame( [ 'dynamic_tags', 'missing_global_colors' ], array_column( $result['warnings'], 'code' ) );
		$color = array_values( array_filter( $result['references'], static fn( array $reference ): bool => 'global_color' === $reference['type'] ) )[0];
		self::assertSame( [ 'ph-3' ], $color['at'], 'References name the placeholder they sit on.' );
	}

	public function test_a_gutenberg_section_keeps_its_markup_and_marks_its_anchors(): void {
		$content = SectionFixtures::gutenberg_features_content( 'Why choose us', 3, 'features' );
		$GLOBALS['stonewright_test_posts'][20] = SectionFixtures::post( 20, 'page', 'publish', 'Blocks', "<!-- wp:paragraph -->\n<p>Intro</p>\n<!-- /wp:paragraph -->\n\n" . $content );

		$result  = self::extract( [ 'post_id' => 20, 'locator' => [ 'kind' => 'block', 'anchor' => 'features' ] ] );
		$payload = $result['section'];

		self::assertSame( 'gutenberg', $payload['builder'] );
		self::assertSame( [ 'kind' => 'block', 'path' => [ 1 ], 'anchor' => 'features' ], $payload['source']['locator'] );
		self::assertCount( 1, $payload['blocks'] );
		self::assertSame( 'core/group', $payload['blocks'][0]['blockName'] );
		self::assertSame( 'features', $payload['blocks'][0]['attrs']['anchor'] );
		self::assertStringContainsString( 'id="features"', $payload['blocks'][0]['innerContent'][0], 'The markup is carried as it is.' );
		self::assertSame( [ [ 'path' => [ 0 ], 'anchor' => 'features' ] ], $payload['anchors'] );
		self::assertSame( 3, $result['layout']['columns'] );
	}

	public function test_a_synced_pattern_stays_a_reference_and_an_unsynced_one_is_copied(): void {
		$content = SectionFixtures::gutenberg_features_content( 'Pattern', 3, 'p' );
		$GLOBALS['stonewright_test_posts'][30] = SectionFixtures::post( 30, 'wp_block', 'publish', 'Synced', $content );
		$GLOBALS['stonewright_test_posts'][31] = SectionFixtures::post( 31, 'wp_block', 'publish', 'Unsynced', $content, [ 'wp_pattern_sync_status' => 'unsynced' ] );

		$synced   = self::extract( [ 'post_id' => 30, 'locator' => [ 'kind' => 'pattern' ] ] );
		$unsynced = self::extract( [ 'post_id' => 31, 'locator' => [ 'kind' => 'pattern' ] ] );

		self::assertSame( 'core/block', $synced['section']['blocks'][0]['blockName'] );
		self::assertSame( 30, $synced['section']['blocks'][0]['attrs']['ref'] );
		self::assertSame( [ '30' ], array_column( array_filter( $synced['references'], static fn( array $r ): bool => 'synced_pattern' === $r['type'] ), 'id' ) );
		self::assertContains( 'synced_patterns', array_column( $synced['warnings'], 'code' ) );
		self::assertSame( 'core/group', $unsynced['section']['blocks'][0]['blockName'] );
	}

	public function test_a_missing_section_and_a_builder_mismatch_are_refused(): void {
		self::elementor_post( 10, [ SectionFixtures::v3_features() ] );

		$missing = ( new SectionReuseExtract() )->execute( [ 'post_id' => 10, 'locator' => [ 'kind' => 'element', 'id' => 'nope' ] ] );
		$wrong   = ( new SectionReuseExtract() )->execute( [ 'post_id' => 10, 'locator' => [ 'kind' => 'element', 'id' => 'f000001' ], 'builder' => 'gutenberg' ] );
		$gone    = ( new SectionReuseExtract() )->execute( [ 'post_id' => 999, 'locator' => [ 'kind' => 'element', 'id' => 'x' ] ] );

		self::assertSame( 'stonewright_section_not_found', $missing->get_error_code() );
		self::assertSame( 'stonewright_builder_mismatch', $wrong->get_error_code() );
		self::assertSame( 'stonewright_not_found', $gone->get_error_code() );
	}

	public function test_the_user_needs_to_read_and_edit_the_source(): void {
		$ability = new SectionReuseExtract();

		$GLOBALS['stonewright_test_user_can_callback'] = static fn( string $cap, mixed ...$args ): bool => 'edit_post' === $cap;
		self::assertFalse( $ability->permission_callback( [ 'post_id' => 10 ] ), 'No read right.' );
		$GLOBALS['stonewright_test_user_can_callback'] = static fn( string $cap, mixed ...$args ): bool => 'read_post' === $cap;
		self::assertFalse( $ability->permission_callback( [ 'post_id' => 10 ] ), 'No edit right.' );
		$GLOBALS['stonewright_test_user_can_callback'] = static fn( string $cap, mixed ...$args ): bool => in_array( $cap, [ 'read_post', 'edit_post' ], true );
		self::assertTrue( $ability->permission_callback( [ 'post_id' => 10 ] ) );
	}

	public function test_a_section_over_the_element_cap_is_refused_with_its_size(): void {
		$cards = [];
		for ( $i = 0; $i < 2100; $i++ ) {
			$cards[] = [ 'id' => 'w' . $i, 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [], 'elements' => [] ];
		}
		self::elementor_post( 13, [ [ 'id' => 'big0001', 'elType' => 'container', 'settings' => [], 'elements' => $cards ] ] );

		$result = ( new SectionReuseExtract() )->execute( [ 'post_id' => 13, 'locator' => [ 'kind' => 'element', 'id' => 'big0001' ] ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_section_too_large', $result->get_error_code() );
	}

	public function test_extracting_changes_nothing(): void {
		self::elementor_post( 10, [ SectionFixtures::v3_features() ] );
		$GLOBALS['stonewright_test_posts'][20] = SectionFixtures::post( 20, 'page', 'publish', 'Blocks', SectionFixtures::gutenberg_features_content() );
		$before = serialize( $GLOBALS['stonewright_test_posts'] );

		self::extract( [ 'post_id' => 10, 'locator' => [ 'kind' => 'element', 'id' => 'f000001' ] ] );
		self::extract( [ 'post_id' => 20, 'locator' => [ 'kind' => 'block', 'path' => [ 0 ] ] ] );

		self::assertSame( $before, serialize( $GLOBALS['stonewright_test_posts'] ) );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );
	}

	public function test_extracting_twice_gives_the_same_payload(): void {
		self::elementor_post( 11, [ SectionFixtures::v4_features( 'v', 3 ) ] );
		$args = [ 'post_id' => 11, 'locator' => [ 'kind' => 'element', 'id' => 'v000001' ] ];

		self::assertSame( self::extract( $args )['section'], self::extract( $args )['section'] );
	}
}
