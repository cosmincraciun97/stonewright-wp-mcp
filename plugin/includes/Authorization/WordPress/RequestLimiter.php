<?php
/**
 * Request limits for the OAuth endpoints.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\WordPress;

use Stonewright\WpMcp\Authorization\Decisions\AbuseBudget;
use Stonewright\WpMcp\Authorization\Ports\Clock;
use Stonewright\WpMcp\Support\Logger;

/**
 * Fixed-window limits per endpoint and requester over the rate_limits table.
 *
 * The bucket key is sha256 of the endpoint and a server-derived requester (the
 * connection address, or the signed-in user for the authorization page), so a client
 * cannot choose its bucket. An IPv6 address counts as its /64 prefix, the block a
 * network normally gives one subscriber, and an IPv4-mapped IPv6 address counts as the
 * IPv4 address it carries; changing address inside one block opens no new budget. IPv4
 * addresses and other requesters count as they are. AbuseBudget decides; the new
 * window is written with a compare-and-swap on the observed window and hit count, so
 * competing processes never admit more than the limit. Discovery documents are not
 * limited. When the table cannot be read or written the request is admitted and a
 * warning is logged: the limits protect the endpoints but must not take OAuth down
 * with them.
 */
final class RequestLimiter {

	/** Endpoint => [maximum requests, window seconds]. */
	public const LIMITS = [
		'authorization' => [ 30, 600 ],
		'token'         => [ 30, 60 ],
		'registration'  => [ 20, 3600 ],
		'revocation'    => [ 30, 60 ],
		'introspection' => [ 60, 60 ],
	];

	/** Filter for one endpoint's [maximum requests, window seconds]. */
	public const FILTER = 'stonewright_oauth_rate_limit';

	private const ATTEMPTS = 4;

	/** @param array<string, array{0: int, 1: int}> $limits */
	public function __construct( private Database $db, private Clock $clock, private array $limits = self::LIMITS ) {}

	public static function bucket( string $endpoint, string $requester ): string {
		return hash( 'sha256', 'stonewright-oauth:rate:' . $endpoint . "\n" . self::counted( $requester ) );
	}

	/** @return array{allowed: bool, retry_after: int} */
	public function admit( string $endpoint, string $requester ): array {
		$limit = $this->limit( $endpoint );
		if ( null === $limit ) {
			return [ 'allowed' => true, 'retry_after' => 0 ];
		}
		[ $maximum, $window_seconds ] = $limit;
		$budget = new AbuseBudget( $maximum, $window_seconds );
		$bucket = self::bucket( $endpoint, '' === $requester ? 'unknown' : $requester );
		$table = $this->db->table( 'rate_limits' );
		for ( $attempt = 0; $attempt < self::ATTEMPTS; ++$attempt ) {
			$now = $this->clock->now();
			$row = $this->db->row( "SELECT window_started, hits FROM {$table} WHERE bucket_key = %s LIMIT 1", [ $bucket ] );
			$window = null === $row ? null : [
				'started_at' => min( max( 0, (int) $row['window_started'] ), PHP_INT_MAX - $window_seconds ),
				'count'      => min( max( 0, (int) $row['hits'] ), $maximum ),
			];
			$decision = $budget->admit( $window, $now );
			if ( ! $decision['allowed'] ) {
				return [ 'allowed' => false, 'retry_after' => max( 1, (int) $decision['retry_after'] ) ];
			}
			$next = $decision['window'];
			if ( null === $row ) {
				$stored = $this->db->insert(
					$table,
					[
						'bucket_key'     => $bucket,
						'window_started' => $next['started_at'],
						'hits'           => $next['count'],
						'updated_at'     => Database::datetime( $now ),
					]
				);
			} else {
				$stored = 1 === $this->db->claim(
					"UPDATE {$table} SET window_started = %d, hits = %d, updated_at = %s WHERE bucket_key = %s AND window_started = %d AND hits = %d",
					[ $next['started_at'], $next['count'], Database::datetime( $now ), $bucket, (int) $row['window_started'], (int) $row['hits'] ]
				);
			}
			if ( $stored ) {
				return [ 'allowed' => true, 'retry_after' => 0 ];
			}
		}
		Logger::warning( 'oauth_rate_limit_unavailable', [ 'endpoint' => $endpoint ] );
		return [ 'allowed' => true, 'retry_after' => 0 ];
	}

	/** What a requester is counted as: its /64 prefix, the IPv4 address inside a mapped one, or itself. */
	private static function counted( string $requester ): string {
		if ( false === filter_var( $requester, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return $requester;
		}
		$packed = inet_pton( $requester );
		if ( false === $packed || 16 !== strlen( $packed ) ) {
			return $requester;
		}
		if ( str_starts_with( $packed, str_repeat( "\0", 10 ) . "\xff\xff" ) ) {
			return (string) inet_ntop( substr( $packed, 12 ) );
		}
		return (string) inet_ntop( substr( $packed, 0, 8 ) . str_repeat( "\0", 8 ) ) . '/64';
	}

	/** @return array{0: int, 1: int}|null */
	private function limit( string $endpoint ): ?array {
		if ( ! isset( $this->limits[ $endpoint ] ) ) {
			return null;
		}
		$limit = apply_filters( self::FILTER, $this->limits[ $endpoint ], $endpoint );
		if ( ! is_array( $limit ) || ! isset( $limit[0], $limit[1] ) || ! is_numeric( $limit[0] ) || ! is_numeric( $limit[1] ) ) {
			$limit = $this->limits[ $endpoint ];
		}
		return [ max( 1, (int) $limit[0] ), max( 1, min( 86400, (int) $limit[1] ) ) ];
	}
}
