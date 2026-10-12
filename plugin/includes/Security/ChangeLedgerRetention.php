<?php
/**
 * Retention of the change ledger: how long, how many and how much it keeps.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

/**
 * A daily event deletes the oldest changes by three limits, and the first one reached decides:
 * 90 days, 500 changes and 100 MB of blobs by default. A site changes them with the options
 * stonewright_change_ledger_days, stonewright_change_ledger_max_changes and
 * stonewright_change_ledger_max_bytes, or with the filters stonewright_change_ledger_retention_days,
 * stonewright_change_ledger_retention_max_changes and stonewright_change_ledger_retention_max_bytes,
 * which receive the value the option gives. A value that is not a positive number goes back to the
 * default, and each limit has a ceiling, so a setting cannot turn retention off or delete everything.
 *
 * What a run never deletes:
 * - an open change (armed, incident or rollback_failed), whatever its age. Open changes are not
 *   counted against the count limit;
 * - a change that has a child row that stays, so that a rollback or redo is never left without its
 *   parent. A chain kept this way may leave the count a little above its limit;
 * - a blob that another row still uses as its before or after image. Rows that share an image share
 *   one blob, and the blob goes with the last of them;
 * - a blob file that is newer than the grace period (five minutes), because a caller that stored it may
 *   be about to reference it. Blobs that no row references and that are older than that are removed,
 *   which also clears what a failed insert left.
 *
 * A run that deletes something writes one short audit row, and every run leaves a receipt in the
 * stonewright_change_ledger_prune_receipt option.
 *
 * dry_run() and forecast() run the same plan over the same rows and delete nothing, write no receipt and no audit
 * row; the Changes page and Setup show what they return.
 */
final class ChangeLedgerRetention {

	public const HOOK = 'stonewright_change_ledger_prune';

	public const DAYS_OPTION = 'stonewright_change_ledger_days';

	public const MAX_CHANGES_OPTION = 'stonewright_change_ledger_max_changes';

	public const MAX_BYTES_OPTION = 'stonewright_change_ledger_max_bytes';

	public const RECEIPT_OPTION = 'stonewright_change_ledger_prune_receipt';

	public const DEFAULT_DAYS = 90;

	public const MAX_DAYS = 3650;

	public const DEFAULT_MAX_CHANGES = 500;

	public const MAX_MAX_CHANGES = 100000;

	public const DEFAULT_MAX_BYTES = 104857600;

	public const MAX_MAX_BYTES = 10737418240;

	/** Seconds a blob file is left alone after it was written or found again. */
	public const BLOB_GRACE_SECONDS = 300;

	/** The share of the count or size limit from which the history warns that the oldest changes will go. */
	public const WARN_PERCENT = 80;

	/** Days ahead the history looks for finished changes that will pass the age limit. */
	public const WARN_DAYS = 7;

	/** Rows one run reads. Older rows come first, so a larger table is worked down over several runs. */
	private const MAX_ROWS_READ = 120000;

	private const DELETE_BATCH = 200;

	public static function days(): int {
		return self::limit( self::DAYS_OPTION, 'stonewright_change_ledger_retention_days', self::DEFAULT_DAYS, self::MAX_DAYS );
	}

	public static function max_changes(): int {
		return self::limit( self::MAX_CHANGES_OPTION, 'stonewright_change_ledger_retention_max_changes', self::DEFAULT_MAX_CHANGES, self::MAX_MAX_CHANGES );
	}

	public static function max_bytes(): int {
		return self::limit( self::MAX_BYTES_OPTION, 'stonewright_change_ledger_retention_max_bytes', self::DEFAULT_MAX_BYTES, self::MAX_MAX_BYTES );
	}

	/** The days the option gives, corrected to the bounds, before any filter. The settings form shows this one. */
	public static function stored_days(): int {
		return self::bounded( get_option( self::DAYS_OPTION, self::DEFAULT_DAYS ), self::DEFAULT_DAYS, self::MAX_DAYS );
	}

