<?php
/**
 * Theme file writes in the change ledger: record before, settle after, restore from the before image.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Security\Adapters\CodeAdapter;
use Stonewright\WpMcp\Security\ChangeJournal;
use Stonewright\WpMcp\Security\ChangeLedger;
use Stonewright\WpMcp\Security\ThemeWriteTransaction;
use Stonewright\WpMcp\Tests\Unit\Security\Fixtures\CodeAdapterHarness;

/**
 * @covers \Stonewright\WpMcp\Security\Adapters\CodeAdapter
 * @covers \Stonewright\WpMcp\Security\ThemeWriteTransaction
 */
final class CodeAdapterThemeTest extends TestCase {
	use CodeAdapterHarness;

	protected function setUp(): void {
		$this->set_up_harness();
	}

	protected function tearDown(): void {
		$this->tear_down_harness();
	}

	/** @return array<string, mixed> */
	private function plan( string $relative, ?string $before, string $after, array $extra = [] ): array {
		$path = $this->theme_dir() . '/' . $relative;
		if ( ! is_dir( dirname( $path ) ) ) {
			mkdir( dirname( $path ), 0777, true );
		}
		if ( null !== $before ) {
			file_put_contents( $path, $before );
		}
		return array_merge(
			[
				'absolute' => $path,
				'relative' => $relative,
				'before'   => $before ?? '',
				'after'    => $after,
				'language' => ThemeWriteTransaction::detect_language( $relative ),
			],
			$extra
		);
	}

	// ---- record and settle ---------------------------------------------------------------------

	public function test_a_patch_records_the_file_before_and_after_and_keeps_one_id_with_the_journal(): void {
		$result = ThemeWriteTransaction::apply( $this->plan( 'functions.php', "<?php\n// ok\n", "<?php\n// ok\n// better\n" ) );

		self::assertIsArray( $result );
		$row = $this->only_row();
		self::assertSame( 'theme_file', $row['family'] );
		self::assertSame( 'theme_file', $row['resource_type'] );
		self::assertSame( 'site-a/functions.php', $row['resource_id'] );
		self::assertSame( 'stonewright/theme-file-patch', $row['ability'] );
		self::assertSame( 'verified', $row['status'] );
		self::assertTrue( $row['restorable'] );
		self::assertSame( "<?php\n// ok\n", ChangeLedger::read_image( $row['change_id'], 'before' ) );
		self::assertSame( "<?php\n// ok\n// better\n", ChangeLedger::read_image( $row['change_id'], 'after' ) );
		self::assertSame( ChangeJournal::recent()[0]['id'], $row['change_id'], 'The journal and the ledger name the change the same way.' );
	}

	public function test_a_created_file_has_no_before_image_and_is_still_restorable(): void {
		$result = ThemeWriteTransaction::apply( $this->plan( 'inc/new-feature.php', null, "<?php\n// new\n" ) );

		self::assertIsArray( $result );
		$row = $this->only_row();
		self::assertSame( '', $row['before_ref'] );
		self::assertTrue( $row['restorable'], 'Undoing a created file means deleting it.' );
		self::assertSame( '', $row['restorable_reason'] );
		self::assertSame( "<?php\n// new\n", ChangeLedger::read_image( $row['change_id'], 'after' ) );
	}

	public function test_a_write_that_changes_nothing_records_nothing(): void {
		ThemeWriteTransaction::apply( $this->plan( 'style.css', "/* same */\n", "/* same */\n" ) );

		self::assertSame( [], $this->ledger_rows() );
	}

	public function test_a_write_the_site_cannot_survive_is_rolled_back_and_the_row_says_so(): void {
		$path = $this->theme_dir() . '/functions.php';
		$this->site( static fn (): string => str_contains( (string) file_get_contents( $path ), 'broken' ) ? 'broken' : 'healthy' );

		$result = ThemeWriteTransaction::apply( $this->plan( 'functions.php', "<?php\n// ok\n", "<?php\n// ok\n// broken\n" ) );

		self::assertInstanceOf( \WP_Error::class, $result );
		$row = $this->only_row();
		self::assertSame( 'rolled_back', $row['status'] );
		self::assertSame( "<?php\n// ok\n", ChangeLedger::read_image( $row['change_id'], 'before' ) );
		self::assertSame( '', $row['after_ref'], 'A write that was taken back leaves no after image.' );
	}

	public function test_a_site_check_that_could_not_run_is_not_called_verified(): void {
		$this->site( static fn (): string => 'unavailable' );

		ThemeWriteTransaction::apply( $this->plan( 'style.css', "/* a */\n", "/* b */\n" ) );

		self::assertSame( 'probe_unavailable', $this->only_row()['status'] );
	}

	public function test_a_candidate_that_fails_validation_records_nothing(): void {
		$result = ThemeWriteTransaction::apply( $this->plan( 'functions.php', "<?php\n// ok\n", "<?php\nobfuscated = array(1);\n" ) );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( [], $this->ledger_rows() );
	}

	// ---- what is never stored ---------------------------------------------------------------------

