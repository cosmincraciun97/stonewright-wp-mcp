<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Core\VendorGuard;

/**
 * @covers \Stonewright\WpMcp\Core\VendorGuard
 */
final class VendorGuardTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_rest_routes'] = [];
		$GLOBALS['stonewright_test_actions']     = [];
		$GLOBALS['stonewright_test_user_caps']   = [ 'manage_options' => true ];
		VendorGuard::reset_for_tests();
	}

	protected function tearDown(): void {
		VendorGuard::reset_for_tests();
		$GLOBALS['stonewright_test_user_caps'] = [];
	}

	public function test_missing_vendor_error_message_mentions_release_zip(): void {
		$error = VendorGuard::missing_vendor_error();
		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'stonewright_missing_vendor', $error->get_error_code() );
		self::assertStringContainsString( 'vendor', strtolower( $error->get_error_message() ) );
		self::assertStringContainsString( 'release', strtolower( $error->get_error_message() ) );
	}

	public function test_register_missing_mcp_endpoint_registers_route(): void {
		VendorGuard::set_error_for_tests( VendorGuard::missing_vendor_error() );
		VendorGuard::register_missing_mcp_endpoint();

		$routes = $GLOBALS['stonewright_test_rest_routes'] ?? [];
		self::assertNotEmpty( $routes );
		$found = false;
		foreach ( $routes as $route ) {
			if ( ( $route['namespace'] ?? '' ) === 'mcp' && ( $route['route'] ?? '' ) === '/stonewright' ) {
				$found = true;
				$callback = $route['args']['callback'] ?? null;
				self::assertIsCallable( $callback );
				$response = $callback( new \WP_REST_Request() );
				self::assertInstanceOf( \WP_Error::class, $response );
				self::assertSame( 500, (int) ( $response->get_error_data()['status'] ?? 0 ) );
				self::assertStringContainsString( 'vendor', strtolower( $response->get_error_message() ) );
			}
		}
		self::assertTrue( $found, 'Expected mcp/stonewright fallback route' );
	}

	public function test_render_admin_notice_outputs_when_error_set(): void {
		VendorGuard::set_error_for_tests( VendorGuard::missing_vendor_error() );
		ob_start();
		VendorGuard::render_admin_notice();
		$html = (string) ob_get_clean();
		self::assertStringContainsString( 'notice-error', $html );
		self::assertStringContainsString( 'Stonewright', $html );
		self::assertStringContainsString( 'github.com', strtolower( $html ) );
	}

	public function test_render_admin_notice_silent_when_healthy(): void {
		ob_start();
		VendorGuard::render_admin_notice();
		$html = (string) ob_get_clean();
		self::assertSame( '', $html );
	}
}
