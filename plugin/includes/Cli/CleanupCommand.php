<?php
/**
 * The wp stonewright cleanup command.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Cli;

use Stonewright\WpMcp\Core\RuntimeState;
use Stonewright\WpMcp\Core\Uninstaller;

/**
 * Removes what Stonewright keeps between requests, and on request all of its data, from the command line.
 *
 * It is for the operator who has shell access and no way to define STONEWRIGHT_REMOVE_ALL_DATA, and for a host
 * where deleting the plugin from wp-admin runs into a resource limit: WP-CLI runs outside the web server's limits.
 * It never deletes the plugin's files and never touches the rescue helper. --all-data deactivates the plugin first:
 * an active plugin creates its tables and OAuth keys again on the next request, so the data would be back before
 * the plugin is uninstalled. Delete the plugin afterwards (`wp plugin uninstall stonewright`).
 *
 * The output goes through the protected methods line(), success() and fail(), so a test can replace WP-CLI.
 */
class CleanupCommand {

	/**
	 * Remove Stonewright's runtime state, or with --all-data --yes every table, option, transient and event it owns.
	 *
	 * The runtime state is what the plugin rebuilds on its own: locks, back-offs, cached verdicts, the remembered state
	 * of the rescue helper and Elementor editor baselines. Settings, tables, OAuth clients and keys, memory, skills and
	 * the audit history stay, so the command is safe to run on a live site.
	 *
	 * With --all-data the data goes too: every Stonewright table, every option and transient whose name starts with
	 * `stonewright_` (the OAuth signing and encryption keys included, so every connected client has to sign in again),
	 * the scheduled events, and the change journal files and change history blobs in uploads/stonewright-state/. The
	 * plugin is deactivated first, so it does not create its tables and keys again on the next request. Pages, posts,
	 * media and the plugin's files are not touched.
	 *
	 * On a multisite network the command acts on the site selected with --url; --network makes it act on every site.
	 *
	 * ## OPTIONS
	 *
	 * [--all-data]
	 * : Also remove all Stonewright data. Needs --yes.
	 *
	 * [--yes]
	 * : Confirm --all-data. The command never asks a question.
	 *
	 * [--network]
	 * : On a multisite network, act on every site instead of the site selected with --url.
	 *
	 * ## EXAMPLES
	 *
	 *     wp stonewright cleanup
	 *     wp stonewright cleanup --all-data --yes
	 *     wp stonewright cleanup --all-data --yes --network
	 *
	 * @param list<string>         $args       Positional arguments (none).
	 * @param array<string, mixed> $assoc_args Named arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args );
		global $wpdb;
		$all_data = ! empty( $assoc_args['all-data'] );
		if ( $all_data && empty( $assoc_args['yes'] ) ) {
			$this->fail( 'Removing all Stonewright data cannot be undone. Run it again with --all-data --yes to confirm, or without --all-data to remove the runtime state only.' );
		}
		$network = is_multisite() && ! empty( $assoc_args['network'] );
		if ( $all_data && ! $network && is_multisite() && $this->network_active() ) {
			// A site-only deactivation leaves the network activation in place, so the plugin would load on the next
			// request and create the tables and keys of this site again.
			$this->fail( 'Stonewright is active for the whole network, so removing the data of one site would only bring it back on the next request. Run the command again with --network, or deactivate the plugin for the network first.' );
		}
		$removed = RuntimeState::clear_everywhere( $wpdb, $network );
		$this->line( sprintf( 'Removed %d runtime rows (locks, back-offs, cached verdicts, helper state, editor baselines).', $removed ) );
		if ( ! $all_data ) {
			$this->success( 'Runtime state removed. Settings, tables and data were kept.' );
			return;
		}
		// Deactivate before the data goes: the deactivation hooks of other plugins may write a row of their own, and
		// whatever they write is removed with the rest.
		$this->deactivate( $network );
		$this->line( 'Deactivated Stonewright, so that it does not create its tables and keys again.' );
		( new Uninstaller( $wpdb, $network ) )->remove_everything();
		$this->line( sprintf( 'Dropped the %d Stonewright tables, removed every stonewright_ option and transient, and cleared the scheduled events.', count( Uninstaller::TABLES ) ) );
		$this->success( 'All Stonewright data removed' . ( $network ? ' on every site of the network' : '' ) . '. Delete the plugin with `wp plugin uninstall stonewright`; its files and the rescue helper were not touched.' );
	}

	// -----------------------------------------------------------------------
	// Output. WP-CLI in production; a recorder in tests.
	// -----------------------------------------------------------------------

	protected function line( string $text ): void {
		self::cli( 'line', $text );
	}

	protected function success( string $text ): void {
		self::cli( 'success', $text );
	}

	/** Whether the plugin is activated for the whole network. Replaced in tests. */
	protected function network_active(): bool {
		if ( ! defined( 'STONEWRIGHT_FILE' ) ) {
			return false;
		}
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return is_plugin_active_for_network( plugin_basename( (string) constant( 'STONEWRIGHT_FILE' ) ) );
	}

	/** Deactivate the plugin, for the network when --network was given. Replaced in tests. */
	protected function deactivate( bool $network ): void {
		if ( ! defined( 'STONEWRIGHT_FILE' ) ) {
			return;
		}
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		deactivate_plugins( plugin_basename( (string) constant( 'STONEWRIGHT_FILE' ) ), false, $network );
	}

	protected function fail( string $text, int $code = 1 ): never {
		self::cli( 'error', $text, $code );
		throw new \RuntimeException( $text, $code );
	}

	private static function cli( string $method, mixed ...$arguments ): void {
		$class = 'WP_CLI';
		$class::$method( ...$arguments );
	}
}
