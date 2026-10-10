<?php
/**
 * The claim that lets one rollback of a resource run at a time.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Security\Rollback;

/**
 * A claim is a row of the options table whose name is built from the resource, taken with a plain INSERT: the
 * unique key on the option name lets exactly one of two callers (a double click, the page and an agent) in. A claim
 * older than TTL seconds is stale: it is replaced by deleting that exact row, which only one caller can do. The claim
 * covers the resource, so a rollback of the change and a redo of its rollback cannot overlap either.
 */
final class RollbackClaim {

	/** Seconds after which a claim is taken to belong to a request that died. */
	public const TTL = 300;

	private const PREFIX = 'stonewright_rb_claim_';

	/** @param array<string, mixed> $row */
	public static function name_for( array $row ): string {
		return self::PREFIX . substr( sha1( implode( '|', [ (string) ( $row['family'] ?? '' ), (string) ( $row['resource_type'] ?? '' ), (string) ( $row['resource_id'] ?? '' ) ] ) ), 0, 32 );
	}

	/** @param array<string, mixed> $row */
	public static function take( array $row ): bool {
		global $wpdb;
		/** @var \wpdb|null $wpdb */
		if ( ! $wpdb instanceof \wpdb ) {
			return false;
		}
		$name = self::name_for( $row );
		if ( self::insert( $name ) ) {
			return true;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- The options table name is core's; the name is prepared.
		$held = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		if ( null === $held ) {
			return self::insert( $name );
		}
		$held = (string) $held;
		if ( ctype_digit( $held ) && time() - (int) $held >= self::TTL ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A claim is not an option anyone reads.
			$deleted = $wpdb->delete( $wpdb->options, [ 'option_name' => $name, 'option_value' => $held ] );
			if ( 1 !== (int) $deleted ) {
				return false;
			}
			return self::insert( $name );
		}
		return false;
	}

	/** @param array<string, mixed> $row */
	public static function release( array $row ): void {
		global $wpdb;
		/** @var \wpdb|null $wpdb */
		if ( ! $wpdb instanceof \wpdb ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A claim is not an option anyone reads.
		$wpdb->delete( $wpdb->options, [ 'option_name' => self::name_for( $row ) ] );
	}

	private static function insert( string $name ): bool {
		global $wpdb;
		/** @var \wpdb|null $wpdb */
		if ( ! $wpdb instanceof \wpdb ) {
			return false;
		}
		$quiet = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : null;
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A plain INSERT is the atomic step; add_option() is not.
			$inserted = $wpdb->insert( $wpdb->options, [ 'option_name' => $name, 'option_value' => (string) time(), 'autoload' => 'no' ] );
		} finally {
			if ( null !== $quiet ) {
				$wpdb->suppress_errors( (bool) $quiet );
			}
		}
		return 1 === (int) $inserted;
	}
}
