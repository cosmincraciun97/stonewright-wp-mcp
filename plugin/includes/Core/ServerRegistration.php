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
		$instructions_on  = (bool) get_option( 'stonewright_custom_instructions_enabled', true );
		$custom           = trim( (string) get_option( 'stonewright_custom_instructions', '' ) );

		$description = $base_description . "\n\n" . AgentInstructions::default();
		if ( $instructions_on && '' !== $custom ) {
			$description .= "\n\n" . mb_substr( $custom, 0, 4000 );
		}

		// Collect all Stonewright ability names for tool exposure.
		$tools = [];
		foreach ( AbilityRegistry::list() as $class ) {
			if ( class_exists( $class ) ) {
				$ability = new $class();
				$tools[] = $ability->name();
			}
		}

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
