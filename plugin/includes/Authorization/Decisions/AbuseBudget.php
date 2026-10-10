<?php
/**
 * Injected fixed-window admission policy.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\Decisions;

/** Call within an adapter's atomic bucket mutation; never trust a request's bucket identity. */
final class AbuseBudget {

	public function __construct( private int $maximum_requests, private int $window_seconds ) {
		if ( $maximum_requests < 1 || $window_seconds < 1 ) {
			throw new \InvalidArgumentException( 'Invalid admission limits.' );
		}
	}

	public function admit( ?array $window, int $now ): array {
		if ( $now < 0 || $now > PHP_INT_MAX - $this->window_seconds ) {
			throw new \InvalidArgumentException( 'Invalid admission time.' );
		}
		if ( null !== $window && ( ! isset( $window['started_at'], $window['count'] ) || ! is_int( $window['started_at'] ) || ! is_int( $window['count'] ) || $window['started_at'] < 0 || $window['started_at'] > PHP_INT_MAX - $this->window_seconds || $window['count'] < 0 || $window['count'] > $this->maximum_requests ) ) {
			throw new \InvalidArgumentException( 'Invalid admission window.' );
		}
		if ( null === $window || $now >= $window['started_at'] + $this->window_seconds ) {
			$window = [ 'started_at' => $now, 'count' => 0 ];
		}
		$allowed = $window['count'] < $this->maximum_requests;
		if ( $allowed ) {
			++$window['count'];
		}
		return [ 'allowed' => $allowed, 'retry_after' => $allowed ? 0 : min( $this->window_seconds, $window['started_at'] + $this->window_seconds - $now ), 'window' => $window ];
	}
}
