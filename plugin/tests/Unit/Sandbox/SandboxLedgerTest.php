<?php
/**
 * Sandbox files in the change ledger: draft writes, activation backups and restores.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Sandbox;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Sandbox\SandboxFiles;
use Stonewright\WpMcp\Security\Adapters\CodeAdapter;
use Stonewright\WpMcp\Security\ChangeJournal;
use Stonewright\WpMcp\Security\ChangeLedger;
use Stonewright\WpMcp\Security\RescueGuard;
use Stonewright\WpMcp\Tests\Unit\Security\Fixtures\CodeAdapterHarness;

/**
 * @covers \Stonewright\WpMcp\Sandbox\SandboxFiles
 * @covers \Stonewright\WpMcp\Security\Adapters\CodeAdapter
 */
final class SandboxLedgerTest extends TestCase {
	use CodeAdapterHarness;

	private const NAME = 'ledger-test-demo.php';

	private const V1 = "<?php\n// version one\nadd_action( 'init', '__return_true' );\n";

	private const V2 = "<?php\n// version two\nadd_action( 'init', '__return_true' );\n";

	protected function setUp(): void {
		$this->set_up_harness();
		$this->clean_sandbox();
	}

	protected function tearDown(): void {
		$this->clean_sandbox();
		$this->tear_down_harness();
	}

