<?php
/**
 * Reusing a V3 section: builder mismatch, older stored values, duplicate ids, placeholders, unregistered
 * widgets, duplicate op_id and the size of the result.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\SectionReuse;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\BatchMutate;
use Stonewright\WpMcp\Abilities\SectionReuse\SectionReuseExtract;
use Stonewright\WpMcp\Elementor\Schema\WidgetSchemaRepository;
use Stonewright\WpMcp\SectionReuse\ReferenceCatalog;
use Stonewright\WpMcp\Security\IncidentStore;
use Stonewright\WpMcp\Support\ElementorData;

require_once __DIR__ . '/SectionFixtures.php';
require_once __DIR__ . '/ReuseWidgetRegistry.php';

/**
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\BatchMutate
 * @covers \Stonewright\WpMcp\SectionReuse\ElementorSectionInserter
 * @covers \Stonewright\WpMcp\SectionReuse\BatchOperationIds
 * @covers \Stonewright\WpMcp\Elementor\Schema\SettingsValidator
 */
final class SectionInsertElementorV3HardeningTest extends TestCase {

	private const TARGET = 501;
	private const SOURCE = 10;

	protected function setUp(): void {
		WidgetSchemaRepository::reset_request_cache();
		IncidentStore::reset_for_tests();
		ReferenceCatalog::set_provider( static fn( string $type, string $id ): ?bool => true );
		$GLOBALS['stonewright_test_options']           = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_post_meta_calls']   = [];
		$GLOBALS['stonewright_test_wpdb_inserts']      = [];
		$GLOBALS['stonewright_test_transients']        = [];
		$GLOBALS['stonewright_test_user_caps']         = [ 'edit_post' => true, 'read_post' => true, 'edit_posts' => true, 'read' => true ];
		$GLOBALS['stonewright_test_user_can_callback'] = null;
		$GLOBALS['stonewright_test_user_logged_in']    = true;
		$GLOBALS['stonewright_test_current_user_id']   = 1;
		self::seed( self::TARGET, [ self::root() ] );
		self::seed( self::SOURCE, [ SectionFixtures::v3_hero() ] );
	}

	protected function tearDown(): void {
		ReuseWidgetRegistry::restore();
		WidgetSchemaRepository::reset_request_cache();
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
		$GLOBALS['stonewright_test_transients']        = [];
	}

	/** @return array<string, mixed> */
	private static function root(): array {
		return [ 'id' => 'root', 'elType' => 'container', 'isInner' => false, 'settings' => [ 'container_type' => 'flex' ], 'elements' => [] ];
	}

