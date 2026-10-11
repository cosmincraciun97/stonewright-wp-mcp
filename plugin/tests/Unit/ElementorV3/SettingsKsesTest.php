<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ElementorV3;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\KitBatchMutate;
use Stonewright\WpMcp\Abilities\ElementorV3\UpdateKitColors;
use Stonewright\WpMcp\Abilities\ElementorV3\UpdateKitTypography;
use Stonewright\WpMcp\Abilities\ElementorV3\UpdatePageSettings;
use Stonewright\WpMcp\Design\Direction\ElementorKitWriter;
use Stonewright\WpMcp\Tests\Support\ElementorKsesSimulation;

require_once dirname( __DIR__, 2 ) . '/Support/elementor-kses-simulation.php';

/**
 * Elementor registers `_elementor_page_settings` with the same kses sanitize callback as `_elementor_data`: for a user
 * without `unfiltered_html` every string of the settings is filtered when it is stored. The page and kit settings
 * writers filter the settings the way Elementor does before they write, so the write is stored, read back and
 * verified, and the result says that content was filtered.
 *
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\UpdatePageSettings
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\UpdateKitColors
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\UpdateKitTypography
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\KitBatchMutate
 * @covers \Stonewright\WpMcp\Elementor\Write\EditorKses
 */
final class SettingsKsesTest extends TestCase {
	use ElementorKsesSimulation;

	private const KIT  = 900;
	private const PAGE = 10;

	private const DIRTY = '<b>Brand</b><script>alert(1)</script>';
	private const CLEAN = '<b>Brand</b>';

	protected function setUp(): void {
		$GLOBALS['stonewright_test_posts']           = [
			self::KIT  => (object) [ 'ID' => self::KIT, 'post_type' => 'elementor_library', 'post_status' => 'publish', 'post_title' => 'Default Kit', 'post_content' => '', 'post_excerpt' => '', 'post_parent' => 0, 'post_name' => 'default-kit', 'meta' => [ '_elementor_page_settings' => [ 'custom_colors' => [ [ '_id' => 'brand', 'title' => 'Brand', 'color' => '#112233' ] ] ] ] ],
			self::PAGE => (object) [ 'ID' => self::PAGE, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Page', 'post_content' => '', 'post_excerpt' => '', 'post_parent' => 0, 'post_name' => 'page', 'meta' => [ '_elementor_page_settings' => [ 'hide_title' => 'yes' ] ] ],
		];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development', 'elementor_active_kit' => self::KIT ];
		$GLOBALS['stonewright_mode']                 = 'development';
		// A role that can edit with Elementor but has no unfiltered_html.
		$GLOBALS['stonewright_test_user_caps']       = [ 'edit_posts' => true, 'edit_pages' => true, 'edit_post' => true, 'edit_theme_options' => true ];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 42;
		$this->simulate_elementor_kses();
	}

	protected function tearDown(): void {
		$this->stop_simulating_elementor_kses();
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		unset( $GLOBALS['stonewright_mode'] );
	}

	/** @return array<string, mixed> */
	private static function kit(): array {
		return $GLOBALS['stonewright_test_posts'][ self::KIT ]->meta['_elementor_page_settings'];
	}

	public function test_kit_colors_are_stored_filtered_and_verified_for_a_user_without_unfiltered_html(): void {
		$result = ( new UpdateKitColors() )->execute( [ 'colors' => [ [ 'id' => 'fresh', 'title' => self::DIRTY, 'color' => '#010203' ] ] ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertTrue( $result['content_filtered'] );
		self::assertSame( self::CLEAN, self::kit()['custom_colors'][1]['title'] );
	}

	public function test_kit_typography_is_stored_filtered_and_verified_for_a_user_without_unfiltered_html(): void {
		$result = ( new UpdateKitTypography() )->execute( [ 'fonts' => [ [ 'id' => 'fresh', 'title' => self::DIRTY, 'font_family' => 'Georgia' ] ] ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertTrue( $result['content_filtered'] );
		self::assertSame( self::CLEAN, self::kit()['custom_typography'][0]['title'] );
	}

	public function test_a_kit_batch_is_stored_filtered_and_its_readback_matches_what_was_stored(): void {
		$result = ( new KitBatchMutate() )->execute( [ 'operations' => [ [ 'group' => 'colors', 'bucket' => 'custom', 'mode' => 'merge', 'colors' => [ [ 'id' => 'fresh', 'title' => self::DIRTY, 'color' => '#010203' ] ] ] ] ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertTrue( $result['ok'] );
		self::assertTrue( $result['content_filtered'] );
		self::assertSame( $result['after_hash'], $result['readback_hash'], 'the planned hash is the hash of the filtered settings that were stored' );
		self::assertSame( self::CLEAN, self::kit()['custom_colors'][1]['title'] );
	}

	public function test_a_kit_batch_dry_run_previews_the_filtered_settings(): void {
		$result = ( new KitBatchMutate() )->execute( [ 'dry_run' => true, 'operations' => [ [ 'group' => 'colors', 'bucket' => 'custom', 'mode' => 'merge', 'colors' => [ [ 'id' => 'fresh', 'title' => self::DIRTY, 'color' => '#010203' ] ] ] ] ] );

		self::assertIsArray( $result );
		self::assertTrue( $result['content_filtered'] );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );
	}

	public function test_page_settings_are_stored_filtered_and_the_result_says_so(): void {
		$result = ( new UpdatePageSettings() )->execute( [ 'post_id' => self::PAGE, 'settings' => [ 'subtitle' => self::DIRTY ], 'mode' => 'merge' ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertTrue( $result['content_filtered'] );
		self::assertSame( self::CLEAN, $GLOBALS['stonewright_test_posts'][ self::PAGE ]->meta['_elementor_page_settings']['subtitle'] );
	}

	public function test_the_direction_kit_writer_stores_the_filtered_kit_and_says_so(): void {
		$result = ElementorKitWriter::apply( self::KIT, [ [ 'group' => 'colors', 'bucket' => 'custom', 'target' => 'brand' . self::DIRTY, 'property' => 'color', 'to' => '#010203' ] ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertTrue( $result['content_filtered'] );
		self::assertSame( 'brand' . self::CLEAN, self::kit()['custom_colors'][1]['_id'] );
	}

	public function test_a_user_with_unfiltered_html_stores_the_settings_as_given_and_no_flag_is_set(): void {
		$GLOBALS['stonewright_test_user_caps']['unfiltered_html'] = true;

		$kit  = ( new UpdateKitColors() )->execute( [ 'colors' => [ [ 'id' => 'fresh', 'title' => self::DIRTY, 'color' => '#010203' ] ] ] );
		$page = ( new UpdatePageSettings() )->execute( [ 'post_id' => self::PAGE, 'settings' => [ 'subtitle' => self::DIRTY ], 'mode' => 'merge' ] );

		self::assertIsArray( $kit );
		self::assertIsArray( $page );
		self::assertArrayNotHasKey( 'content_filtered', $kit );
		self::assertArrayNotHasKey( 'content_filtered', $page );
		self::assertSame( self::DIRTY, self::kit()['custom_colors'][1]['title'] );
	}

	public function test_settings_that_kses_leaves_alone_are_not_reported_as_filtered(): void {
		$result = ( new UpdateKitColors() )->execute( [ 'colors' => [ [ 'id' => 'fresh', 'title' => 'Fresh', 'color' => '#010203' ] ] ] );

		self::assertIsArray( $result );
		self::assertArrayNotHasKey( 'content_filtered', $result );
	}
}