	public function test_a_credential_file_is_written_but_never_stored(): void {
		$secret = 'fixture-credential-7f3a91';
		$result = ThemeWriteTransaction::apply( $this->plan( 'wp-config.php', "<?php\n// {$secret}\n", "<?php\n// {$secret}-2\n", [ 'skip_smoke' => true ] ) );

		self::assertIsArray( $result, 'The ledger refusing an image does not stop the write.' );
		$row = $this->only_row();
		self::assertFalse( $row['restorable'] );
		self::assertSame( 'secret_file', $row['restorable_reason'] );
		self::assertSame( '', $row['before_ref'] );
		self::assertSame( '', $row['before_sha256'] );
		self::assertSame( '', $row['after_ref'] );
		self::assertSame( [], $this->blob_files(), 'No blob was written for a refused file.' );
		self::assertStringNotContainsString( $secret, (string) json_encode( $this->ledger_rows() ) );
	}

	public function test_a_dot_env_file_is_never_stored(): void {
		ThemeWriteTransaction::apply( $this->plan( 'inc/.env', "KEY=synthetic\n", "KEY=synthetic2\n", [ 'skip_smoke' => true, 'language' => 'text' ] ) );

		self::assertSame( 'secret_file', $this->only_row()['restorable_reason'] );
		self::assertSame( [], $this->blob_files() );
	}

	public function test_a_file_with_a_credential_line_is_stored_masked_and_not_restorable(): void {
		$line = "define( 'AUTH_KEY', 'synthetic-key-value' );";
		ThemeWriteTransaction::apply( $this->plan( 'functions.php', "<?php\n{$line}\n", "<?php\n{$line}\n// edit\n", [ 'skip_smoke' => true ] ) );

		$row = $this->only_row();
		self::assertFalse( $row['restorable'] );
		self::assertSame( 'masked_secret', $row['restorable_reason'] );
		self::assertStringNotContainsString( 'synthetic-key-value', (string) ChangeLedger::read_image( $row['change_id'], 'before' ) );
	}

	// ---- a ledger failure never fails the write -------------------------------------------------------

	public function test_a_ledger_that_cannot_write_does_not_fail_the_write(): void {
		$this->break_ledger();

		$result = ThemeWriteTransaction::apply( $this->plan( 'style.css', "/* a */\n", "/* b */\n" ) );

		self::assertIsArray( $result );
		self::assertSame( "/* b */\n", file_get_contents( $this->theme_dir() . '/style.css' ) );
		self::assertSame( 'verified', $result['verification_status'] );
	}

	public function test_a_ledger_that_throws_does_not_fail_the_write(): void {
		$this->explode_ledger();

		$result = ThemeWriteTransaction::apply( $this->plan( 'style.css', "/* a */\n", "/* b */\n" ) );

		self::assertIsArray( $result );
		self::assertSame( "/* b */\n", file_get_contents( $this->theme_dir() . '/style.css' ) );
	}

	// ---- restore ---------------------------------------------------------------------------------------

	public function test_restore_writes_the_before_image_back_through_the_theme_transaction(): void {
		ThemeWriteTransaction::apply( $this->plan( 'functions.php', "<?php\n// ok\n", "<?php\n// ok\n// better\n" ) );
		$change = $this->only_row()['change_id'];
		$swbak  = count( glob( $this->uploads_dir() . '/stonewright-theme-backups/*.swbak' ) ?: [] );

		$result = CodeAdapter::restore_theme_file( $change );

		self::assertSame( 'succeeded', $result['status'], $result['detail'] );
		self::assertSame( "<?php\n// ok\n", file_get_contents( $this->theme_dir() . '/functions.php' ) );
		self::assertSame(
			$swbak + 1,
			count( glob( $this->uploads_dir() . '/stonewright-theme-backups/*.swbak' ) ?: [] ),
			'The restore is itself a backed-up write: it went through ThemeWriteTransaction::apply().'
		);
	}

	public function test_a_restore_is_recorded_as_a_rollback_row_under_the_change(): void {
		ThemeWriteTransaction::apply( $this->plan( 'functions.php', "<?php\n// ok\n", "<?php\n// ok\n// better\n" ) );
		$change = $this->only_row()['change_id'];

		$result = CodeAdapter::restore_theme_file( $change );

		self::assertCount( 2, $this->ledger_rows() );
		$rollback = ChangeLedger::get( (string) $result['rollback_change_id'] );
		self::assertNotNull( $rollback );
		self::assertSame( 'rollback', $rollback['kind'] );
		self::assertSame( $change, $rollback['parent_id'] );
		self::assertSame( 'theme_file', $rollback['family'] );
		self::assertSame( "<?php\n// ok\n// better\n", ChangeLedger::read_image( $rollback['change_id'], 'before' ), 'A rollback keeps what it replaced, so it can be undone too.' );
	}

