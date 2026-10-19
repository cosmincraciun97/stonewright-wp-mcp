<?php
/**
 * Plugin Name: Stonewright
 * Plugin URI: https://github.com/cosmincraciun97/stonewright-wp-mcp
 * Description: Guarded WordPress MCP tools for Elementor, Gutenberg/FSE, WooCommerce catalogs, content models, PHP runtime execution, and tokenized WP-CLI.
 * Version: 1.0.0-beta.17.1
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
	// Another Stonewright copy, in another plugin folder, loaded first and runs; this one does nothing. Say so on the
	// Plugins screen and the Dashboard, because the copy that runs can be the older one.
	$stonewright_skipped_file = __FILE__;
	if ( function_exists( 'add_action' ) && realpath( (string) STONEWRIGHT_FILE ) !== realpath( $stonewright_skipped_file ) ) {
		add_action(
			'admin_notices',
			static function () use ( $stonewright_skipped_file ): void {
				$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
				if ( ! current_user_can( 'activate_plugins' ) || null === $screen || ! in_array( (string) $screen->id, [ 'plugins', 'plugins-network', 'dashboard', 'dashboard-network' ], true ) ) {
					return;
				}
				$skipped = get_file_data( $stonewright_skipped_file, [ 'version' => 'Version' ] );
				$text    = sprintf(
					/* translators: 1: plugin folder, 2: version, 3: plugin folder, 4: version */
					__( 'Two copies of Stonewright are active. Only the copy in the folder %1$s (version %2$s) runs; the copy in the folder %3$s (version %4$s) does nothing. Deactivate the copy you do not use, then delete it.', 'stonewright' ),
					basename( dirname( (string) STONEWRIGHT_FILE ) ),
					defined( 'STONEWRIGHT_VERSION' ) ? (string) STONEWRIGHT_VERSION : '',
					basename( dirname( $stonewright_skipped_file ) ),
					(string) ( $skipped['version'] ?? '' )
				);
				echo '<div class="notice notice-error"><p>' . esc_html( $text ) . '</p></div>';
			}
		);
	}
	return;
}

define( 'STONEWRIGHT_FILE', __FILE__ );
define( 'STONEWRIGHT_DIR', plugin_dir_path( __FILE__ ) );
define( 'STONEWRIGHT_URL', plugin_dir_url( __FILE__ ) );
define( 'STONEWRIGHT_VERSION', '1.0.0-beta.17.1' );
define( 'STONEWRIGHT_MIN_PHP', '8.1' );
define( 'STONEWRIGHT_MIN_WP', '6.7' );

require_once STONEWRIGHT_DIR . 'includes/Support/Requirements.php';

if ( ! Stonewright\WpMcp\Support\Requirements::met() ) {
	add_action( 'admin_notices', [ Stonewright\WpMcp\Support\Requirements::class, 'render_notice' ] );
	return;
}

$stonewright_package_autoload  = STONEWRIGHT_DIR . 'vendor/autoload_packages.php';
$stonewright_composer_autoload = STONEWRIGHT_DIR . 'vendor/autoload.php';
if ( is_readable( $stonewright_package_autoload ) ) {
	require_once $stonewright_package_autoload;
} elseif ( is_readable( $stonewright_composer_autoload ) ) {
	// Source checkouts can use Composer's regular loader. Release packages
	// always ship Jetpack Autoloader so shared WordPress dependencies coexist.
	require_once $stonewright_composer_autoload;
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
