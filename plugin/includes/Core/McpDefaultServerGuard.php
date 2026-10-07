<?php
/**
 * Creation guard for the default server of the bundled MCP adapter.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Core;

/**
 * The adapter creates a default server whose three tools are abilities it registers on the
 * `wp_abilities_api_init` action. It attaches that action while it initialises, which happens
 * when the REST server is built. A request that has already made the Abilities API fire the
 * action before that (an admin screen that lists abilities and then asks for the REST routes)
 * cannot register them any more, so the default server would be created with tools that do not
 * exist and the log would fill with errors.
 *
 * The guard answers the adapter's `mcp_adapter_create_default_server` filter: such a request
 * does not get the default server. A request in which the adapter initialises first, as every
 * REST request does, is not affected, and neither is a site that turned the default server off.
 * The Stonewright servers are created on `mcp_adapter_init` and do not depend on it.
 */
final class McpDefaultServerGuard {

	public const FILTER = 'mcp_adapter_create_default_server';

	public static function register(): void {
		add_filter( self::FILTER, [ self::class, 'permit' ] );
	}

	/**
	 * @param mixed $create Whether the default server is to be created, as decided so far.
	 */
	public static function permit( mixed $create ): bool {
		if ( ! $create ) {
			return false;
		}

		return 0 === did_action( 'wp_abilities_api_init' );
	}
}
