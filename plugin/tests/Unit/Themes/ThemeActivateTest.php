<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Themes;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Themes\ThemeActivate;

/**
 * @covers \Stonewright\WpMcp\Abilities\Themes\ThemeActivate
 */
final class ThemeActivateTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_user_caps']       = [ 'switch_themes' => true ];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		$GLOBALS['stonewright_test_stylesheet']      = 'site-a-theme';
		unset( $GLOBALS['stonewright_test_missing_themes'], $GLOBALS['stonewright_test_switch_theme_noop'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['stonewright_test_missing_themes'], $GLOBALS['stonewright_test_switch_theme_noop'], $GLOBALS['stonewright_test_stylesheet'] );
		$GLOBALS['stonewright_test_options']   = [];
		$GLOBALS['stonewright_test_user_caps'] = [];
	}

	public function test_a_theme_that_is_not_installed_is_refused_and_the_active_theme_stays(): void {
		$GLOBALS['stonewright_test_missing_themes'] = [ 'no-such-theme' ];

		$result = ( new ThemeActivate() )->execute( [ 'stylesheet' => 'no-such-theme' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_theme_not_found', $result->get_error_code() );
		self::assertSame( 'site-a-theme', get_stylesheet() );
	}

	public function test_the_active_flag_is_read_back_and_not_assumed(): void {
		// WordPress did not switch (a filter, a failed update of the option): the result must not say active.
		$GLOBALS['stonewright_test_switch_theme_noop'] = true;

		$result = ( new ThemeActivate() )->execute( [ 'stylesheet' => 'site-b-theme' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_theme_switch_failed', $result->get_error_code() );
		self::assertSame( 'site-a-theme', get_stylesheet() );
	}

	public function test_an_installed_theme_is_activated_and_reported_active(): void {
		$result = ( new ThemeActivate() )->execute( [ 'stylesheet' => 'site-b-theme' ] );

		self::assertSame( [ 'stylesheet' => 'site-b-theme', 'active' => true ], $result );
		self::assertSame( 'site-b-theme', get_stylesheet() );
	}
}
