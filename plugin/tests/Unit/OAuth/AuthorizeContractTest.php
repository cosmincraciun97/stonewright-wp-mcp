<?php
/**
 * OAuth authorization endpoint contract tests.
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\OAuth;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\OAuth\Endpoints\Authorize;

final class AuthorizeContractTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_submenu_pages'] = [];
		$GLOBALS['stonewright_test_actions']       = [];
		$_GET                                      = [];
	}

	public function test_registers_hidden_admin_page_with_manage_options_gate(): void {
		Authorize::register();

		$page = $GLOBALS['stonewright_test_submenu_pages'][ Authorize::PAGE_SLUG ];
		self::assertSame( '', $page['parent'] );
		self::assertSame( 'manage_options', $page['capability'] );
		self::assertSame( [ Authorize::class, 'render' ], $page['callback'] );
	}

	public function test_sanitizes_scalar_query_parameters_and_rejects_arrays(): void {
		$_GET['client_id'] = " abc<script>\n";
		$_GET['scope']     = [ 'mcp' ];

		self::assertSame( 'abc', Authorize::get_param( 'client_id' ) );
		self::assertSame( '', Authorize::get_param( 'scope' ) );
	}

	public function test_management_permission_uses_stonewright_permissions(): void {
		$GLOBALS['stonewright_test_user_caps'] = [];
		self::assertFalse( Authorize::can_authorize() );

		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true ];
		self::assertTrue( Authorize::can_authorize() );
	}
}
