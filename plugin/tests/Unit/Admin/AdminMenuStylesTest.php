<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\AdminBootstrap;

/**
 * The sidebar is on every wp-admin page, so the EXP marker and its tooltip are inline styles printed in the head,
 * not a stylesheet that loads on Stonewright pages only.
 *
 * @covers \Stonewright\WpMcp\Admin\AdminBootstrap::output_menu_styles
 */
final class AdminMenuStylesTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_is_admin']  = true;
		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true ];
	}

	protected function tearDown(): void {
		unset( $GLOBALS['stonewright_test_is_admin'] );
		$GLOBALS['stonewright_test_user_caps'] = [];
	}

	private static function styles(): string {
		ob_start();
		AdminBootstrap::output_menu_styles();

		return (string) ob_get_clean();
	}

	/** The declarations of the rule whose selector list is exactly this text. */
	private static function rule( string $css, string $selector ): string {
		preg_match_all( '/([^{}]+)\{([^}]*)\}/', $css, $matches, PREG_SET_ORDER );
		foreach ( $matches as $match ) {
			if ( trim( strip_tags( $match[1] ) ) === $selector ) {
				return $match[2];
			}
		}
		self::fail( 'No rule for ' . $selector );
	}

	public function test_the_styles_are_one_inline_block_for_people_who_see_the_sidebar(): void {
		self::assertStringStartsWith( '<style id="stonewright-admin-menu">', self::styles() );

		$GLOBALS['stonewright_test_user_caps'] = [];
		self::assertSame( '', self::styles() );

		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_is_admin']  = false;
		self::assertSame( '', self::styles() );
	}

	public function test_the_beta_box_is_gone(): void {
		$css = self::styles();

		self::assertStringNotContainsString( 'sw-menu-beta', $css );
		self::assertStringNotContainsString( 'border:1px solid currentColor', $css );
	}

	public function test_the_marker_is_a_small_white_uppercase_help_cursor_after_the_label(): void {
		$declarations = self::rule( self::styles(), '#adminmenu .wp-submenu a .sw-menu-exp' );

		foreach ( [ 'font-size:9px', 'font-weight:600', 'line-height:18px', 'letter-spacing:.06em', 'text-transform:uppercase', 'color:#fff', 'cursor:help', 'margin-inline-start:10px', 'position:relative' ] as $expected ) {
			self::assertStringContainsString( $expected, $declarations, $expected );
		}
	}

	public function test_the_tooltip_is_css_only_to_the_right_of_the_marker_and_shows_on_pointer_hover_only(): void {
		$css   = self::styles();
		$after = self::rule( $css, '#adminmenu .wp-submenu a .sw-menu-exp::after' );

		self::assertStringContainsString( 'content:attr(data-sw-tip)', $after );
		self::assertStringContainsString( 'display:none', $after );
		self::assertStringContainsString( 'white-space:nowrap', $after );
		self::assertStringContainsString( 'pointer-events:none', $after );
		self::assertStringContainsString( 'z-index:100000', $after );
		self::assertStringContainsString( 'position:absolute', $after );
		self::assertStringContainsString( 'inset-inline-start:calc(100% + 8px)', $after );
		self::assertStringContainsString( 'top:50%', $after );
		self::assertStringContainsString( 'transform:translateY(-50%)', $after );
		foreach ( [ 'background:#1d2327', 'color:#fff', 'font-size:11px', 'line-height:14.3px', 'padding:5px 8px', 'border-radius:3px', 'box-shadow:0 2px 8px rgba(0,0,0,.28)', 'font-weight:400' ] as $expected ) {
			self::assertStringContainsString( $expected, $after, $expected );
		}
		self::assertStringContainsString( '.sw-menu-exp:hover::after{display:block', $css, 'Instant: no transition, shown over the marker only.' );
		self::assertStringNotContainsString( 'transition', $css );
		self::assertStringNotContainsString( ':focus', $css, 'The marker is not focusable and focusing the entry shows nothing.' );
	}

	public function test_nothing_clips_the_tooltip_in_the_flyout_or_the_mobile_menu(): void {
		$css = self::styles();

		self::assertStringNotContainsString( 'overflow', $css );
		self::assertStringContainsString( '#adminmenu .wp-submenu a .sw-menu-exp', $css );
	}
}
