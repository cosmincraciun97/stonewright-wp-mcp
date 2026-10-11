<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ElementorV3;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\UpdateKitColors;
use Stonewright\WpMcp\Abilities\ElementorV3\UpdateKitTypography;

/**
 * The kit colour and typography writers merge rows by their _id, treat an unchanged re-apply as a success, and
 * point to the CSS regeneration the written kit needs.
 *
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\UpdateKitColors
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\UpdateKitTypography
 * @covers \Stonewright\WpMcp\Elementor\KitRows
 */
final class KitWritersTest extends TestCase {

	private const KIT = 900;

	protected function setUp(): void {
		$GLOBALS['stonewright_test_posts']           = [
			self::KIT => (object) [
				'ID'           => self::KIT,
				'post_type'    => 'elementor_library',
				'post_status'  => 'publish',
				'post_title'   => 'Default Kit',
				'post_content' => '',
				'post_excerpt' => '',
				'post_parent'  => 0,
				'post_name'    => 'default-kit',
				'meta'         => [
					'_elementor_page_settings' => [
						'system_colors'     => [ [ '_id' => 'primary', 'title' => 'Primary', 'color' => '#111111' ] ],
						'custom_colors'     => [
							[ '_id' => 'brand', 'title' => 'Brand', 'color' => '#112233' ],
							[ '_id' => 'accent', 'title' => 'Accent', 'color' => '#445566' ],
						],
						'custom_typography' => [
							[ '_id' => 'body', 'title' => 'Body', 'typography_typography' => 'custom', 'typography_font_family' => 'Georgia', 'typography_font_weight' => '700' ],
						],
					],
				],
			],
		];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development', 'elementor_active_kit' => self::KIT ];
		$GLOBALS['stonewright_test_user_caps']       = [ 'edit_posts' => true, 'edit_theme_options' => true, 'manage_options' => true ];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 42;
		$GLOBALS['stonewright_test_actions']         = [];
		unset( $GLOBALS['stonewright_test_update_post_meta_returns'] );
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_actions']         = [];
		unset( $GLOBALS['stonewright_test_update_post_meta_returns'] );
	}

	/** @return array<string, mixed> */
	private static function settings(): array {
		return $GLOBALS['stonewright_test_posts'][ self::KIT ]->meta['_elementor_page_settings'];
	}

	/** @return list<string> Meta keys written since the test began. */
	private static function written_keys(): array {
		return array_values( array_unique( array_map( static fn( array $call ): string => (string) $call['meta_key'], $GLOBALS['stonewright_test_post_meta_calls'] ) ) );
	}

	public function test_colors_merge_by_id_updates_a_row_in_place_and_appends_a_new_one(): void {
		$result = ( new UpdateKitColors() )->execute(
			[
				'colors' => [
					[ 'id' => 'brand', 'color' => '#abcdef' ],
					[ 'id' => 'fresh', 'title' => 'Fresh', 'color' => '#010203' ],
				],
			]
		);

		self::assertIsArray( $result );
		$stored = self::settings()['custom_colors'];
		self::assertSame( [ 'brand', 'accent', 'fresh' ], array_column( $stored, '_id' ), 'No id appears twice.' );
		self::assertSame( [ '_id' => 'brand', 'title' => 'Brand', 'color' => '#abcdef' ], $stored[0], 'The title the call did not send stays.' );
		self::assertSame( '#445566', $stored[1]['color'] );
		self::assertSame( [ [ '_id' => 'primary', 'title' => 'Primary', 'color' => '#111111' ] ], self::settings()['system_colors'], 'The system colours are not touched.' );
	}

	public function test_colors_merge_collapses_rows_that_already_share_an_id(): void {
		$GLOBALS['stonewright_test_posts'][ self::KIT ]->meta['_elementor_page_settings']['custom_colors'] = [
			[ '_id' => 'brand', 'title' => 'Brand', 'color' => '#112233' ],
			[ '_id' => 'accent', 'title' => 'Accent', 'color' => '#445566' ],
			[ '_id' => 'brand', 'title' => 'Brand again', 'color' => '#778899' ],
		];

		$result = ( new UpdateKitColors() )->execute( [ 'colors' => [ [ 'id' => 'brand', 'color' => '#abcdef' ], [ 'id' => 'brand', 'color' => '#fedcba' ] ] ] );

		self::assertIsArray( $result );
		$stored = self::settings()['custom_colors'];
		self::assertSame( [ 'brand', 'accent' ], array_column( $stored, '_id' ) );
		self::assertSame( '#fedcba', $stored[0]['color'], 'The last value the call sent for an id wins.' );
	}

