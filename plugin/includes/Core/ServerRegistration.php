<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Core;

use Stonewright\WpMcp\Support\Logger;
use Throwable;
use WP_Error;
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
		$adapter_class = get_class( $adapter );
		$compatibility = McpAbilitiesCompatibilityPreflight::inspect( null, $adapter_class );
		$meta = self::runtime_meta( $compatibility );
		if ( ! $compatibility['compatible'] || $adapter_class !== ( $compatibility['adapter']['class'] ?? null ) ) {
			self::record_pair(
				'blocked',
				'runtime_incompatible',
				'Stonewright refused to register because the MCP runtime contract is not compatible.',
				$meta
			);
			return;
		}

		if ( ! method_exists( $adapter, 'create_server' ) ) {
			self::record_pair(
				'failed',
				'missing_create_server',
				'The selected MCP adapter does not expose create_server().',
				$meta
			);
			return;
		}

		$base_description = __( 'MCP server for design-accurate WordPress building.', 'stonewright' );
		$description      = $base_description . "\n\n" . AgentInstructions::server_bootstrap_summary();
		$tools            = AbilityRegistry::mcp_server_ability_names();

		self::create_server( $adapter, self::SERVER_ID, self::ROUTE, $description, $tools, $meta );
		self::create_server( $adapter, self::OAUTH_SERVER_ID, self::OAUTH_ROUTE, $description, $tools, $meta );
	}

	/**
	 * @param array<string,string> $meta
	 * @param list<string>         $tools Ability names.
	 */
	private static function create_server(
		object $adapter,
		string $server_id,
		string $route,
		string $description,
		array $tools,
		array $meta
	): void {
		try {
			if ( method_exists( $adapter, 'get_server' ) ) {
				$existing = $adapter->get_server( $server_id );
				if ( is_object( $existing ) ) {
					if ( self::is_stonewright_identity( $existing, $server_id, $route ) ) {
						McpRegistrationState::record(
							$server_id,
							[
								'state'      => 'registered',
								'error_code' => '',
								'message'    => 'Server already registered with the Stonewright identity.',
								'owner'      => $meta['owner'],
								'version'    => $meta['version'],
							]
						);
						return;
					}
					McpRegistrationState::record(
						$server_id,
						[
							'state'      => 'failed',
							'error_code' => 'duplicate_server_id',
							'message'    => 'A different MCP server already occupies this server ID.',
							'owner'      => $meta['owner'],
							'version'    => $meta['version'],
						]
					);
					return;
				}
			}

			$result = $adapter->create_server(
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

			if ( $result instanceof WP_Error ) {
				McpRegistrationState::record(
					$server_id,
					[
						'state'      => 'failed',
						'error_code' => sanitize_key( (string) $result->get_error_code() ),
						'message'    => sanitize_text_field( (string) $result->get_error_message() ),
						'owner'      => $meta['owner'],
						'version'    => $meta['version'],
					]
				);
				return;
			}

			if ( method_exists( $adapter, 'get_server' ) ) {
				$registered = $adapter->get_server( $server_id );
				if ( ! is_object( $registered ) || ! self::is_stonewright_identity( $registered, $server_id, $route ) ) {
					McpRegistrationState::record(
						$server_id,
						[
							'state'      => 'failed',
							'error_code' => 'server_not_in_registry',
							'message'    => 'create_server() did not leave the Stonewright server in the adapter registry.',
							'owner'      => $meta['owner'],
							'version'    => $meta['version'],
						]
					);
					return;
				}
			}

			McpRegistrationState::record(
				$server_id,
				[
					'state'      => 'registered',
					'error_code' => '',
					'message'    => '',
					'owner'      => $meta['owner'],
					'version'    => $meta['version'],
				]
			);
		} catch ( Throwable $error ) {
			Logger::warning(
				'mcp_server_registration_failed',
				[
					'server_id'   => $server_id,
					'error_class' => $error::class,
				]
			);
			McpRegistrationState::record(
				$server_id,
				[
					'state'      => 'failed',
					'error_code' => 'registration_exception',
					'message'    => 'MCP server registration failed.',
					'owner'      => $meta['owner'],
					'version'    => $meta['version'],
				]
			);
		}
	}

	private static function is_stonewright_identity( object $server, string $server_id, string $route ): bool {
		$id = method_exists( $server, 'get_server_id' ) ? (string) $server->get_server_id() : '';
		$server_route = method_exists( $server, 'get_server_route' ) ? (string) $server->get_server_route() : '';
		$name = method_exists( $server, 'get_server_name' ) ? (string) $server->get_server_name() : '';
		return $server_id === $id && $route === $server_route && 'Stonewright' === $name;
	}

	/**
	 * @param array<string,mixed> $compatibility
	 * @return array{owner:string,version:string}
	 */
	private static function runtime_meta( array $compatibility ): array {
		$adapter = is_array( $compatibility['adapter'] ?? null ) ? $compatibility['adapter'] : [];
		return [
			'owner'   => sanitize_text_field( (string) ( $adapter['selected_owner'] ?? '' ) ),
			'version' => sanitize_text_field( (string) ( $adapter['selected_version'] ?? ( $adapter['abi']['version'] ?? '' ) ) ),
		];
	}

	/**
	 * @param array{owner:string,version:string} $meta
	 */
	private static function record_pair( string $state, string $error_code, string $message, array $meta ): void {
		foreach ( [ self::SERVER_ID, self::OAUTH_SERVER_ID ] as $server_id ) {
			McpRegistrationState::record(
				$server_id,
				[
					'state'      => $state,
					'error_code' => $error_code,
					'message'    => $message,
					'owner'      => $meta['owner'],
					'version'    => $meta['version'],
				]
			);
		}
	}
}
