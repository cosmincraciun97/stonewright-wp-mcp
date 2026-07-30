<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Core;

use WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler;
use WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler;
use WP\MCP\Transport\HttpTransport;

/**
 * Registers the Stonewright MCP server with the WordPress MCP Adapter.
 */
final class ServerRegistration {

	public const SERVER_ID          = 'stonewright';
	public const OAUTH_SERVER_ID    = 'stonewright-oauth';
	public const ROUTE_NAMESPACE    = 'mcp';
	public const ROUTE              = 'stonewright';
	public const OAUTH_ROUTE        = 'stonewright-oauth';

	public static function register_server( object $adapter ): void {
		if ( ! method_exists( $adapter, 'create_server' ) ) {
			return;
		}

		$base_description = __( 'MCP server for design-accurate WordPress building.', 'stonewright' );
		$description      = $base_description . "\n\n" . AgentInstructions::server_bootstrap_summary();
		$tools            = AbilityRegistry::mcp_server_ability_names();

		self::create_server( $adapter, self::SERVER_ID, self::ROUTE, $description, $tools );
		self::create_server( $adapter, self::OAUTH_SERVER_ID, self::OAUTH_ROUTE, $description, $tools );
	}

	/**
	 * @param list<string> $tools Ability names.
	 */
	private static function create_server(
		object $adapter,
		string $server_id,
		string $route,
		string $description,
		array $tools
	): void {
		$adapter->create_server(
			$server_id,
			self::ROUTE_NAMESPACE,
			$route,
			'Stonewright',
			$description,
			STONEWRIGHT_VERSION,
			[ HttpTransport::class ],
			ErrorLogMcpErrorHandler::class,
			NullMcpObservabilityHandler::class,
			$tools,
			[],
			[]
		);
	}
}
