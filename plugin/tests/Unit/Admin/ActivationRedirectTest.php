<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\ActivationRedirect;
use Stonewright\WpMcp\Admin\AdminBootstrap;
use Stonewright\WpMcp\Core\PluginRegistration;

/**
 * @covers \Stonewright\WpMcp\Admin\ActivationRedirect
 */
final class ActivationRedirectTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps']  = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_options']    = [];
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_actions']    = [];
		unset( $GLOBALS['stonewright_test_last_redirect'], $GLOBALS['stonewright_test_doing_ajax'], $GLOBALS['stonewright_test_network_admin'] );
		$_GET = [];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_caps']  = [];
		$GLOBALS['stonewright_test_options']    = [];
		$GLOBALS['stonewright_test_transients'] = [];
		unset( $GLOBALS['stonewright_test_last_redirect'], $GLOBALS['stonewright_test_doing_ajax'], $GLOBALS['stonewright_test_network_admin'] );
		$_GET = [];
	}

	public function test_activating_a_site_that_never_ran_stonewright_arms_one_redirect(): void {
		ActivationRedirect::arm();

		self::assertTrue( (bool) get_transient( ActivationRedirect::TRANSIENT ) );
		self::assertTrue( ActivationRedirect::maybe_redirect() );
		self::assertSame( 'https://example.test/wp-admin/admin.php?page=stonewright-status', $GLOBALS['stonewright_test_last_redirect'] );
	}

	public function test_the_redirect_happens_once(): void {
		ActivationRedirect::arm();
		ActivationRedirect::maybe_redirect();
		unset( $GLOBALS['stonewright_test_last_redirect'] );

		self::assertFalse( ActivationRedirect::maybe_redirect() );
		self::assertArrayNotHasKey( 'stonewright_test_last_redirect', $GLOBALS );
	}

	public function test_a_site_that_already_chose_enabled_or_not_is_left_alone(): void {
		$GLOBALS['stonewright_test_options']['stonewright_enabled'] = false;

		ActivationRedirect::arm();

		self::assertFalse( get_transient( ActivationRedirect::TRANSIENT ) );
		self::assertFalse( ActivationRedirect::maybe_redirect() );
	}

	public function test_bulk_activation_does_not_redirect_and_forgets_the_flag(): void {
		ActivationRedirect::arm();
		$_GET['activate-multi'] = 'true';

		self::assertFalse( ActivationRedirect::maybe_redirect() );
		self::assertFalse( get_transient( ActivationRedirect::TRANSIENT ), 'The flag is consumed, so a later visit is not hijacked.' );
		self::assertArrayNotHasKey( 'stonewright_test_last_redirect', $GLOBALS );
	}

	public function test_ajax_network_admin_and_non_administrators_are_never_redirected(): void {
		foreach ( [
			'ajax'    => static function (): void {
				$GLOBALS['stonewright_test_doing_ajax'] = true;
			},
			'network' => static function (): void {
				$GLOBALS['stonewright_test_network_admin'] = true;
			},
			'editor'  => static function (): void {
				$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => false ];
			},
		] as $name => $condition ) {
			unset( $GLOBALS['stonewright_test_last_redirect'], $GLOBALS['stonewright_test_doing_ajax'], $GLOBALS['stonewright_test_network_admin'] );
			$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true ];
			ActivationRedirect::arm();
			$condition();

			self::assertFalse( ActivationRedirect::maybe_redirect(), $name );
			self::assertArrayNotHasKey( 'stonewright_test_last_redirect', $GLOBALS, $name );
		}
	}

	public function test_nothing_happens_without_a_flag(): void {
		self::assertFalse( ActivationRedirect::maybe_redirect() );
	}

	public function test_it_runs_early_in_the_admin_request(): void {
		ActivationRedirect::register();

		self::assertSame( [ 'admin_init' ], array_keys( $GLOBALS['stonewright_test_actions'] ) );
	}

	public function test_activation_arms_the_redirect_and_the_admin_bootstrap_registers_the_entry_points(): void {
		self::assertStringContainsString( 'ActivationRedirect::arm()', self::method_source( PluginRegistration::class, 'on_activate' ) );

		$register = self::method_source( AdminBootstrap::class, 'register' );
		foreach ( [ 'MenuOrder::register()', 'PluginActionLinks::register()', 'ActivationRedirect::register()', 'HelpTabs::register()' ] as $call ) {
			self::assertStringContainsString( $call, $register, $call );
		}
	}

	private static function method_source( string $class, string $method ): string {
		$reflection = new \ReflectionMethod( $class, $method );
		$lines      = file( (string) $reflection->getFileName() );
		self::assertIsArray( $lines );

		return implode( '', array_slice( $lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1 ) );
	}
}