	/** @param list<array<string, mixed>> $tree */
	private static function seed( int $id, array $tree ): void {
		$GLOBALS['stonewright_test_posts'][ $id ] = SectionFixtures::post( $id, 'page', self::SOURCE === $id ? 'publish' : 'draft', 'Page ' . $id, '', SectionFixtures::elementor_meta( $tree, defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.0.0' ) );
	}

	/** @return array<string, mixed> The portable section the extract ability returns for the first section of the source. */
	private static function section( ?string $id = null ): array {
		$tree = ElementorData::read( self::SOURCE );
		$GLOBALS['stonewright_test_user_can_callback'] = static fn( string $cap, mixed ...$args ): bool => in_array( $cap, [ 'edit_posts', 'read_post', 'edit_post' ], true );
		$result = ( new SectionReuseExtract() )->execute( [ 'post_id' => self::SOURCE, 'locator' => [ 'kind' => 'element', 'id' => $id ?? (string) $tree[0]['id'] ] ] );
		$GLOBALS['stonewright_test_user_can_callback'] = null;
		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_code() . ': ' . $result->get_error_message() : '' );

		return $result;
	}

	/** @return array<string, mixed> */
	private static function insert_op( array $section, array $extra = [] ): array {
		return array_merge( [ 'action' => 'insert_section', 'op_id' => 'feat', 'parent_id' => 'root', 'section' => $section ], $extra );
	}

	/** @return array<string, mixed>|\WP_Error */
	private static function batch( array $operations, bool $dry_run = false ): array|\WP_Error {
		return ( new BatchMutate() )->execute( [ 'post_id' => self::TARGET, 'dry_run' => $dry_run, 'operations' => $operations ] );
	}

	private static function target_writes(): int {
		return count( array_filter( $GLOBALS['stonewright_test_post_meta_calls'], static fn( array $call ): bool => self::TARGET === $call['post_id'] && '_elementor_data' === $call['meta_key'] ) );
	}

	private static function error_code( array|\WP_Error $result ): string {
		self::assertInstanceOf( \WP_Error::class, $result );
		$data = $result->get_error_data();
		if ( 'stonewright_batch_operation_failed' === $result->get_error_code() && is_array( $data['items'] ?? null ) ) {
			foreach ( $data['items'] as $item ) {
				if ( empty( $item['ok'] ) ) {
					return (string) $item['error']['code'];
				}
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

	/** @param list<array<string, mixed>> $tree @return array<string, array<string, mixed>> Elements by id that carry a `_element_id`. */
	private static function dom_ids( array $tree ): array {
		$found = [];
		foreach ( ElementorData::flatten( $tree ) as $element ) {
			if ( ! empty( $element['settings']['_element_id'] ) ) {
				$found[ (string) $element['id'] ] = (string) $element['settings']['_element_id'];
			}
		}

		return $found;
	}

	// ---------------------------------------------------------------- BUG-3

	public function test_a_v4_payload_sent_to_the_v3_writer_fails_with_the_builder_mismatch_code_before_the_css_gate(): void {
		$payload = [ 'schema' => 'SectionPortableV1', 'builder' => 'elementor-v4', 'element' => SectionFixtures::v4_features( 'v' ) ];

		foreach ( [ true, false ] as $dry_run ) {
			$result = self::batch( [ self::insert_op( $payload ) ], $dry_run );

			self::assertInstanceOf( \WP_Error::class, $result );
			self::assertSame( 'stonewright_section_builder_mismatch', $result->get_error_code(), 'Not the custom CSS approval flow: the mistake is the builder.' );
			self::assertSame( 'elementor-v4', $result->get_error_data()['section_builder'] );
			self::assertSame( 'elementor-v3', $result->get_error_data()['target_builder'] );
		}
		self::assertSame( 0, self::target_writes() );
	}

	// ---------------------------------------------------------------- BUG-4

	public function test_a_stored_value_the_live_control_still_maps_does_not_block_the_section(): void {
		ReuseWidgetRegistry::install( [], true );
		$hero = SectionFixtures::v3_hero();
		$hero['elements'][0]['elements'][0]['settings']['align'] = 'left';
		self::seed( self::SOURCE, [ $hero ] );

		$plan   = self::batch( [ self::insert_op( self::section()['section'] ) ], true );
		$result = self::batch( [ self::insert_op( self::section()['section'] ) ] );

		self::assertIsArray( $plan, $plan instanceof \WP_Error ? wp_json_encode( $plan->get_error_data() ) : '' );
		self::assertIsArray( $result, $result instanceof \WP_Error ? wp_json_encode( $result->get_error_data() ) : '' );
		$heading = ElementorData::flatten( ElementorData::read( self::TARGET ) )[ $result['refs']['feat.ph-3'] ];
		self::assertSame( 'left', $heading['settings']['align'], 'The stored value is kept exactly.' );
	}

	public function test_a_value_the_live_control_does_not_map_is_still_refused_naming_the_key(): void {
		ReuseWidgetRegistry::install( [], true );
		$hero = SectionFixtures::v3_hero();
		$hero['elements'][0]['elements'][0]['settings']['align'] = 'sideways';
		self::seed( self::SOURCE, [ $hero ] );

		$result = self::batch( [ self::insert_op( self::section()['section'] ) ], true );

		self::assertSame( 'stonewright_section_settings_not_reusable', self::error_code( $result ) );
		$violation = self::error_data( $result )['violations'][0];
		self::assertSame( 'settings.align', $violation['path'] );
		self::assertSame( 'invalid_option', $violation['code'] );
	}

	// ---------------------------------------------------------------- BUG-5

	/** @return array<string, mixed> A features section with an anchor, a button and a text link that point at it. */
	private static function anchored_section(): array {
		return [
			'id'       => 'a000001',
			'elType'   => 'container',
			'isInner'  => false,
			'settings' => [ 'container_type' => 'flex', '_element_id' => 'features' ],
			'elements' => [
				[ 'id' => 'a000002', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Why choose us' ], 'elements' => [] ],
				[ 'id' => 'a000003', 'elType' => 'widget', 'widgetType' => 'button', 'settings' => [ 'text' => 'Back to features', 'link' => [ 'url' => '#features', 'is_external' => '', 'nofollow' => '' ] ], 'elements' => [] ],
				[ 'id' => 'a000004', 'elType' => 'widget', 'widgetType' => 'text-editor', 'settings' => [ 'editor' => '<p><a href="#features">Top</a> and <a href="#pricing">Pricing</a></p>' ], 'elements' => [] ],
			],
		];
	}

	public function test_an_element_id_the_page_already_uses_is_renamed_and_the_links_of_the_copy_follow(): void {
		self::seed( self::SOURCE, [ self::anchored_section() ] );
		$target       = self::root();
		$target['elements'][] = [ 'id' => 'taken01', 'elType' => 'container', 'isInner' => true, 'settings' => [ '_element_id' => 'features' ], 'elements' => [] ];
		self::seed( self::TARGET, [ $target ] );

		$result = self::batch( [ self::insert_op( self::section()['section'] ) ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? wp_json_encode( $result->get_error_data() ) : '' );
		$elements = ElementorData::flatten( ElementorData::read( self::TARGET ) );
		self::assertSame( 'features', $elements['taken01']['settings']['_element_id'], 'The page\'s own element keeps its id.' );
		self::assertSame( 'features-2', $elements[ $result['refs']['feat'] ]['settings']['_element_id'] );
		self::assertSame( '#features-2', $elements[ $result['refs']['feat.ph-3'] ]['settings']['link']['url'] );
		self::assertSame( '<p><a href="#features-2">Top</a> and <a href="#pricing">Pricing</a></p>', $elements[ $result['refs']['feat.ph-4'] ]['settings']['editor'], 'Only the links to the renamed id change.' );
		self::assertSame( 'anchors_renamed', $result['items'][0]['warnings'][0]['code'] );
		self::assertSame( [ 'features -> features-2' ], $result['items'][0]['warnings'][0]['items'] );
		$ids = array_values( self::dom_ids( ElementorData::read( self::TARGET ) ) );
		self::assertSame( $ids, array_values( array_unique( $ids ) ), 'No id appears twice in the document.' );
	}

	public function test_the_same_section_twice_in_one_batch_gets_distinct_ids_and_each_copy_links_to_its_own(): void {
		self::seed( self::SOURCE, [ self::anchored_section() ] );
		$section = self::section()['section'];

		$result = self::batch( [ self::insert_op( $section ), self::insert_op( $section, [ 'op_id' => 'again' ] ) ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? wp_json_encode( $result->get_error_data() ) : '' );
		$elements = ElementorData::flatten( ElementorData::read( self::TARGET ) );
		self::assertSame( 'features', $elements[ $result['refs']['feat'] ]['settings']['_element_id'] );
		self::assertSame( 'features-2', $elements[ $result['refs']['again'] ]['settings']['_element_id'] );
		self::assertSame( '#features', $elements[ $result['refs']['feat.ph-3'] ]['settings']['link']['url'] );
		self::assertSame( '#features-2', $elements[ $result['refs']['again.ph-3'] ]['settings']['link']['url'] );
		self::assertArrayNotHasKey( 'warnings', $result['items'][0], 'The first copy needed no rename.' );
		self::assertSame( 'anchors_renamed', $result['items'][1]['warnings'][0]['code'] );
		$ids = array_values( self::dom_ids( ElementorData::read( self::TARGET ) ) );
		self::assertSame( $ids, array_values( array_unique( $ids ) ) );
	}

	public function test_the_next_free_number_is_used(): void {
		self::seed( self::SOURCE, [ self::anchored_section() ] );
		$target = self::root();
		foreach ( [ 'features', 'features-2' ] as $index => $dom_id ) {
			$target['elements'][] = [ 'id' => 'taken0' . $index, 'elType' => 'container', 'isInner' => true, 'settings' => [ '_element_id' => $dom_id ], 'elements' => [] ];
		}
		self::seed( self::TARGET, [ $target ] );

		$result = self::batch( [ self::insert_op( self::section()['section'] ) ] );

		self::assertIsArray( $result );
		self::assertSame( 'features-3', ElementorData::flatten( ElementorData::read( self::TARGET ) )[ $result['refs']['feat'] ]['settings']['_element_id'] );
	}

	// ---------------------------------------------------------------- BUG-7, BUG-8

	/** @return array<string, mixed> A section with a form widget (no settings) and a price table (with settings). */
	private static function section_with_pro_widgets(): array {
		return [
			'id'       => 'p000001',
			'elType'   => 'container',
			'isInner'  => false,
			'settings' => [ 'container_type' => 'flex' ],
			'elements' => [
				[ 'id' => 'p000002', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Plans' ], 'elements' => [] ],
				[ 'id' => 'p000003', 'elType' => 'widget', 'widgetType' => 'form', 'settings' => [], 'elements' => [] ],
				[ 'id' => 'p000004', 'elType' => 'widget', 'widgetType' => 'price-table', 'settings' => [ 'heading' => 'Basic', 'price' => '9' ], 'elements' => [] ],
			],
		];
	}

	public function test_extract_warns_with_the_names_of_the_placeholder_widgets(): void {
		ReuseWidgetRegistry::install( [ 'form', 'price-table' ] );
		self::seed( self::SOURCE, [ self::section_with_pro_widgets() ] );

		$warnings = self::section()['warnings'];

		$placeholder = array_values( array_filter( $warnings, static fn( array $warning ): bool => 'placeholder_widgets' === $warning['code'] ) );
		self::assertCount( 1, $placeholder );
		self::assertSame( [ 'form', 'price-table' ], $placeholder[0]['items'] );
		self::assertSame( 2, $placeholder[0]['count'] );
	}

	public function test_a_placeholder_widget_is_never_inserted_with_or_without_settings_dry_run_included(): void {
		ReuseWidgetRegistry::install( [ 'form', 'price-table' ] );
		$settings_only = self::section_with_pro_widgets();
		array_splice( $settings_only['elements'], 1, 1 );
		$empty_only = self::section_with_pro_widgets();
		array_splice( $empty_only['elements'], 2, 1 );

		foreach ( [ [ $empty_only, 'form' ], [ $settings_only, 'price-table' ] ] as [ $tree, $widget ] ) {
			self::seed( self::SOURCE, [ $tree ] );
			$section = self::section()['section'];
			foreach ( [ true, false ] as $dry_run ) {
				$result = self::batch( [ self::insert_op( $section ) ], $dry_run );

				self::assertSame( 'stonewright_section_placeholder_widget', self::error_code( $result ), $widget );
				$data = self::error_data( $result );
				self::assertSame( $widget, $data['widget_type'] );
				self::assertStringContainsString( '"' . $widget . '"', $result->get_error_data()['items'][0]['error']['message'] );
				self::assertStringContainsString( 'not active', $result->get_error_data()['items'][0]['error']['message'] );
			}
		}
		self::assertSame( 0, self::target_writes() );
	}

	public function test_an_unregistered_widget_fails_at_the_dry_run_with_or_without_settings(): void {
		foreach ( [ [], [ 'title' => 'Hello' ] ] as $settings ) {
			$tree = self::section_with_pro_widgets();
			$tree['elements'] = [ [ 'id' => 'p000002', 'elType' => 'widget', 'widgetType' => 'qa-ghost-widget', 'settings' => $settings, 'elements' => [] ] ];
			self::seed( self::SOURCE, [ $tree ] );
			$section = self::section()['section'];

			foreach ( [ true, false ] as $dry_run ) {
				$result = self::batch( [ self::insert_op( $section ) ], $dry_run );

				self::assertSame( 'stonewright_section_widget_unregistered', self::error_code( $result ) );
				self::assertSame( 'qa-ghost-widget', self::error_data( $result )['widget_type'] );
			}
		}
		self::assertSame( 0, self::target_writes() );
	}

	// ---------------------------------------------------------------- BUG-11

	public function test_a_duplicate_op_id_in_one_batch_is_refused_before_anything_runs(): void {
		$section = self::section()['section'];

		foreach ( [
			[ self::insert_op( $section ), self::insert_op( $section ) ],
			[ self::insert_op( $section, [ 'op_id' => 'feat' ] ), [ 'action' => 'add_container', 'op_id' => 'feat', 'parent_id' => 'root', 'settings' => [ 'flex_direction' => 'column' ] ] ],
		] as $operations ) {
			foreach ( [ true, false ] as $dry_run ) {
				$result = self::batch( $operations, $dry_run );

				self::assertInstanceOf( \WP_Error::class, $result );
				self::assertSame( 'stonewright_duplicate_op_id', $result->get_error_code() );
				self::assertSame( 'feat', $result->get_error_data()['op_id'] );
				self::assertSame( [ 0, 1 ], $result->get_error_data()['indexes'] );
			}
		}
		self::assertSame( 0, self::target_writes() );
	}

	public function test_operations_without_an_op_id_or_with_different_ones_are_untouched(): void {
		$section = self::section()['section'];

		$result = self::batch( [ self::insert_op( $section, [ 'op_id' => 'one' ] ), self::insert_op( $section, [ 'op_id' => 'two' ] ), [ 'action' => 'update_element', 'element_ref' => 'one.ph-3', 'settings' => [ 'title' => 'x' ] ], [ 'action' => 'update_element', 'element_ref' => 'two.ph-3', 'settings' => [ 'title' => 'y' ] ] ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? wp_json_encode( $result->get_error_data() ) : '' );
	}

	// ---------------------------------------------------------------- BUG-12

	/** @return array<string, mixed> A section of one container and the given number of headings. */
	private static function wide_section( int $widgets ): array {
		$children = [];
		for ( $i = 1; $i <= $widgets; $i++ ) {
			$children[] = [ 'id' => 'w' . $i, 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Row ' . $i ], 'elements' => [] ];
		}

		return [ 'id' => 'wide', 'elType' => 'container', 'isInner' => false, 'settings' => [ 'container_type' => 'flex' ], 'elements' => $children ];
	}

	public function test_the_copies_of_one_batch_may_not_exceed_the_element_cap(): void {
		self::seed( self::SOURCE, [ self::wide_section( 1000 ) ] );
		$section = self::section()['section'];

		$one = self::batch( [ self::insert_op( $section ) ], true );
		self::assertIsArray( $one, $one instanceof \WP_Error ? self::error_code( $one ) : '' );

		foreach ( [ true, false ] as $dry_run ) {
			$two = self::batch( [ self::insert_op( $section ), self::insert_op( $section, [ 'op_id' => 'again' ] ) ], $dry_run );

			self::assertSame( 'stonewright_section_batch_too_large', self::error_code( $two ) );
			$data = self::error_data( $two );
			self::assertSame( 2000, $data['limit'] );
			self::assertSame( 1001, $data['inserted'] );
			self::assertSame( 1001, $data['adding'] );
		}
		self::assertSame( 0, self::target_writes() );
	}

	// ---------------------------------------------------------------- a nested container as the section

	/** @return array<string, mixed> A features section whose row of cards is an anchored, nested container. */
	private static function section_with_nested_containers(): array {
		$section = SectionFixtures::v3_features( 'f', 3 );
		$section['elements'][1]['settings']['_element_id'] = 'cards';
		$section['elements'][1]['elements'][0]['elements'][0]['settings']['link'] = [ 'url' => '#cards', 'is_external' => '', 'nofollow' => '' ];

		return $section;
	}

	public function test_a_nested_container_is_inserted_like_a_top_level_section_with_placeholders_fresh_ids_and_refs(): void {
		self::seed( self::SOURCE, [ SectionFixtures::v3_hero(), self::section_with_nested_containers() ] );
		$extracted = self::section( 'f000003' );
		$ops       = [
			self::insert_op( $extracted['section'] ),
			[ 'action' => 'update_element', 'element_ref' => 'feat.ph-3', 'settings' => [ 'title_text' => 'Quality' ] ],
		];

		$plan   = self::batch( $ops, true );
		$result = self::batch( $ops );

		self::assertIsArray( $plan, $plan instanceof \WP_Error ? wp_json_encode( $plan->get_error_data() ) : '' );
		self::assertIsArray( $result, $result instanceof \WP_Error ? wp_json_encode( $result->get_error_data() ) : '' );
		self::assertSame( 7, $result['items'][0]['placeholders'], 'The row, three cards and three icon boxes.' );
		$elements = ElementorData::flatten( ElementorData::read( self::TARGET ) );
		self::assertCount( 8, $elements, 'The root and the seven copied elements.' );
		self::assertSame( 'Quality', $elements[ $result['refs']['feat.ph-3'] ]['settings']['title_text'], 'A placeholder is addressed in the same batch.' );
		$ids = array_keys( $elements );
		self::assertSame( [], array_intersect( $ids, [ 'f000003', 'fc1', 'fc2', 'fc3', 'fi1', 'fi2', 'fi3' ] ), 'No source id reaches the target.' );
		self::assertSame( $ids, array_values( array_unique( $ids ) ) );
		self::assertTrue( $elements[ $result['refs']['feat'] ]['isInner'], 'Inside the root container it is an inner container.' );
	}

	public function test_a_nested_container_inserted_at_the_document_root_is_a_top_level_element(): void {
		self::seed( self::SOURCE, [ self::section_with_nested_containers() ] );
		$section = self::section( 'f000003' )['section'];
		$op      = self::insert_op( $section );
		unset( $op['parent_id'] );

		$result = self::batch( [ $op ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? wp_json_encode( $result->get_error_data() ) : '' );
		$tree = ElementorData::read( self::TARGET );
		self::assertSame( [ 'root', $result['refs']['feat'] ], array_column( $tree, 'id' ) );
		self::assertFalse( $tree[1]['isInner'] );
	}

	public function test_the_anchor_of_a_nested_container_is_renamed_when_the_page_already_uses_it(): void {
		self::seed( self::SOURCE, [ self::section_with_nested_containers() ] );
		$target               = self::root();
		$target['elements'][] = [ 'id' => 'taken01', 'elType' => 'container', 'isInner' => true, 'settings' => [ '_element_id' => 'cards' ], 'elements' => [] ];
		self::seed( self::TARGET, [ $target ] );
		$section = self::section( 'f000003' )['section'];

		$result = self::batch( [ self::insert_op( $section ) ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? wp_json_encode( $result->get_error_data() ) : '' );
		$elements = ElementorData::flatten( ElementorData::read( self::TARGET ) );
		self::assertSame( 'cards', $elements['taken01']['settings']['_element_id'] );
		self::assertSame( 'cards-2', $elements[ $result['refs']['feat'] ]['settings']['_element_id'] );
		self::assertSame( '#cards-2', $elements[ $result['refs']['feat.ph-3'] ]['settings']['link']['url'], 'Links of the copy follow the renamed id.' );
		self::assertSame( 'anchors_renamed', $result['items'][0]['warnings'][0]['code'] );
	}

	public function test_a_nested_container_counts_against_the_element_cap_like_any_section(): void {
		$wide            = self::wide_section( 1000 );
		$wide['isInner'] = true;
		self::seed( self::SOURCE, [ [ 'id' => 'outer', 'elType' => 'container', 'isInner' => false, 'settings' => [], 'elements' => [ $wide ] ] ] );
		$section = self::section( 'wide' )['section'];

		$two = self::batch( [ self::insert_op( $section ), self::insert_op( $section, [ 'op_id' => 'again' ] ) ] );

		self::assertSame( 'stonewright_section_batch_too_large', self::error_code( $two ) );
		self::assertSame( 1001, self::error_data( $two )['adding'] );
		self::assertSame( 0, self::target_writes() );
	}

	public function test_a_private_nested_source_is_recorded_and_the_insert_checks_the_source_again(): void {
		self::seed( self::SOURCE, [ self::section_with_nested_containers() ] );
		$GLOBALS['stonewright_test_posts'][ self::SOURCE ]->post_status = 'private';
		$section = self::section( 'f000003' )['section'];

		$result = self::batch( [ self::insert_op( $section ) ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? wp_json_encode( $result->get_error_data() ) : '' );
		self::assertSame( self::SOURCE, $result['items'][0]['reuse_source']['post_id'] );
		self::assertSame( 'f000003', $result['items'][0]['reuse_source']['locator']['id'] );
		$GLOBALS['stonewright_test_user_can_callback'] = static fn( string $cap, mixed ...$args ): bool => 'edit_posts' === $cap || ( 'edit_post' === $cap && self::TARGET === (int) ( $args[0] ?? 0 ) );
		$refused = self::batch( [ self::insert_op( $section, [ 'op_id' => 'again' ] ) ] );
		self::assertSame( 'stonewright_section_source_not_permitted', self::error_code( $refused ), 'A payload never grants access to a private post.' );
	}
}
