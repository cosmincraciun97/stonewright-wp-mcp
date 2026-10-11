<?php
/**
 * Hooks of the rescue helper.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Core;

use Stonewright\WpMcp\Admin\RescueSettings;
use Stonewright\WpMcp\Cli\ChangesCommand;
use Stonewright\WpMcp\Cli\RescueCommand;
use Stonewright\WpMcp\Cli\StonewrightCommand;
use Stonewright\WpMcp\Security\RescueRecoveryEmail;
use Stonewright\WpMcp\Security\RescueSafeBoot;

/**
 * Wires every hook the rescue helper needs, with one call from the plugin boot.
 *
 * - activation installs the MU-plugin and deactivation removes the rescue keys and sessions that
 *   are still waiting (the MU-plugin file stays and does nothing while the plugin is inactive);
 * - init installs it again when the plugin version or file name changed; every admin page load compares
 *   its hash with the bundled file and writes it again when it is missing or was changed, and an
 *   admin notice says so when it cannot be installed;
 * - the recovery mode email gets the safe mode link, the entry into safe boot is audited, and the
 *   optional MCP route setting is on Settings > General;
 * - when WP-CLI runs, "wp stonewright rescue" and "wp stonewright changes" are registered.
 */
final class RescueBootstrap {

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		$file = defined( 'STONEWRIGHT_FILE' ) ? (string) constant( 'STONEWRIGHT_FILE' ) : ( defined( 'STONEWRIGHT_DIR' ) ? (string) constant( 'STONEWRIGHT_DIR' ) . 'stonewright.php' : '' );
		if ( '' !== $file ) {
			register_activation_hook( $file, [ RescueInstaller::class, 'on_activate' ] );
			register_deactivation_hook( $file, [ RescueInstaller::class, 'on_deactivate' ] );
		}
		add_action( 'init', [ RescueInstaller::class, 'maybe_upgrade' ], 16, 0 );
		add_action( 'admin_init', [ RescueInstaller::class, 'on_admin_init' ], 10, 0 );
		add_action( 'admin_notices', [ RescueInstaller::class, 'admin_notice' ] );
		add_action( 'network_admin_notices', [ RescueInstaller::class, 'admin_notice' ] );

		RescueSafeBoot::register();
		RescueRecoveryEmail::register();
		RescueSettings::register();
		self::register_commands();
	}

	/** Forget that register() ran. For tests. */
	public static function reset_for_tests(): void {
		self::$registered = false;
	}

	/**
	 * Registers "wp stonewright rescue" and "wp stonewright changes", and the "stonewright" parent when no one else did.
	 * WP_CLI is not a dependency: the classes are only touched when WP-CLI is running.
	 */
	private static function register_commands(): void {
		if ( ! defined( 'WP_CLI' ) || true !== constant( 'WP_CLI' ) || ! class_exists( 'WP_CLI', false ) ) {
			return;
		}
		$cli  = 'WP_CLI';
		$path = [ 'stonewright' ];
		if ( ! $cli::get_root_command()->find_subcommand( $path ) ) {
			$cli::add_command( 'stonewright', StonewrightCommand::class );
		}
		$cli::add_command( 'stonewright rescue', new RescueCommand() );
		$cli::add_command( 'stonewright changes', new ChangesCommand() );
	}
}
