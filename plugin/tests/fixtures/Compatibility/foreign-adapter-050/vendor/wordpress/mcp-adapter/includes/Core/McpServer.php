<?php
// Synthetic fixture. Written for the compatibility tests.
declare( strict_types=1 );

namespace WP\MCP\Core;

final class McpServer {

	/** @var list<string> */
	private array $tools;

	/** @param list<string> $tools */
	public function __construct(
		private string $server_id,
		private string $server_route_namespace,
		private string $server_route,
		private string $server_name,
		string $server_description,
		string $server_version,
		array $mcp_transports,
		?string $error_handler,
		?string $observability_handler,
		array $tools = array(),
		array $resources = array(),
		array $prompts = array(),
		?callable $transport_permission_callback = null
	) {
		$this->tools = $tools;
	}

	public function get_server_id(): string {
		return $this->server_id;
	}

	public function get_server_route_namespace(): string {
		return $this->server_route_namespace;
	}

	public function get_server_route(): string {
		return $this->server_route;
	}

	public function get_server_name(): string {
		return $this->server_name;
	}

	/** The names a `tools/list` request on this server would answer with. @return list<string> */
	public function list_tool_names(): array {
		return $this->tools;
	}
}
