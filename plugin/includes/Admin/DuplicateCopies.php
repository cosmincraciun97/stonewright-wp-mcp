<?php
/**
 * A second Stonewright copy in the plugins folder: the notice that names both, and a safe delete of the other one.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

use Stonewright\WpMcp\Core\Uninstaller;

/**
 * A ZIP uploaded under another folder name installs a second copy beside the first. Only one copy runs (the second
 * one stops at its first line), but both are listed and both can be active.
 *
 * The notice on the Plugins screen and the Dashboard names the folders, their versions and the copy that runs.
 *
 * Deleting the other copy while this one runs makes WordPress include the uninstall.php of that copy. It would load
 * classes this copy already loaded, which is a fatal error that stops the delete, and in an older copy it would
 * remove the rescue helper and the list of registered widgets that this copy still uses. The folder is deleted right
 * after, so its uninstall.php is removed first, in the action WordPress runs before it looks for that file.
 */
final class DuplicateCopies {

	public static function register(): void {
		add_action( 'admin_notices', [ self::class, 'notice' ] );
		add_action( 'pre_uninstall_plugin', [ self::class, 'before_other_copy_uninstall' ], 10, 1 );
	}

	/** The running copy, as a plugin file name relative to the plugins folder. */
	public static function running(): string {
		return defined( 'STONEWRIGHT_FILE' ) ? plugin_basename( (string) constant( 'STONEWRIGHT_FILE' ) ) : 'stonewright/stonewright.php';
	}

	/** Whether $plugin is a Stonewright copy other than the running one. */
	public static function is_other_copy( string $plugin ): bool {
		$plugin = str_replace( '\\', '/', $plugin );
		if ( strtok( $plugin, '/' ) === strtok( self::running(), '/' ) ) {
			return false;
		}

		return in_array( $plugin, Uninstaller::installed_copies(), true );
	}

	public static function notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) || ! self::on_notice_screen() ) {
			return;
		}
		$copies = Uninstaller::installed_copies();
		if ( count( $copies ) < 2 ) {
			return;
		}

		$running = self::running();
		$items   = '';
		foreach ( $copies as $copy ) {
			$header = get_file_data( trailingslashit( (string) WP_PLUGIN_DIR ) . $copy, [ 'version' => 'Version' ] );
			$text   = sprintf(
				/* translators: 1: plugin folder name, 2: version */
				__( 'Folder %1$s, version %2$s', 'stonewright' ),
				strtok( $copy, '/' ),
				(string) ( $header['version'] ?? '' )
			);
			if ( $copy === $running ) {
				$text .= ' — ' . __( 'this copy runs', 'stonewright' );
			}
			$items .= '<li>' . esc_html( $text ) . '</li>';
		}

		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Stonewright is installed twice.', 'stonewright' ) . '</strong> '
			. esc_html__( 'Only one copy runs at a time. Delete the copy you do not use on the Plugins screen; the settings, the history and the connections are shared and stay with the other copy.', 'stonewright' )
			. '</p><ul style="list-style:disc;margin-left:2em;">' . $items . '</ul></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every item is escaped above.
	}

	/** @param string $plugin The plugin WordPress is about to uninstall. */
	public static function before_other_copy_uninstall( mixed $plugin ): void {
		if ( ! is_string( $plugin ) || ! self::is_other_copy( $plugin ) ) {
			return;
		}
		$file = trailingslashit( (string) constant( 'WP_PLUGIN_DIR' ) ) . dirname( str_replace( '\\', '/', $plugin ) ) . '/uninstall.php';
		if ( is_file( $file ) ) {
			wp_delete_file( $file );
		}
	}

	private static function on_notice_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null === $screen ) {
			return false;
		}

		return in_array( (string) $screen->id, [ 'plugins', 'plugins-network', 'dashboard', 'dashboard-network' ], true )
			|| str_contains( (string) $screen->id, 'stonewright' );
	}
}
