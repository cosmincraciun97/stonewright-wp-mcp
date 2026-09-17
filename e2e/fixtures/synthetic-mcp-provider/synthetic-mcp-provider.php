<?php
declare( strict_types=1 );

/**
 * Plugin Name: Stonewright Synthetic MCP Provider
 * Description: Boots the WordPress MCP adapter early so e2e can prove Stonewright still registers after another plugin calls McpAdapter::instance().
 * Version: 1.0.0
 * License: MIT
 */

add_action(
	'plugins_loaded',
	static function (): void {
		if ( class_exists( '\\WP\\MCP\\Core\\McpAdapter' ) ) {
			\WP\MCP\Core\McpAdapter::instance();
		}
	},
	1
);
