<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Context;

/**
 * Short-lived workflow token proving the agent loaded Stonewright context.
 */
final class ContextToken {

	private const PREFIX = 'swctx_';
	private const TTL    = 30 * MINUTE_IN_SECONDS;

	/**
	 * @return array{token:string,expires_at:string}
	 */
	public static function issue( string $task, string $scope = '*' ): array {
		$token      = self::PREFIX . bin2hex( random_bytes( 24 ) );
		$expires_at = gmdate( 'c', time() + self::TTL );

		set_transient(
			self::transient_key( $token ),
			[
				'user_id'   => get_current_user_id(),
				'task_hash' => hash( 'sha256', $task ),
				'scope'     => $scope,
				'issued_at' => time(),
				'expires_at' => $expires_at,
			],
			self::TTL
		);

		return [
			'token'      => $token,
			'expires_at' => $expires_at,
		];
	}

	public static function verify( string $token, string $ability_name ): bool|\WP_Error {
		if ( ! str_starts_with( $token, self::PREFIX ) ) {
			return self::error();
		}

		$data = get_transient( self::transient_key( $token ) );
		if ( ! is_array( $data ) ) {
			return self::error();
		}

		if ( (int) ( $data['user_id'] ?? -1 ) !== get_current_user_id() ) {
			return self::error();
		}

		$scope = (string) ( $data['scope'] ?? '*' );
		if ( '*' !== $scope && $scope !== $ability_name && ! str_starts_with( $ability_name, rtrim( $scope, '*' ) ) ) {
			return self::error();
		}

		return true;
	}

	private static function transient_key( string $token ): string {
		return 'stonewright_context_' . hash( 'sha256', $token );
	}

	private static function error(): \WP_Error {
		return new \WP_Error(
			'stonewright_context_required',
			__( 'Call MCP tool stonewright-task-start (WordPress ability stonewright/task-start) first for this task and pass the returned stonewright_context_token to write or destructive abilities. Compatibility path: stonewright-context-bootstrap.', 'stonewright' ),
			[ 'status' => 403 ]
		);
	}
}
