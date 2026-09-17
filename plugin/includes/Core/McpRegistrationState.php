<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Core;

/**
 * Request-local MCP server registration outcomes. Never writes options or transients.
 */
final class McpRegistrationState {

	private const STATES = [ 'registered', 'blocked', 'failed', 'not_checked' ];

	/** @var array<string, array<string, mixed>> */
	private static array $servers = [];

	/**
	 * @param array{state?:string,error_code?:string,message?:string,owner?:string,version?:string} $result
	 */
	public static function record( string $server_id, array $result ): void {
		$server_id = sanitize_key( $server_id );
		if ( '' === $server_id ) {
			return;
		}
		$state = sanitize_key( (string) ( $result['state'] ?? 'failed' ) );
		if ( ! in_array( $state, self::STATES, true ) ) {
			$state = 'failed';
		}
		self::$servers[ $server_id ] = [
			'server_id'  => $server_id,
			'state'      => $state,
			'error_code' => sanitize_key( (string) ( $result['error_code'] ?? '' ) ),
			'message'    => sanitize_text_field( (string) ( $result['message'] ?? '' ) ),
			'owner'      => sanitize_text_field( (string) ( $result['owner'] ?? '' ) ),
			'version'    => sanitize_text_field( (string) ( $result['version'] ?? '' ) ),
		];
	}

	/**
	 * @return array{rest_registry_ready:bool,servers:list<array<string,mixed>>}
	 */
	public static function report(): array {
		$rest_ready = function_exists( 'did_action' ) && did_action( 'rest_api_init' ) > 0;
		$ids = [ ServerRegistration::SERVER_ID, ServerRegistration::OAUTH_SERVER_ID ];
		$servers = [];
		foreach ( $ids as $id ) {
			if ( isset( self::$servers[ $id ] ) ) {
				$servers[] = self::$servers[ $id ];
				continue;
			}
			$servers[] = [
				'server_id'  => $id,
				'state'      => $rest_ready ? 'failed' : 'not_checked',
				'error_code' => $rest_ready ? 'server_not_recorded' : '',
				'message'    => $rest_ready
					? 'Stonewright did not record a registration outcome for this server.'
					: 'REST routes have not been initialized in this request.',
				'owner'      => '',
				'version'    => '',
			];
		}
		foreach ( self::$servers as $id => $row ) {
			if ( in_array( $id, $ids, true ) ) {
				continue;
			}
			$servers[] = $row;
		}
		return [
			'rest_registry_ready' => $rest_ready,
			'servers'             => $servers,
		];
	}

	/** @internal */
	public static function reset_for_tests(): void {
		self::$servers = [];
	}
}