	/** The count the option gives, corrected to the bounds, before any filter. */
	public static function stored_max_changes(): int {
		return self::bounded( get_option( self::MAX_CHANGES_OPTION, self::DEFAULT_MAX_CHANGES ), self::DEFAULT_MAX_CHANGES, self::MAX_MAX_CHANGES );
	}

	/** The bytes the option gives, corrected to the bounds, before any filter. */
	public static function stored_max_bytes(): int {
		return self::bounded( get_option( self::MAX_BYTES_OPTION, self::DEFAULT_MAX_BYTES ), self::DEFAULT_MAX_BYTES, self::MAX_MAX_BYTES );
	}

	/**
	 * The receipt of the last cleanup, or null when none has run yet.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function last_receipt(): ?array {
		$receipt = get_option( self::RECEIPT_OPTION, null );
		return is_array( $receipt ) && is_string( $receipt['run_at'] ?? null ) && false !== strtotime( $receipt['run_at'] ) ? $receipt : null;
	}

	/**
	 * What a cleanup at $now would remove, and what the ledger holds. Read-only: it runs the same plan as
	 * prune() over the same rows, deletes nothing, and writes no receipt and no audit row.
	 *
	 * @return array{available: bool, run_at: string, days: int, max_changes: int, max_bytes: int, would_remove: int, ids: list<string>, finished: int, open: int, stored_bytes: int}
	 */
	public static function dry_run( ?int $now = null ): array {
		$now  = $now ?? ChangeJournal::now();
		$rows = self::load_rows();
		return self::summary( $rows, $now );
	}

	/**
	 * Whether to warn that the history is about to lose changes, and what to say: how many finished changes
	 * the next cleanups remove and by which date (a Unix time of a daily cleanup), or only that a limit is
	 * close. It warns when the finished changes or the stored copies are at or over 80% of their limit, or
	 * when finished changes pass the age limit within the next 7 days. The numbers come from the same plan
	 * the pruner runs, so what is shown is what would be removed.
	 *
	 * @return array{show: bool, reasons: list<string>, would_remove: int, by: int|null, changes_percent: int, bytes_percent: int, days: int, max_changes: int, max_bytes: int}
	 */
	public static function forecast( ?int $now = null ): array {
		$now     = $now ?? ChangeJournal::now();
		$rows    = self::load_rows();
		$today   = self::summary( $rows, $now );
		$result  = [
			'show'            => false,
			'reasons'         => [],
			'would_remove'    => 0,
			'by'              => null,
			'changes_percent' => 0,
			'bytes_percent'   => 0,
			'days'            => $today['days'],
			'max_changes'     => $today['max_changes'],
			'max_bytes'       => $today['max_bytes'],
		];
		if ( ! $today['available'] ) {
			return $result;
		}

		$result['changes_percent'] = intdiv( $today['finished'] * 100, $today['max_changes'] );
		$result['bytes_percent']   = intdiv( $today['stored_bytes'] * 100, $today['max_bytes'] );
		if ( $today['finished'] * 100 >= self::WARN_PERCENT * $today['max_changes'] ) {
			$result['reasons'][] = 'changes';
		}
		if ( $today['stored_bytes'] * 100 >= self::WARN_PERCENT * $today['max_bytes'] ) {
			$result['reasons'][] = 'bytes';
		}

		$horizon  = $now + self::WARN_DAYS * DAY_IN_SECONDS;
		$coming   = self::summary( $rows, $horizon );
		$due_now  = array_flip( $today['ids'] );
		$created  = [];
		foreach ( $rows as $row ) {
			$created[ (string) ( $row['change_id'] ?? '' ) ] = (string) ( $row['created_at'] ?? '' );
		}
		$last_expiry = $now;
		$by_age      = false;
		foreach ( $coming['ids'] as $id ) {
			if ( isset( $due_now[ $id ] ) ) {
				continue;
			}
			$stamp  = strtotime( ( $created[ $id ] ?? '' ) . ' UTC' );
			$expiry = false === $stamp ? $now : $stamp + $today['days'] * DAY_IN_SECONDS;
			if ( $expiry <= $horizon && $expiry > $now ) {
				$by_age      = true;
				$last_expiry = max( $last_expiry, $expiry );
			}
		}
		if ( $by_age ) {
			$result['reasons'][] = 'age';
		}
		if ( [] === $coming['ids'] && [] === $today['ids'] ) {
			$result['show'] = [] !== $result['reasons'];
			return $result;
		}

		$next = (int) wp_next_scheduled( self::HOOK );
		$run  = $next >= $now ? $next : $now;
		while ( $run < $last_expiry ) {
			$run += DAY_IN_SECONDS;
		}
		$result['would_remove'] = count( $coming['ids'] );
		$result['by']           = $run;
		$result['show']         = true;
		if ( [] !== $today['ids'] && ! in_array( 'changes', $result['reasons'], true ) && ! in_array( 'bytes', $result['reasons'], true ) ) {
			$result['reasons'][] = 'due';
		}
		return $result;
	}

