<?php
/**
 * Guard for hooks that run after the plugin's files may have changed on disk.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Support;

/**
 * Tells a late hook whether the classes it is about to use can still be loaded.
 *
 * A request that loaded the plugin keeps running while an update, a rename or a deletion replaces the
 * plugin folder under it. A hook that fires later in that request (admin_init, a scheduled event) and
 * autoloads a class whose file is gone makes the loader fail with a fatal error. Each such hook names the
 * classes it will touch and returns when this says no. Checking reads no file and loads nothing.
 *
 * Load this class in the boot, before any hook can fire: the guard cannot be loaded from the folder that is gone.
 */
final class LateLoad {

	private const NAMESPACE_PREFIX = 'Stonewright\\WpMcp\\';

	private static ?string $root = null;

	/**
	 * True when every class is loaded already, or is one of the plugin's own and its file is readable.
	 * A class outside the plugin's namespace counts only when it is loaded, since its file cannot be placed.
	 */
	public static function ready( string ...$classes ): bool {
		foreach ( $classes as $class ) {
			if ( class_exists( $class, false ) || interface_exists( $class, false ) || trait_exists( $class, false ) ) {
				continue;
			}
			if ( 0 !== strpos( $class, self::NAMESPACE_PREFIX ) ) {
				return false;
			}
			$relative = str_replace( '\\', '/', substr( $class, strlen( self::NAMESPACE_PREFIX ) ) );
			if ( ! is_readable( self::root() . 'includes/' . $relative . '.php' ) ) {
				return false;
			}
		}
		return true;
	}

	/** The plugin directory, with a trailing slash. */
	public static function root(): string {
		if ( null !== self::$root ) {
			return self::$root;
		}
		$dir = defined( 'STONEWRIGHT_DIR' ) ? (string) constant( 'STONEWRIGHT_DIR' ) : dirname( __DIR__, 2 );
		return rtrim( $dir, '/\\' ) . '/';
	}

	/** Replace the plugin directory. For tests; null goes back to STONEWRIGHT_DIR. */
	public static function set_root( ?string $root ): void {
		self::$root = null === $root ? null : rtrim( $root, '/\\' ) . '/';
	}
}
