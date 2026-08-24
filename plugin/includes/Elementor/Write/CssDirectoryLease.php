<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Write;

/**
 * Short-lived shared lease for the Elementor CSS directory.
 *
 * The lease is deliberately keyed by a directory fingerprint rather than a
 * raw path. Paths never leave this process through an error or receipt.
 */
final class CssDirectoryLease {
	private const PREFIX = 'stonewright_elementor_css_lease_';

	/**
	 * @return array{key:string,scope:string,owner:string,acquired_at:int,expires_at:int,ttl:int}|\WP_Error
	 */
	public static function acquire( string $scope, string $owner, int $ttl = 30 ): array|\WP_Error {
		$scope = self::scope_hash( $scope );
		return self::acquire_hashed( $scope, $owner, $ttl );
	}

	/**
	 * Acquire a lease after the public scope has already been fingerprinted.
	 *
	 * Keeping retries on this path prevents an expired-lease takeover from
	 * hashing the fingerprint a second time.
	 *
	 * @return array{key:string,scope:string,owner:string,acquired_at:int,expires_at:int,ttl:int}|\WP_Error
	 */
	private static function acquire_hashed( string $scope, string $owner, int $ttl ): array|\WP_Error {
		$owner = sanitize_key( $owner );
		if ( '' === $scope || '' === $owner ) {
			return self::error( 'stonewright_elementor_css_lease_invalid', 'The Elementor CSS lease identity is invalid.', 400 );
		}

		$ttl  = self::bounded_ttl( $ttl );
		$now  = time();
		$key  = self::PREFIX . $scope;
		$lease = [
			'key'         => $key,
			'scope'       => $scope,
			'owner'       => $owner,
			'acquired_at' => $now,
			'expires_at'  => $now + $ttl,
			'ttl'         => $ttl,
		];
		$stored = [
			'scope'       => $scope,
			'owner'       => $owner,
			'acquired_at' => $lease['acquired_at'],
			'expires_at'  => $lease['expires_at'],
			'ttl'         => $ttl,
		];

		if ( add_option( $key, $stored, '', false ) ) {
			return $lease;
		}

		$current = get_option( $key, [] );
		if ( is_array( $current ) && (int) ( $current['expires_at'] ?? 0 ) <= $now ) {
			if ( self::delete_if_unchanged( $key, $current ) ) {
				return self::acquire_hashed( $scope, $owner, $ttl );
			}
			$current = get_option( $key, [] );
		}

		$expires_at  = is_array( $current ) ? (int) ( $current['expires_at'] ?? $now + 5 ) : $now + 5;
		$retry_after = max( 1, min( 120, $expires_at - $now ) );
		return self::error(
			'stonewright_elementor_css_lease_busy',
			'Another Elementor CSS transaction owns the shared asset lease.',
			409,
			[
				'retryable'          => true,
				'retry_after'        => $retry_after,
				'retry_after_seconds'=> $retry_after,
				'lease_fingerprint'  => hash( 'sha256', $scope . '|' . (string) ( is_array( $current ) ? ( $current['owner'] ?? '' ) : '' ) ),
			]
		);
	}

	/**
	 * Renew only the exact owner token that acquired the lease.
	 *
	 * @param array{key:string,scope:string,owner:string,acquired_at:int,expires_at:int,ttl:int} $lease
	 * @return array{key:string,scope:string,owner:string,acquired_at:int,expires_at:int,ttl:int}|\WP_Error
	 */
	public static function renew( array $lease, int $ttl = 0 ): array|\WP_Error {
		$key   = sanitize_key( (string) ( $lease['key'] ?? '' ) );
		$scope = sanitize_key( (string) ( $lease['scope'] ?? '' ) );
		$owner = sanitize_key( (string) ( $lease['owner'] ?? '' ) );
		if ( '' === $key || '' === $scope || '' === $owner || ! str_starts_with( $key, self::PREFIX ) ) {
			return self::error( 'stonewright_elementor_css_lease_invalid', 'The Elementor CSS lease identity is invalid.', 400 );
		}

		$current = get_option( $key, [] );
		$now     = time();
		if ( ! is_array( $current )
			|| ! hash_equals( $owner, (string) ( $current['owner'] ?? '' ) )
			|| ! hash_equals( $scope, (string) ( $current['scope'] ?? '' ) )
			|| (int) ( $current['expires_at'] ?? 0 ) <= $now ) {
			return self::error( 'stonewright_elementor_css_lease_lost', 'The Elementor CSS lease is no longer owned by this transaction.', 409 );
		}

		$ttl = self::bounded_ttl( $ttl > 0 ? $ttl : (int) ( $lease['ttl'] ?? 30 ) );
		$next = [
			'scope'       => $scope,
			'owner'       => $owner,
			'acquired_at' => (int) ( $current['acquired_at'] ?? $lease['acquired_at'] ?? $now ),
			'expires_at'  => $now + $ttl,
			'ttl'         => $ttl,
		];

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
		if ( 1 !== $updated ) {
			return self::error( 'stonewright_elementor_css_lease_lost', 'The Elementor CSS lease could not be renewed safely.', 409 );
		}

		return [
			'key'         => $key,
			'scope'       => $scope,
			'owner'       => $owner,
			'acquired_at' => $next['acquired_at'],
			'expires_at'  => $next['expires_at'],
			'ttl'         => $ttl,
		];
	}

	/**
	 * Release only the exact lease owner.
	 *
	 * @param array{key:string,scope:string,owner:string,acquired_at:int,expires_at:int,ttl:int} $lease
	 */
	public static function release( array $lease ): bool {
		$key   = sanitize_key( (string) ( $lease['key'] ?? '' ) );
		$owner = sanitize_key( (string) ( $lease['owner'] ?? '' ) );
		if ( '' === $key || '' === $owner ) {
			return false;
		}
		$current = get_option( $key, [] );
		if ( ! is_array( $current ) || ! hash_equals( $owner, (string) ( $current['owner'] ?? '' ) ) ) {
			return false;
		}

		return self::delete_if_unchanged( $key, $current );
	}

	public static function scope_hash( string $scope ): string {
		$scope = trim( $scope );
		return '' === $scope ? '' : substr( hash( 'sha256', 'elementor-css-dir|' . $scope ), 0, 40 );
	}

	private static function bounded_ttl( int $ttl ): int {
		return max( 5, min( 120, $ttl ) );
	}

	/** @param array<string,mixed> $observed */
	private static function delete_if_unchanged( string $key, array $observed ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$deleted = $wpdb->delete(
			$wpdb->options,
			[ 'option_name' => $key, 'option_value' => maybe_serialize( $observed ) ],
			[ '%s', '%s' ]
		);
		wp_cache_delete( $key, 'options' );
		return 1 === $deleted;
	}

	/** @param array<string,mixed> $extra */
	private static function error( string $code, string $message, int $status, array $extra = [] ): \WP_Error {
		return new \WP_Error( $code, $message, array_merge( [ 'status' => $status ], $extra ) );
	}
}
