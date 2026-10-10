<?php
/**
 * The .swbak files of theme writes: the rollback keeps working, and files nothing refers to are pruned.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Security\ChangeJournal;
use Stonewright\WpMcp\Security\RollbackRecipes;
use Stonewright\WpMcp\Security\ThemeWriteTransaction;
use Stonewright\WpMcp\Tests\Unit\Security\Fixtures\CodeAdapterHarness;

/**
 * @covers \Stonewright\WpMcp\Security\ThemeWriteTransaction
 */
final class ThemeBackupPruneTest extends TestCase {
	use CodeAdapterHarness;

	private const INDEX = 'stonewright_theme_backup_index';

	protected function setUp(): void {
		$this->set_up_harness();
	}

	protected function tearDown(): void {
		$this->tear_down_harness();
	}

	private function backup_dir(): string {
		return $this->uploads_dir() . '/stonewright-theme-backups';
	}

	/** @return list<string> */
	private function swbak_names(): array {
		$names = array_map( 'basename', glob( $this->backup_dir() . '/*.swbak' ) ?: [] );
		sort( $names );
		return $names;
	}

	/** An orphan-shaped file of the given age that no index entry refers to. */
	private function stray( string $label, int $age_seconds ): string {
		if ( ! is_dir( $this->backup_dir() ) ) {
			mkdir( $this->backup_dir(), 0777, true );
		}
		$name = gmdate( 'Ymd-His', time() - $age_seconds ) . '-' . hash( 'sha256', $label ) . '-' . $label . '.swbak';
		file_put_contents( $this->backup_dir() . '/' . $name, "<?php\n// {$label}\n" );
		touch( $this->backup_dir() . '/' . $name, time() - $age_seconds );
		return $name;
	}

	private function write( string $body ): void {
		$path   = $this->theme_dir() . '/functions.php';
		$before = is_file( $path ) ? (string) file_get_contents( $path ) : "<?php\n// start\n";
		file_put_contents( $path, $before );
		$result = ThemeWriteTransaction::apply(
			[
				'absolute'   => $path,
				'relative'   => 'functions.php',
				'before'     => $before,
				'after'      => $body,
				'language'   => 'php',
				'skip_smoke' => true,
			]
		);
		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
	}

	// ---- the existing rollback still works ---------------------------------------------------------------

	public function test_the_journal_recipe_still_restores_from_the_swbak_backup(): void {
		$this->write( "<?php\n// changed\n" );
		$entry = ChangeJournal::recent()[0];

		$result = RollbackRecipes::run( $entry );

		self::assertSame( 'succeeded', $result['status'], $result['detail'] );
		self::assertSame( "<?php\n// start\n", file_get_contents( $this->theme_dir() . '/functions.php' ) );
	}

	public function test_the_journal_entry_names_the_backup_file(): void {
		$this->write( "<?php\n// changed\n" );

		$entry = ChangeJournal::recent()[0];

		self::assertSame( $this->swbak_names()[0], $entry['recipe_detail']['backup_file'] );
	}

	public function test_two_writes_to_one_file_in_the_same_second_keep_both_backups(): void {
		$this->write( "<?php\n// changed\n" );
		$this->write( "<?php\n// changed again\n" );

		$bodies = array_map( static fn ( string $file ): string => (string) file_get_contents( $file ), glob( $this->backup_dir() . '/*.swbak' ) ?: [] );
		sort( $bodies );
		self::assertSame( [ "<?php\n// changed\n", "<?php\n// start\n" ], $bodies, 'The second backup did not replace the first.' );

		$older = array_values( array_filter( ChangeJournal::recent(), static fn ( array $e ): bool => 'theme_backup' === $e['recipe']['type'] ) );
		self::assertCount( 2, $older );
		foreach ( $older as $entry ) {
			self::assertSame( 'succeeded', RollbackRecipes::run( $entry )['status'], 'Each backup still passes its integrity hash.' );
		}
	}

	// ---- the prune ------------------------------------------------------------------------------------------

	public function test_a_file_no_index_entry_and_no_journal_entry_refers_to_is_removed(): void {
		$orphan = $this->stray( 'orphan.css', 3 * DAY_IN_SECONDS );

		$result = ThemeWriteTransaction::prune_orphan_backups();

		self::assertSame( 1, $result['removed'] );
		self::assertFileDoesNotExist( $this->backup_dir() . '/' . $orphan );
	}

