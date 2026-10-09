<?php
/**
 * Reusing a V3 section through elementor-v3-batch-mutate.
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
use Stonewright\WpMcp\SectionReuse\SectionReuseSetting;
use Stonewright\WpMcp\Security\ConfirmationToken;
use Stonewright\WpMcp\Security\IncidentStore;
use Stonewright\WpMcp\Support\ElementorData;
use Stonewright\WpMcp\Tests\Unit\Security\ChangeSetAssertions;

require_once __DIR__ . '/SectionFixtures.php';
require_once dirname( __DIR__ ) . '/Security/ChangeSetAssertions.php';

/**
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\BatchMutate
 * @covers \Stonewright\WpMcp\SectionReuse\ElementorSectionInserter
 * @covers \Stonewright\WpMcp\SectionReuse\ReuseSource
 */
final class SectionInsertElementorV3Test extends TestCase {
	use ChangeSetAssertions;

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
		$GLOBALS['stonewright_test_posts']             = [
			self::TARGET => SectionFixtures::post( self::TARGET, 'page', 'draft', 'New page', '', SectionFixtures::elementor_meta( [ [ 'id' => 'root', 'elType' => 'container', 'isInner' => false, 'settings' => [ 'container_type' => 'flex' ], 'elements' => [] ] ], defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.0.0' ) ),
			self::SOURCE => SectionFixtures::post( self::SOURCE, 'page', 'publish', 'Source page', '', SectionFixtures::elementor_meta( [ SectionFixtures::v3_hero(), SectionFixtures::v3_features( 'f', 3, 'Why choose us' ) ] ) ),
		];
	}

	protected function tearDown(): void {
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

	/** @return array<string, mixed> The portable section the extract ability returns. */
	private static function section( string $id = 'f000001' ): array {
		$GLOBALS['stonewright_test_user_can_callback'] = static fn( string $cap, mixed ...$args ): bool => in_array( $cap, [ 'edit_posts', 'read_post', 'edit_post' ], true );
		$result = ( new SectionReuseExtract() )->execute( [ 'post_id' => self::SOURCE, 'locator' => [ 'kind' => 'element', 'id' => $id ] ] );
		$GLOBALS['stonewright_test_user_can_callback'] = null;
		self::assertIsArray( $result );

		return $result['section'];
	}

	/** @return array<string, mixed> */
	private static function insert_op( array $section, array $extra = [] ): array {
		return array_merge( [ 'action' => 'insert_section', 'op_id' => 'feat', 'parent_id' => 'root', 'section' => $section ], $extra );
	}

	/** @return list<array<string, mixed>> */
	private static function target_tree(): array {
		return ElementorData::read( self::TARGET );
	}

	/** @return list<string> Every element id of a tree. */
	private static function ids( array $tree ): array {
		return array_keys( ElementorData::flatten( $tree ) );
	}

	private static function source_snapshot(): string {
		return serialize( $GLOBALS['stonewright_test_posts'][ self::SOURCE ] );
	}

	/** @return int Number of writes of the target's _elementor_data. */
	private static function target_writes(): int {
		return count( array_filter( $GLOBALS['stonewright_test_post_meta_calls'], static fn( array $call ): bool => self::TARGET === $call['post_id'] && '_elementor_data' === $call['meta_key'] ) );
	}

	public function test_a_section_and_its_adaptations_go_through_one_dry_run_and_one_apply(): void {
		$section = self::section();
		$before  = self::source_snapshot();
		$ops     = [
			self::insert_op( $section ),
			[ 'action' => 'update_element', 'element_ref' => 'feat.ph-2', 'settings' => [ 'title' => 'Built to last' ] ],
			[ 'action' => 'update_element', 'element_ref' => 'feat.ph-5', 'settings' => [ 'title_text' => 'Quality', 'description_text' => 'Made by hand' ] ],
		];

		$plan = ( new BatchMutate() )->execute( [ 'post_id' => self::TARGET, 'dry_run' => true, 'operations' => $ops ] );

		self::assertIsArray( $plan, $plan instanceof \WP_Error ? $plan->get_error_message() : '' );
		self::assertTrue( $plan['ok'] );
		self::assertSame( 0, self::target_writes(), 'A dry run writes nothing.' );
		self::assertSame( 'insert_section', $plan['items'][0]['action'] );
		self::assertSame( 9, $plan['items'][0]['placeholders'] );
		self::assertSame( 3, $plan['applied'], 'The insert and both adaptations are planned together.' );
		self::assertCount( 10, ElementorData::flatten( $plan['preview'] ), 'The root and the nine copied elements.' );

		$result = ( new BatchMutate() )->execute( [ 'post_id' => self::TARGET, 'expected_tree_hash' => $plan['before_hash'], 'operations' => $ops ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertSame( 1, self::target_writes(), 'One apply is one write.' );
		self::assertSame( 'verified', $result['verification_status'] );
		self::assertSame( $result['after_hash'], $result['readback_hash'], 'The readback equals the plan.' );
		self::assertCount( 10, ElementorData::flatten( self::target_tree() ), 'The stored document has what the plan showed.' );
		self::assertNotSame( '', $result['snapshot_id'] );
		self::assertSame( self::source_snapshot(), $before, 'The source post is byte for byte unchanged.' );
	}

	public function test_the_copy_gets_fresh_unique_ids_and_keeps_everything_else(): void {
		$section = self::section();
		$source  = ElementorData::read( self::SOURCE )[1];

		$result = ( new BatchMutate() )->execute( [ 'post_id' => self::TARGET, 'operations' => [ self::insert_op( $section ), self::insert_op( $section, [ 'op_id' => 'again' ] ) ] ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$tree = self::target_tree();
		$ids  = self::ids( $tree );
		self::assertCount( count( array_unique( $ids ) ), $ids, 'Every id in the document is unique.' );
		self::assertCount( 19, $ids, 'The root and two copies of nine elements.' );
		foreach ( self::ids( [ $source ] ) as $source_id ) {
			self::assertNotContains( $source_id, $ids, 'No source id is reused.' );
		}
		$first  = $tree[0]['elements'][0];
		$second = $tree[0]['elements'][1];
		self::assertSame( $result['refs']['feat'], $first['id'] );
		self::assertSame( $result['refs']['again'], $second['id'] );
		self::assertNotSame( $first['id'], $second['id'] );

		$strip = static function ( array $element ) use ( &$strip ): array {
			unset( $element['id'] );
			$element['elements'] = array_map( $strip, $element['elements'] ?? [] );
			return $element;
		};
		$expected         = $strip( $source );
		$expected['isInner'] = true;
		self::assertSame( $expected, $strip( $first ), 'Only ids differ: widget types, settings and nesting are the source\'s.' );
		self::assertTrue( $first['isInner'], 'A section inside a container is an inner container.' );
	}

	public function test_later_operations_address_the_new_elements_by_op_id_and_placeholder(): void {
		$result = ( new BatchMutate() )->execute(
			[
				'post_id'    => self::TARGET,
				'operations' => [
					self::insert_op( self::section() ),
					[ 'action' => 'update_element', 'element_ref' => 'feat.ph-2', 'settings' => [ 'title' => 'Fresh headline' ] ],
				],
			]
		);

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$heading = ElementorData::flatten( self::target_tree() )[ $result['refs']['feat.ph-2'] ];
		self::assertSame( 'Fresh headline', $heading['settings']['title'] );
		self::assertSame( 'heading', $heading['widgetType'], 'The widget type is never changed.' );
	}

	public function test_the_change_set_records_the_source_and_every_new_element_is_planned(): void {
		$result = ( new BatchMutate() )->execute( [ 'post_id' => self::TARGET, 'operations' => [ self::insert_op( self::section() ) ] ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$change_set = $result['change_set'];
		self::assertValidChangeSet( $change_set );
		self::assertSame( [ [ 'post_id' => self::SOURCE, 'builder' => 'elementor-v3', 'locator' => [ 'kind' => 'element', 'id' => 'f000001' ] ] ], $change_set['reuse_source'] );
		self::assertSame( [ 'insert_section' ], array_column( $change_set['planned'], 'action' ) );
		self::assertSame( $change_set['planned'], $change_set['applied'] );
		self::assertSame( [], $change_set['unexpected'], 'The copied children are part of the plan.' );
		self::assertSame( 'verified', $change_set['verification']['status'] );
		self::assertTrue( $change_set['rollback_available'] );
	}

	public function test_a_write_that_reused_nothing_has_no_reuse_source(): void {
		$result = ( new BatchMutate() )->execute( [ 'post_id' => self::TARGET, 'operations' => [ [ 'action' => 'add_container', 'parent_id' => 'root', 'settings' => [ 'flex_direction' => 'column' ] ] ] ] );

		self::assertIsArray( $result );
		self::assertArrayNotHasKey( 'reuse_source', $result['change_set'] );
	}

	public function test_while_the_setting_is_off_the_insert_is_refused_and_nothing_is_written(): void {
		$section = self::section();
		$GLOBALS['stonewright_test_options'][ SectionReuseSetting::OPTION ] = 'off';
		$tree = self::target_tree();

		$result = ( new BatchMutate() )->execute( [ 'post_id' => self::TARGET, 'operations' => [ self::insert_op( $section ) ] ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_section_reuse_off', $result->get_error_data()['items'][0]['error']['code'] );
		self::assertSame( $tree, self::target_tree() );
		self::assertSame( 0, self::target_writes() );
	}

	public function test_a_section_of_another_builder_is_refused(): void {
		$section            = self::section();
		$section['builder'] = 'elementor-v4';

		$result = ( new BatchMutate() )->execute( [ 'post_id' => self::TARGET, 'operations' => [ self::insert_op( $section ) ] ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_section_builder_mismatch', $result->get_error_code() );
		self::assertSame( 0, self::target_writes() );
	}

	public function test_a_missing_global_reference_fails_with_the_exact_reference_and_writes_nothing(): void {
		$GLOBALS['stonewright_test_posts'][ self::SOURCE ]->meta['_elementor_data'] = (string) wp_json_encode(
			( static function (): array {
				$section = SectionFixtures::v3_features( 'f' );
				$section['elements'][0]['settings']['__globals__'] = [ 'title_color' => 'globals/colors?id=brand-gold' ];
				return [ $section ];
			} )()
		);
		$section = self::section();
		ReferenceCatalog::set_provider( static fn( string $type, string $id ): ?bool => 'brand-gold' === $id ? false : true );

		$result = ( new BatchMutate() )->execute( [ 'post_id' => self::TARGET, 'operations' => [ self::insert_op( $section ) ] ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		$error = $result->get_error_data()['items'][0]['error'];
		self::assertSame( 'stonewright_section_reference_missing', $error['code'] );
		self::assertSame( 'global_color', $error['data']['reference']['type'] );
		self::assertSame( 'brand-gold', $error['data']['reference']['id'] );
		self::assertSame( [ 'ph-2' ], $error['data']['reference']['at'] );
		self::assertSame( 0, self::target_writes() );
	}

	public function test_an_existing_global_reference_is_kept_as_it_is(): void {
		$GLOBALS['stonewright_test_posts'][ self::SOURCE ]->meta['_elementor_data'] = (string) wp_json_encode(
			( static function (): array {
				$section = SectionFixtures::v3_features( 'f' );
				$section['elements'][0]['settings']['__globals__'] = [ 'title_color' => 'globals/colors?id=primary' ];
				return [ $section ];
			} )()
		);

		$result = ( new BatchMutate() )->execute( [ 'post_id' => self::TARGET, 'operations' => [ self::insert_op( self::section() ) ] ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$heading = ElementorData::flatten( self::target_tree() )[ $result['refs']['feat.ph-2'] ];
		self::assertSame( [ 'title_color' => 'globals/colors?id=primary' ], $heading['settings']['__globals__'] );
	}

	public function test_a_setting_the_live_schema_does_not_know_is_never_stripped_and_blocks_the_copy_in_the_dry_run(): void {
		$GLOBALS['stonewright_test_posts'][ self::SOURCE ]->meta['_elementor_data'] = (string) wp_json_encode(
			( static function (): array {
				$section = SectionFixtures::v3_features( 'f' );
				$section['elements'][0]['settings']['acme_vendor_setting'] = [ 'keep' => 'me' ];
				return [ $section ];
			} )()
		);
		$section = self::section();
		self::assertSame( [ 'keep' => 'me' ], $section['element']['elements'][0]['settings']['acme_vendor_setting'], 'Extract carries it.' );

		$plan = ( new BatchMutate() )->execute( [ 'post_id' => self::TARGET, 'dry_run' => true, 'operations' => [ self::insert_op( $section ) ] ] );

		self::assertInstanceOf( \WP_Error::class, $plan, 'The dry run already refuses the copy instead of dropping the setting.' );
		$error = $plan->get_error_data()['items'][0]['error'];
		self::assertSame( 'stonewright_section_settings_not_reusable', $error['code'] );
		self::assertSame( 'ph-2', $error['data']['element'] );
		self::assertSame( 'heading', $error['data']['element_type'] );
		self::assertSame( 'settings.acme_vendor_setting', $error['data']['violations'][0]['path'] );
		self::assertSame( 0, self::target_writes() );
	}

	public function test_a_setting_the_schema_knows_is_kept_exactly(): void {
		$GLOBALS['stonewright_test_posts'][ self::SOURCE ]->meta['_elementor_data'] = (string) wp_json_encode(
			( static function (): array {
				$section = SectionFixtures::v3_features( 'f' );
				$section['elements'][0]['settings']['align'] = 'center';
				return [ $section ];
			} )()
		);

		$result = ( new BatchMutate() )->execute( [ 'post_id' => self::TARGET, 'operations' => [ self::insert_op( self::section() ) ] ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? wp_json_encode( $result->get_error_data() ) : '' );
		$heading = ElementorData::flatten( self::target_tree() )[ $result['refs']['feat.ph-2'] ];
		self::assertSame( 'center', $heading['settings']['align'] );
	}

	public function test_an_html_widget_in_the_source_follows_the_html_widget_policy_of_the_site(): void {
		$GLOBALS['stonewright_test_posts'][ self::SOURCE ]->meta['_elementor_data'] = (string) wp_json_encode(
			( static function (): array {
				$section = SectionFixtures::v3_features( 'f' );
				$section['elements'][] = [ 'id' => 'f000099', 'elType' => 'widget', 'widgetType' => 'html', 'settings' => [ 'html' => '<p>Embed</p>' ], 'elements' => [] ];
				return [ $section ];
			} )()
		);
		$section = self::section();

		$off = ( new BatchMutate() )->execute( [ 'post_id' => self::TARGET, 'operations' => [ self::insert_op( $section ) ] ] );
		self::assertInstanceOf( \WP_Error::class, $off );
		self::assertSame( 'stonewright_html_widget_disabled', $off->get_error_data()['items'][0]['error']['code'] );

		$GLOBALS['stonewright_test_options']['stonewright_allow_html_widgets'] = true;
		$unapproved = ( new BatchMutate() )->execute( [ 'post_id' => self::TARGET, 'operations' => [ self::insert_op( $section, [ 'allow_html_widget' => false ] ) ] ] );
		self::assertInstanceOf( \WP_Error::class, $unapproved );
		self::assertSame( 'html_widget_requires_explicit_approval', $unapproved->get_error_data()['items'][0]['error']['code'] );
		self::assertSame( 0, self::target_writes() );

		$approved = ( new BatchMutate() )->execute( [ 'post_id' => self::TARGET, 'operations' => [ self::insert_op( $section, [ 'allow_html_widget' => true ] ) ] ] );
		self::assertIsArray( $approved, $approved instanceof \WP_Error ? wp_json_encode( $approved->get_error_data() ) : '' );
	}

	public function test_custom_css_in_the_source_needs_the_same_human_approval_as_custom_css_written_by_hand(): void {
		$GLOBALS['stonewright_test_posts'][ self::SOURCE ]->meta['_elementor_data'] = (string) wp_json_encode(
			( static function (): array {
				$section = SectionFixtures::v3_features( 'f' );
				$section['elements'][0]['settings']['custom_css'] = 'selector { color: red; }';
				return [ $section ];
			} )()
		);

		$result = ( new BatchMutate() )->execute( [ 'post_id' => self::TARGET, 'operations' => [ self::insert_op( self::section() ) ] ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_custom_code_approval_required', $result->get_error_code() );
		self::assertSame( 0, self::target_writes() );
		self::assertSame( [ 'root' ], self::ids( self::target_tree() ) );
	}

	public function test_dynamic_tags_are_kept_and_flagged(): void {
		$GLOBALS['stonewright_test_posts'][ self::SOURCE ]->meta['_elementor_data'] = (string) wp_json_encode(
			( static function (): array {
				$section = SectionFixtures::v3_features( 'f' );
				$section['elements'][0]['settings']['__dynamic__'] = [ 'title' => '[elementor-tag id="abc1234" name="post-title" settings="%7B%7D"]' ];
				return [ $section ];
			} )()
		);

		$result = ( new BatchMutate() )->execute( [ 'post_id' => self::TARGET, 'operations' => [ self::insert_op( self::section() ) ] ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertSame( 'dynamic_tags_kept', $result['items'][0]['warnings'][0]['code'] );
		$heading = ElementorData::flatten( self::target_tree() )[ $result['refs']['feat.ph-2'] ];
		self::assertSame( '[elementor-tag id="abc1234" name="post-title" settings="%7B%7D"]', $heading['settings']['__dynamic__']['title'] );
	}

	public function test_the_source_must_be_readable_and_editable_by_the_user(): void {
		$section = self::section();
		$GLOBALS['stonewright_test_user_can_callback'] = static fn( string $cap, mixed ...$args ): bool => 'edit_post' === $cap && self::TARGET === (int) ( $args[0] ?? 0 ) || 'edit_posts' === $cap;

		$result = ( new BatchMutate() )->execute( [ 'post_id' => self::TARGET, 'operations' => [ self::insert_op( $section ) ] ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_section_source_not_permitted', $result->get_error_data()['items'][0]['error']['code'] );
		self::assertSame( 0, self::target_writes() );
	}

	public function test_a_malformed_payload_is_refused(): void {
		foreach ( [ [], [ 'schema' => 'Other' ], [ 'schema' => 'SectionPortableV1', 'builder' => 'elementor-v3' ] ] as $payload ) {
			$result = ( new BatchMutate() )->execute( [ 'post_id' => self::TARGET, 'operations' => [ self::insert_op( $payload ) ] ] );

			self::assertInstanceOf( \WP_Error::class, $result );
			self::assertSame( 'stonewright_section_invalid', $result->get_error_data()['items'][0]['error']['code'], (string) wp_json_encode( $payload ) );
		}
	}

	public function test_a_section_cannot_go_into_a_v4_document(): void {
		$GLOBALS['stonewright_test_posts'][ self::TARGET ]->meta['_elementor_data'] = (string) wp_json_encode( [ SectionFixtures::v4_features( 'v' ) ] );

		$result = ( new BatchMutate() )->execute( [ 'post_id' => self::TARGET, 'operations' => [ self::insert_op( self::section(), [ 'parent_id' => '' ] ) ] ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 0, self::target_writes() );
	}

	public function test_production_safe_mode_needs_a_token_for_an_insert_that_removes_nothing(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		$arguments = [ 'post_id' => self::TARGET, 'operations' => [ self::insert_op( self::section() ) ] ];

		$refused = ( new BatchMutate() )->execute( $arguments );

		self::assertInstanceOf( \WP_Error::class, $refused );
		self::assertSame( 'stonewright_confirmation_required', $refused->get_error_code() );
		self::assertSame( 0, self::target_writes() );

		$token  = ConfirmationToken::issue( 'stonewright/elementor-v3-batch-mutate', $arguments );
		$result = ( new BatchMutate() )->execute( $arguments + [ 'confirmation_token' => $token ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertSame( 'verified', $result['verification_status'] );
	}

	public function test_css_is_left_to_the_css_regenerator(): void {
		$result = ( new BatchMutate() )->execute( [ 'post_id' => self::TARGET, 'operations' => [ self::insert_op( self::section() ) ] ] );

		self::assertIsArray( $result );
		self::assertSame( 'stonewright/elementor-css-regenerate', $result['next_step']['tool'] );
		self::assertSame( self::TARGET, $result['next_step']['post_id'] );
		self::assertContains( $result['refs']['feat'], $result['next_step']['element_ids'], 'The new elements are the ones to verify.' );
	}

	public function test_a_failure_in_one_operation_writes_nothing_even_after_a_good_insert(): void {
		$result = ( new BatchMutate() )->execute(
			[
				'post_id'    => self::TARGET,
				'operations' => [
					self::insert_op( self::section() ),
					[ 'action' => 'update_element', 'element_ref' => 'feat.ph-404', 'settings' => [ 'title' => 'x' ] ],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 0, self::target_writes() );
		self::assertSame( [ 'root' ], self::ids( self::target_tree() ) );
	}
}
