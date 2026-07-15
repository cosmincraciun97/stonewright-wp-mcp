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
}
