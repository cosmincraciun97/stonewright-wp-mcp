<?php
/**
 * Reusing a V4 section: builder mismatch, duplicate ids, duplicate op_id and the size of the result.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\SectionReuse;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV4\UpdateNode;
use Stonewright\WpMcp\Abilities\SectionReuse\SectionReuseExtract;
use Stonewright\WpMcp\Elementor\V4\AtomicSchemaRepository;
use Stonewright\WpMcp\Elementor\V4\V4FeatureGate;
use Stonewright\WpMcp\SectionReuse\ReferenceCatalog;
use Stonewright\WpMcp\Security\IncidentStore;
use Stonewright\WpMcp\Support\ElementorData;

require_once __DIR__ . '/SectionFixtures.php';

/**
 * @covers \Stonewright\WpMcp\Abilities\ElementorV4\UpdateNode
 * @covers \Stonewright\WpMcp\SectionReuse\ElementorSectionInserter
 * @covers \Stonewright\WpMcp\SectionReuse\BatchOperationIds
 */
final class SectionInsertElementorV4HardeningTest extends TestCase {

	private const TARGET = 601;
	private const SOURCE = 20;

	protected function setUp(): void {
		AtomicSchemaRepository::invalidate();
		IncidentStore::reset_for_tests();
		V4FeatureGate::set_atomic_module_present_for_tests( true );
		ReferenceCatalog::set_provider( static fn( string $type, string $id ): ?bool => true );
		$GLOBALS['stonewright_test_options']           = [ 'stonewright_mode' => 'development', 'stonewright_elementor_v4_atomic' => true ];
		$GLOBALS['stonewright_test_post_meta_calls']   = [];
		$GLOBALS['stonewright_test_wpdb_inserts']      = [];
		$GLOBALS['stonewright_test_user_caps']         = [ 'edit_post' => true, 'read_post' => true, 'edit_posts' => true, 'read' => true ];
		$GLOBALS['stonewright_test_user_can_callback'] = null;
		$GLOBALS['stonewright_test_user_logged_in']    = true;
		$GLOBALS['stonewright_test_current_user_id']   = 1;
		self::seed( self::TARGET, [ self::root() ] );
		self::seed( self::SOURCE, [ SectionFixtures::v4_features( 'v', 1, 'Why choose us' ) ] );
	}

	protected function tearDown(): void {
		V4FeatureGate::set_atomic_module_present_for_tests( null );
		AtomicSchemaRepository::invalidate();
		ReferenceCatalog::set_provider( null );
		IncidentStore::reset_for_tests();
		$GLOBALS['stonewright_test_posts']             = [];
		$GLOBALS['stonewright_test_post_meta_calls']   = [];
		$GLOBALS['stonewright_test_wpdb_inserts']      = [];
		$GLOBALS['stonewright_test_options']           = [];
		$GLOBALS['stonewright_test_user_caps']         = [];
		$GLOBALS['stonewright_test_user_can_callback'] = null;
		$GLOBALS['stonewright_test_user_logged_in']    = false;
		$GLOBALS['stonewright_test_current_user_id']   = 0;
	}

	/** @return array<string, mixed> */
	private static function root(): array {
		return [ 'id' => 'a000001', 'version' => '0.0', 'elType' => 'e-div-block', 'isInner' => false, 'settings' => [], 'editor_settings' => [], 'interactions' => [], 'styles' => [], 'elements' => [] ];
	}

	/** @param list<array<string, mixed>> $tree */
	private static function seed( int $id, array $tree ): void {
		$GLOBALS['stonewright_test_posts'][ $id ] = SectionFixtures::post( $id, 'page', self::SOURCE === $id ? 'publish' : 'draft', 'Page ' . $id, '', SectionFixtures::elementor_meta( $tree ) );
	}

	/** @return array<string, mixed> */
	private static function section(): array {
		$tree = ElementorData::read( self::SOURCE );
		$GLOBALS['stonewright_test_user_can_callback'] = static fn( string $cap, mixed ...$args ): bool => in_array( $cap, [ 'edit_posts', 'read_post', 'edit_post' ], true );
		$result = ( new SectionReuseExtract() )->execute( [ 'post_id' => self::SOURCE, 'locator' => [ 'kind' => 'element', 'id' => (string) $tree[0]['id'] ] ] );
		$GLOBALS['stonewright_test_user_can_callback'] = null;
		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_code() . ': ' . $result->get_error_message() : '' );

