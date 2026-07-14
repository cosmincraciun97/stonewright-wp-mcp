<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Write;

/** Short-lived replay protection for Elementor writes. */
final class IdempotencyStore {
	private const TTL = 86400;

	/** @return array<string, mixed>|\WP_Error|null */
	public static function lookup( int $post_id, string $key, string $request_hash ): array|\WP_Error|null {
		if ( '' === $key ) {
			return null;
		}
		$stored = get_transient( self::transient_key( $post_id, $key ) );
		if ( false === $stored || ! is_array( $stored ) ) {
			return null;
		}
		if ( ! hash_equals( (string) ( $stored['request_hash'] ?? '' ), $request_hash ) ) {
			return new \WP_Error(
				'stonewright_idempotency_conflict',
				__( 'The idempotency key was already used with different Elementor input.', 'stonewright' ),
				[ 'status' => 409 ]
			);
		}
		$response = isset( $stored['response'] ) && is_array( $stored['response'] ) ? $stored['response'] : [];
		$response['idempotent_replay'] = true;
		return $response;
	}

	/** @param array<string, mixed> $response */
	public static function remember( int $post_id, string $key, string $request_hash, array $response ): void {
		if ( '' === $key ) {
			return;
		}
		set_transient(
			self::transient_key( $post_id, $key ),
			[ 'request_hash' => $request_hash, 'response' => $response ],
			self::TTL
		);
	}

	private static function transient_key( int $post_id, string $key ): string {
		return 'stonewright_eidem_' . hash( 'sha256', $post_id . '|' . $key );
	}
}
