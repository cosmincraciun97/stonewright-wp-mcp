<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Write;

/**
 * Short-lived per-post lease that prevents concurrent Elementor writes.
 */
final class PostWriteLock {
	private const PREFIX = 'stonewright_elementor_lock_';

	/**
	 * @return array{post_id:int,owner:string,expires_at:int}|\WP_Error
	 */
	public static function acquire( int $post_id, string $owner, int $ttl = 30 ): array|\WP_Error {
		$owner = sanitize_key( $owner );
		if ( $post_id < 1 || '' === $owner ) {
			return new \WP_Error(
				'stonewright_elementor_lock_invalid',
				__( 'Elementor write locks require a post and owner.', 'stonewright' ),
				[ 'status' => 400 ]
			);
		}

		$key   = self::key( $post_id );
		$now   = time();
		$lease = [
			'post_id'    => $post_id,
			'owner'      => $owner,
			'expires_at' => $now + max( 5, min( 120, $ttl ) ),
		];
		if ( add_option( $key, $lease, '', false ) ) {
			return $lease;
		}

		$current = get_option( $key, [] );
		if ( is_array( $current ) && (int) ( $current['expires_at'] ?? 0 ) <= $now ) {
			if ( delete_option( $key ) ) {
				return self::acquire( $post_id, $owner, $ttl );
			}
			$current = get_option( $key, [] );
		}

		return new \WP_Error(
			'stonewright_elementor_write_busy',
			__( 'Another Elementor transaction is writing this post.', 'stonewright' ),
			[
				'status'          => 409,
				'retryable'       => true,
				'lock_expires_at' => is_array( $current ) ? (int) ( $current['expires_at'] ?? $now + 5 ) : $now + 5,
			]
		);
	}

	public static function release( int $post_id, string $owner ): bool {
		$key     = self::key( $post_id );
		$current = get_option( $key, [] );
		$owner   = sanitize_key( $owner );
		if ( ! is_array( $current ) || ! hash_equals( (string) ( $current['owner'] ?? '' ), $owner ) ) {
			return false;
		}

		return delete_option( $key );
	}

	private static function key( int $post_id ): string {
		return self::PREFIX . $post_id;
	}
}
