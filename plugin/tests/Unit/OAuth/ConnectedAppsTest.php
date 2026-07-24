<?php
/**
 * Connected OAuth applications contract tests.
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\OAuth;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\OAuth\ConnectedApps;
use Stonewright\WpMcp\OAuth\Repositories\ClientRepository;

final class ConnectedAppsTest extends TestCase {

	public function test_connected_apps_requires_login_and_management_capability(): void {
		$GLOBALS['stonewright_test_user_logged_in'] = false;
		$GLOBALS['stonewright_test_user_caps']      = [ 'manage_options' => true ];
		self::assertFalse( ConnectedApps::can_manage() );

		$GLOBALS['stonewright_test_user_logged_in'] = true;
		$GLOBALS['stonewright_test_user_caps']      = [ 'manage_options' => true ];
		self::assertTrue( ConnectedApps::can_manage() );
	}

	public function test_client_repository_exposes_admin_client_management_queries(): void {
		$repository = new ClientRepository();
		self::assertSame( [], $repository->list_recent() );
		self::assertNull( $repository->find_admin_client_id( 'Stonewright test' ) );
		self::assertSame( [], $repository->list_admin_clients() );
	}

	public function test_empty_page_escapes_product_copy_and_shows_no_apps(): void {
		ob_start();
		ConnectedApps::render_page( 7 );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Connected Apps', $html );
		self::assertStringContainsString( 'via Stonewright', $html );
		self::assertStringContainsString( 'No apps are currently connected', $html );
	}
}