	/**
	 * Delete what the three limits say, then the blobs nothing uses.
	 *
	 * @return array<string, mixed> The receipt: status (completed, failed or unavailable), run_at, the
	 *                              limits, deleted_rows, deleted_blobs, freed_bytes, remaining_rows and
	 *                              remaining_bytes.
	 */
	public static function prune( ?int $now = null ): array {
		$now         = $now ?? ChangeJournal::now();
		$days        = self::days();
		$max_changes = self::max_changes();
		$max_bytes   = self::max_bytes();
		$receipt     = [
			'status'          => 'completed',
			'run_at'          => gmdate( 'c', $now ),
			'days'            => $days,
			'max_changes'     => $max_changes,
			'max_bytes'       => $max_bytes,
			'deleted_rows'    => 0,
			'deleted_blobs'   => 0,
			'freed_bytes'     => 0,
			'remaining_rows'  => 0,
			'remaining_bytes' => 0,
		];

		global $wpdb;
		if ( ! self::database_ready() ) {
			$receipt['status'] = 'unavailable';
			return $receipt;
		}
		$table = ChangeLedger::table_name();
		$store = ChangeLedger::blob_store();
		$rows  = self::load_rows();

		$doomed = self::plan( $rows, gmdate( 'Y-m-d H:i:s', $now - $days * DAY_IN_SECONDS ), $max_changes, $max_bytes, $store );
		foreach ( array_chunk( $doomed, self::DELETE_BATCH ) as $batch ) {
			$placeholders = implode( ', ', array_fill( 0, count( $batch ), '%s' ) );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Internal table name, generated placeholders, prepared values.
			$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE change_id IN ({$placeholders})", ...$batch ) );
			// phpcs:enable
			if ( false === $deleted ) {
				$receipt['status'] = 'failed';
				break;
			}
			$receipt['deleted_rows'] += max( 0, (int) $deleted );
		}

		if ( null !== $store && 'failed' !== $receipt['status'] ) {
			$swept                    = $store->sweep( self::referenced_blobs(), self::grace() );
			$receipt['deleted_blobs'] = $swept['deleted'];
			$receipt['freed_bytes']   = $swept['freed'];
		}
		$receipt['remaining_rows']  = max( 0, count( $rows ) - $receipt['deleted_rows'] );
		$receipt['remaining_bytes'] = null === $store ? 0 : $store->total_bytes();

		update_option( self::RECEIPT_OPTION, $receipt, false );
		if ( $receipt['deleted_rows'] > 0 || $receipt['deleted_blobs'] > 0 ) {
			self::audit( $receipt );
		}
		return $receipt;
	}

