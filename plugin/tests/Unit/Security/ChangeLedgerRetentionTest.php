<?php
/**
 * Retention of the change ledger: age, count and size, with the rows and blobs that stay.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Security\ChangeLedger;
use Stonewright\WpMcp\Security\ChangeLedgerRetention;
use Stonewright\WpMcp\Tests\Unit\Security\Fixtures\LedgerWpdb;

/**
 * @covers \Stonewright\WpMcp\Security\ChangeLedgerRetention
 * @covers \Stonewright\WpMcp\Security\ChangeLedger
 * @covers \Stonewright\WpMcp\Security\BlobStore
 */
final class ChangeLedgerRetentionTest extends TestCase {

	private const NOW = 1790000000;

	private mixed $original_wpdb;

	private LedgerWpdb $db;

	private string $uploads = '';

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$this->db            = new LedgerWpdb();
		$this->db->unique[ $this->db->prefix . 'stonewright_changes' ] = [ 'change_id' ];
		$GLOBALS['wpdb']     = $this->db;
		$this->uploads       = str_replace( '\\', '/', sys_get_temp_dir() ) . '/sw-retention-' . bin2hex( random_bytes( 5 ) );
		mkdir( $this->uploads, 0777, true );
		$GLOBALS['stonewright_test_upload_dir'] = [
			'basedir' => $this->uploads,
			'baseurl' => 'https://example.test/wp-content/uploads',
			'error'   => false,
		];
		$GLOBALS['stonewright_test_options']     = [];
		$GLOBALS['stonewright_test_scheduled_hooks'] = [];
		$GLOBALS['stonewright_test_filters']     = [
			'stonewright_rescue_now'                 => fn(): int => self::NOW,
			'stonewright_change_ledger_blob_grace'   => fn(): int => 0,
		];
		ChangeLedger::reset_schema_health_cache_for_tests();
		ChangeLedger::use_blob_store_for_tests( null );
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->original_wpdb;
		unset( $GLOBALS['stonewright_test_upload_dir'] );
		$GLOBALS['stonewright_test_filters']         = [];
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_scheduled_hooks'] = [];
		ChangeLedger::use_blob_store_for_tests( null );
		self::remove_tree( $this->uploads );
	}

	private static function remove_tree( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			@unlink( $path );
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		foreach ( scandir( $path ) ?: [] as $item ) {
			if ( '.' !== $item && '..' !== $item ) {
				self::remove_tree( $path . '/' . $item );
			}
		}
		@rmdir( $path );
	}

	/**
	 * Records a change and moves its created time $days_ago days before NOW.
	 *
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private function change( float $days_ago, string $status = 'verified', array $overrides = [] ): array {
		static $counter = 0;
		++$counter;
		$row = ChangeLedger::record(
			array_merge(
				[
					'ability'       => 'stonewright/content-update-page',
					'family'        => 'post',
					'resource_type' => 'post',
					'resource_id'   => (string) $counter,
					'before'        => [ 'n' => $counter, 'pad' => base64_encode( random_bytes( 200 ) ) ],
				],
				$overrides
			)
		);
		self::assertIsArray( $row, is_wp_error( $row ) ? $row->get_error_message() : '' );
		if ( 'armed' !== $status ) {
			ChangeLedger::settle( $row['change_id'], [ 'status' => $status ] );
		}
		$this->move( $row['change_id'], $days_ago );
		return ChangeLedger::get( $row['change_id'] ) ?? [];
	}

	private function move( string $change_id, float $days_ago ): void {
		$table = $this->db->prefix . 'stonewright_changes';
		foreach ( $this->db->tables[ $table ] as $index => $row ) {
			if ( $row['change_id'] === $change_id ) {
				$this->db->tables[ $table ][ $index ]['created_at'] = gmdate( 'Y-m-d H:i:s', (int) ( self::NOW - $days_ago * 86400 ) );
			}
		}
	}

	/** @return list<string> */
	private function remaining(): array {
		return array_column( $this->db->tables[ $this->db->prefix . 'stonewright_changes' ] ?? [], 'change_id' );
	}

	/** @return list<string> */
	private function blob_files(): array {
		return array_map( static fn ( string $file ): string => basename( $file, '.gz' ), glob( $this->uploads . '/stonewright-state/blobs/*.gz' ) ?: [] );
	}

	/** @param list<array<string, mixed>> $rows @return list<string> */
	private static function ids( array $rows ): array {
		return array_map( static fn ( array $row ): string => (string) $row['change_id'], $rows );
	}

	// ---- settings -----------------------------------------------------------------------------------

	public function test_the_defaults_are_ninety_days_five_hundred_changes_and_one_hundred_megabytes(): void {
		self::assertSame( 90, ChangeLedgerRetention::days() );
		self::assertSame( 500, ChangeLedgerRetention::max_changes() );
		self::assertSame( 100 * 1024 * 1024, ChangeLedgerRetention::max_bytes() );
	}

	public function test_an_administrator_changes_the_limits_with_options(): void {
		update_option( ChangeLedgerRetention::DAYS_OPTION, '30' );
		update_option( ChangeLedgerRetention::MAX_CHANGES_OPTION, 120 );
		update_option( ChangeLedgerRetention::MAX_BYTES_OPTION, 5 * 1024 * 1024 );

		self::assertSame( 30, ChangeLedgerRetention::days() );
		self::assertSame( 120, ChangeLedgerRetention::max_changes() );
		self::assertSame( 5 * 1024 * 1024, ChangeLedgerRetention::max_bytes() );
		self::assertSame( 'stonewright_change_ledger_days', ChangeLedgerRetention::DAYS_OPTION );
	}

	public function test_a_site_changes_the_limits_with_filters_that_see_the_option_value(): void {
		update_option( ChangeLedgerRetention::DAYS_OPTION, 30 );
		$GLOBALS['stonewright_test_filters']['stonewright_change_ledger_retention_days']        = static fn ( int $days ): int => $days * 2;
		$GLOBALS['stonewright_test_filters']['stonewright_change_ledger_retention_max_changes'] = static fn (): int => 40;
		$GLOBALS['stonewright_test_filters']['stonewright_change_ledger_retention_max_bytes']   = static fn (): int => 2 * 1024 * 1024;

		self::assertSame( 60, ChangeLedgerRetention::days() );
		self::assertSame( 40, ChangeLedgerRetention::max_changes() );
		self::assertSame( 2 * 1024 * 1024, ChangeLedgerRetention::max_bytes() );
	}

	/** @return array<string, array{0: mixed, 1: int}> */
	public static function unusableDays(): array {
		return [
			'zero'     => [ 0, 90 ],
			'negative' => [ -5, 90 ],
			'text'     => [ 'forever', 90 ],
			'empty'    => [ '', 90 ],
			'too long' => [ 100000, 3650 ],
			'one'      => [ 1, 1 ],
		];
	}

	/** @dataProvider unusableDays */
	public function test_a_value_that_would_delete_everything_or_nothing_is_corrected( mixed $value, int $expected ): void {
		update_option( ChangeLedgerRetention::DAYS_OPTION, $value );

		self::assertSame( $expected, ChangeLedgerRetention::days() );
	}

	public function test_the_count_and_size_limits_keep_a_floor_and_a_ceiling(): void {
		update_option( ChangeLedgerRetention::MAX_CHANGES_OPTION, 0 );
		update_option( ChangeLedgerRetention::MAX_BYTES_OPTION, -1 );
		self::assertSame( 500, ChangeLedgerRetention::max_changes() );
		self::assertSame( 100 * 1024 * 1024, ChangeLedgerRetention::max_bytes() );

		update_option( ChangeLedgerRetention::MAX_CHANGES_OPTION, 9999999 );
		update_option( ChangeLedgerRetention::MAX_BYTES_OPTION, PHP_INT_MAX );
		self::assertSame( 100000, ChangeLedgerRetention::max_changes() );
		self::assertSame( 10 * 1024 * 1024 * 1024, ChangeLedgerRetention::max_bytes() );
	}

	// ---- age ----------------------------------------------------------------------------------------

	public function test_changes_older_than_the_retention_days_are_deleted_oldest_first_and_newer_ones_stay(): void {
		$old   = $this->change( 120 );
		$aged  = $this->change( 91 );
		$edge  = $this->change( 90 );
		$week  = $this->change( 7 );
		$fresh = $this->change( 0 );

		$receipt = ChangeLedgerRetention::prune( self::NOW );

		self::assertSame( 'completed', $receipt['status'] );
		self::assertSame( 2, $receipt['deleted_rows'] );
		self::assertSame( 3, $receipt['remaining_rows'] );
		self::assertSame( self::ids( [ $edge, $week, $fresh ] ), $this->remaining(), 'a change exactly at the limit is kept' );
		self::assertNotContains( $old['before_ref'], $this->blob_files() );
		self::assertNotContains( $aged['before_ref'], $this->blob_files() );
		self::assertContains( $edge['before_ref'], $this->blob_files() );
	}

	public function test_a_second_run_finds_nothing_to_delete(): void {
		$this->change( 120 );
		$this->change( 1 );
		ChangeLedgerRetention::prune( self::NOW );

		$again = ChangeLedgerRetention::prune( self::NOW );

		self::assertSame( 0, $again['deleted_rows'] );
		self::assertSame( 0, $again['deleted_blobs'] );
		self::assertSame( 1, $again['remaining_rows'] );
	}

	public function test_the_days_option_decides_the_cutoff(): void {
		update_option( ChangeLedgerRetention::DAYS_OPTION, 10 );
		$this->change( 11 );
		$kept = $this->change( 9 );

		ChangeLedgerRetention::prune( self::NOW );

		self::assertSame( self::ids( [ $kept ] ), $this->remaining() );
	}

	// ---- count --------------------------------------------------------------------------------------

	public function test_only_the_newest_changes_up_to_the_count_limit_stay(): void {
		update_option( ChangeLedgerRetention::MAX_CHANGES_OPTION, 5 );
		$rows = [];
		for ( $i = 8; $i >= 1; $i-- ) {
			$rows[] = $this->change( $i );
		}

		$receipt = ChangeLedgerRetention::prune( self::NOW );

		self::assertSame( 3, $receipt['deleted_rows'] );
		self::assertSame( self::ids( array_slice( $rows, 3 ) ), $this->remaining() );
		self::assertCount( 5, $this->blob_files() );
	}

	// ---- size ---------------------------------------------------------------------------------------

	public function test_the_oldest_changes_go_first_when_the_blobs_exceed_the_size_limit(): void {
		$rows = [];
		for ( $i = 6; $i >= 1; $i-- ) {
			$rows[] = $this->change( $i );
		}
		$store = \Stonewright\WpMcp\Security\BlobStore::default();
		self::assertNotNull( $store );
		$largest = max( array_map( static fn ( array $row ): int => $store->stored_size( (string) $row['before_ref'] ), $rows ) );
		update_option( ChangeLedgerRetention::MAX_BYTES_OPTION, 2 * $largest + 5 );

		$receipt = ChangeLedgerRetention::prune( self::NOW );

		self::assertSame( 4, $receipt['deleted_rows'] );
		self::assertSame( self::ids( array_slice( $rows, 4 ) ), $this->remaining() );
		self::assertLessThanOrEqual( 2 * $largest + 5, $receipt['remaining_bytes'] );
		self::assertGreaterThan( 0, $receipt['freed_bytes'] );
		self::assertSame( $receipt['remaining_bytes'], $store->total_bytes() );
	}

	public function test_the_limit_that_is_reached_first_decides(): void {
		update_option( ChangeLedgerRetention::MAX_CHANGES_OPTION, 100 );
		update_option( ChangeLedgerRetention::DAYS_OPTION, 30 );
		$this->change( 40 );
		$this->change( 35 );
		$kept = [ $this->change( 20 ), $this->change( 10 ), $this->change( 1 ) ];

		ChangeLedgerRetention::prune( self::NOW );
		self::assertSame( self::ids( $kept ), $this->remaining(), 'age first' );

		update_option( ChangeLedgerRetention::MAX_CHANGES_OPTION, 2 );
		ChangeLedgerRetention::prune( self::NOW );
		self::assertSame( self::ids( array_slice( $kept, 1 ) ), $this->remaining(), 'then count' );
	}

	// ---- what stays ---------------------------------------------------------------------------------

	/** @return array<string, array{0: string}> */
	public static function openStatuses(): array {
		return [
			'armed'           => [ 'armed' ],
			'incident'        => [ 'incident' ],
			'rollback_failed' => [ 'rollback_failed' ],
		];
	}

	/** @dataProvider openStatuses */
	public function test_an_open_change_is_never_deleted_by_age( string $status ): void {
		$open = $this->change( 400, $status );
		$done = $this->change( 400 );

		ChangeLedgerRetention::prune( self::NOW );

		self::assertSame( self::ids( [ $open ] ), $this->remaining() );
		self::assertContains( $open['before_ref'], $this->blob_files() );
		self::assertNotContains( $done['before_ref'], $this->blob_files() );
	}

	public function test_open_changes_stay_when_the_count_and_size_limits_are_exceeded_and_do_not_use_up_the_count(): void {
		update_option( ChangeLedgerRetention::MAX_CHANGES_OPTION, 2 );
		$incident = $this->change( 50, 'incident' );
		$armed    = $this->change( 49, 'armed' );
		$gone_1   = $this->change( 8 );
		$gone_2   = $this->change( 7 );
		$kept_1   = $this->change( 2 );
		$kept_2   = $this->change( 1 );

		$receipt = ChangeLedgerRetention::prune( self::NOW );

		self::assertSame( 2, $receipt['deleted_rows'] );
		self::assertSame( self::ids( [ $incident, $armed, $kept_1, $kept_2 ] ), $this->remaining() );
		self::assertNotContains( $gone_1['before_ref'], $this->blob_files() );
		self::assertNotContains( $gone_2['before_ref'], $this->blob_files() );

		update_option( ChangeLedgerRetention::MAX_BYTES_OPTION, 1 );
		ChangeLedgerRetention::prune( self::NOW );
		self::assertSame( self::ids( [ $incident, $armed ] ), $this->remaining(), 'with a size limit nothing can meet, only the open changes are left' );
	}

	public function test_a_change_whose_child_stays_is_kept_so_the_chain_is_not_cut(): void {
		$parent = $this->change( 120 );
		$child  = ChangeLedger::record( [ 'ability' => 'stonewright/change-rollback', 'family' => 'post', 'resource_type' => 'post', 'resource_id' => '1', 'kind' => 'rollback', 'parent_id' => $parent['change_id'], 'before' => [ 'n' => 'child' ] ] );
		self::assertIsArray( $child );
		ChangeLedger::settle( $child['change_id'], [ 'status' => 'verified' ] );
		$this->move( $child['change_id'], 10 );
		$unrelated = $this->change( 120 );

		$receipt = ChangeLedgerRetention::prune( self::NOW );

		self::assertSame( 1, $receipt['deleted_rows'] );
		self::assertSame( [ $parent['change_id'], $child['change_id'] ], $this->remaining() );
		self::assertContains( $parent['before_ref'], $this->blob_files() );
		self::assertNotContains( $unrelated['before_ref'], $this->blob_files() );
		self::assertCount( 2, ChangeLedger::chain( $child['change_id'] ), 'the chain is whole' );
	}

	public function test_a_whole_old_chain_is_deleted_together(): void {
		$parent = $this->change( 120 );
		$child  = ChangeLedger::record( [ 'ability' => 'stonewright/change-rollback', 'family' => 'post', 'resource_type' => 'post', 'resource_id' => '1', 'kind' => 'rollback', 'parent_id' => $parent['change_id'], 'before' => [ 'n' => 'child' ] ] );
		self::assertIsArray( $child );
		ChangeLedger::settle( $child['change_id'], [ 'status' => 'verified' ] );
		$this->move( $child['change_id'], 100 );

		$receipt = ChangeLedgerRetention::prune( self::NOW );

		self::assertSame( 2, $receipt['deleted_rows'] );
		self::assertSame( [], $this->remaining() );
	}

	public function test_a_parent_kept_for_its_child_may_leave_the_count_slightly_over_the_limit(): void {
		update_option( ChangeLedgerRetention::MAX_CHANGES_OPTION, 2 );
		$parent = $this->change( 9 );
		$other  = $this->change( 8 );
		$child  = ChangeLedger::record( [ 'ability' => 'stonewright/change-rollback', 'family' => 'post', 'resource_type' => 'post', 'resource_id' => '1', 'kind' => 'rollback', 'parent_id' => $parent['change_id'], 'before' => [ 'n' => 'child' ] ] );
		self::assertIsArray( $child );
		ChangeLedger::settle( $child['change_id'], [ 'status' => 'verified' ] );
		$this->move( $child['change_id'], 2 );
		$newest = $this->change( 1 );

		ChangeLedgerRetention::prune( self::NOW );

		self::assertSame( [ $parent['change_id'], $child['change_id'], $newest['change_id'] ], $this->remaining() );
		self::assertNotContains( $other['change_id'], $this->remaining() );
	}

	// ---- blobs --------------------------------------------------------------------------------------

	public function test_a_blob_shared_with_a_surviving_change_is_kept(): void {
		$shared = [ 'n' => 'shared image' ];
		$old    = $this->change( 120, 'verified', [ 'before' => $shared ] );
		$new    = $this->change( 1, 'verified', [ 'before' => $shared ] );
		self::assertSame( $old['before_ref'], $new['before_ref'] );

		$receipt = ChangeLedgerRetention::prune( self::NOW );

		self::assertSame( 1, $receipt['deleted_rows'] );
		self::assertSame( 0, $receipt['deleted_blobs'] );
		self::assertSame( [ $new['before_ref'] ], $this->blob_files() );
		self::assertIsArray( ChangeLedger::read_image( $new['change_id'], 'before' ) );
	}

	public function test_a_blob_used_as_an_after_image_by_a_surviving_change_is_kept(): void {
		$old = $this->change( 120, 'verified', [ 'before' => [ 'n' => 'v1' ] ] );
		$new = $this->change( 1, 'verified', [ 'before' => [ 'n' => 'v2' ] ] );
		ChangeLedger::settle( $new['change_id'], [ 'status' => 'verified', 'after' => [ 'n' => 'v1' ] ] );

		ChangeLedgerRetention::prune( self::NOW );

		self::assertContains( $old['before_ref'], $this->blob_files(), 'the blob is the after image of the change that stays' );
	}

	public function test_a_blob_goes_when_the_last_change_that_uses_it_goes(): void {
		$shared = [ 'n' => 'shared image' ];
		$one    = $this->change( 130, 'verified', [ 'before' => $shared ] );
		$two    = $this->change( 120, 'verified', [ 'before' => $shared ] );

		$receipt = ChangeLedgerRetention::prune( self::NOW );

		self::assertSame( 2, $receipt['deleted_rows'] );
		self::assertSame( 1, $receipt['deleted_blobs'] );
		self::assertSame( [], $this->blob_files() );
		self::assertSame( $one['before_ref'], $two['before_ref'] );
	}

	public function test_a_blob_that_no_change_uses_is_swept_but_only_after_the_grace_period(): void {
		$store = \Stonewright\WpMcp\Security\BlobStore::default();
		self::assertNotNull( $store );
		$orphan = $store->put( 'written, then the insert failed' );
		self::assertIsArray( $orphan );
		$file = $this->uploads . '/stonewright-state/blobs/' . $orphan['sha256'] . '.gz';

		$GLOBALS['stonewright_test_filters']['stonewright_change_ledger_blob_grace'] = static fn (): int => 600;
		ChangeLedgerRetention::prune( self::NOW );
		self::assertFileExists( $file, 'it is only a moment old on the clock of the files' );

		touch( $file, time() - 7200 );
		$receipt = ChangeLedgerRetention::prune( self::NOW );
		self::assertFileDoesNotExist( $file );
		self::assertSame( 1, $receipt['deleted_blobs'] );
	}

	public function test_by_default_a_blob_written_in_the_last_minutes_survives_the_run_that_deletes_its_row(): void {
		unset( $GLOBALS['stonewright_test_filters']['stonewright_change_ledger_blob_grace'] );
		$old = $this->change( 120 );

		$first = ChangeLedgerRetention::prune( self::NOW );
		self::assertSame( 1, $first['deleted_rows'] );
		self::assertSame( 0, $first['deleted_blobs'] );
		self::assertContains( $old['before_ref'], $this->blob_files() );

		touch( $this->uploads . '/stonewright-state/blobs/' . $old['before_ref'] . '.gz', time() - 3600 );
		$second = ChangeLedgerRetention::prune( self::NOW );
		self::assertSame( 1, $second['deleted_blobs'] );
		self::assertSame( [], $this->blob_files() );
	}

	public function test_without_a_blob_store_the_rows_are_still_pruned(): void {
		$old = $this->change( 120 );
		$GLOBALS['stonewright_test_upload_dir'] = [ 'basedir' => '', 'baseurl' => '', 'error' => 'no uploads' ];
		ChangeLedger::use_blob_store_for_tests( null );

		$receipt = ChangeLedgerRetention::prune( self::NOW );

		self::assertSame( 'completed', $receipt['status'] );
		self::assertSame( 1, $receipt['deleted_rows'] );
		self::assertSame( 0, $receipt['deleted_blobs'] );
		self::assertSame( [], $this->remaining() );
		self::assertNotSame( '', $old['before_ref'] );
	}

	// ---- audit and receipt --------------------------------------------------------------------------

	public function test_a_prune_that_deletes_writes_one_short_audit_row_and_a_receipt(): void {
		$this->change( 120 );
		$this->change( 1 );
		$this->db->tables[ $this->db->prefix . 'stonewright_audit_log' ] = [];

		$receipt = ChangeLedgerRetention::prune( self::NOW );

		$audit = $this->db->tables[ $this->db->prefix . 'stonewright_audit_log' ] ?? [];
		self::assertCount( 1, $audit );
		self::assertSame( 'stonewright/change-ledger-prune', $audit[0]['ability_name'] );
		self::assertSame( 'ok', $audit[0]['result_status'] );
		$args = json_decode( (string) $audit[0]['sanitized_args'], true );
		self::assertIsArray( $args );
		self::assertSame( 1, $args['deleted_rows'] );
		self::assertSame( 1, $args['remaining_rows'] );
		self::assertLessThan( 2500, strlen( (string) $audit[0]['sanitized_args'] ) );
		self::assertStringNotContainsString( 'cs-', (string) $audit[0]['sanitized_args'], 'no change ids in the row' );
		self::assertSame( $receipt, get_option( ChangeLedgerRetention::RECEIPT_OPTION ) );
		self::assertSame( gmdate( 'c', self::NOW ), $receipt['run_at'] );
		self::assertSame( [ 90, 500, 100 * 1024 * 1024 ], [ $receipt['days'], $receipt['max_changes'], $receipt['max_bytes'] ] );
	}

	public function test_a_prune_that_deletes_nothing_writes_no_audit_row(): void {
		$this->change( 1 );
		$this->db->tables[ $this->db->prefix . 'stonewright_audit_log' ] = [];

		ChangeLedgerRetention::prune( self::NOW );

		self::assertSame( [], $this->db->tables[ $this->db->prefix . 'stonewright_audit_log' ] ?? [] );
	}

	// ---- schedule -----------------------------------------------------------------------------------

	public function test_the_daily_event_is_scheduled_once_and_runs_the_prune(): void {
		self::assertSame( 'stonewright_change_ledger_prune', ChangeLedgerRetention::HOOK );

		ChangeLedgerRetention::sync_schedule( self::NOW );
		self::assertSame( self::NOW + 3600, wp_next_scheduled( ChangeLedgerRetention::HOOK ) );
		$GLOBALS['stonewright_test_scheduled_hooks'][ ChangeLedgerRetention::HOOK ] = 123;
		ChangeLedgerRetention::sync_schedule( self::NOW );
		self::assertSame( 123, wp_next_scheduled( ChangeLedgerRetention::HOOK ), 'an event that exists is left alone' );

		$this->change( 120 );
		ChangeLedgerRetention::run_scheduled();
		self::assertSame( [], $this->remaining() );

		ChangeLedgerRetention::unschedule();
		self::assertFalse( wp_next_scheduled( ChangeLedgerRetention::HOOK ) );
	}
}
