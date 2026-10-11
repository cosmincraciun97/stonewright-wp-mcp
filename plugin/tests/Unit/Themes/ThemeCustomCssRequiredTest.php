<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Themes;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Themes\ThemeCustomCss;

/**
 * An update names the CSS it stores. Leaving css out used to store an empty stylesheet.
 *
 * @covers \Stonewright\WpMcp\Abilities\Themes\ThemeCustomCss
 */
final class ThemeCustomCssRequiredTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps']       = [ 'read' => true, 'edit_theme_options' => true, 'edit_css' => true, 'manage_options' => true ];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development', 'stonewright_disabled_abilities' => [] ];
		$GLOBALS['stonewright_test_custom_css']      = '.keep { color: red; }';
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_caps'] = [];
		$GLOBALS['stonewright_test_options']   = [];
		unset( $GLOBALS['stonewright_test_custom_css'] );
	}

	public function test_an_update_without_css_is_refused_and_the_stored_css_stays(): void {
		foreach ( [ [ 'action' => 'update' ], [ 'action' => 'update', 'dry_run' => true ] ] as $args ) {
			$result = ( new ThemeCustomCss() )->execute( $args );

			self::assertInstanceOf( \WP_Error::class, $result, (string) wp_json_encode( $args ) );
			self::assertSame( 'stonewright_css_required', $result->get_error_code() );
		}
		self::assertSame( '.keep { color: red; }', wp_get_custom_css() );
	}

	public function test_the_refusal_comes_before_the_production_safe_token_is_spent(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';

		$result = ( new ThemeCustomCss() )->execute( [ 'action' => 'update' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_css_required', $result->get_error_code() );
	}

	public function test_a_get_needs_no_css(): void {
		$result = ( new ThemeCustomCss() )->execute( [ 'action' => 'get' ] );

		self::assertIsArray( $result );
		self::assertSame( '.keep { color: red; }', $result['css'] );
	}

	public function test_the_input_schema_says_css_is_required_for_an_update(): void {
		$schema = ( new ThemeCustomCss() )->input_schema();

		self::assertStringContainsString( 'update', (string) $schema['properties']['css']['description'] );
	}
}
