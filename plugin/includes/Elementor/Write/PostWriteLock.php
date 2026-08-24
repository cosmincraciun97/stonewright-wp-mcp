<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Write;

/**
 * Short-lived per-post lease that prevents concurrent Elementor writes.
 */
final class PostWriteLock {
	private const PREFIX = 'stonewright_elementor_lock_';

	/**
	 * @return array{post_id:int,owner:string,expires_at:int,acquired_at:int}|\WP_Error
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
			'acquired_at'=> $now,
			'expires_at' => $now + max( 5, min( 120, $ttl ) ),
		];
		if ( add_option( $key, $lease, '', false ) ) {
			return $lease;
		}

		$current = get_option( $key, [] );
		if ( is_array( $current ) && (int) ( $current['expires_at'] ?? 0 ) <= $now ) {
			if ( self::delete_if_unchanged( $key, $current ) ) {
				return self::acquire( $post_id, $owner, $ttl );
			}
			$current = get_option( $key, [] );
		}

		$expires_at = is_array( $current ) ? (int) ( $current['expires_at'] ?? $now + 5 ) : $now + 5;
		$acquired_at = is_array( $current ) ? (int) ( $current['acquired_at'] ?? max( 0, $expires_at - 30 ) ) : $now;
		$retry_after = max( 1, min( 120, $expires_at - $now ) );
		$fingerprint = hash( 'sha256', 'elementor-lock|' . $post_id . '|' . (string) ( is_array( $current ) ? ( $current['owner'] ?? '' ) : '' ) );
		return new \WP_Error(
			'stonewright_elementor_write_busy',
			__( 'Another Elementor transaction is writing this post.', 'stonewright' ),
			[
				'status'          => 409,
				'retryable'       => true,
				'lock_expires_at' => $expires_at,
				'lock_age_seconds' => max( 0, $now - $acquired_at ),
				'lock_fingerprint' => $fingerprint,
				'retry_after'      => $retry_after,
				'retry_after_seconds' => $retry_after,
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

		return self::delete_if_unchanged( $key, $current );
	}

	public static function owned_by( int $post_id, string $owner ): bool {
		$current = get_option( self::key( $post_id ), [] );
		$owner   = sanitize_key( $owner );
		return is_array( $current )
			&& '' !== $owner
			&& (int) ( $current['expires_at'] ?? 0 ) > time()
			&& hash_equals( (string) ( $current['owner'] ?? '' ), $owner );
	}

	/**
	 * Renew the exact owner lease with a compare-and-swap update.
	 *
	 * A WordPress options CAS miss is not proof of ownership loss: `$wpdb->update`
	 * reports changed rows, so a same-second identical payload or a serialization
	 * mismatch can return 0 while this writer still holds a live lease.
	 *
	 * @param array{post_id:int,owner:string,expires_at:int,acquired_at:int} $lease
	 * @return array{post_id:int,owner:string,expires_at:int,acquired_at:int}|\WP_Error
	 */
	public static function renew( array $lease, int $ttl = 30 ): array|\WP_Error {
		$post_id = (int) ( $lease['post_id'] ?? 0 );
		$owner   = sanitize_key( (string) ( $lease['owner'] ?? '' ) );
		if ( $post_id < 1 || '' === $owner ) {
			return new \WP_Error(
				'stonewright_elementor_lock_invalid',
				__( 'Elementor write locks require a post and owner.', 'stonewright' ),
				[ 'status' => 400 ]
			);
		}

		$key     = self::key( $post_id );
		$current = get_option( $key, [] );
		$now     = time();
		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			if ( $attempt > 0 ) {
				wp_cache_delete( $key, 'options' );
				$current = get_option( $key, [] );
				$now     = time();
			}
			if ( ! self::is_live_owner( $current, $owner, $now ) ) {
				return self::lock_lost();
			}

			$next = self::next_lease( $post_id, $owner, $current, $lease, $now, $ttl );
			if ( self::cas_replace( $key, $current, $next ) ) {
				return $next;
			}
		}

		wp_cache_delete( $key, 'options' );
		$observed = get_option( $key, [] );
		$now      = time();
		if ( self::is_live_owner( $observed, $owner, $now ) ) {
			return [
				'post_id'     => $post_id,
				'owner'       => $owner,
				'acquired_at' => (int) ( $observed['acquired_at'] ?? $lease['acquired_at'] ?? $now ),
				'expires_at'  => (int) ( $observed['expires_at'] ?? 0 ),
			];
		}

		return self::lock_lost();
	}

	private static function key( int $post_id ): string {
		return self::PREFIX . $post_id;
	}

	/**
	 * @param mixed $current
	 */
	private static function is_live_owner( mixed $current, string $owner, int $now ): bool {
		return is_array( $current )
			&& '' !== $owner
			&& hash_equals( $owner, (string) ( $current['owner'] ?? '' ) )
			&& (int) ( $current['expires_at'] ?? 0 ) > $now;
	}

	/**
	 * @param array<string, mixed> $current
	 * @param array<string, mixed> $lease
	 * @return array{post_id:int,owner:string,expires_at:int,acquired_at:int}
	 */
	private static function next_lease( int $post_id, string $owner, array $current, array $lease, int $now, int $ttl ): array {
		$ttl_expires = $now + max( 5, min( 120, $ttl ) );
		return [
			'post_id'     => $post_id,
			'owner'       => $owner,
			'acquired_at' => (int) ( $current['acquired_at'] ?? $lease['acquired_at'] ?? $now ),
			'expires_at'  => max( $ttl_expires, (int) ( $current['expires_at'] ?? 0 ) + 1 ),
		];
	}

	private static function lock_lost(): \WP_Error {
		return new \WP_Error(
			'stonewright_elementor_lock_lost',
			__( 'The Elementor write lock is no longer owned by this transaction.', 'stonewright' ),
			[ 'status' => 409 ]
		);
	}

	/**
	 * @param array<string, mixed> $current
	 * @param array<string, mixed> $next
	 */
	private static function cas_replace( string $key, array $current, array $next ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$updated = $wpdb->update(
			$wpdb->options,
			[ 'option_value' => maybe_serialize( $next ) ],
			[ 'option_name' => $key, 'option_value' => maybe_serialize( $current ) ],
			[ '%s' ],
			[ '%s', '%s' ]
		);
		wp_cache_delete( $key, 'options' );

		return 1 === $updated;
	}

	/** @param array<string, mixed> $observed */
	private static function delete_if_unchanged( string $key, array $observed ): bool {
		global $wpdb;
		// The option name and its exact serialized lease form a compare-and-delete
		// guard, so an expired observer cannot remove a newer owner's live lease.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$deleted = $wpdb->delete(
			$wpdb->options,
			[
				'option_name'  => $key,
				'option_value' => maybe_serialize( $observed ),
			],
			[ '%s', '%s' ]
		);
		wp_cache_delete( $key, 'options' );

		return 1 === $deleted;
	}
}