	public function test_colors_replace_stores_exactly_the_rows_sent(): void {
		$result = ( new UpdateKitColors() )->execute( [ 'mode' => 'replace', 'colors' => [ [ 'id' => 'only', 'title' => 'Only', 'color' => '#000000' ] ] ] );

		self::assertIsArray( $result );
		self::assertSame( [ [ '_id' => 'only', 'title' => 'Only', 'color' => '#000000' ] ], self::settings()['custom_colors'] );
	}

	public function test_colors_reapplied_unchanged_succeeds_without_writing(): void {
		$args = [ 'colors' => [ [ 'id' => 'brand', 'title' => 'Brand', 'color' => '#112233' ] ] ];
		// The same call twice: the second finds nothing to change, and update_post_meta() reports that as false.
		self::assertIsArray( ( new UpdateKitColors() )->execute( $args ) );
		$GLOBALS['stonewright_test_post_meta_calls'] = [];

		$result = ( new UpdateKitColors() )->execute( $args );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_code() : '' );
		self::assertTrue( $result['unchanged'] );
		self::assertSame( self::KIT, $result['kit_id'] );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'], 'Nothing was snapshotted or written.' );
	}

	public function test_colors_failing_to_store_a_real_change_is_still_an_error(): void {
		$GLOBALS['stonewright_test_update_post_meta_returns'] = [ '_elementor_page_settings' => false ];

		$result = ( new UpdateKitColors() )->execute( [ 'colors' => [ [ 'id' => 'brand', 'color' => '#abcdef' ] ] ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_write_failed', $result->get_error_code() );
	}

	public function test_colors_result_points_to_css_regeneration_and_purges_the_kit_cache(): void {
		$purged = [];
		add_action(
			'litespeed_purge_post',
			static function ( int $post_id ) use ( &$purged ): void {
				$purged[] = $post_id;
			}
		);

		$result = ( new UpdateKitColors() )->execute( [ 'colors' => [ [ 'id' => 'brand', 'color' => '#abcdef' ] ] ] );

		self::assertIsArray( $result );
		self::assertSame( 'stonewright/elementor-css-regenerate', $result['next_step']['tool'] );
		self::assertSame( self::KIT, $result['next_step']['post_id'] );
		self::assertSame( [ self::KIT ], $purged );
		self::assertContains( 'litespeed_cache', $result['cache_purge']['ran'] );
	}

	public function test_colors_description_says_which_colours_it_changes(): void {
		$description = ( new UpdateKitColors() )->description();

		self::assertStringContainsString( 'custom_colors', $description );
		self::assertStringContainsString( 'system', $description );
	}

	public function test_typography_merge_by_id_keeps_the_fields_the_call_did_not_send(): void {
		$result = ( new UpdateKitTypography() )->execute(
			[
				'fonts' => [
					[ 'id' => 'body', 'font_family' => 'Verdana' ],
					[ 'id' => 'display', 'title' => 'Display', 'font_family' => 'Helvetica', 'font_weight' => '300' ],
				],
			]
		);

		self::assertIsArray( $result );
		$stored = self::settings()['custom_typography'];
		self::assertSame( [ 'body', 'display' ], array_column( $stored, '_id' ) );
		self::assertSame( 'Verdana', $stored[0]['typography_font_family'] );
		self::assertSame( '700', $stored[0]['typography_font_weight'], 'The weight the call did not send stays.' );
		self::assertSame( 'Body', $stored[0]['title'] );
	}

	public function test_typography_replace_stores_exactly_the_rows_sent(): void {
		$result = ( new UpdateKitTypography() )->execute( [ 'mode' => 'replace', 'fonts' => [ [ 'id' => 'only', 'title' => 'Only', 'font_family' => 'Arial' ] ] ] );

		self::assertIsArray( $result );
		self::assertSame( [ 'only' ], array_column( self::settings()['custom_typography'], '_id' ) );
	}

	public function test_typography_reapplied_unchanged_succeeds_without_writing(): void {
		$args = [ 'fonts' => [ [ 'id' => 'body', 'title' => 'Body', 'font_family' => 'Georgia', 'font_weight' => '700' ] ] ];

		$result = ( new UpdateKitTypography() )->execute( $args );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_code() : '' );
		self::assertTrue( $result['unchanged'] );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );
	}

	public function test_typography_result_points_to_css_regeneration_and_purges_the_kit_cache(): void {
		$purged = [];
		add_action(
			'litespeed_purge_post',
			static function ( int $post_id ) use ( &$purged ): void {
				$purged[] = $post_id;
			}
		);

		$result = ( new UpdateKitTypography() )->execute( [ 'fonts' => [ [ 'id' => 'body', 'font_family' => 'Verdana' ] ] ] );

		self::assertIsArray( $result );
		self::assertSame( 'stonewright/elementor-css-regenerate', $result['next_step']['tool'] );
		self::assertSame( self::KIT, $result['next_step']['post_id'] );
		self::assertSame( [ self::KIT ], $purged );
	}
}
