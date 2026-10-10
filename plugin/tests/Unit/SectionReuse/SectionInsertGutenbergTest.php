<?php
/**
 * Reusing a Gutenberg section through blocks-batch-mutate.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\SectionReuse;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Gutenberg\BlocksBatchMutate;
use Stonewright\WpMcp\Abilities\SectionReuse\SectionReuseExtract;
use Stonewright\WpMcp\SectionReuse\ReferenceCatalog;
use Stonewright\WpMcp\SectionReuse\SectionReuseSetting;
use Stonewright\WpMcp\Security\IncidentStore;
use Stonewright\WpMcp\Support\BlockTree;
use Stonewright\WpMcp\Support\ErrorEnvelope;
use Stonewright\WpMcp\Tests\Unit\Security\ChangeSetAssertions;

require_once __DIR__ . '/SectionFixtures.php';
require_once dirname( __DIR__ ) . '/Security/ChangeSetAssertions.php';

/**
 * @covers \Stonewright\WpMcp\Abilities\Gutenberg\BlocksBatchMutate
 * @covers \Stonewright\WpMcp\SectionReuse\GutenbergSectionInserter
 * @covers \Stonewright\WpMcp\SectionReuse\MarkupSkeleton
 */
final class SectionInsertGutenbergTest extends TestCase {
	use ChangeSetAssertions;

	private const TARGET  = 701;
	private const SOURCE  = 30;
	private const PATTERN = 40;

	private const INTRO = "<!-- wp:paragraph -->\n<p>Intro</p>\n<!-- /wp:paragraph -->";

	protected function setUp(): void {
		IncidentStore::reset_for_tests();
		ReferenceCatalog::set_provider( static fn( string $type, string $id ): ?bool => true );
		$GLOBALS['stonewright_test_options']           = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_post_meta_calls']   = [];
		$GLOBALS['stonewright_test_wp_update_post_calls'] = [];
		$GLOBALS['stonewright_test_wpdb_inserts']      = [];
		$GLOBALS['stonewright_test_user_caps']         = [ 'edit_post' => true, 'read_post' => true, 'edit_posts' => true, 'read' => true ];
		$GLOBALS['stonewright_test_user_can_callback'] = null;
		$GLOBALS['stonewright_test_user_logged_in']    = true;
		$GLOBALS['stonewright_test_current_user_id']   = 1;
		$GLOBALS['stonewright_test_posts']             = [
			self::TARGET  => SectionFixtures::post( self::TARGET, 'page', 'draft', 'New page', self::INTRO ),
			self::SOURCE  => SectionFixtures::post( self::SOURCE, 'page', 'publish', 'Source page', self::INTRO . "\n\n" . SectionFixtures::gutenberg_features_content( 'Why choose us', 3, 'features' ) ),
			self::PATTERN => SectionFixtures::post( self::PATTERN, 'wp_block', 'publish', 'Banner', "<!-- wp:paragraph -->\n<p>Pattern text</p>\n<!-- /wp:paragraph -->" ),
		];
	}

	protected function tearDown(): void {
		ReferenceCatalog::set_provider( null );
		IncidentStore::reset_for_tests();
		$GLOBALS['stonewright_test_posts']             = [];
		$GLOBALS['stonewright_test_options']           = [];
		$GLOBALS['stonewright_test_post_meta_calls']   = [];
		$GLOBALS['stonewright_test_wp_update_post_calls'] = [];
		$GLOBALS['stonewright_test_wpdb_inserts']      = [];
		$GLOBALS['stonewright_test_user_caps']         = [];
		$GLOBALS['stonewright_test_user_can_callback'] = null;
		$GLOBALS['stonewright_test_user_logged_in']    = false;
		$GLOBALS['stonewright_test_current_user_id']   = 0;
	}

	/** @return array<string, mixed> */
	private static function section( int $post_id = self::SOURCE, array $locator = [ 'kind' => 'block', 'anchor' => 'features' ] ): array {
		$GLOBALS['stonewright_test_user_can_callback'] = static fn( string $cap, mixed ...$args ): bool => in_array( $cap, [ 'edit_posts', 'read_post', 'edit_post' ], true );
		$result = ( new SectionReuseExtract() )->execute( [ 'post_id' => $post_id, 'locator' => $locator ] );
		$GLOBALS['stonewright_test_user_can_callback'] = null;
		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );

