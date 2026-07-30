<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\AdminBootstrap;
use Stonewright\WpMcp\Sandbox\CrashRecovery;

/**
 * @covers \Stonewright\WpMcp\Admin\AdminBootstrap
 *
 * Verifies Gap 2: CrashRecovery::admin_notice is registered on admin_notices.
 */
final class AdminBootstrapCrashRecoveryTest extends TestCase {

	protected function setUp(): void {
		// Reset so register() runs fresh.
		AdminBootstrap::reset_for_tests();

		$GLOBALS['stonewright_test_actions']          = [];
		$GLOBALS['stonewright_test_rest_routes']      = [];
		$GLOBALS['stonewright_test_submenu_pages']    = [];
		$GLOBALS['stonewright_test_menu_pages']       = [];
		$GLOBALS['stonewright_test_enqueued_styles']  = [];
		$GLOBALS['stonewright_test_enqueued_scripts'] = [];

		// AdminBootstrap needs STONEWRIGHT_URL defined to enqueue assets.
		if ( ! defined( 'STONEWRIGHT_URL' ) ) {
			define( 'STONEWRIGHT_URL', 'https://example.test/wp-content/plugins/stonewright/' );
		}
	}

	protected function tearDown(): void {
		AdminBootstrap::reset_for_tests();
		$GLOBALS['stonewright_test_actions'] = [];
	}

	public function test_crash_recovery_admin_notice_is_hooked_on_register(): void {
		AdminBootstrap::register();

		$actions = $GLOBALS['stonewright_test_actions'] ?? [];

		$this->assertArrayHasKey(
			'admin_notices',
			$actions,
			'admin_notices hook should be registered'
		);

		$found = false;
		foreach ( $actions['admin_notices'] as $hook ) {
			$cb = $hook['callback'];
			if (
				is_array( $cb )
				&& $cb[0] === CrashRecovery::class
				&& $cb[1] === 'admin_notice'
			) {
				$found = true;
				break;
			}
		}

		$this->assertTrue(
			$found,
			'CrashRecovery::admin_notice should be registered on admin_notices'
		);
	}

	public function test_register_is_idempotent(): void {
		AdminBootstrap::register();
		AdminBootstrap::register();

		$actions = $GLOBALS['stonewright_test_actions'] ?? [];

		// Count how many times admin_notices is registered for CrashRecovery::admin_notice.
		$count = 0;
		foreach ( $actions['admin_notices'] ?? [] as $hook ) {
			$cb = $hook['callback'];
			if (
				is_array( $cb )
				&& $cb[0] === CrashRecovery::class
				&& $cb[1] === 'admin_notice'
			) {
				$count++;
			}
		}

		$this->assertSame(
			1,
			$count,
			'admin_notices hook for CrashRecovery should only be registered once (idempotent)'
		);
	}
}
