<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\FSE;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\FSE\ReadGlobalStyles;
use Stonewright\WpMcp\Abilities\FSE\UpdateGlobalStyles;
use Stonewright\WpMcp\Abilities\FSE\WriteGlobalStyles;
use Stonewright\WpMcp\ThemeJson\Validator;

/**
 * WordPress only applies a user global styles record whose JSON carries
 * `isGlobalStylesUserThemeJSON: true`. Every write keeps that marker, and a
 * record WordPress created itself can be merged into.
 *
 * @covers \Stonewright\WpMcp\Abilities\FSE\UpdateGlobalStyles
 * @covers \Stonewright\WpMcp\Abilities\FSE\WriteGlobalStyles
 * @covers \Stonewright\WpMcp\FSE\GlobalStylesWriter
 * @covers \Stonewright\WpMcp\ThemeJson\Validator
 */
final class GlobalStylesMarkerTest extends TestCase {

	private const STOCK = '{"version":3,"isGlobalStylesUserThemeJSON":true}';

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_user_caps']       = [ 'manage_options' => true, 'edit_theme_options' => true ];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_posts']           = [
			2 => (object) [
				'ID'           => 2,
				'post_type'    => 'wp_global_styles',
				'post_status'  => 'publish',
				'post_title'   => 'Custom Styles',
				'post_content' => self::STOCK,
				'post_excerpt' => '',
				'post_parent'  => 0,
				'post_name'    => 'wp-global-styles-active-theme',
				'meta'         => [],
			],
		];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_posts']   = [];
		$GLOBALS['stonewright_test_options'] = [];
	}

	private function stored(): string {
		return (string) $GLOBALS['stonewright_test_posts'][2]->post_content;
	}

	/** @return array<string, mixed> */
	private function stored_json(): array {
		$decoded = json_decode( $this->stored(), true );
		self::assertIsArray( $decoded );
		return $decoded;
	}

	public function test_write_stores_the_marker_wordpress_needs_to_apply_the_record(): void {
		$result = ( new WriteGlobalStyles() )->execute(
			[
				'theme_json' => [
					'version'  => 3,
					'settings' => [ 'color' => [ 'palette' => [ [ 'slug' => 'qa-accent', 'color' => '#ff5a1f', 'name' => 'QA Accent' ] ] ] ],
					'styles'   => [ 'color' => [ 'background' => '#fafaf5' ] ],
				],
			]
		);

		self::assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() : '' );
		$json = $this->stored_json();
		self::assertTrue( $json['isGlobalStylesUserThemeJSON'] );
		self::assertSame( '#fafaf5', $json['styles']['color']['background'] );
		self::assertSame( 'qa-accent', $json['settings']['color']['palette'][0]['slug'] );
	}

	public function test_write_accepts_a_payload_that_already_carries_the_marker(): void {
		$result = ( new WriteGlobalStyles() )->execute(
			[ 'theme_json' => [ 'version' => 3, 'isGlobalStylesUserThemeJSON' => true, 'styles' => [ 'color' => [ 'text' => '#111111' ] ] ] ]
		);

		self::assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() : '' );
		self::assertTrue( $this->stored_json()['isGlobalStylesUserThemeJSON'] );
	}

	public function test_write_never_stores_the_marker_as_false(): void {
		$result = ( new WriteGlobalStyles() )->execute(
			[ 'theme_json' => [ 'version' => 3, 'isGlobalStylesUserThemeJSON' => false ] ]
		);

		self::assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() : '' );
		self::assertTrue( $this->stored_json()['isGlobalStylesUserThemeJSON'] );
	}

	public function test_merge_works_on_the_record_wordpress_creates(): void {
		$result = ( new UpdateGlobalStyles() )->execute( [ 'mode' => 'merge', 'styles' => [ 'typography' => [ 'lineHeight' => '1.7' ] ] ] );

		self::assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() : '' );
		$json = $this->stored_json();
		self::assertTrue( $json['isGlobalStylesUserThemeJSON'] );
		self::assertSame( '1.7', $json['styles']['typography']['lineHeight'] );
	}

	public function test_merge_keeps_what_an_earlier_write_stored(): void {
		( new UpdateGlobalStyles() )->execute( [ 'mode' => 'merge', 'styles' => [ 'color' => [ 'background' => '#fafaf5' ] ] ] );
		$result = ( new UpdateGlobalStyles() )->execute( [ 'mode' => 'merge', 'styles' => [ 'typography' => [ 'lineHeight' => '1.7' ] ] ] );

		self::assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() : '' );
		$json = $this->stored_json();
		self::assertSame( '#fafaf5', $json['styles']['color']['background'] );
		self::assertSame( '1.7', $json['styles']['typography']['lineHeight'] );
	}

	public function test_replace_keeps_the_marker_and_does_not_store_empty_lists_for_objects(): void {
		$result = ( new UpdateGlobalStyles() )->execute( [ 'mode' => 'replace', 'styles' => [ 'typography' => [ 'lineHeight' => '1.7' ] ] ] );

		self::assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() : '' );
		self::assertTrue( $this->stored_json()['isGlobalStylesUserThemeJSON'] );
		self::assertStringNotContainsString( '"settings":[]', $this->stored() );
		self::assertStringNotContainsString( '"styles":[]', $this->stored() );
	}

	public function test_read_returns_the_stored_record_with_its_marker_and_it_can_be_written_back(): void {
		$read = ( new ReadGlobalStyles() )->execute( [] );
		self::assertIsArray( $read );
		self::assertTrue( $read['theme_json']['isGlobalStylesUserThemeJSON'] );

		$result = ( new WriteGlobalStyles() )->execute( [ 'theme_json' => $read['theme_json'] ] );
		self::assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() : '' );
	}

	public function test_the_validator_accepts_the_marker_and_still_rejects_other_unknown_top_level_keys(): void {
		$canonical = Validator::validate( [ 'version' => 3, 'isGlobalStylesUserThemeJSON' => true ] );
		self::assertIsArray( $canonical );
		self::assertTrue( $canonical['isGlobalStylesUserThemeJSON'] );

		$rejected = Validator::validate( [ 'version' => 3, 'isGlobalStylesUserThemeJSON' => true, 'unknownKey' => 1 ] );
		self::assertInstanceOf( \WP_Error::class, $rejected );
		self::assertSame( 'stonewright_theme_json_invalid', $rejected->get_error_code() );
	}

	public function test_the_validator_rejects_a_marker_that_is_not_a_boolean(): void {
		$result = Validator::validate( [ 'version' => 3, 'isGlobalStylesUserThemeJSON' => 'yes' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_theme_json_invalid', $result->get_error_code() );
	}
}
