<?php
/**
 * Refresh credential lifetimes and duplicate-delivery window.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\Refresh;

/**
 * Fixed lifetimes plus one bounded duplicate window. The WordPress layer supplies the
 * window value; this object only clamps it. A zero window means strict single-use rotation.
 */
final class RefreshPolicy {

	public const ACCESS_LIFETIME = 3600;
	public const REFRESH_IDLE_LIFETIME = 2592000;
	public const FAMILY_LIFETIME = 7776000;
	public const DEFAULT_DUPLICATE_WINDOW = 60;
	public const MAXIMUM_DUPLICATE_WINDOW = 300;

	private int $duplicate_window;

	public function __construct( int $duplicate_window = self::DEFAULT_DUPLICATE_WINDOW ) {
		$this->duplicate_window = max( 0, min( self::MAXIMUM_DUPLICATE_WINDOW, $duplicate_window ) );
	}

	public function duplicate_window(): int {
		return $this->duplicate_window;
	}

	/** Whether every deadline derived from this time stays representable. */
	public function accepts_time( int $now ): bool {
		return $now >= 1 && $now <= PHP_INT_MAX - self::FAMILY_LIFETIME;
	}

	/**
	 * A new family can never outlive its origin plus the family lifetime.
	 *
	 * @throws \InvalidArgumentException When the time is not representable.
	 */
	public function family_deadline( int $origin ): int {
		$this->require_time( $origin );
		return $origin + self::FAMILY_LIFETIME;
	}

	/**
	 * Access credentials always receive the full access lifetime.
	 *
	 * @throws \InvalidArgumentException When the time is not representable.
	 */
	public function access_deadline( int $now ): int {
		$this->require_time( $now );
		return $now + self::ACCESS_LIFETIME;
	}

	/**
	 * Each refresh credential expires after the idle lifetime, but never after its family.
	 *
	 * @throws \InvalidArgumentException When the time is not representable.
	 */
	public function refresh_deadline( int $now, int $family_deadline ): int {
		$this->require_time( $now );
		return min( $now + self::REFRESH_IDLE_LIFETIME, $family_deadline );
	}

	/**
	 * Strict comparison: at exactly consumed_at + window the presentation is outside.
	 * A clock that moved backwards counts as no elapsed time.
	 */
	public function within_duplicate_window( int $consumed_at, int $now ): bool {
		return max( 0, $now - $consumed_at ) < $this->duplicate_window;
	}

	/** @throws \InvalidArgumentException When the time is not representable. */
	private function require_time( int $now ): void {
		if ( ! $this->accepts_time( $now ) ) {
			throw new \InvalidArgumentException( 'Unsupported credential time.' );
		}
	}
}
