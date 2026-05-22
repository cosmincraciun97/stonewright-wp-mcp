<?php
/**
 * PHPStan bootstrap — minimal WordPress constant/stub definitions for static analysis.
 *
 * NOT a PHPUnit bootstrap. Does not load the full WP stack or the PHPUnit bootstrap.php.
 * Defines only what PHPStan needs to resolve constants and load PSR-4 classes.
 */

declare( strict_types=1 );

// ---------------------------------------------------------------------------
// Core WordPress constants used throughout the codebase.
// ---------------------------------------------------------------------------

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}

if ( ! defined( 'WPINC' ) ) {
	define( 'WPINC', 'wp-includes' );
}

if ( ! defined( 'WP_DEBUG' ) ) {
	define( 'WP_DEBUG', false );
}

if ( ! defined( 'WP_DEBUG_LOG' ) ) {
	define( 'WP_DEBUG_LOG', false );
}

if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
}

if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
	define( 'WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins' );
}

// Plugin-specific constants so stonewright.php analysis doesn't error
// (STONEWRIGHT_FILE etc. are normally set at runtime via define()).
if ( ! defined( 'STONEWRIGHT_FILE' ) ) {
	define( 'STONEWRIGHT_FILE', dirname( __DIR__ ) . '/stonewright.php' );
}

if ( ! defined( 'STONEWRIGHT_DIR' ) ) {
	define( 'STONEWRIGHT_DIR', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'STONEWRIGHT_URL' ) ) {
	define( 'STONEWRIGHT_URL', 'http://localhost/' );
}

if ( ! defined( 'STONEWRIGHT_VERSION' ) ) {
	define( 'STONEWRIGHT_VERSION', '1.0.0-alpha.1' );
}

if ( ! defined( 'STONEWRIGHT_MIN_PHP' ) ) {
	define( 'STONEWRIGHT_MIN_PHP', '8.1' );
}

if ( ! defined( 'STONEWRIGHT_MIN_WP' ) ) {
	define( 'STONEWRIGHT_MIN_WP', '6.7' );
}

// ---------------------------------------------------------------------------
// Composer autoloader — required for PSR-4 class resolution.
// ---------------------------------------------------------------------------

$phpstan_autoload = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( file_exists( $phpstan_autoload ) ) {
	require_once $phpstan_autoload;
}


