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
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'query' ) || ! method_exists( $wpdb, 'get_results' ) ) {
			$receipt['status'] = 'unavailable';
			return $receipt;
		}
		$table = ChangeLedger::table_name();
		$store = ChangeLedger::blob_store();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table; every value is prepared.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT change_id, parent_id, status, created_at, before_ref, after_ref FROM {$table} ORDER BY created_at ASC, id ASC LIMIT %d", self::MAX_ROWS_READ ),
			ARRAY_A
		);
		// phpcs:enable
		$rows = is_array( $rows ) ? array_values( array_filter( $rows, 'is_array' ) ) : [];

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