	public function test_a_file_an_index_entry_refers_to_is_kept_however_old(): void {
		$this->write( "<?php\n// changed\n" );
		foreach ( glob( $this->backup_dir() . '/*.swbak' ) ?: [] as $file ) {
			touch( $file, time() - 30 * DAY_IN_SECONDS );
		}

		$result = ThemeWriteTransaction::prune_orphan_backups();

		self::assertSame( 0, $result['removed'] );
		self::assertCount( 1, $this->swbak_names() );
	}

	public function test_a_file_an_open_journal_entry_refers_to_is_kept_after_the_index_lost_it(): void {
		$this->write( "<?php\n// changed\n" );
		$name = $this->swbak_names()[0];
		touch( $this->backup_dir() . '/' . $name, time() - 30 * DAY_IN_SECONDS );
		$GLOBALS['stonewright_test_options'][ self::INDEX ] = [];

		$result = ThemeWriteTransaction::prune_orphan_backups();

		self::assertSame( 0, $result['removed'], 'The journal entry still points at the file.' );
		self::assertFileExists( $this->backup_dir() . '/' . $name );
	}

	public function test_a_file_that_is_still_young_is_kept_unless_the_grace_is_lifted(): void {
		$young = $this->stray( 'young.css', 60 );

		self::assertSame( 0, ThemeWriteTransaction::prune_orphan_backups()['removed'], 'A backup may be written a moment before its index entry.' );
		self::assertFileExists( $this->backup_dir() . '/' . $young );

		self::assertSame( 1, ThemeWriteTransaction::prune_orphan_backups( 0 )['removed'] );
	}

	public function test_only_backup_files_are_touched(): void {
		$this->stray( 'orphan.css', 3 * DAY_IN_SECONDS );
		foreach ( [ 'index.html', '.htaccess', 'notes.txt', 'x.swbak.keep', 'not-a-backup.swbak' ] as $name ) {
			file_put_contents( $this->backup_dir() . '/' . $name, 'keep' );
			touch( $this->backup_dir() . '/' . $name, time() - 30 * DAY_IN_SECONDS );
		}

		$result = ThemeWriteTransaction::prune_orphan_backups();

		self::assertSame( 1, $result['removed'] );
		foreach ( [ 'index.html', '.htaccess', 'notes.txt', 'x.swbak.keep', 'not-a-backup.swbak' ] as $name ) {
			self::assertFileExists( $this->backup_dir() . '/' . $name );
		}
	}

	public function test_a_missing_backup_folder_is_not_an_error(): void {
		self::assertSame( [ 'removed' => 0, 'kept' => 0 ], ThemeWriteTransaction::prune_orphan_backups() );
	}

	public function test_trimming_the_index_prunes_the_files_it_let_go_of_and_keeps_the_referenced_ones(): void {
		mkdir( $this->backup_dir(), 0777, true );
		$index = [];
		$names = [];
		for ( $i = 1; $i <= 100; ++$i ) {
			$label = sprintf( 'f%03d.css', $i );
			$name  = $this->stray( $label, ( 200 - $i ) * 3600 + 7200 );
			$ref   = 'sw-theme-backup-' . sprintf( '%08d-0000-4000-8000-%012d', $i, $i );
			$index[ $ref ] = [
				'absolute'    => $this->theme_dir() . '/' . $label,
				'relative'    => $label,
				'backup_path' => $this->backup_dir() . '/' . $name,
				'sha256'      => hash( 'sha256', "<?php\n// {$label}\n" ),
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
			];
			$names[ $i ] = $name;
		}
		$GLOBALS['stonewright_test_options'][ self::INDEX ] = $index;

		$this->write( "<?php\n// the 101st\n" );

		self::assertCount( 100, $GLOBALS['stonewright_test_options'][ self::INDEX ], 'The index is still capped at 100.' );
		self::assertFileDoesNotExist( $this->backup_dir() . '/' . $names[1], 'The oldest entry fell out of the index: its file went with it.' );
		self::assertFileExists( $this->backup_dir() . '/' . $names[2] );
		self::assertFileExists( $this->backup_dir() . '/' . $names[100] );
	}
}
