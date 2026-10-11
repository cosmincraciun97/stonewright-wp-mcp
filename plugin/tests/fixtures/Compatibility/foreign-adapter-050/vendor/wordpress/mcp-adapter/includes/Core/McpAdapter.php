<?php
// Synthetic fixture: a stand-in for an older adapter that another plugin ships without a Jetpack
// manifest. Written for the compatibility tests; it is loaded by them, never by WordPress.
declare( strict_types=1 );

namespace WP\MCP\Core;

final class McpAdapter {

	public const VERSION = '0.5.0';

	private static self $instance;

	/** @var array<string, McpServer> */
	private array $servers = array();

	/** @var list<list<mixed>> */
	public array $calls = array();

	public static function instance(): self {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function create_server( string $server_id, string $server_route_namespace, string $server_route, string $server_name, string $server_description, string $server_version, array $mcp_transports, ?string $error_handler, ?string $observability_handler = null, array $tools = array(), array $resources = array(), array $prompts = array(), ?callable $transport_permission_callback = null ) {
		$this->calls[]               = func_get_args();
		$this->servers[ $server_id ] = new McpServer( $server_id, $server_route_namespace, $server_route, $server_name, $server_description, $server_version, $mcp_transports, $error_handler, $observability_handler, $tools, $resources, $prompts, $transport_permission_callback );

		return $this;
	}

	public function get_server( string $server_id ): ?McpServer {
		return $this->servers[ $server_id ] ?? null;
	}
}
