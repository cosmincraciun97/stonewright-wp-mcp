<?php
/**
 * What the user, comment, media, catalog, site, memory and revision-link families share when they write to
 * the change ledger.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Security\Adapters;

use Stonewright\WpMcp\Security\ChangeLedger;
use Stonewright\WpMcp\Support\Logger;

/**
 * Recording and settling a row, the check that the ledger table is installed, and the outcome of an ability
 * result. Every function swallows its own failure: a ledger that cannot record never changes a write.
 */
final class FamilyLedger {

	/** Start of the summary of a row that created its resource. */
	public const CREATED_SUMMARY = 'Created ';

	/** @var bool|null Whether the ledger table is usable; only a yes is remembered. */
	private static ?bool $ready = null;

	/** @var int Depth of the restores that are running. */
	private static int $restoring = 0;

	public static function reset_for_tests(): void {
		self::$ready     = null;
		self::$restoring = 0;
	}

	public static function ready(): bool {
		if ( true === self::$ready ) {
			return true;
		}
		try {
			if ( ChangeLedger::table_schema_ok() ) {
				self::$ready = true;
				return true;
			}
		} catch ( \Throwable $failure ) {
			Logger::warning( 'change_ledger_unavailable', [ 'error' => $failure::class ] );
		}
		return false;
	}

	/** Whether a restore is running: the writes it makes are described by its own rollback row. */
	public static function restoring(): bool {
		return self::$restoring > 0;
	}

	/**
	 * Run a restore. The hooks of the writers it calls record nothing while it runs.
	 *
	 * @template T
	 * @param callable():T $run
	 * @return T
	 */
	public static function while_restoring( callable $run ): mixed {
		++self::$restoring;
		try {
			return $run();
		} finally {
			--self::$restoring;
		}
	}

	/**
	 * Record a change. Spec keys are those of ChangeLedger::record().
	 *
	 * @param array<string, mixed> $spec
	 * @return string|null The change id, or null when nothing was recorded.
	 */
	public static function record( array $spec ): ?string {
		try {
			$row = ChangeLedger::record( $spec );
			if ( $row instanceof \WP_Error ) {
				Logger::warning( 'change_ledger_record_failed', [ 'code' => $row->get_error_code(), 'ability' => (string) ( $spec['ability'] ?? '' ) ] );
				return null;
			}
			return (string) $row['change_id'];
		} catch ( \Throwable $failure ) {
			Logger::warning( 'change_ledger_record_failed', [ 'error' => $failure::class, 'ability' => (string) ( $spec['ability'] ?? '' ) ] );
			return null;
		}
	}

	/**
	 * Close a recorded change with its status and the image the write produced.
	 *
	 * @param mixed $after The image, or null when the resource is gone or has none.
	 */
	public static function settle( ?string $change_id, string $status, mixed $after = null ): void {
		if ( null === $change_id ) {
			return;
		}
		try {
			$result = [ 'status' => $status ];
			if ( null !== $after ) {
				$result['after'] = $after;
			}
			$row = ChangeLedger::settle( $change_id, $result );
			if ( $row instanceof \WP_Error ) {
				Logger::warning( 'change_ledger_settle_failed', [ 'code' => $row->get_error_code() ] );
			}
		} catch ( \Throwable $failure ) {
			Logger::warning( 'change_ledger_settle_failed', [ 'error' => $failure::class ] );
		}
	}

	/**
	 * Whether an ability result reports success: it is not an error, and it does not say ok is false.
	 */
	public static function succeeded( mixed $result ): bool {
		if ( $result instanceof \WP_Error ) {
			return false;
		}
		return ! is_array( $result ) || false !== ( $result['ok'] ?? true );
	}

	/** Whether a ledger row is the creation of its resource: no before image, and an undo that removes it. */
	public static function is_created_row( array $row ): bool {
		return true === ( $row['restorable'] ?? false )
			&& '' === (string) ( $row['before_ref'] ?? 'x' )
			&& '' === (string) ( $row['before_sha256'] ?? 'x' )
			&& str_starts_with( (string) ( $row['summary'] ?? '' ), self::CREATED_SUMMARY );
	}

	/** A short text of $max characters at most. */
	public static function clip( string $text, int $max ): string {
		$text = trim( (string) preg_replace( '/\s+/', ' ', $text ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max ) : substr( $text, 0, $max );
	}
}