	/** Keep the daily event scheduled. */
	public static function sync_schedule( mixed $now = null ): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			$timestamp = is_int( $now ) ? $now : time();
			wp_schedule_event( $timestamp + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	public static function run_scheduled(): void {
		self::prune();
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	private static function database_ready(): bool {
		global $wpdb;
		return is_object( $wpdb ) && method_exists( $wpdb, 'prepare' ) && method_exists( $wpdb, 'query' ) && method_exists( $wpdb, 'get_results' );
	}

	/**
	 * The rows a run reads, oldest first, at most MAX_ROWS_READ of them. prune() and the dry run read through
	 * this one function, so they plan over the same rows.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function load_rows(): array {
		global $wpdb;
		if ( ! self::database_ready() ) {
			return [];
		}
		$table = ChangeLedger::table_name();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table; every value is prepared.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT change_id, parent_id, status, created_at, before_ref, after_ref FROM {$table} ORDER BY created_at ASC, id ASC LIMIT %d", self::MAX_ROWS_READ ),
			ARRAY_A
		);
		// phpcs:enable
		return is_array( $rows ) ? array_values( array_filter( $rows, 'is_array' ) ) : [];
	}

	/**
	 * The plan for a run at $now over $rows, and what the ledger holds. Reads only.
	 *
	 * @param list<array<string, mixed>> $rows Every row, oldest first.
	 * @return array{available: bool, run_at: string, days: int, max_changes: int, max_bytes: int, would_remove: int, ids: list<string>, finished: int, open: int, stored_bytes: int}
	 */
	private static function summary( array $rows, int $now ): array {
		$days        = self::days();
		$max_changes = self::max_changes();
		$max_bytes   = self::max_bytes();
		$summary     = [
			'available'    => self::database_ready(),
			'run_at'       => gmdate( 'c', $now ),
			'days'         => $days,
			'max_changes'  => $max_changes,
			'max_bytes'    => $max_bytes,
			'would_remove' => 0,
			'ids'          => [],
			'finished'     => 0,
			'open'         => 0,
			'stored_bytes' => 0,
		];
		if ( ! $summary['available'] ) {
			return $summary;
		}

		$store = ChangeLedger::blob_store();
		$open  = array_flip( ChangeLedger::OPEN_STATUSES );
		$sizes = [];
		foreach ( $rows as $row ) {
			if ( isset( $open[ (string) ( $row['status'] ?? '' ) ] ) ) {
				++$summary['open'];
			} else {
				++$summary['finished'];
			}
			if ( null === $store ) {
				continue;
			}
			foreach ( [ 'before_ref', 'after_ref' ] as $column ) {
				$hash = (string) ( $row[ $column ] ?? '' );
				if ( BlobStore::is_valid_hash( $hash ) && ! isset( $sizes[ $hash ] ) ) {
					$sizes[ $hash ] = $store->stored_size( $hash );
				}
			}
		}
		$summary['stored_bytes'] = (int) array_sum( $sizes );
		$summary['ids']          = self::plan( $rows, gmdate( 'Y-m-d H:i:s', $now - $days * DAY_IN_SECONDS ), $max_changes, $max_bytes, $store );
		$summary['would_remove'] = count( $summary['ids'] );
		return $summary;
	}

	/**
	 * The change ids to delete.
	 *
	 * @param list<array<string, mixed>> $rows Every row, oldest first.
	 * @return list<string>
	 */
	private static function plan( array $rows, string $cutoff, int $max_changes, int $max_bytes, ?BlobStore $store ): array {
		$open     = array_flip( ChangeLedger::OPEN_STATUSES );
		$children = [];
		foreach ( $rows as $row ) {
			$parent = (string) ( $row['parent_id'] ?? '' );
			if ( '' !== $parent ) {
				$children[ $parent ][] = (string) $row['change_id'];
			}
		}

		$selected = [];
		foreach ( $rows as $row ) {
			if ( ! isset( $open[ (string) ( $row['status'] ?? '' ) ] ) && strcmp( (string) ( $row['created_at'] ?? '' ), $cutoff ) < 0 ) {
				$selected[ (string) $row['change_id'] ] = true;
			}
		}

		$prunable = array_values(
			array_filter(
				$rows,
				static fn ( array $row ): bool => ! isset( $open[ (string) ( $row['status'] ?? '' ) ] ) && ! isset( $selected[ (string) $row['change_id'] ] )
			)
		);
		$excess = count( $prunable ) - $max_changes;
		for ( $i = 0; $i < $excess; $i++ ) {
			$selected[ (string) $prunable[ $i ]['change_id'] ] = true;
		}

		if ( null !== $store ) {
			$uses  = [];
			$sizes = [];
			foreach ( $rows as $row ) {
				if ( isset( $selected[ (string) $row['change_id'] ] ) ) {
					continue;
				}
				foreach ( [ 'before_ref', 'after_ref' ] as $column ) {
					$hash = (string) ( $row[ $column ] ?? '' );
					if ( BlobStore::is_valid_hash( $hash ) ) {
						$uses[ $hash ]  = ( $uses[ $hash ] ?? 0 ) + 1;
						$sizes[ $hash ] = $sizes[ $hash ] ?? $store->stored_size( $hash );
					}
				}
			}
			$total = array_sum( $sizes );
			foreach ( $rows as $row ) {
				if ( $total <= $max_bytes ) {
					break;
				}
				$id = (string) $row['change_id'];
				if ( isset( $selected[ $id ] ) || isset( $open[ (string) ( $row['status'] ?? '' ) ] ) ) {
					continue;
				}
				$selected[ $id ] = true;
				foreach ( [ 'before_ref', 'after_ref' ] as $column ) {
					$hash = (string) ( $row[ $column ] ?? '' );
					if ( isset( $uses[ $hash ] ) && 0 === --$uses[ $hash ] ) {
						$total -= $sizes[ $hash ];
					}
				}
			}
		}

		// A row whose child stays stays too, and then so does its own parent.
		do {
			$changed = false;
			foreach ( array_keys( $selected ) as $id ) {
				foreach ( $children[ $id ] ?? [] as $child ) {
					if ( ! isset( $selected[ $child ] ) ) {
						unset( $selected[ $id ] );
						$changed = true;
						break;
					}
				}
			}
		} while ( $changed );

		return array_keys( $selected );
	}

	/**
	 * Every blob that a row uses.
	 *
	 * @return array<string, true>
	 */
	private static function referenced_blobs(): array {
		global $wpdb;
		$table      = ChangeLedger::table_name();
		$referenced = [];
		foreach ( [ 'before_ref', 'after_ref' ] as $column ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Internal table and column names, no input.
			$found = $wpdb->get_col( "SELECT {$column} FROM {$table} WHERE {$column} != ''" );
			foreach ( is_array( $found ) ? $found : [] as $hash ) {
				$referenced[ (string) $hash ] = true;
			}
		}
		return $referenced;
	}

	private static function grace(): int {
		$grace = apply_filters( 'stonewright_change_ledger_blob_grace', self::BLOB_GRACE_SECONDS );
		return is_numeric( $grace ) ? max( 0, (int) $grace ) : self::BLOB_GRACE_SECONDS;
	}

	/**
	 * The value of a limit: the option, then the filter, each corrected to a positive number of at most $max.
	 */
	private static function limit( string $option, string $filter, int $default, int $max ): int {
		$value = self::bounded( get_option( $option, $default ), $default, $max );
		return self::bounded( apply_filters( $filter, $value ), $default, $max );
	}

	private static function bounded( mixed $value, int $default, int $max ): int {
		if ( ! is_numeric( $value ) || (float) $value < 1 ) {
			return $default;
		}
		return (int) min( (float) $max, (float) $value );
	}

	/**
	 * @param array<string, mixed> $receipt
	 */
	private static function audit( array $receipt ): void {
		try {
			AuditLog::record(
				'stonewright/change-ledger-prune',
				[
					'_meta'          => [
						'operation_class'  => 'change_ledger_prune',
						'resource_type'    => 'change_ledger',
						'resource_ref'     => ChangeLedger::TABLE,
						'execution_status' => 'executed',
					],
					'deleted_rows'   => $receipt['deleted_rows'],
					'deleted_blobs'  => $receipt['deleted_blobs'],
					'freed_bytes'    => $receipt['freed_bytes'],
					'remaining_rows' => $receipt['remaining_rows'],
					'days'           => $receipt['days'],
					'max_changes'    => $receipt['max_changes'],
					'max_bytes'      => $receipt['max_bytes'],
				],
				'ok'
			);
		} catch ( \Throwable $failure ) {
			unset( $failure );
		}
	}
}
