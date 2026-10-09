<?php
/**
 * Reusing a V4 (Atomic) section through elementor-v4-update-node.
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
use Stonewright\WpMcp\SectionReuse\SectionReuseSetting;
use Stonewright\WpMcp\Security\IncidentStore;
use Stonewright\WpMcp\Support\ElementorData;
use Stonewright\WpMcp\Tests\Unit\Security\ChangeSetAssertions;

require_once __DIR__ . '/SectionFixtures.php';
require_once dirname( __DIR__ ) . '/Security/ChangeSetAssertions.php';

/**
 * @covers \Stonewright\WpMcp\Abilities\ElementorV4\UpdateNode
 * @covers \Stonewright\WpMcp\SectionReuse\ElementorSectionInserter
 */
final class SectionInsertElementorV4Test extends TestCase {
	use ChangeSetAssertions;

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
		$root = [
			'id'              => 'a000001',
			'version'         => '0.0',
			'elType'          => 'e-div-block',
			'isInner'         => false,
			'settings'        => [],
			'editor_settings' => [],
			'interactions'    => [],
			'styles'          => [],
			'elements'        => [],
		];
		$GLOBALS['stonewright_test_posts'] = [
			self::TARGET => SectionFixtures::post( self::TARGET, 'page', 'draft', 'New page', '', SectionFixtures::elementor_meta( [ $root ] ) ),
			self::SOURCE => SectionFixtures::post( self::SOURCE, 'page', 'publish', 'Source page', '', SectionFixtures::elementor_meta( [ SectionFixtures::v4_features( 'v', 3, 'Why choose us' ) ] ) ),
		];
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
	private static function section(): array {
		$GLOBALS['stonewright_test_user_can_callback'] = static fn( string $cap, mixed ...$args ): bool => in_array( $cap, [ 'edit_posts', 'read_post', 'edit_post' ], true );
		$result = ( new SectionReuseExtract() )->execute( [ 'post_id' => self::SOURCE, 'locator' => [ 'kind' => 'element', 'id' => 'v000001' ] ] );
		$GLOBALS['stonewright_test_user_can_callback'] = null;
		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );

		return $result['section'];
	}

	/** @return array<string, mixed> */
	private static function insert_op( array $section, array $extra = [] ): array {
		return array_merge( [ 'action' => 'insert_section', 'op_id' => 'feat', 'parent_id' => 'a000001', 'section' => $section ], $extra );
	}

	/** @return array<string, mixed>|\WP_Error */
	private static function batch( array $operations, array $extra = [] ): array|\WP_Error {
		return ( new UpdateNode() )->execute( array_merge( [ 'post_id' => self::TARGET, 'operations' => $operations ], $extra ) );
	}

	/** @return list<array<string, mixed>> */
	private static function tree(): array {
		return ElementorData::read( self::TARGET );
	}

	private static function target_writes(): int {
		return count( array_filter( $GLOBALS['stonewright_test_post_meta_calls'], static fn( array $call ): bool => self::TARGET === $call['post_id'] && '_elementor_data' === $call['meta_key'] ) );
	}

	private static function html( string $text ): array {
		return [ '$$type' => 'html-v3', 'value' => [ 'content' => [ '$$type' => 'string', 'value' => $text ], 'children' => [] ] ];
	}

	public function test_a_section_and_its_adaptation_go_through_one_dry_run_and_one_apply(): void {
		$section = self::section();
		$before  = serialize( $GLOBALS['stonewright_test_posts'][ self::SOURCE ] );
		$ops     = [
			self::insert_op( $section ),
			[ 'action' => 'update_node', 'element_ref' => 'feat.ph-2', 'settings' => [ 'title' => self::html( 'Built to last' ) ] ],
		];

		$plan = self::batch( $ops, [ 'dry_run' => true ] );

		self::assertIsArray( $plan, $plan instanceof \WP_Error ? $plan->get_error_message() . wp_json_encode( $plan->get_error_data() ) : '' );
		self::assertTrue( $plan['dry_run'] );
		self::assertSame( 0, self::target_writes() );
		self::assertSame( 'insert_section', $plan['items'][0]['action'] );
		self::assertSame( 'update_node', $plan['items'][1]['action'] );

		$result = self::batch( $ops );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() . wp_json_encode( $result->get_error_data() ) : '' );
		self::assertSame( 1, self::target_writes(), 'One apply is one write.' );
		self::assertTrue( $result['readback']['verified'] );
		self::assertSame( 'document_tree', $result['readback']['method'] );
		self::assertSame( 10, $result['readback']['checked'], 'Every node of the document was compared, children included.' );
		self::assertNotSame( '', $result['snapshot_id'] );
		$heading = ElementorData::flatten( self::tree() )[ $result['refs']['feat.ph-2'] ];
		self::assertSame( 'Built to last', $heading['settings']['title']['value']['content']['value'] );
		self::assertSame( 'e-heading', $heading['widgetType'], 'The widget type is never changed.' );
		self::assertSame( $before, serialize( $GLOBALS['stonewright_test_posts'][ self::SOURCE ] ), 'The source is byte for byte unchanged.' );
	}

	public function test_an_applied_batch_clears_the_cached_local_styles_of_the_target_only(): void {
		$cleared = [];
		add_action(
			'elementor/atomic-widgets/styles/clear',
			static function ( array $path ) use ( &$cleared ): void {
				$cleared[] = $path;
			}
		);
		$ops = [ self::insert_op( self::section() ) ];

		self::batch( $ops, [ 'dry_run' => true ] );
		self::assertSame( [], $cleared, 'A dry run clears nothing.' );

		$result = self::batch( $ops );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertSame( [ [ 'local', self::TARGET ] ], $cleared );
		remove_all_actions( 'elementor/atomic-widgets/styles/clear' );
	}

	public function test_fresh_element_ids_and_consistently_remapped_local_style_ids(): void {
		$section = self::section();

		$result = self::batch( [ self::insert_op( $section ), self::insert_op( $section, [ 'op_id' => 'again' ] ) ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() . wp_json_encode( $result->get_error_data() ) : '' );
		$flat = ElementorData::flatten( self::tree() );
		self::assertCount( 19, $flat, 'The root and two copies of nine nodes.' );
		$all_style_ids = [];
		foreach ( $flat as $id => $node ) {
			$styles = is_array( $node['styles'] ?? null ) ? array_keys( $node['styles'] ) : [];
			foreach ( $styles as $style_id ) {
				self::assertStringStartsWith( 'e-' . $id . '-', (string) $style_id, 'A local style id names the element it belongs to.' );
				self::assertSame( $style_id, $node['styles'][ $style_id ]['id'], 'The style definition carries its own new id.' );
				self::assertNotContains( $style_id, $all_style_ids, 'No style id is shared between elements or copies.' );
				$all_style_ids[] = $style_id;
			}
			$classes = is_array( $node['settings']['classes']['value'] ?? null ) ? $node['settings']['classes']['value'] : [];
			foreach ( $classes as $class ) {
				if ( 'g-card' === $class ) {
					continue;
				}
				self::assertContains( $class, $styles, 'Every local class on an element is one of its own styles.' );
			}
		}
		self::assertCount( 10, $all_style_ids, 'Five styled elements in each copy.' );
		foreach ( [ 'f00ba12', 'a1b2c3d', '9e8d7c6' ] as $old_suffix ) {
			self::assertStringNotContainsString( $old_suffix, (string) wp_json_encode( self::tree() ), 'No source style id survives.' );
		}
		$card = $flat[ $result['refs']['feat.ph-4'] ];
		self::assertSame( 'g-card', $card['settings']['classes']['value'][1], 'The global class is kept.' );
	}

	public function test_the_change_set_records_the_source_and_the_route_says_which_writer_ran(): void {
		$result = self::batch( [ self::insert_op( self::section() ) ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() . wp_json_encode( $result->get_error_data() ) : '' );
		$change_set = $result['change_set'];
		self::assertValidChangeSet( $change_set );
		self::assertSame( [ [ 'post_id' => self::SOURCE, 'builder' => 'elementor-v4', 'locator' => [ 'kind' => 'element', 'id' => 'v000001' ] ] ], $change_set['reuse_source'] );
		self::assertSame( [ 'insert_section' ], array_column( $change_set['planned'], 'action' ) );
		self::assertSame( $change_set['planned'], $change_set['applied'] );
		self::assertSame( [], $change_set['unexpected'] );
		self::assertSame( 'verified', $change_set['verification']['status'] );
		self::assertSame( 'stonewright_v4_fallback', $result['route']['route'] );
		self::assertSame( 'native', $result['route']['document_route']['route'], 'The document itself would route native; a raw Atomic tree cannot be carried by the native composition.' );
	}

	public function test_while_the_setting_is_off_the_insert_is_refused(): void {
		$section = self::section();
		$GLOBALS['stonewright_test_options'][ SectionReuseSetting::OPTION ] = 'off';

		$result = self::batch( [ self::insert_op( $section ) ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_section_reuse_off', $result->get_error_data()['items'][0]['error']['code'] );
		self::assertSame( 0, self::target_writes() );
	}

	public function test_a_section_of_another_builder_is_refused(): void {
		$section            = self::section();
		$section['builder'] = 'elementor-v3';

		$result = self::batch( [ self::insert_op( $section ) ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_section_builder_mismatch', $result->get_error_code() );
	}

	public function test_a_missing_global_class_or_variable_fails_with_the_exact_reference(): void {
		$section = self::section();
		ReferenceCatalog::set_provider( static fn( string $type, string $id ): ?bool => 'g-card' === $id ? false : true );

		$result = self::batch( [ self::insert_op( $section ) ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		$error = $result->get_error_data()['items'][0]['error'];
		self::assertSame( 'stonewright_section_reference_missing', $error['code'] );
		self::assertSame( [ 'type' => 'global_class', 'id' => 'g-card' ], array_intersect_key( $error['data']['reference'], [ 'type' => 1, 'id' => 1 ] ) );
		self::assertSame( 0, self::target_writes() );

		$section['element']['styles']['ls-1']['variants'][0]['props']['color'] = [ '$$type' => 'global-color-variable', 'value' => 'e-gv-brand' ];
		ReferenceCatalog::set_provider( static fn( string $type, string $id ): ?bool => 'e-gv-brand' === $id ? false : true );

		$again = self::batch( [ self::insert_op( $section ) ] );

		self::assertInstanceOf( \WP_Error::class, $again );
		self::assertSame( 'variable', $again->get_error_data()['items'][0]['error']['data']['reference']['type'] );
		self::assertSame( 'e-gv-brand', $again->get_error_data()['items'][0]['error']['data']['reference']['id'] );
	}

	public function test_an_atomic_type_this_site_does_not_have_fails_with_the_exact_missing_feature(): void {
		$section = self::section();
		$section['element']['elements'][0]['widgetType'] = 'e-acme-unknown';

		$result = self::batch( [ self::insert_op( $section ) ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		$error = $result->get_error_data()['items'][0]['error'];
		self::assertSame( 'stonewright_atomic_type_unavailable', $error['code'] );
		self::assertSame( 'atomic_type:e-acme-unknown', $error['data']['missing_feature'] );
	}

	public function test_production_safe_mode_still_blocks_v4_writes_but_allows_the_dry_run(): void {
		$section = self::section();
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		$ability = new UpdateNode();

		$write = $ability->permission_callback( [ 'post_id' => self::TARGET, 'operations' => [ self::insert_op( $section ) ] ] );
		$plan  = $ability->permission_callback( [ 'post_id' => self::TARGET, 'dry_run' => true, 'operations' => [ self::insert_op( $section ) ] ] );

		self::assertInstanceOf( \WP_Error::class, $write );
		self::assertSame( 'stonewright_v4_experimental_production_block', $write->get_error_code() );
		self::assertTrue( $plan );
	}

	public function test_a_v3_document_is_never_converted(): void {
		$GLOBALS['stonewright_test_posts'][ self::TARGET ]->meta['_elementor_data'] = (string) wp_json_encode( [ SectionFixtures::v3_features( 'f' ) ] );

		$result = self::batch( [ self::insert_op( self::section(), [ 'parent_id' => 'f000001' ] ) ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_v4_architecture_mismatch', $result->get_error_data()['items'][0]['error']['code'] );
		self::assertSame( 0, self::target_writes() );
	}

	public function test_a_mixed_document_does_not_take_a_v4_section_at_its_root(): void {
		$GLOBALS['stonewright_test_posts'][ self::TARGET ]->meta['_elementor_data'] = (string) wp_json_encode( [ SectionFixtures::v3_features( 'f' ), SectionFixtures::v4_features( 'q' ) ] );

		$result = self::batch( [ self::insert_op( self::section(), [ 'parent_id' => '' ] ) ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_native_route_refused', $result->get_error_data()['items'][0]['error']['code'] );
	}

	public function test_custom_css_in_a_style_needs_human_approval_but_the_empty_custom_css_key_of_every_variant_does_not(): void {
		$section = self::section();
		self::assertNull( $section['element']['styles']['ls-1']['variants'][0]['custom_css'], 'Every variant carries the key, empty.' );

		$plain = self::batch( [ self::insert_op( $section ) ], [ 'dry_run' => true ] );
		self::assertIsArray( $plain, $plain instanceof \WP_Error ? $plain->get_error_message() : '' );

		$section['element']['styles']['ls-1']['variants'][0]['custom_css'] = [ 'raw' => base64_encode( 'selector { color: red; }' ) ];
		$gated = self::batch( [ self::insert_op( $section ) ], [ 'dry_run' => true ] );

		self::assertInstanceOf( \WP_Error::class, $gated );
		self::assertSame( 'stonewright_custom_code_approval_required', $gated->get_error_code() );
	}

	public function test_the_single_node_patch_still_works_unchanged(): void {
		$GLOBALS['stonewright_test_posts'][ self::TARGET ]->meta['_elementor_data'] = (string) wp_json_encode( [ SectionFixtures::v4_features( 'v' ) ] );

		$result = ( new UpdateNode() )->execute( [ 'post_id' => self::TARGET, 'element_id' => 'v000002', 'settings' => [ 'tag' => [ '$$type' => 'heading-level', 'value' => 'h3' ] ] ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertSame( 'v000002', $result['element_id'] );
	}

	public function test_a_single_node_patch_clears_the_cached_local_styles_of_the_target_after_the_write(): void {
		$GLOBALS['stonewright_test_posts'][ self::TARGET ]->meta['_elementor_data'] = (string) wp_json_encode( [ SectionFixtures::v4_features( 'v' ) ] );
		$cleared = [];
		add_action(
			'elementor/atomic-widgets/styles/clear',
			static function ( array $path ) use ( &$cleared ): void {
				$cleared[] = $path;
			}
		);
		$input = [ 'post_id' => self::TARGET, 'element_id' => 'v000002', 'settings' => [ 'tag' => [ '$$type' => 'heading-level', 'value' => 'h3' ] ] ];

		( new UpdateNode() )->execute( array_merge( $input, [ 'dry_run' => true ] ) );
		self::assertSame( [], $cleared, 'A dry run clears nothing.' );

		$result = ( new UpdateNode() )->execute( $input );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertSame( [ [ 'local', self::TARGET ] ], $cleared );
		remove_all_actions( 'elementor/atomic-widgets/styles/clear' );
	}

	public function test_a_batch_with_a_failing_operation_writes_nothing(): void {
		$result = self::batch(
			[
				self::insert_op( self::section() ),
				[ 'action' => 'update_node', 'element_ref' => 'feat.ph-404', 'settings' => [ 'tag' => [ '$$type' => 'string', 'value' => 'h3' ] ] ],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 0, self::target_writes() );
		self::assertSame( [ 'a000001' ], array_keys( ElementorData::flatten( self::tree() ) ) );
	}

	public function test_the_source_must_be_readable_and_editable_by_the_user(): void {
		$section = self::section();
		$GLOBALS['stonewright_test_user_can_callback'] = static fn( string $cap, mixed ...$args ): bool => 'edit_post' === $cap && self::TARGET === (int) ( $args[0] ?? 0 ) || 'edit_posts' === $cap;

		$result = self::batch( [ self::insert_op( $section ) ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_section_source_not_permitted', $result->get_error_data()['items'][0]['error']['code'] );
	}
}
