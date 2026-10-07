<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\AdminBarIndicator;
use Stonewright\WpMcp\Core\VendorGuard;

/**
 * @covers \Stonewright\WpMcp\Admin\AdminBarIndicator
 */
final class AdminBarIndicatorTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps']      = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_options']        = [ 'stonewright_enabled' => true ];
		$GLOBALS['stonewright_test_actions']        = [];
		$GLOBALS['stonewright_test_last_redirect']  = null;
		$GLOBALS['stonewright_test_admin_bar_showing'] = true;
		VendorGuard::reset_for_tests();
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_caps'] = [];
		$GLOBALS['stonewright_test_options']   = [];
		VendorGuard::reset_for_tests();
	}

	public function test_add_node_shows_on_state_when_enabled(): void {
		$bar = new \WP_Admin_Bar();
		AdminBarIndicator::add_node( $bar );

		self::assertArrayHasKey( 'stonewright-on', $bar->nodes );
		self::assertStringContainsString( 'ON', (string) $bar->nodes['stonewright-on']['title'] );
		self::assertArrayHasKey( 'stonewright-toggle', $bar->nodes );
		self::assertStringContainsString( 'Turn Off', (string) $bar->nodes['stonewright-toggle']['title'] );
		self::assertStringContainsString( 'target=off', (string) $bar->nodes['stonewright-toggle']['href'] );
	}

	public function test_add_node_shows_off_state_when_disabled(): void {
		$GLOBALS['stonewright_test_options']['stonewright_enabled'] = false;
		$bar = new \WP_Admin_Bar();
		AdminBarIndicator::add_node( $bar );

		self::assertArrayHasKey( 'stonewright-on', $bar->nodes );
		self::assertStringContainsString( 'OFF', (string) $bar->nodes['stonewright-on']['title'] );
		self::assertStringContainsString( 'Turn On', (string) $bar->nodes['stonewright-toggle']['title'] );
		self::assertStringContainsString( 'target=on', (string) $bar->nodes['stonewright-toggle']['href'] );
	}

	public function test_add_node_shows_blocked_not_off_on_domain_mismatch(): void {
		$GLOBALS['stonewright_test_options']['stonewright_enabled'] = true;
		$GLOBALS['stonewright_test_options']['stonewright_locked_domain'] = 'https://old.example/';
		$GLOBALS['stonewright_test_home_url'] = 'https://new.example/';

		$bar = new \WP_Admin_Bar();
		AdminBarIndicator::add_node( $bar );

		self::assertStringContainsString( 'BLOCKED', (string) $bar->nodes['stonewright-on']['title'] );
		self::assertStringNotContainsString( 'Stonewright OFF', (string) $bar->nodes['stonewright-on']['title'] );
		self::assertArrayHasKey( 'stonewright-review-domain', $bar->nodes );
		self::assertStringContainsString( 'domain-lock', (string) $bar->nodes['stonewright-on']['href'] );
	}

	public function test_add_node_shows_error_when_vendor_missing_and_enabled(): void {
		VendorGuard::set_error_for_tests(
			new \WP_Error( 'stonewright_missing_vendor', 'vendor missing' )
		);
		$bar = new \WP_Admin_Bar();
		AdminBarIndicator::add_node( $bar );

		self::assertStringContainsString( 'ERROR', (string) $bar->nodes['stonewright-on']['title'] );
	}

	public function test_handle_toggle_requires_manage_options(): void {
		$GLOBALS['stonewright_test_user_caps'] = [];
		$_GET['target'] = 'off';
		$_REQUEST['_wpnonce'] = 'test-nonce';

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/wp_die/' );
		AdminBarIndicator::handle_toggle();
	}

	public function test_apply_toggle_turns_off_when_enabled(): void {
		$GLOBALS['stonewright_test_options']['stonewright_enabled'] = true;
		AdminBarIndicator::apply_toggle( 'off' );
		self::assertFalse( (bool) get_option( 'stonewright_enabled', true ) );
	}

	public function test_apply_toggle_turns_on_when_disabled(): void {
		$GLOBALS['stonewright_test_options']['stonewright_enabled'] = false;
		AdminBarIndicator::apply_toggle( 'on' );
		self::assertTrue( (bool) get_option( 'stonewright_enabled', false ) );
	}

	public function test_apply_toggle_requires_manage_options(): void {
		$GLOBALS['stonewright_test_user_caps'] = [];
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/wp_die/' );
		AdminBarIndicator::apply_toggle( 'on' );
	}

	public function test_add_node_requires_manage_options(): void {
		$GLOBALS['stonewright_test_user_caps'] = [];
		$bar = new \WP_Admin_Bar();
		AdminBarIndicator::add_node( $bar );
		self::assertSame( [], $bar->nodes );
	}

	private static function badge_rule( string $css, string $state ): string {
		self::assertSame(
			1,
			preg_match( '/#wpadminbar \.stonewright-ab-badge--' . $state . ' \{([^}]*)\}/', $css, $match ),
			'The ' . $state . ' badge needs its own rule.'
		);

		return $match[1];
	}

	private static function badge_css(): string {
		ob_start();
		AdminBarIndicator::output_styles();

		return (string) ob_get_clean();
	}

	public function test_the_on_badge_is_green_with_a_dot_and_red_is_kept_for_error(): void {
		$css = self::badge_css();

		$on    = self::badge_rule( $css, 'on' );
		$error = self::badge_rule( $css, 'error' );

		self::assertMatchesRegularExpression( '/background:\s*#157347/', $on, 'ON is green: a running state is not an alarm.' );
		self::assertMatchesRegularExpression( '/background:\s*#b32d2e/', $error, 'ERROR keeps the red.' );
		self::assertStringNotContainsString( '#d63638', $css, 'The old red ON colour must be gone.' );
		self::assertStringContainsString( '#wpadminbar .stonewright-ab-badge--on::before', $css, 'ON carries a dot so its state is not carried by colour alone.' );
	}

	public function test_every_badge_state_keeps_white_text_that_reads_on_its_background(): void {
		$css = self::badge_css();

		// White text on each background needs 4.5:1 (WCAG 1.4.3).
		foreach ( [ 'on', 'off', 'error', 'blocked' ] as $state ) {
			$rule = self::badge_rule( $css, $state );
			self::assertSame( 1, preg_match( '/background:\s*(#[0-9a-f]{6})/i', $rule, $background ), $state );
			self::assertStringContainsString( 'color: #fff', $rule, $state );
			self::assertGreaterThanOrEqual( 4.5, self::contrast_with_white( $background[1] ), $state . ' badge text contrast' );
		}
	}

	private static function contrast_with_white( string $hex ): float {
		$channels = array_map(
			static function ( string $pair ): float {
				$value = hexdec( $pair ) / 255;

				return $value <= 0.03928 ? $value / 12.92 : ( ( $value + 0.055 ) / 1.055 ) ** 2.4;
			},
			str_split( ltrim( $hex, '#' ), 2 )
		);
		$luminance = 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];

		return 1.05 / ( $luminance + 0.05 );
	}
}
