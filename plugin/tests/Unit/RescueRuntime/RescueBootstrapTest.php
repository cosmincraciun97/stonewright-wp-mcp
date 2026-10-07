<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\RescueRuntime;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Cli\RescueCommand;
use Stonewright\WpMcp\Cli\StonewrightCommand;
use Stonewright\WpMcp\Core\RescueBootstrap;
use Stonewright\WpMcp\Core\RescueInstaller;
use Stonewright\WpMcp\Tests\Unit\RescueRuntime\Support\MuRuntime;

/**
 * RescueBootstrap::register(): the one call that wires every rescue hook.
 *
 * @covers \Stonewright\WpMcp\Core\RescueBootstrap
 */
final class RescueBootstrapTest extends TestCase {

	protected function setUp(): void {
		MuRuntime::begin( false );
		$GLOBALS['stonewright_test_lifecycle_hooks'] = [];
		RescueBootstrap::reset_for_tests();
	}

	protected function tearDown(): void {
		RescueBootstrap::reset_for_tests();
		MuRuntime::end();
	}

	/** @return list<callable|array{0: string, 1: string}> */
	private static function callbacks( string $hook ): array {
		return array_map( static fn ( array $registered ) => $registered['callback'], $GLOBALS['stonewright_test_actions'][ $hook ] ?? [] );
	}

	public function test_the_plugin_activation_and_deactivation_hooks_are_registered_for_the_plugin_file(): void {
		RescueBootstrap::register();

		$activate   = $GLOBALS['stonewright_test_lifecycle_hooks']['activate'][0];
		$deactivate = $GLOBALS['stonewright_test_lifecycle_hooks']['deactivate'][0];
		self::assertSame( STONEWRIGHT_DIR . 'stonewright.php', $activate[0] );
		self::assertSame( [ RescueInstaller::class, 'on_activate' ], $activate[1] );
		self::assertSame( STONEWRIGHT_DIR . 'stonewright.php', $deactivate[0] );
		self::assertSame( [ RescueInstaller::class, 'on_deactivate' ], $deactivate[1] );
	}

	public function test_the_helper_is_installed_on_init_and_checked_on_admin_load(): void {
		RescueBootstrap::register();

		self::assertContains( [ RescueInstaller::class, 'maybe_upgrade' ], self::callbacks( 'init' ) );
		self::assertContains( [ RescueInstaller::class, 'on_admin_init' ], self::callbacks( 'admin_init' ) );
		self::assertContains( [ RescueInstaller::class, 'admin_notice' ], self::callbacks( 'admin_notices' ) );
		self::assertContains( [ RescueInstaller::class, 'admin_notice' ], self::callbacks( 'network_admin_notices' ) );
	}

	public function test_the_safe_boot_audit_the_email_filter_and_the_setting_are_registered(): void {
		RescueBootstrap::register();

		self::assertContains( [ \Stonewright\WpMcp\Security\RescueSafeBoot::class, 'audit_entry' ], self::callbacks( 'init' ) );
		self::assertTrue( MuRuntime::has_filter( 'recovery_mode_email' ) );
		self::assertContains( [ \Stonewright\WpMcp\Admin\RescueSettings::class, 'register_settings' ], self::callbacks( 'admin_init' ) );
	}

	public function test_registering_twice_wires_everything_once(): void {
		RescueBootstrap::register();
		RescueBootstrap::register();

		self::assertCount( 1, $GLOBALS['stonewright_test_lifecycle_hooks']['activate'] );
		self::assertCount( 1, array_keys( self::callbacks( 'admin_notices' ), [ RescueInstaller::class, 'admin_notice' ], true ) );
	}

	public function test_the_commands_are_not_registered_outside_wp_cli(): void {
		self::assertFalse( defined( 'WP_CLI' ) );

		RescueBootstrap::register();

		self::assertFalse( class_exists( 'WP_CLI', false ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_commands_are_registered_when_wp_cli_runs(): void {
		define( 'WP_CLI', true );
		require_once __DIR__ . '/Support/WpCliFake.php';

		RescueBootstrap::register();

		self::assertSame( [ 'stonewright', 'stonewright rescue' ], array_keys( \WP_CLI::$commands ) );
		self::assertSame( StonewrightCommand::class, \WP_CLI::$commands['stonewright'] );
		self::assertInstanceOf( RescueCommand::class, \WP_CLI::$commands['stonewright rescue'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_existing_stonewright_command_is_not_registered_again(): void {
		define( 'WP_CLI', true );
		require_once __DIR__ . '/Support/WpCliFake.php';
		\WP_CLI::add_command( 'stonewright', 'SomeOtherStonewrightCommand' );

		RescueBootstrap::register();

		self::assertSame( 'SomeOtherStonewrightCommand', \WP_CLI::$commands['stonewright'] );
		self::assertInstanceOf( RescueCommand::class, \WP_CLI::$commands['stonewright rescue'] );
	}
}
