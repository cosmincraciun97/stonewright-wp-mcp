<?php
/**
 * The read-only plan of the change ledger retention: what a cleanup would remove, and when to warn.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Security\BlobStore;
use Stonewright\WpMcp\Security\ChangeLedger;
use Stonewright\WpMcp\Security\ChangeLedgerRetention;
use Stonewright\WpMcp\Tests\Unit\Security\Fixtures\LedgerWpdb;

/**
 * @covers \Stonewright\WpMcp\Security\ChangeLedgerRetention
 */
final class ChangeLedgerRetentionDryRunTest extends TestCase {

	private const NOW = 1790000000;

	private mixed $original_wpdb;

	private LedgerWpdb $db;

	private string $uploads = '';

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$this->db            = new LedgerWpdb();
		$this->db->unique[ $this->db->prefix . 'stonewright_changes' ] = [ 'change_id' ];
		$GLOBALS['wpdb']     = $this->db;
		$this->uploads       = str_replace( '\\', '/', sys_get_temp_dir() ) . '/sw-dryrun-' . bin2hex( random_bytes( 5 ) );
		mkdir( $this->uploads, 0777, true );
		$GLOBALS['stonewright_test_upload_dir']      = [
			'basedir' => $this->uploads,
			'baseurl' => 'https://example.test/wp-content/uploads',
			'error'   => false,
		];
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_scheduled_hooks'] = [];
		$GLOBALS['stonewright_test_filters']         = [
			'stonewright_rescue_now'               => fn(): int => self::NOW,
			'stonewright_change_ledger_blob_grace' => fn(): int => 0,
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

	private function child_of( array $parent, float $days_ago ): array {
		$child = ChangeLedger::record( [ 'ability' => 'stonewright/change-rollback', 'family' => 'post', 'resource_type' => 'post', 'resource_id' => '1', 'kind' => 'rollback', 'parent_id' => $parent['change_id'], 'before' => [ 'n' => 'child' ] ] );
		self::assertIsArray( $child );
		ChangeLedger::settle( $child['change_id'], [ 'status' => 'verified' ] );
		$this->move( $child['change_id'], $days_ago );
		return ChangeLedger::get( $child['change_id'] ) ?? [];
	}

	// ---- the dry run is the plan the pruner runs -----------------------------------------------------

	public function test_the_dry_run_names_exactly_the_changes_prune_then_removes(): void {
		update_option( ChangeLedgerRetention::DAYS_OPTION, 30 );
		update_option( ChangeLedgerRetention::MAX_CHANGES_OPTION, 4 );
		$all   = [];
		$all[] = $this->change( 200, 'incident' );
		$all[] = $this->change( 80 );
		$all[] = $this->change( 41 );
		$parent = $this->change( 60 );
		$all[]  = $parent;
		$all[]  = $this->child_of( $parent, 3 );
		$all[]  = $this->change( 20 );
		$all[]  = $this->change( 15 );
		$all[]  = $this->change( 10 );
		$all[]  = $this->change( 5 );
		$all[]  = $this->change( 1 );
		$before = self::ids( $all );

		$plan    = ChangeLedgerRetention::dry_run( self::NOW );
		$receipt = ChangeLedgerRetention::prune( self::NOW );

		$removed = array_values( array_diff( $before, $this->remaining() ) );
		self::assertSame( $receipt['deleted_rows'], $plan['would_remove'] );
		self::assertSame( count( $removed ), $plan['would_remove'] );
		self::assertEqualsCanonicalizing( $removed, $plan['ids'] );
		self::assertGreaterThan( 0, $plan['would_remove'] );
	}

	public function test_the_dry_run_equals_prune_when_the_size_limit_decides(): void {
		$rows = [];
		for ( $i = 6; $i >= 1; $i-- ) {
			$rows[] = $this->change( $i );
		}
		$store = BlobStore::default();
		self::assertNotNull( $store );
		$largest = max( array_map( static fn ( array $row ): int => $store->stored_size( (string) $row['before_ref'] ), $rows ) );
		update_option( ChangeLedgerRetention::MAX_BYTES_OPTION, 2 * $largest + 5 );

		$plan    = ChangeLedgerRetention::dry_run( self::NOW );
		$receipt = ChangeLedgerRetention::prune( self::NOW );

		self::assertSame( 4, $plan['would_remove'] );
		self::assertSame( $receipt['deleted_rows'], $plan['would_remove'] );
		self::assertEqualsCanonicalizing( array_diff( self::ids( $rows ), $this->remaining() ), $plan['ids'] );
	}

	public function test_the_dry_run_deletes_and_records_nothing(): void {
		update_option( ChangeLedgerRetention::DAYS_OPTION, 30 );
		$this->change( 100 );
		$this->change( 40 );
		$this->change( 2 );
		$rows       = $this->db->tables;
		$files      = $this->blob_files();
		$options    = $GLOBALS['stonewright_test_options'];
		$statements = count( $this->db->statements );

		$plan = ChangeLedgerRetention::dry_run( self::NOW );
		ChangeLedgerRetention::forecast( self::NOW );

		self::assertSame( 2, $plan['would_remove'] );
		self::assertSame( $rows, $this->db->tables, 'No row was touched.' );
		self::assertSame( $files, $this->blob_files(), 'No blob was touched.' );
		self::assertSame( $options, $GLOBALS['stonewright_test_options'], 'No receipt and no option was written.' );
		foreach ( array_slice( $this->db->statements, $statements ) as $sql ) {
			self::assertStringStartsWith( 'SELECT', $sql, 'A dry run only reads.' );
		}
	}

	public function test_the_dry_run_reads_no_more_rows_than_the_pruner_does(): void {
		$this->change( 1 );

		ChangeLedgerRetention::dry_run( self::NOW );
		ChangeLedgerRetention::forecast( self::NOW );

		$reads = array_values( array_filter( $this->db->statements, static fn ( string $sql ): bool => str_contains( $sql, 'ORDER BY created_at ASC' ) ) );
		self::assertNotSame( [], $reads );
		foreach ( $reads as $sql ) {
			self::assertStringContainsString( 'LIMIT 120000', $sql );
		}
	}

	public function test_the_dry_run_counts_finished_and_open_changes_and_the_bytes_the_ledger_keeps(): void {
		$a = $this->change( 3 );
		$this->change( 2 );
		$this->change( 5, 'incident' );
		$store = BlobStore::default();
		self::assertNotNull( $store );

		$plan = ChangeLedgerRetention::dry_run( self::NOW );

		self::assertTrue( $plan['available'] );
		self::assertSame( 2, $plan['finished'] );
		self::assertSame( 1, $plan['open'] );
		self::assertSame( $store->total_bytes(), $plan['stored_bytes'] );
		self::assertSame( 90, $plan['days'] );
		self::assertSame( 500, $plan['max_changes'] );
		self::assertSame( 0, $plan['would_remove'] );
		self::assertNotSame( '', (string) $a['change_id'] );
	}

	public function test_without_a_database_the_dry_run_says_so_and_removes_nothing(): void {
		$GLOBALS['wpdb'] = null;

		$plan = ChangeLedgerRetention::dry_run( self::NOW );

		self::assertFalse( $plan['available'] );
		self::assertSame( 0, $plan['would_remove'] );
		self::assertFalse( ChangeLedgerRetention::forecast( self::NOW )['show'] );
	}

	// ---- the advance warning -------------------------------------------------------------------------

	public function test_an_empty_ledger_and_a_quiet_one_raise_no_warning(): void {
		self::assertFalse( ChangeLedgerRetention::forecast( self::NOW )['show'] );

		update_option( ChangeLedgerRetention::MAX_CHANGES_OPTION, 100 );
		for ( $i = 0; $i < 5; $i++ ) {
			$this->change( $i + 1 );
		}
		$forecast = ChangeLedgerRetention::forecast( self::NOW );

		self::assertFalse( $forecast['show'] );
		self::assertSame( [], $forecast['reasons'] );
		self::assertSame( 0, $forecast['would_remove'] );
		self::assertNull( $forecast['by'] );
	}

	public function test_the_count_warns_from_eighty_percent_of_the_limit(): void {
		update_option( ChangeLedgerRetention::MAX_CHANGES_OPTION, 10 );
		for ( $i = 0; $i < 7; $i++ ) {
			$this->change( $i + 1 );
		}
		self::assertFalse( ChangeLedgerRetention::forecast( self::NOW )['show'], '70% is quiet' );

		$this->change( 1 );
		$at_eighty = ChangeLedgerRetention::forecast( self::NOW );
		self::assertTrue( $at_eighty['show'], '80% warns' );
		self::assertSame( [ 'changes' ], $at_eighty['reasons'] );
		self::assertSame( 80, $at_eighty['changes_percent'] );
		self::assertSame( 0, $at_eighty['would_remove'], 'Nothing is over the limit yet.' );
	}

	public function test_open_changes_do_not_count_toward_the_count_warning(): void {
		update_option( ChangeLedgerRetention::MAX_CHANGES_OPTION, 10 );
		for ( $i = 0; $i < 7; $i++ ) {
			$this->change( $i + 1 );
		}
		$this->change( 1, 'incident' );
		$this->change( 1, 'armed' );

		self::assertFalse( ChangeLedgerRetention::forecast( self::NOW )['show'] );
	}

	public function test_a_change_over_the_count_limit_says_how_many_go_and_when_the_next_cleanup_is(): void {
		update_option( ChangeLedgerRetention::MAX_CHANGES_OPTION, 4 );
		for ( $i = 6; $i >= 1; $i-- ) {
			$this->change( $i );
		}
		$GLOBALS['stonewright_test_scheduled_hooks'][ ChangeLedgerRetention::HOOK ] = self::NOW + 3600;

		$forecast = ChangeLedgerRetention::forecast( self::NOW );

		self::assertTrue( $forecast['show'] );
		self::assertSame( 2, $forecast['would_remove'] );
		self::assertSame( self::NOW + 3600, $forecast['by'], 'The next scheduled cleanup removes them.' );
		self::assertContains( 'changes', $forecast['reasons'] );
	}

	public function test_the_size_warns_from_eighty_percent_of_the_limit(): void {
		$this->change( 1 );
		$this->change( 2 );
		$total = ChangeLedgerRetention::dry_run( self::NOW )['stored_bytes'];
		self::assertGreaterThan( 100, $total );

		update_option( ChangeLedgerRetention::MAX_BYTES_OPTION, intdiv( $total * 100, 80 ) + 1 );
		self::assertFalse( ChangeLedgerRetention::forecast( self::NOW )['show'], 'just under 80%' );

		update_option( ChangeLedgerRetention::MAX_BYTES_OPTION, intdiv( $total * 100, 80 ) );
		$forecast = ChangeLedgerRetention::forecast( self::NOW );
		self::assertTrue( $forecast['show'], 'at 80%' );
		self::assertSame( [ 'bytes' ], $forecast['reasons'] );
		self::assertGreaterThanOrEqual( 80, $forecast['bytes_percent'] );
		self::assertSame( 0, $forecast['would_remove'] );
	}

	public function test_a_change_that_passes_the_age_limit_within_seven_days_warns_with_its_count_and_date(): void {
		update_option( ChangeLedgerRetention::DAYS_OPTION, 30 );
		$GLOBALS['stonewright_test_scheduled_hooks'][ ChangeLedgerRetention::HOOK ] = self::NOW + 3600;
		$this->change( 24 );
		$this->change( 25 );
		$this->change( 22 );

		$forecast = ChangeLedgerRetention::forecast( self::NOW );

		self::assertTrue( $forecast['show'] );
		self::assertSame( [ 'age' ], $forecast['reasons'] );
		self::assertSame( 2, $forecast['would_remove'], 'The two that pass 30 days within a week; the 22 day old one does not.' );
		// The 24 day old change passes the limit in six days. The cleanups run daily from NOW + 1 hour.
		self::assertSame( self::NOW + 3600 + 6 * 86400, $forecast['by'] );
		self::assertSame( 0, ChangeLedgerRetention::dry_run( self::NOW )['would_remove'], 'Nothing is due yet.' );
	}

	public function test_a_change_that_passes_the_age_limit_after_eight_days_is_not_warned_about(): void {
		update_option( ChangeLedgerRetention::DAYS_OPTION, 30 );
		$this->change( 22 );
		$this->change( 5 );

		self::assertFalse( ChangeLedgerRetention::forecast( self::NOW )['show'] );
	}

	public function test_open_changes_never_raise_the_age_warning(): void {
		update_option( ChangeLedgerRetention::DAYS_OPTION, 30 );
		$this->change( 29, 'incident' );
		$this->change( 40, 'armed' );

		self::assertFalse( ChangeLedgerRetention::forecast( self::NOW )['show'] );
	}

	public function test_the_forecast_uses_the_pruner_plan_so_a_kept_parent_is_not_counted(): void {
		update_option( ChangeLedgerRetention::DAYS_OPTION, 30 );
		$parent = $this->change( 29 );
		$this->child_of( $parent, 2 );
		$this->change( 28 );

		$forecast = ChangeLedgerRetention::forecast( self::NOW );

		self::assertSame( 1, $forecast['would_remove'], 'The parent stays with its newer child; only the plain change goes.' );
	}

	public function test_the_forecast_never_names_a_date_before_the_next_cleanup(): void {
		update_option( ChangeLedgerRetention::DAYS_OPTION, 30 );
		$GLOBALS['stonewright_test_scheduled_hooks'][ ChangeLedgerRetention::HOOK ] = self::NOW - 5000;
		$this->change( 40 );

		$forecast = ChangeLedgerRetention::forecast( self::NOW );

		self::assertSame( 1, $forecast['would_remove'] );
		self::assertGreaterThanOrEqual( self::NOW, (int) $forecast['by'] );
	}
}
