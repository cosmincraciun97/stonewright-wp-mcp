<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\PluginsManage;

/**
 * Checks on the plugin an ability is asked to activate, deactivate or delete.
 */
final class PluginTarget {

	/**
	 * A plugin path the way WordPress lists it: forward slashes, no leading slash.
	 */
	public static function normalise( string $plugin ): string {
		return ltrim( str_replace( '\\', '/', trim( $plugin ) ), '/' );
	}

	/**
	 * The basename of the running Stonewright plugin, for example `stonewright/stonewright.php`.
	 */
	public static function own_basename(): string {
		return defined( 'STONEWRIGHT_FILE' ) ? plugin_basename( (string) constant( 'STONEWRIGHT_FILE' ) ) : 'stonewright/stonewright.php';
	}

	/**
	 * Whether $plugin is Stonewright itself: its basename, or any file in its folder. A plugin that only has
	 * "stonewright" somewhere in its name is another plugin.
	 */
	public static function is_self( string $plugin ): bool {
		$plugin = self::normalise( $plugin );
		$own    = self::own_basename();
		if ( $plugin === $own ) {
			return true;
		}
		$folder = dirname( $own );
		return '.' !== $folder && str_starts_with( $plugin, $folder . '/' );
	}

	/**
	 * Whether WordPress knows an installed plugin under this path.
	 */
	public static function exists( string $plugin ): bool {
		if ( ! function_exists( 'get_plugins' ) && defined( 'ABSPATH' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return '' !== $plugin && array_key_exists( $plugin, get_plugins() );
	}

	/**
	 * The refusal for a plugin that is not installed.
	 */
	public static function not_found_error(): \WP_Error {
		return new \WP_Error( 'stonewright_plugin_not_found', __( 'That plugin is not installed.', 'stonewright' ), [ 'status' => 404 ] );
	}
}
