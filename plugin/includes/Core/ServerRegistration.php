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
	public const ROUTE_NAMESPACE    = 'mcp';
	public const ROUTE              = 'stonewright';

	public static function register_server( object $adapter ): void {
		if ( ! method_exists( $adapter, 'create_server' ) ) {
			return;
		}

		$base_description = __( 'MCP server for design-accurate WordPress building.', 'stonewright' );
		$description      = $base_description . "\n\n" . AgentInstructions::server_bootstrap_summary();
		$tools            = AbilityRegistry::mcp_server_ability_names();

		$adapter->create_server(
			self::SERVER_ID,
			self::ROUTE_NAMESPACE,
			self::ROUTE,
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