		return $result['section'];
	}

	/** @return array<string, mixed> */
	private static function insert_op( array $section, array $extra = [] ): array {
		return array_merge( [ 'action' => 'insert_section', 'op_id' => 'feat', 'path' => [], 'position' => 1, 'section' => $section ], $extra );
	}

	private static function content(): string {
		return (string) get_post( self::TARGET )->post_content;
	}

	/** Dry run, then apply with the hash it returned. @return array<string, mixed>|\WP_Error */
	private static function apply( array $operations, array $extra = [] ): array|\WP_Error {
		$plan = ( new BlocksBatchMutate() )->execute( array_merge( [ 'post_id' => self::TARGET, 'dry_run' => true, 'operations' => $operations ], $extra ) );
		if ( $plan instanceof \WP_Error ) {
			return $plan;
		}

		return ( new BlocksBatchMutate() )->execute( array_merge( [ 'post_id' => self::TARGET, 'expected_content_hash' => $plan['before_hash'], 'operations' => $operations ], $extra ) );
	}

	public function test_a_section_with_text_and_image_changes_goes_through_one_dry_run_and_one_apply(): void {
		$source  = SectionFixtures::gutenberg_features_content( 'Why choose us', 3, 'features' );
		$GLOBALS['stonewright_test_posts'][ self::SOURCE ]->post_content = "<!-- wp:group {\"anchor\":\"features\"} -->\n<div id=\"features\" class=\"wp-block-group\"><!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Why choose us</h2>\n<!-- /wp:heading -->\n\n<!-- wp:image {\"id\":61} -->\n<figure class=\"wp-block-image\"><img src=\"https://example.test/old.jpg\" alt=\"Old\" class=\"wp-image-61\"/></figure>\n<!-- /wp:image --></div>\n<!-- /wp:group -->";
		unset( $source );
		$section = self::section();
		$before  = serialize( $GLOBALS['stonewright_test_posts'][ self::SOURCE ] );
		$ops     = [
			self::insert_op( $section ),
			[ 'action' => 'update', 'section_ref' => 'feat', 'relative_path' => [ 0, 0 ], 'innerHTML' => "\n<h2 class=\"wp-block-heading\">Built to last</h2>\n" ],
			[ 'action' => 'update', 'section_ref' => 'feat', 'relative_path' => [ 0, 1 ], 'attrs' => [ 'id' => 88 ], 'innerHTML' => "\n<figure class=\"wp-block-image\"><img src=\"https://example.test/new.jpg\" alt=\"New hand-built chair\" class=\"wp-image-88\"/></figure>\n" ],
		];

		$plan = ( new BlocksBatchMutate() )->execute( [ 'post_id' => self::TARGET, 'dry_run' => true, 'operations' => $ops ] );

		self::assertIsArray( $plan, $plan instanceof \WP_Error ? $plan->get_error_message() . wp_json_encode( $plan->get_error_data() ) : '' );
		self::assertSame( 3, $plan['applied'] );
		self::assertSame( self::INTRO, self::content(), 'A dry run writes nothing.' );
		self::assertSame( [], $GLOBALS['stonewright_test_wp_update_post_calls'] );

		$result = ( new BlocksBatchMutate() )->execute( [ 'post_id' => self::TARGET, 'expected_content_hash' => $plan['before_hash'], 'operations' => $ops ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() . wp_json_encode( $result->get_error_data() ) : '' );
		self::assertSame( 'verified', $result['verification_status'] );
		self::assertSame( $plan['after_hash'], $result['after_hash'], 'The apply writes exactly what the dry run planned.' );
		self::assertSame( $result['after_hash'], $result['readback_hash'], 'The readback equals the plan.' );
		self::assertCount( 1, $GLOBALS['stonewright_test_wp_update_post_calls'], 'One apply is one write.' );
		self::assertNotSame( '', $result['snapshot_id'] );
		self::assertStringContainsString( 'Built to last', self::content() );
		self::assertStringContainsString( 'new.jpg', self::content() );
		self::assertStringContainsString( 'wp-image-88', self::content() );
		self::assertStringContainsString( 'Intro', self::content(), 'The existing content stays.' );
		self::assertSame( $before, serialize( $GLOBALS['stonewright_test_posts'][ self::SOURCE ] ), 'The source post is byte for byte unchanged.' );
		self::assertSame( [ 'core/paragraph', 'core/group' ], array_column( BlockTree::parse( self::content() ), 'blockName' ) );
	}

	public function test_a_change_that_alters_the_saved_markup_structure_is_refused(): void {
		$section = self::section();
		$ops     = [
			self::insert_op( $section ),
			[ 'action' => 'update', 'section_ref' => 'feat', 'relative_path' => [ 0, 0 ], 'innerHTML' => "\n<h3 class=\"wp-block-heading\">Other tag</h3>\n" ],
		];

		$result = self::apply( $ops );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_markup_structure_changed', $result->get_error_data()['items'][1]['error']['code'], 'The insert is fine; the structural change to its heading is not.' );
		self::assertSame( self::INTRO, self::content() );
	}

	public function test_the_new_blocks_are_found_by_section_ref_even_when_another_section_is_inserted_before_them(): void {
		$section = self::section();
		$ops     = [
			self::insert_op( $section ),
			self::insert_op( $section, [ 'op_id' => 'early', 'position' => 0 ] ),
			[ 'action' => 'update', 'section_ref' => 'feat', 'relative_path' => [ 0, 0 ], 'innerHTML' => "
<h2 class=\"wp-block-heading\">After a shift</h2>
" ],
		];

		$result = self::apply( $ops );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() . wp_json_encode( $result->get_error_data() ) : '' );
		$blocks = BlockTree::parse( self::content() );
		self::assertSame( [ 'features-2', '', 'features' ], array_map( static fn( array $block ): string => (string) ( $block['attrs']['anchor'] ?? '' ), $blocks ) );
		self::assertStringNotContainsString( 'After a shift', (string) wp_json_encode( $blocks[0] ), 'The section inserted first in the batch, now at index two, is the one that changed.' );
		self::assertStringContainsString( 'After a shift', (string) wp_json_encode( $blocks[2] ) );
	}

	public function test_a_duplicate_anchor_is_renamed_in_the_attribute_the_markup_and_the_links(): void {
		$GLOBALS['stonewright_test_posts'][ self::TARGET ]->post_content = self::INTRO . "\n\n" . SectionFixtures::gutenberg_features_content( 'Already here', 3, 'features' );
		$GLOBALS['stonewright_test_posts'][ self::SOURCE ]->post_content = str_replace( '<h2 class="wp-block-heading">Why choose us</h2>', '<h2 class="wp-block-heading">Why choose us</h2><a href="#features">Jump</a>', $GLOBALS['stonewright_test_posts'][ self::SOURCE ]->post_content );
		$section = self::section();

		$result = self::apply( [ self::insert_op( $section, [ 'position' => 2 ] ), self::insert_op( $section, [ 'op_id' => 'again', 'position' => 3 ] ) ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() . wp_json_encode( $result->get_error_data() ) : '' );
		$blocks = BlockTree::parse( self::content() );
		self::assertSame( [ 'features', 'features-2', 'features-3' ], array_map( static fn( array $block ): string => (string) ( $block['attrs']['anchor'] ?? '' ), array_slice( $blocks, 1 ) ) );
		self::assertStringContainsString( 'id="features-2"', self::content() );
		self::assertStringContainsString( 'id="features-3"', self::content() );
		self::assertStringContainsString( 'id="features"', self::content(), 'The anchor that was already there is untouched.' );
		$first_copy = (string) wp_json_encode( $blocks[2] );
		self::assertStringContainsString( '#features-2', $first_copy, 'A link inside the copy follows the renamed anchor.' );
		self::assertSame( [ 'features' => 'features-2' ], $result['items'][0]['anchors_renamed'] );
	}

	/** A page whose only section holds a synced pattern. */
	private static function page_with_synced_pattern(): array {
		$GLOBALS['stonewright_test_posts'][ self::SOURCE ]->post_content = "<!-- wp:group {\"anchor\":\"promo\"} -->
<div id=\"promo\" class=\"wp-block-group\"><!-- wp:block {\"ref\":40} /--></div>
<!-- /wp:group -->";

		return self::section( self::SOURCE, [ 'kind' => 'block', 'path' => [ 0 ] ] );
	}

	public function test_a_synced_pattern_stays_a_reference_and_a_missing_one_fails_with_its_id(): void {
		$section = self::page_with_synced_pattern();
		$result  = self::apply( [ self::insert_op( $section ) ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() . wp_json_encode( $result->get_error_data() ) : '' );
		self::assertStringContainsString( '"ref":40', self::content() );
		self::assertStringNotContainsString( 'Pattern text', self::content(), 'The pattern is referenced, not copied.' );

		unset( $GLOBALS['stonewright_test_posts'][ self::PATTERN ] );
		$GLOBALS['stonewright_test_posts'][ self::TARGET ]->post_content = self::INTRO;
		$gone = self::apply( [ self::insert_op( $section ) ] );

		self::assertInstanceOf( \WP_Error::class, $gone );
		$error = $gone->get_error_data()['items'][0]['error'];
		self::assertSame( 'stonewright_section_reference_missing', $error['code'] );
		self::assertSame( [ 'type' => 'synced_pattern', 'id' => '40' ], array_intersect_key( $error['data']['reference'], [ 'type' => 1, 'id' => 1 ] ) );
		self::assertSame( self::INTRO, self::content() );
	}

	public function test_a_synced_pattern_can_be_detached_into_a_local_copy_by_id_or_for_all(): void {
		$section = self::page_with_synced_pattern();

		$result = self::apply( [ self::insert_op( $section, [ 'detach_patterns' => true ] ) ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() . wp_json_encode( $result->get_error_data() ) : '' );
		self::assertStringNotContainsString( '"ref":40', self::content() );
		self::assertStringContainsString( 'Pattern text', self::content(), 'The pattern content is now local.' );
		self::assertSame( [ 40 ], $result['items'][0]['detached'] );
		self::assertSame( 'publish', get_post( self::PATTERN )->post_status, 'The pattern itself is untouched.' );
		$group = BlockTree::parse( self::content() )[1];
		self::assertSame( 'core/paragraph', $group['innerBlocks'][0]['blockName'], 'The copy sits where the reference was.' );

		$GLOBALS['stonewright_test_posts'][ self::TARGET ]->post_content = self::INTRO;
		$other = self::apply( [ self::insert_op( $section, [ 'detach_patterns' => [ 99 ] ] ) ] );
		self::assertIsArray( $other );
		self::assertStringContainsString( '"ref":40', self::content(), 'Only the listed patterns are detached.' );
	}

	public function test_the_change_set_records_the_source_post_and_locator(): void {
		$result = self::apply( [ self::insert_op( self::section() ) ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$change_set = $result['change_set'];
		self::assertValidChangeSet( $change_set );
		self::assertSame( [ [ 'post_id' => self::SOURCE, 'builder' => 'gutenberg', 'locator' => [ 'kind' => 'block', 'path' => '1', 'anchor' => 'features' ] ] ], $change_set['reuse_source'] );
		self::assertSame( [ 'insert_section' ], array_column( $change_set['planned'], 'action' ) );
		self::assertSame( 'verified', $change_set['verification']['status'] );
	}

	public function test_while_the_setting_is_off_the_insert_is_refused(): void {
		$section = self::section();
		$GLOBALS['stonewright_test_options'][ SectionReuseSetting::OPTION ] = 'off';

		$result = self::apply( [ self::insert_op( $section ) ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_section_reuse_off', $result->get_error_data()['items'][0]['error']['code'] );
		self::assertSame( self::INTRO, self::content() );
	}

	public function test_the_off_refusal_tells_an_mcp_client_the_reason_and_that_it_is_final(): void {
		$section = self::section();
		$GLOBALS['stonewright_test_options'][ SectionReuseSetting::OPTION ] = 'off';

		$result = self::apply( [ self::insert_op( $section ) ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		$message = ErrorEnvelope::with_agent_visible_payload( $result )->get_error_message();
		self::assertStringContainsString( SectionReuseSetting::OFF_INSTRUCTION, $message );
		self::assertStringContainsString( '"retryable":false', $message );
		self::assertStringContainsString( '"execution_status":"blocked"', $message );
	}

	public function test_a_section_of_another_builder_or_an_elementor_page_is_refused(): void {
		$section            = self::section();
		$other              = $section;
		$other['builder']   = 'elementor-v3';
		$wrong              = self::apply( [ self::insert_op( $other ) ] );
		self::assertInstanceOf( \WP_Error::class, $wrong );
		self::assertSame( 'stonewright_section_builder_mismatch', $wrong->get_error_data()['items'][0]['error']['code'] );

		$GLOBALS['stonewright_test_posts'][ self::TARGET ]->meta['_elementor_edit_mode'] = 'builder';
		$elementor = self::apply( [ self::insert_op( $section ) ] );
		self::assertInstanceOf( \WP_Error::class, $elementor );
		self::assertSame( 'stonewright_section_builder_mismatch', $elementor->get_error_data()['items'][0]['error']['code'] );
	}

	public function test_the_source_must_be_readable_and_editable_by_the_user(): void {
		$section = self::section();
		$GLOBALS['stonewright_test_user_can_callback'] = static fn( string $cap, mixed ...$args ): bool => 'edit_post' === $cap && self::TARGET === (int) ( $args[0] ?? 0 ) || 'edit_posts' === $cap;

		$result = ( new BlocksBatchMutate() )->execute( [ 'post_id' => self::TARGET, 'dry_run' => true, 'operations' => [ self::insert_op( $section ) ] ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_section_source_not_permitted', $result->get_error_data()['items'][0]['error']['code'] );
	}

	public function test_raw_html_in_a_copied_section_needs_the_same_approval_as_raw_html_written_by_hand(): void {
		$GLOBALS['stonewright_test_posts'][ self::SOURCE ]->post_content = "<!-- wp:group {\"anchor\":\"embed\"} -->\n<div id=\"embed\" class=\"wp-block-group\"><!-- wp:html -->\n<script>alert(1)</script>\n<!-- /wp:html --></div>\n<!-- /wp:group -->";
		$section = self::section( self::SOURCE, [ 'kind' => 'block', 'path' => [ 0 ] ] );

		$result = ( new BlocksBatchMutate() )->execute( [ 'post_id' => self::TARGET, 'dry_run' => true, 'operations' => [ self::insert_op( $section ) ] ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_custom_code_approval_required', $result->get_error_code() );
		self::assertSame( self::INTRO, self::content() );
	}

	public function test_a_malformed_payload_is_refused(): void {
		foreach ( [ [], [ 'schema' => 'SectionPortableV1', 'builder' => 'gutenberg', 'blocks' => [] ], [ 'schema' => 'SectionPortableV1', 'builder' => 'gutenberg', 'blocks' => [ [ 'attrs' => [] ] ] ] ] as $payload ) {
			$result = ( new BlocksBatchMutate() )->execute( [ 'post_id' => self::TARGET, 'dry_run' => true, 'operations' => [ self::insert_op( $payload ) ] ] );

			self::assertInstanceOf( \WP_Error::class, $result );
			self::assertSame( 'stonewright_section_invalid', $result->get_error_data()['items'][0]['error']['code'], (string) wp_json_encode( $payload ) );
		}
	}

	public function test_production_safe_mode_needs_no_token_for_an_insert_that_removes_nothing(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';

		$result = self::apply( [ self::insert_op( self::section() ) ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertSame( 'verified', $result['verification_status'] );
	}

	public function test_the_insert_is_never_queued_for_the_browser_finalizer(): void {
		$result = self::apply( [ self::insert_op( self::section() ) ] );

		self::assertIsArray( $result );
		self::assertArrayNotHasKey( 'queued', $result );
		self::assertSame( 1, count( $GLOBALS['stonewright_test_wp_update_post_calls'] ) );
	}

	public function test_drop_settings_is_refused_for_a_gutenberg_section_and_nothing_is_written(): void {
		$section = self::section();

		foreach ( [ [ [ 'element' => 'ph-2', 'setting' => 'title' ] ], 'title', [] ] as $drop ) {
			$result = ( new BlocksBatchMutate() )->execute( [ 'post_id' => self::TARGET, 'dry_run' => true, 'operations' => [ self::insert_op( $section, [ 'drop_settings' => $drop ] ) ] ] );

			self::assertInstanceOf( \WP_Error::class, $result );
			self::assertSame( 'stonewright_section_drop_settings_unsupported', $result->get_error_data()['items'][0]['error']['code'] );
			self::assertStringContainsString( 'drop_settings applies only to Elementor V3 sections', $result->get_error_message(), 'The message an MCP client reads says so.' );
			self::assertStringContainsString( 'Gutenberg section', $result->get_error_message() );
		}
		$applied = self::apply( [ self::insert_op( $section, [ 'drop_settings' => [ [ 'element' => 'ph-2', 'setting' => 'title' ] ] ] ) ] );
		self::assertInstanceOf( \WP_Error::class, $applied );
		self::assertSame( self::INTRO, self::content() );
		self::assertSame( [], $GLOBALS['stonewright_test_wp_update_post_calls'] );
	}

	public function test_an_absent_or_null_drop_settings_does_not_change_a_gutenberg_insert(): void {
		$result = self::apply( [ self::insert_op( self::section(), [ 'drop_settings' => null ] ) ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertSame( 1, count( $GLOBALS['stonewright_test_wp_update_post_calls'] ) );
	}
}
