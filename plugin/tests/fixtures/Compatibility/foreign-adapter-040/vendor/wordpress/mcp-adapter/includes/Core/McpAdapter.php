<?php
// Synthetic fixture: a stand-in for an adapter whose server API Stonewright cannot call. Written for the
// compatibility tests; it is loaded by them, never by WordPress.
declare( strict_types=1 );

namespace WP\MCP\Core;

final class McpAdapter {

	public const VERSION = '0.4.0';

	private static self $instance;

	public static function instance(): self {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function create_server( string $server_id, string $route_namespace, string $route, string $name, string $description, string $version, array $transports ) {
		return $this;
	}
}