	public function test_restoring_a_created_file_deletes_it_after_backing_it_up(): void {
		ThemeWriteTransaction::apply( $this->plan( 'inc/new-feature.php', null, "<?php\n// new\n" ) );
		$change = $this->only_row()['change_id'];
		self::assertFileExists( $this->theme_dir() . '/inc/new-feature.php' );

		$result = CodeAdapter::restore_theme_file( $change );

		self::assertSame( 'succeeded', $result['status'], $result['detail'] );
		self::assertFileDoesNotExist( $this->theme_dir() . '/inc/new-feature.php' );
		self::assertCount( 1, glob( $this->uploads_dir() . '/stonewright-theme-backups/*.swbak' ) ?: [], 'The deleted bytes are kept in a backup.' );
		$rollback = ChangeLedger::get( (string) $result['rollback_change_id'] );
		self::assertSame( "<?php\n// new\n", ChangeLedger::read_image( $rollback['change_id'], 'before' ) );
		self::assertSame( '', $rollback['after_ref'] );
	}

	public function test_restore_refuses_when_the_file_changed_since_the_hash_the_caller_expected(): void {
		ThemeWriteTransaction::apply( $this->plan( 'functions.php', "<?php\n// ok\n", "<?php\n// ok\n// better\n" ) );
		$row = $this->only_row();
		file_put_contents( $this->theme_dir() . '/functions.php', "<?php\n// edited by hand\n" );

		$result = CodeAdapter::restore_theme_file( $row['change_id'], [ 'expected_current_sha256' => hash( 'sha256', "<?php\n// ok\n// better\n" ) ] );

		self::assertSame( 'failed', $result['status'] );
		self::assertSame( 'current_changed', $result['detail'] );
		self::assertSame( "<?php\n// edited by hand\n", file_get_contents( $this->theme_dir() . '/functions.php' ) );
	}

	public function test_restore_refuses_a_row_that_is_not_restorable(): void {
		$line = "define( 'AUTH_KEY', 'synthetic-key-value' );";
		ThemeWriteTransaction::apply( $this->plan( 'functions.php', "<?php\n{$line}\n", "<?php\n{$line}\n// edit\n", [ 'skip_smoke' => true ] ) );

		$result = CodeAdapter::restore_theme_file( $this->only_row()['change_id'] );

		self::assertSame( 'failed', $result['status'] );
		self::assertSame( 'not_restorable', $result['detail'] );
		self::assertStringContainsString( '// edit', (string) file_get_contents( $this->theme_dir() . '/functions.php' ) );
	}

	public function test_restore_refuses_a_path_that_leaves_the_theme(): void {
		$row = ChangeLedger::record(
			[
				'ability'       => 'stonewright/theme-file-patch',
				'family'        => 'theme_file',
				'resource_type' => 'theme_file',
				'resource_id'   => 'site-a/../../outside.php',
				'before'        => "<?php\n// x\n",
			]
		);
		self::assertIsArray( $row );

		$result = CodeAdapter::restore_theme_file( $row['change_id'] );

		self::assertSame( 'failed', $result['status'] );
		self::assertFileDoesNotExist( $this->harness_base . '/outside.php' );
	}

	public function test_restore_of_an_unknown_change_fails_cleanly(): void {
		$result = CodeAdapter::restore_theme_file( 'cs-' . str_repeat( 'a', 24 ) );

		self::assertSame( 'failed', $result['status'] );
		self::assertSame( 'change_not_found', $result['detail'] );
	}

	public function test_restore_dispatches_by_family_and_refuses_other_families(): void {
		ThemeWriteTransaction::apply( $this->plan( 'style.css', "/* a */\n", "/* b */\n" ) );
		$change = $this->only_row()['change_id'];

		self::assertSame( 'succeeded', CodeAdapter::restore( $change )['status'] );
		self::assertSame( "/* a */\n", file_get_contents( $this->theme_dir() . '/style.css' ) );

		$other = ChangeLedger::record(
			[
				'ability'       => 'stonewright/content-update-page',
				'family'        => 'post',
				'resource_type' => 'post',
				'resource_id'   => '42',
				'before'        => [ 'post_title' => 'x' ],
			]
		);
		self::assertSame( 'not_available', CodeAdapter::restore( (string) $other['change_id'] )['status'], 'The code adapter restores only code.' );
	}

	public function test_the_live_hash_matches_the_after_image_until_the_file_changes(): void {
		ThemeWriteTransaction::apply( $this->plan( 'style.css', "/* a */\n", "/* b */\n" ) );
		$row = $this->only_row();

		self::assertSame( $row['after_sha256'], CodeAdapter::live_sha256( $row['change_id'] ) );

		file_put_contents( $this->theme_dir() . '/style.css', "/* c */\n" );
		self::assertNotSame( $row['after_sha256'], CodeAdapter::live_sha256( $row['change_id'] ) );
	}

	// ---- the journal's outcome reaches the ledger ----------------------------------------------------------

	public function test_a_journal_outcome_moves_the_row_with_the_same_id(): void {
		ThemeWriteTransaction::apply( $this->plan( 'style.css', "/* a */\n", "/* b */\n", [ 'skip_smoke' => true ] ) );
		$row = $this->only_row();
		self::assertSame( 'probe_unavailable', $row['status'] );

		ChangeJournal::settle( $row['change_id'], 'rolled_back' );

		self::assertSame( 'rolled_back', ChangeLedger::get( $row['change_id'] )['status'] );
	}
}