	private function clean_sandbox(): void {
		foreach ( [ SandboxFiles::draft_dir() . '/ledger-test-*', SandboxFiles::draft_dir() . '/wp-config.*', SandboxFiles::mu_dir() . '/' . SandboxFiles::active_prefix() . 'ledger-test-*' ] as $pattern ) {
			foreach ( glob( $pattern ) ?: [] as $file ) {
				@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
	}

	private function twin( string $name = self::NAME ): string {
		return SandboxFiles::mu_dir() . '/' . SandboxFiles::active_prefix() . $name;
	}

	/** @return list<array<string, mixed>> */
	private function rows_of( string $resource_type ): array {
		$out = [];
		foreach ( $this->ledger_rows() as $row ) {
			if ( $resource_type === $row['resource_type'] ) {
				$out[] = ChangeLedger::get( (string) $row['change_id'] ) ?? [];
			}
		}
		return $out;
	}

	/** @return list<string> Backup files of the active copy. */
	private function active_backups( string $name = self::NAME ): array {
		return array_map( 'basename', glob( SandboxFiles::draft_dir() . '/' . substr( $name, 0, -4 ) . '.active.*.bak' ) ?: [] );
	}

	// ---- draft writes ------------------------------------------------------------------------------------

	public function test_a_new_draft_is_recorded_as_created_and_a_second_write_keeps_the_first_body(): void {
		SandboxFiles::write( self::NAME, self::V1 );
		SandboxFiles::write( self::NAME, self::V2 );

		$rows = $this->rows_of( 'sandbox_draft' );
		self::assertCount( 2, $rows );
		self::assertSame( 'sandbox', $rows[0]['family'] );
		self::assertSame( self::NAME, $rows[0]['resource_id'] );
		self::assertSame( 'stonewright/sandbox-write', $rows[0]['ability'] );
		self::assertSame( '', $rows[0]['before_ref'], 'The first write created the draft: no before image.' );
		self::assertTrue( $rows[0]['restorable'] );
		self::assertSame( self::V1, ChangeLedger::read_image( $rows[0]['change_id'], 'after' ) );
		self::assertSame( self::V1, ChangeLedger::read_image( $rows[1]['change_id'], 'before' ) );
		self::assertSame( self::V2, ChangeLedger::read_image( $rows[1]['change_id'], 'after' ) );
		self::assertSame( 'verified', $rows[1]['status'] );
	}

	public function test_an_edit_is_recorded_with_the_ability_name_of_the_edit(): void {
		SandboxFiles::write( self::NAME, self::V1 );
		SandboxFiles::edit( self::NAME, 'version one', 'version two' );

		$rows = $this->rows_of( 'sandbox_draft' );
		self::assertCount( 2, $rows );
		self::assertSame( 'stonewright/sandbox-edit', $rows[1]['ability'] );
		self::assertSame( self::V1, ChangeLedger::read_image( $rows[1]['change_id'], 'before' ) );
		self::assertSame( self::V2, ChangeLedger::read_image( $rows[1]['change_id'], 'after' ) );
	}

	public function test_a_delete_records_the_draft_body_and_the_active_copy(): void {
		SandboxFiles::write( self::NAME, self::V1 );
		SandboxFiles::activate( self::NAME );
		$twin = (string) file_get_contents( $this->twin() );
		$before_rows = count( $this->ledger_rows() );

		SandboxFiles::delete( self::NAME );

		$new = array_slice( $this->ledger_rows(), $before_rows );
		self::assertCount( 2, $new, 'One row for the draft and one for the active copy that went with it.' );
		$by_type = [];
		foreach ( $new as $row ) {
			$by_type[ $row['resource_type'] ] = ChangeLedger::get( (string) $row['change_id'] );
		}
		self::assertSame( self::V1, ChangeLedger::read_image( $by_type['sandbox_draft']['change_id'], 'before' ) );
		self::assertSame( '', $by_type['sandbox_draft']['after_ref'] );
		self::assertSame( $twin, ChangeLedger::read_image( $by_type['sandbox_active']['change_id'], 'before' ) );
		self::assertSame( 'stonewright/sandbox-delete', $by_type['sandbox_draft']['ability'] );
	}

	// ---- activation ----------------------------------------------------------------------------------------

	public function test_the_first_activation_is_recorded_as_created_and_makes_no_backup(): void {
		SandboxFiles::write( self::NAME, self::V1 );

		SandboxFiles::activate( self::NAME );

		$rows = $this->rows_of( 'sandbox_active' );
		self::assertCount( 1, $rows );
		self::assertSame( 'stonewright/sandbox-activate', $rows[0]['ability'] );
		self::assertSame( '', $rows[0]['before_ref'] );
		self::assertTrue( $rows[0]['restorable'] );
		self::assertSame( (string) file_get_contents( $this->twin() ), ChangeLedger::read_image( $rows[0]['change_id'], 'after' ) );
		self::assertSame( [], $this->active_backups(), 'There was no active copy to keep.' );
	}

	public function test_a_second_activation_backs_up_the_active_copy_before_it_overwrites_it(): void {
		SandboxFiles::write( self::NAME, self::V1 );
		SandboxFiles::activate( self::NAME );
		$first = (string) file_get_contents( $this->twin() );
		SandboxFiles::write( self::NAME, self::V2 );

		$result = SandboxFiles::activate( self::NAME );

		self::assertTrue( $result );
		$backups = $this->active_backups();
		self::assertCount( 1, $backups );
		self::assertSame( $first, file_get_contents( SandboxFiles::draft_dir() . '/' . $backups[0] ), 'The backup holds the copy that was replaced.' );
		self::assertNotSame( $first, file_get_contents( $this->twin() ) );
		$rows = $this->rows_of( 'sandbox_active' );
		self::assertSame( $first, ChangeLedger::read_image( $rows[1]['change_id'], 'before' ) );
	}

	public function test_activations_in_the_same_second_get_unique_backup_names(): void {
		SandboxFiles::write( self::NAME, self::V1 );
		SandboxFiles::activate( self::NAME );
		$contents = [ (string) file_get_contents( $this->twin() ) ];
		foreach ( [ 'two', 'three', 'four' ] as $word ) {
			SandboxFiles::write( self::NAME, "<?php\n// {$word}\nadd_action( 'init', '__return_true' );\n" );
			SandboxFiles::activate( self::NAME );
			$contents[] = (string) file_get_contents( $this->twin() );
		}

		$backups = $this->active_backups();
		self::assertCount( 3, $backups, 'Three replaced copies, three files, though they were written in one second.' );
		$kept = array_map( static fn ( string $name ): string => (string) file_get_contents( SandboxFiles::draft_dir() . '/' . $name ), $backups );
		sort( $kept );
		$expected = array_slice( $contents, 0, 3 );
		sort( $expected );
		self::assertSame( $expected, $kept );
	}

	public function test_draft_writes_in_the_same_second_get_unique_backup_names(): void {
		foreach ( [ 'one', 'two', 'three', 'four' ] as $word ) {
			SandboxFiles::write( self::NAME, "<?php\n// {$word}\n" );
		}

		$versions = SandboxFiles::backup_versions( self::NAME );
		self::assertCount( 3, $versions, 'The first write had nothing to back up; the next three each keep what they replaced.' );
		self::assertCount( 3, array_unique( array_column( $versions, 'timestamp' ) ) );
		$bodies = array_map( static fn ( array $v ): string => (string) file_get_contents( $v['path'] ), $versions );
		self::assertSame( [ "<?php\n// three\n", "<?php\n// two\n", "<?php\n// one\n" ], $bodies, 'Newest first.' );
	}

	public function test_active_backups_are_pruned_to_the_same_limit_as_draft_backups(): void {
		SandboxFiles::write( self::NAME, self::V1 );
		for ( $i = 0; $i < 14; ++$i ) {
			SandboxFiles::write( self::NAME, "<?php\n// v{$i}\nadd_action( 'init', '__return_true' );\n" );
			SandboxFiles::activate( self::NAME );
		}

		self::assertCount( 10, $this->active_backups() );
	}

	public function test_deleting_a_draft_removes_its_active_backups_too(): void {
		SandboxFiles::write( self::NAME, self::V1 );
		SandboxFiles::activate( self::NAME );
		SandboxFiles::write( self::NAME, self::V2 );
		SandboxFiles::activate( self::NAME );
		self::assertNotSame( [], $this->active_backups() );

		SandboxFiles::delete( self::NAME );

		self::assertSame( [], $this->active_backups() );
	}

	public function test_active_backups_do_not_show_up_as_draft_versions(): void {
		SandboxFiles::write( self::NAME, self::V1 );
		SandboxFiles::activate( self::NAME );
		SandboxFiles::write( self::NAME, self::V2 );
		SandboxFiles::activate( self::NAME );

		self::assertCount( 1, SandboxFiles::backup_versions( self::NAME ), 'Only the one draft backup from the second write.' );
	}

	public function test_a_deactivation_records_the_copy_that_was_removed(): void {
		SandboxFiles::write( self::NAME, self::V1 );
		SandboxFiles::activate( self::NAME );
		$twin = (string) file_get_contents( $this->twin() );

		SandboxFiles::deactivate( self::NAME );

		$rows = $this->rows_of( 'sandbox_active' );
		self::assertCount( 2, $rows );
		self::assertSame( 'stonewright/sandbox-deactivate', $rows[1]['ability'] );
		self::assertSame( $twin, ChangeLedger::read_image( $rows[1]['change_id'], 'before' ) );
		self::assertSame( '', $rows[1]['after_ref'] );
		self::assertSame( 'verified', $rows[1]['status'] );
	}

	public function test_a_failed_activation_is_recorded_as_failed_and_changes_nothing(): void {
		SandboxFiles::write( self::NAME, "<?php\neval( 'x' );\n" );

		$result = SandboxFiles::activate( self::NAME );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( [], $this->rows_of( 'sandbox_active' ), 'The static guard stopped it before anything was written.' );
		self::assertFileDoesNotExist( $this->twin() );
	}

	public function test_inside_a_rescue_call_the_activation_takes_the_journal_id(): void {
		SandboxFiles::write( self::NAME, self::V1 );
		RescueGuard::enter( 'stonewright/sandbox-activate' );
		$armed = (string) RescueGuard::arm_sandbox_write( self::NAME );

		SandboxFiles::activate( self::NAME );

		$rows = $this->rows_of( 'sandbox_active' );
		self::assertSame( $armed, $rows[0]['change_id'] );
		self::assertSame( 'armed', $rows[0]['status'] );
		ChangeJournal::settle( $armed, 'rolled_back' );
		self::assertSame( 'rolled_back', ChangeLedger::get( $armed )['status'] );
	}

	// ---- what is never stored ------------------------------------------------------------------------------------

	public function test_a_credential_named_file_is_written_but_never_stored(): void {
		$secret = 'fixture-secret-7f3a91';

		$result = SandboxFiles::write( 'wp-config.php', "<?php\n// {$secret}\n" );
		SandboxFiles::delete( 'wp-config.php' );

		self::assertTrue( $result );
		foreach ( $this->ledger_rows() as $row ) {
			self::assertSame( 'secret_file', $row['restorable_reason'] );
			self::assertSame( '', $row['before_ref'] );
			self::assertSame( '', $row['after_ref'] );
		}
		self::assertSame( [], $this->blob_files() );
		self::assertStringNotContainsString( $secret, (string) json_encode( $this->ledger_rows() ) );
	}

	// ---- a ledger failure never fails the write -------------------------------------------------------------------

	public function test_a_ledger_that_cannot_write_does_not_stop_sandbox_writes(): void {
		$this->break_ledger();

		self::assertTrue( SandboxFiles::write( self::NAME, self::V1 ) );
		self::assertTrue( SandboxFiles::edit( self::NAME, 'version one', 'version two' ) );
		self::assertTrue( SandboxFiles::activate( self::NAME ) );
		self::assertFileExists( $this->twin() );
		self::assertTrue( SandboxFiles::deactivate( self::NAME ) );
		self::assertTrue( SandboxFiles::delete( self::NAME ) );
	}

	public function test_a_ledger_that_throws_does_not_stop_sandbox_writes(): void {
		$this->explode_ledger();

		self::assertTrue( SandboxFiles::write( self::NAME, self::V1 ) );
		self::assertTrue( SandboxFiles::activate( self::NAME ) );
	}

	// ---- restore -------------------------------------------------------------------------------------------------

	public function test_restore_puts_the_earlier_draft_back_through_sandbox_files(): void {
		SandboxFiles::write( self::NAME, self::V1 );
		SandboxFiles::write( self::NAME, self::V2 );
		$second = $this->rows_of( 'sandbox_draft' )[1];

		$result = CodeAdapter::restore_sandbox( $second['change_id'] );

		self::assertSame( 'succeeded', $result['status'], $result['detail'] );
		self::assertSame( self::V1, SandboxFiles::read( self::NAME ) );
		self::assertNotSame( [], SandboxFiles::backup_versions( self::NAME ), 'The restore kept what it replaced, as every write does.' );
		$rollback = ChangeLedger::get( (string) $result['rollback_change_id'] );
		self::assertSame( 'rollback', $rollback['kind'] );
		self::assertSame( $second['change_id'], $rollback['parent_id'] );
		self::assertSame( self::V2, ChangeLedger::read_image( $rollback['change_id'], 'before' ) );
	}

	public function test_restoring_a_created_draft_deletes_it(): void {
		SandboxFiles::write( self::NAME, self::V1 );
		$first = $this->rows_of( 'sandbox_draft' )[0];

		$result = CodeAdapter::restore( $first['change_id'] );

		self::assertSame( 'succeeded', $result['status'], $result['detail'] );
		self::assertFileDoesNotExist( SandboxFiles::stored_path( self::NAME ) );
	}

	public function test_restore_puts_the_earlier_active_copy_back(): void {
		SandboxFiles::write( self::NAME, self::V1 );
		SandboxFiles::activate( self::NAME );
		$first = (string) file_get_contents( $this->twin() );
		SandboxFiles::write( self::NAME, self::V2 );
		SandboxFiles::activate( self::NAME );
		$second = $this->rows_of( 'sandbox_active' )[1];

		$result = CodeAdapter::restore_sandbox( $second['change_id'] );

		self::assertSame( 'succeeded', $result['status'], $result['detail'] );
		self::assertSame( $first, file_get_contents( $this->twin() ) );
		self::assertSame( self::V2, SandboxFiles::read( self::NAME ), 'The draft is not touched by restoring the active copy.' );
	}

	public function test_restoring_a_first_activation_removes_the_active_copy(): void {
		SandboxFiles::write( self::NAME, self::V1 );
		SandboxFiles::activate( self::NAME );
		$first = $this->rows_of( 'sandbox_active' )[0];

		$result = CodeAdapter::restore_sandbox( $first['change_id'] );

		self::assertSame( 'succeeded', $result['status'], $result['detail'] );
		self::assertFileDoesNotExist( $this->twin() );
		self::assertSame( self::V1, SandboxFiles::read( self::NAME ), 'The draft stays.' );
	}

	public function test_restore_refuses_when_the_file_changed_since_the_expected_hash(): void {
		SandboxFiles::write( self::NAME, self::V1 );
		SandboxFiles::write( self::NAME, self::V2 );
		$second = $this->rows_of( 'sandbox_draft' )[1];
		SandboxFiles::write( self::NAME, "<?php\n// by hand\n" );

		$result = CodeAdapter::restore_sandbox( $second['change_id'], [ 'expected_current_sha256' => $second['after_sha256'] ] );

		self::assertSame( 'failed', $result['status'] );
		self::assertSame( 'current_changed', $result['detail'] );
		self::assertSame( "<?php\n// by hand\n", SandboxFiles::read( self::NAME ) );
	}

	public function test_restoring_an_active_copy_still_passes_the_static_guard(): void {
		$result = SandboxFiles::restore_active( self::NAME, "<?php\neval( 'x' );\n" );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_sandbox_static_guard', $result->get_error_code() );
		self::assertFileDoesNotExist( $this->twin() );
	}

	public function test_restore_refuses_a_reserved_or_invalid_name(): void {
		$row = ChangeLedger::record(
			[
				'ability'       => 'stonewright/sandbox-write',
				'family'        => 'sandbox',
				'resource_type' => 'sandbox_draft',
				'resource_id'   => '../escape.php',
				'before'        => "<?php\n// x\n",
			]
		);

		$result = CodeAdapter::restore_sandbox( (string) $row['change_id'] );

		self::assertSame( 'failed', $result['status'] );
		self::assertSame( 'invalid_name', $result['detail'] );
	}

	public function test_the_live_hash_of_a_sandbox_resource_follows_the_file(): void {
		SandboxFiles::write( self::NAME, self::V1 );
		$row = $this->rows_of( 'sandbox_draft' )[0];

		self::assertSame( $row['after_sha256'], CodeAdapter::live_sha256( $row['change_id'] ) );

		SandboxFiles::write( self::NAME, self::V2 );
		self::assertNotSame( $row['after_sha256'], CodeAdapter::live_sha256( $row['change_id'] ) );
	}
}
