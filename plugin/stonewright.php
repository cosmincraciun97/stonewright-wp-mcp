<?php
/**
 * Plugin Name: Stonewright
 * Plugin URI: https://github.com/cosmincraciun97/stonewright-wp-mcp
 * Description: Stonewright exposes WordPress building primitives to MCP clients for design-accurate Gutenberg and Elementor work.
 * Version: 1.0.0-alpha.53
 * Requires at least: 6.7
 * Requires PHP: 8.1
 * Author: Stonewright
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: stonewright
 * Domain Path: /languages
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

// Ensure mb_* string functions operate in UTF-8.
// Critical for Windows PowerShell callers: ConvertTo-Json emits \uXXXX escapes
// which PHP json_decode handles correctly only when internal encoding is UTF-8.
if ( function_exists( 'mb_internal_encoding' ) ) {
	mb_internal_encoding( 'UTF-8' );
}

if ( defined( 'STONEWRIGHT_FILE' ) ) {
	return;
}

define( 'STONEWRIGHT_FILE', __FILE__ );
define( 'STONEWRIGHT_DIR', plugin_dir_path( __FILE__ ) );
define( 'STONEWRIGHT_URL', plugin_dir_url( __FILE__ ) );
define( 'STONEWRIGHT_VERSION', '1.0.0-alpha.53' );
define( 'STONEWRIGHT_MIN_PHP', '8.1' );
define( 'STONEWRIGHT_MIN_WP', '6.7' );

require_once STONEWRIGHT_DIR . 'includes/Support/Requirements.php';

if ( ! Stonewright\WpMcp\Support\Requirements::met() ) {
	add_action( 'admin_notices', [ Stonewright\WpMcp\Support\Requirements::class, 'render_notice' ] );
	return;
}

$stonewright_autoload = STONEWRIGHT_DIR . 'vendor/autoload.php';
if ( file_exists( $stonewright_autoload ) ) {
	require_once $stonewright_autoload;
}

/**
 * Lightweight PSR-4 autoloader for the Stonewright\WpMcp namespace.
 *
 * Used when Composer's autoloader is unavailable (e.g. running from a
 * source checkout without `composer install`). Composer autoload still
 * wins if it loaded first because of `spl_autoload_register` ordering.
 */
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'Stonewright\\WpMcp\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		$path     = STONEWRIGHT_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

require_once STONEWRIGHT_DIR . 'includes/Core/PluginRegistration.php';

\Stonewright\WpMcp\Core\PluginRegistration::boot( STONEWRIGHT_FILE );