		return $result['section'];
	}

	/** @return array<string, mixed> */
	private static function insert_op( array $section, array $extra = [] ): array {
		return array_merge( [ 'action' => 'insert_section', 'op_id' => 'feat', 'parent_id' => 'a000001', 'section' => $section ], $extra );
	}

	/** @return array<string, mixed>|\WP_Error */
	private static function batch( array $operations, bool $dry_run = false ): array|\WP_Error {
		return ( new UpdateNode() )->execute( [ 'post_id' => self::TARGET, 'dry_run' => $dry_run, 'operations' => $operations ] );
	}

	private static function target_writes(): int {
		return count( array_filter( $GLOBALS['stonewright_test_post_meta_calls'], static fn( array $call ): bool => self::TARGET === $call['post_id'] && '_elementor_data' === $call['meta_key'] ) );
	}

	private static function error_code( array|\WP_Error $result ): string {
		self::assertInstanceOf( \WP_Error::class, $result );
		$data = $result->get_error_data();
		foreach ( (array) ( $data['items'] ?? [] ) as $item ) {
			if ( empty( $item['ok'] ) ) {
				return (string) $item['error']['code'];
			}
		}

		return $result->get_error_code();
	}

	/** @return array<string, mixed> */
	private static function error_data( \WP_Error $result ): array {
		$data = (array) $result->get_error_data();
		foreach ( (array) ( $data['items'] ?? [] ) as $item ) {
			if ( empty( $item['ok'] ) ) {
				return (array) $item['error']['data'];
			}
		}

		return $data;
	}

	/** @return array<string, string> Dom id by element id. */
	private static function css_ids( array $tree ): array {
		$found = [];
		foreach ( ElementorData::flatten( $tree ) as $element ) {
			$value = $element['settings']['_cssid']['value'] ?? '';
			if ( is_string( $value ) && '' !== $value ) {
				$found[ (string) $element['id'] ] = $value;
			}
		}

		return $found;
	}

	public function test_a_v3_payload_sent_to_the_v4_writer_fails_with_the_builder_mismatch_code_before_the_css_gate(): void {
		$v3      = SectionFixtures::v3_hero();
		$v3['settings']['custom_css'] = 'selector { color: red; }';
		$payload = [ 'schema' => 'SectionPortableV1', 'builder' => 'elementor-v3', 'element' => $v3 ];

		foreach ( [ true, false ] as $dry_run ) {
			$result = self::batch( [ self::insert_op( $payload ) ], $dry_run );

			self::assertInstanceOf( \WP_Error::class, $result );
			self::assertSame( 'stonewright_section_builder_mismatch', $result->get_error_code() );
			self::assertSame( 'elementor-v3', $result->get_error_data()['section_builder'] );
		}
		self::assertSame( 0, self::target_writes() );
	}

	/** @return array<string, mixed> A V4 section whose root has a `_cssid`, with a button that links to it. */
	private static function anchored_section(): array {
		$section = SectionFixtures::v4_features( 'v', 1, 'Why choose us' );
		$section['settings']['_cssid'] = [ '$$type' => 'string', 'value' => 'features' ];
		$section['elements'][] = [
			'id'              => 'v000009',
			'version'         => '0.0',
			'elType'          => 'widget',
			'widgetType'      => 'e-button',
			'isInner'         => false,
			'settings'        => [
				'text' => [ '$$type' => 'html-v3', 'value' => [ 'content' => [ '$$type' => 'string', 'value' => 'Back to features' ], 'children' => [] ] ],
				'link' => [ '$$type' => 'link', 'value' => [ 'destination' => [ '$$type' => 'url', 'value' => '#features' ], 'isTargetBlank' => [ '$$type' => 'boolean', 'value' => false ] ] ],
			],
			'editor_settings' => [],
			'interactions'    => [],
			'styles'          => [],
			'elements'        => [],
		];

		return $section;
	}

	public function test_a_css_id_the_page_already_uses_is_renamed_and_the_links_of_the_copy_follow(): void {
		self::seed( self::SOURCE, [ self::anchored_section() ] );
		$target = self::root();
		$target['elements'][] = [ 'id' => 'taken01', 'version' => '0.0', 'elType' => 'e-div-block', 'isInner' => true, 'settings' => [ '_cssid' => [ '$$type' => 'string', 'value' => 'features' ] ], 'editor_settings' => [], 'interactions' => [], 'styles' => [], 'elements' => [] ];
		self::seed( self::TARGET, [ $target ] );
		$section = self::section();

		$result = self::batch( [ self::insert_op( $section ) ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_code() . ' ' . wp_json_encode( $result->get_error_data() ) : '' );
		$elements = ElementorData::flatten( ElementorData::read( self::TARGET ) );
		self::assertSame( 'features', $elements['taken01']['settings']['_cssid']['value'] );
		self::assertSame( 'features-2', $elements[ $result['refs']['feat'] ]['settings']['_cssid']['value'] );
		$button = $elements[ $result['refs']['feat.ph-6'] ?? '' ] ?? null;
		self::assertNotNull( $button, 'The button is addressable by its placeholder.' );
		self::assertSame( '#features-2', $button['settings']['link']['value']['destination']['value'] );
		self::assertSame( 'anchors_renamed', $result['items'][0]['warnings'][0]['code'] );
		self::assertSame( [ 'features -> features-2' ], $result['items'][0]['warnings'][0]['items'] );
		$ids = array_values( self::css_ids( ElementorData::read( self::TARGET ) ) );
		self::assertSame( $ids, array_values( array_unique( $ids ) ) );
	}

	public function test_the_same_v4_section_twice_in_one_batch_gets_distinct_css_ids(): void {
		self::seed( self::SOURCE, [ self::anchored_section() ] );
		$section = self::section();

		$result = self::batch( [ self::insert_op( $section ), self::insert_op( $section, [ 'op_id' => 'again' ] ) ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_code() . ' ' . wp_json_encode( $result->get_error_data() ) : '' );
		$elements = ElementorData::flatten( ElementorData::read( self::TARGET ) );
		self::assertSame( 'features', $elements[ $result['refs']['feat'] ]['settings']['_cssid']['value'] );
		self::assertSame( 'features-2', $elements[ $result['refs']['again'] ]['settings']['_cssid']['value'] );
		self::assertSame( '#features', $elements[ $result['refs']['feat.ph-6'] ]['settings']['link']['value']['destination']['value'] );
		self::assertSame( '#features-2', $elements[ $result['refs']['again.ph-6'] ]['settings']['link']['value']['destination']['value'] );
	}

	public function test_a_duplicate_op_id_in_one_batch_is_refused_before_anything_runs(): void {
		$section = self::section();

		foreach ( [ true, false ] as $dry_run ) {
			$result = self::batch( [ self::insert_op( $section ), self::insert_op( $section ) ], $dry_run );

			self::assertInstanceOf( \WP_Error::class, $result );
			self::assertSame( 'stonewright_duplicate_op_id', $result->get_error_code() );
			self::assertSame( 'feat', $result->get_error_data()['op_id'] );
			self::assertSame( [ 0, 1 ], $result->get_error_data()['indexes'] );
		}
		self::assertSame( 0, self::target_writes() );
	}

	public function test_the_copies_of_one_batch_may_not_exceed_the_element_cap(): void {
		$wide = SectionFixtures::v4_features( 'v', 1 );
		$card = $wide['elements'][1]['elements'][0];
		$rows = [];
		for ( $i = 1; $i <= 999; $i++ ) {
			$row       = $card;
			$row['id'] = 'r' . $i;
			$row['settings']['classes']['value'] = [ 'g-card' ];
			$row['styles']   = [];
			$row['elements'] = [];
			$rows[]          = $row;
		}
		$wide['elements'] = [ array_merge( $wide['elements'][1], [ 'elements' => $rows ] ) ];
		self::seed( self::SOURCE, [ $wide ] );
		$section = self::section();

		$one = self::batch( [ self::insert_op( $section ) ], true );
		self::assertIsArray( $one, $one instanceof \WP_Error ? self::error_code( $one ) : '' );

		foreach ( [ true, false ] as $dry_run ) {
			$two = self::batch( [ self::insert_op( $section ), self::insert_op( $section, [ 'op_id' => 'again' ] ) ], $dry_run );

			self::assertSame( 'stonewright_section_batch_too_large', self::error_code( $two ) );
			self::assertSame( 2000, self::error_data( $two )['limit'] );
		}
		self::assertSame( 0, self::target_writes() );
	}
}
