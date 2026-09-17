<?php
declare( strict_types=1 );

/**
 * Plugin Name: Stonewright Synthetic MCP Provider
 * Description: Loads an independent MCP adapter vendor and boots it early so e2e can prove Stonewright still registers.
 * Version: 1.0.0
 * License: MIT
 */

$stonewright_synthetic_packages = __DIR__ . '/vendor/autoload_packages.php';
$stonewright_synthetic_autoload = __DIR__ . '/vendor/autoload.php';
if ( is_readable( $stonewright_synthetic_packages ) ) {
	require_once $stonewright_synthetic_packages;
} elseif ( is_readable( $stonewright_synthetic_autoload ) ) {
	require_once $stonewright_synthetic_autoload;
}

add_action(
	'plugins_loaded',
	static function (): void {
		if ( class_exists( '\\WP\\MCP\\Core\\McpAdapter' ) ) {
			\WP\MCP\Core\McpAdapter::instance();
		}
	},
	1
);
