<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Security\ChangeJournalFile;

/**
 * The journal file is the compact copy that a dependency-free reader (the rescue
 * MU-plugin, WP-CLI with most plugins skipped) can load without the full plugin.
 *
 * @covers \Stonewright\WpMcp\Security\ChangeJournalFile
 */
final class ChangeJournalFileTest extends TestCase {

	private string $dir;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/sw-journal-' . bin2hex( random_bytes( 5 ) );
		mkdir( $this->dir, 0700, true );
	}

	protected function tearDown(): void {
		self::remove_tree( $this->dir );
	}

	private function file(): ChangeJournalFile {
		return new ChangeJournalFile( $this->dir . '/journal-' . str_repeat( 'a', 32 ) . '.json' );
	}

	/** @return array<string, mixed> */
	private static function entry( string $id, array $override = [] ): array {
		return array_merge(
			[
				'id'             => $id,
				'ability'        => 'stonewright/theme-file-patch',
				'resource_type'  => 'theme_file',
				'resource_key'   => 'functions.php',
				'recipe'         => [ 'type' => 'theme_backup', 'ref' => 'sw-theme-backup-1234' ],
				'paths'          => [ 'wp-content/themes/site-a/functions.php' ],
				'armed_at'       => 1000,
				'probe_deadline' => 1120,
				'state'          => 'armed',
				'incident'       => null,
			],
			$override
		);
	}

	public function test_a_file_over_one_megabyte_is_never_read(): void {
		self::assertSame( 1048576, ChangeJournalFile::MAX_BYTES, 'The same limit the rescue helper reads with.' );
		$journal = $this->file();
		file_put_contents( $journal->path(), str_repeat( ' ', ChangeJournalFile::MAX_BYTES ) . '{"version":1,"entries":[{"id":"cs-big","ability":"stonewright/a-b","resource_type":"option","resource_key":"x","recipe":{"type":"none","ref":""},"paths":[],"armed_at":1,"probe_deadline":2,"state":"armed","incident":null}]}' );
		clearstatcache();

		self::assertTrue( $journal->is_oversize() );
		self::assertNull( $journal->read_raw() );
		self::assertSame( [], $journal->read()['entries'], 'An oversize file reads as empty.' );
	}

	public function test_a_file_within_the_limit_reads_normally(): void {
		$journal = $this->file();
		file_put_contents( $journal->path(), '{"version":1,"entries":[]}' );
		clearstatcache();

		self::assertFalse( $journal->is_oversize() );
		self::assertSame( '{"version":1,"entries":[]}', $journal->read_raw() );
	}
	public function test_a_missing_file_reads_as_an_empty_document(): void {
		$document = $this->file()->read();

		self::assertSame( 1, $document['version'] );
		self::assertSame( [], $document['entries'] );
	}

	public function test_a_transaction_writes_exactly_the_shared_contract_shape(): void {
		$file = $this->file();
		$file->transaction(
			static function ( array $document ): array {
				$document['entries'][] = ChangeJournalFileTest::entry_for_shape();
				return $document;
			}
		);

		$raw     = json_decode( (string) file_get_contents( $file->path() ), true );
		$entry   = $raw['entries'][0];
		self::assertSame( [ 'version', 'updated_at', 'entries' ], array_keys( $raw ) );
		self::assertSame( 1, $raw['version'] );
		self::assertIsInt( $raw['updated_at'] );
		self::assertSame(
			[ 'id', 'ability', 'resource_type', 'resource_key', 'recipe', 'paths', 'armed_at', 'probe_deadline', 'state', 'incident' ],
			array_keys( $entry )
		);
		self::assertSame( [ 'type', 'ref' ], array_keys( $entry['recipe'] ) );
		self::assertNull( $entry['incident'] );
	}

	/** @return array<string, mixed> */
	public static function entry_for_shape(): array {
		return self::entry( 'cs-shape' );
	}

	public function test_unknown_keys_and_unusable_entries_are_dropped_on_write(): void {
		$file = $this->file();
		$file->transaction(
			static function ( array $document ): array {
				$document['entries'][] = ChangeJournalFileTest::entry_for_shape() + [ 'request_body' => '{"secret":"x"}' ];
				$document['entries'][] = [ 'id' => '../../etc/passwd', 'state' => 'armed' ];
				$document['entries'][] = 'not-an-entry';
				return $document;
			}
		);

		$raw = json_decode( (string) file_get_contents( $file->path() ), true );
		self::assertCount( 1, $raw['entries'] );
		self::assertArrayNotHasKey( 'request_body', $raw['entries'][0] );
		self::assertStringNotContainsString( 'secret', (string) file_get_contents( $file->path() ) );
	}

	public function test_field_values_are_bounded_and_secret_like_values_are_redacted(): void {
		$file = $this->file();
		$file->transaction(
			static function ( array $document ): array {
				$document['entries'][] = ChangeJournalFileTest::entry_for_shape();
				$document['entries'][0]['resource_key'] = str_repeat( 'k', 400 );
				$document['entries'][0]['recipe']['ref'] = 'Bearer abcdefghijklmnopqrstuvwxyz0123456789';
				$document['entries'][0]['paths']          = [ 'wp-content/../../outside.php', '/abs/path.php', 'C:/Windows/x.php', 'wp-content/themes/site-a/ok.php' ];
				$document['entries'][0]['state']          = 'exploded';
				return $document;
			}
		);

		$entry = $file->read()['entries'][0];
		self::assertSame( 191, strlen( $entry['resource_key'] ) );
		self::assertSame( '[redacted]', $entry['recipe']['ref'] );
		self::assertSame( [ 'wp-content/themes/site-a/ok.php' ], $entry['paths'] );
		self::assertSame( 'armed', $entry['state'] );
	}

	public function test_an_incident_object_keeps_only_the_contract_fields(): void {
		$file = $this->file();
		$file->transaction(
			static function ( array $document ): array {
				$entry             = ChangeJournalFileTest::entry_for_shape();
				$entry['state']    = 'incident';
				$entry['incident'] = [
					'recorded_at'    => 2000,
					'file'           => 'wp-content/themes/site-a/functions.php',
					'line'           => 12,
					'type'           => 1,
					'message_sha256' => str_repeat( 'b', 64 ),
					'source'         => 'shutdown',
					'message'        => 'Uncaught Error: raw text must never be stored',
				];
				$document['entries'][] = $entry;
				return $document;
			}
		);

		$entry = $file->read()['entries'][0];
		self::assertSame( 'incident', $entry['state'] );
		self::assertSame( [ 'recorded_at', 'file', 'line', 'type', 'message_sha256', 'source' ], array_keys( $entry['incident'] ) );
		self::assertSame( str_repeat( 'b', 64 ), $entry['incident']['message_sha256'] );
	}

	public function test_it_keeps_at_most_fifty_entries_and_drops_the_oldest_settled_first(): void {
		$file = $this->file();
		$file->transaction(
			static function ( array $document ): array {
				// One old unsettled entry, then 55 settled ones that are newer.
				$document['entries'][] = ChangeJournalFileTest::entry_with( 'cs-open-old', 10, 'armed' );
				for ( $i = 1; $i <= 55; $i++ ) {
					$document['entries'][] = ChangeJournalFileTest::entry_with( sprintf( 'cs-settled-%02d', $i ), 100 + $i, 'verified' );
				}
				return $document;
			}
		);

		$entries = $file->read()['entries'];
		$ids     = array_column( $entries, 'id' );
		self::assertCount( 50, $entries );
		self::assertContains( 'cs-open-old', $ids, 'An unsettled entry outlives older settled entries.' );
		self::assertNotContains( 'cs-settled-01', $ids );
		self::assertNotContains( 'cs-settled-06', $ids );
		self::assertContains( 'cs-settled-07', $ids );
		self::assertContains( 'cs-settled-55', $ids );
	}

	/** @return array<string, mixed> */
	public static function entry_with( string $id, int $armed_at, string $state ): array {
		return self::entry( $id, [ 'armed_at' => $armed_at, 'probe_deadline' => $armed_at + 120, 'state' => $state ] );
	}

	public function test_when_everything_is_unsettled_the_oldest_entries_go(): void {
		$file = $this->file();
		$file->transaction(
			static function ( array $document ): array {
				for ( $i = 1; $i <= 52; $i++ ) {
					$document['entries'][] = ChangeJournalFileTest::entry_with( sprintf( 'cs-open-%02d', $i ), $i, 'armed' );
				}
				return $document;
			}
		);

		$ids = array_column( $file->read()['entries'], 'id' );
		self::assertCount( 50, $ids );
		self::assertNotContains( 'cs-open-01', $ids );
		self::assertNotContains( 'cs-open-02', $ids );
		self::assertContains( 'cs-open-52', $ids );
	}

	public function test_a_corrupt_file_reads_as_empty_and_is_replaced_by_the_next_write(): void {
		$file = $this->file();
		file_put_contents( $file->path(), '{"version":1,"entries":[{"id":"cs-half' );

		self::assertSame( [], $file->read()['entries'] );

		$file->transaction(
			static function ( array $document ): array {
				$document['entries'][] = ChangeJournalFileTest::entry_for_shape();
				return $document;
			}
		);
		self::assertSame( [ 'cs-shape' ], array_column( $file->read()['entries'], 'id' ) );
	}

	public function test_a_write_leaves_no_temporary_file_and_replaces_the_target_by_rename(): void {
		$file = $this->file();
		for ( $i = 0; $i < 5; $i++ ) {
			$file->transaction(
				static function ( array $document ) use ( $i ): array {
					$document['entries'][] = ChangeJournalFileTest::entry_with( 'cs-' . $i, 100 + $i, 'armed' );
					return $document;
				}
			);
		}

		$names = array_values( array_diff( scandir( $this->dir ) ?: [], [ '.', '..' ] ) );
		sort( $names );
		self::assertSame( [ basename( $file->path() ), basename( $file->path() ) . '.lock' ], $names );
	}

	public function test_a_held_lock_makes_a_write_give_up_instead_of_blocking(): void {
		$file = $this->file();
		$held = fopen( $file->lock_path(), 'c' );
		self::assertIsResource( $held );
		self::assertTrue( flock( $held, LOCK_EX ) );

		$started = microtime( true );
		$result  = $file->transaction(
			static function ( array $document ): array {
				$document['entries'][] = ChangeJournalFileTest::entry_for_shape();
				return $document;
			},
			150
		);
		$elapsed = microtime( true ) - $started;
		flock( $held, LOCK_UN );
		fclose( $held );

		self::assertNull( $result );
		self::assertLessThan( 2.0, $elapsed );
		self::assertFileDoesNotExist( $file->path() );
	}

	public function test_concurrent_writers_do_not_lose_updates(): void {
		$file    = $this->file();
		$workers = 4;
		$each    = 12;
		$procs   = [];
		for ( $w = 0; $w < $workers; $w++ ) {
			$procs[] = $this->spawn_writer( $file->path(), $w, $each, 2 );
		}
		foreach ( $procs as $proc ) {
			self::assertSame( 0, $this->finish( $proc ), 'A writer process failed or timed out acquiring the lock.' );
		}

		$ids = array_column( $file->read()['entries'], 'id' );
		self::assertCount( $workers * $each, $ids );
		self::assertCount( $workers * $each, array_unique( $ids ), 'Every update must survive exactly once.' );
	}

	public function test_a_reader_never_sees_a_partial_document_while_writers_replace_the_file(): void {
		$file = $this->file();
		$proc = $this->spawn_writer( $file->path(), 9, 40, 1 );

		$invalid   = 0;
		$reads     = 0;
		$exit_code = null;
		$deadline  = microtime( true ) + 8.0;
		do {
			$status = proc_get_status( $proc['process'] );
			if ( ! $status['running'] && null === $exit_code ) {
				$exit_code = (int) $status['exitcode'];
			}
			$raw    = @file_get_contents( $file->path() );
			if ( false === $raw ) {
				usleep( 500 );
				continue;
			}
			++$reads;
			$decoded = json_decode( $raw, true );
			if ( ! is_array( $decoded ) || 1 !== ( $decoded['version'] ?? null ) || ! is_array( $decoded['entries'] ?? null ) ) {
				++$invalid;
			}
		} while ( $status['running'] && microtime( true ) < $deadline );

		self::assertSame( 0, $this->finish( $proc, $exit_code ) );
		self::assertGreaterThan( 5, $reads );
		self::assertSame( 0, $invalid, 'The target must always hold a complete document.' );
		self::assertCount( 40, $file->read()['entries'] );
	}

	public function test_the_state_directory_is_closed_to_the_web_and_listings(): void {
		$dir = $this->dir . '/stonewright-state';
		self::assertTrue( ChangeJournalFile::protect_directory( $dir ) );

		self::assertFileExists( $dir . '/index.php' );
		ob_start();
		include $dir . '/index.php';
		self::assertSame( '', (string) ob_get_clean(), 'index.php must be silent.' );
		$htaccess = (string) file_get_contents( $dir . '/.htaccess' );
		self::assertStringContainsString( 'Require all denied', $htaccess );
		self::assertStringContainsString( 'Deny from all', $htaccess );
		self::assertFileExists( $dir . '/web.config' );
	}

	public function test_a_state_directory_name_is_random_and_matches_the_contract(): void {
		$name = ChangeJournalFile::new_file_name();

		self::assertMatchesRegularExpression( '/^journal-[a-f0-9]{32}\.json$/', $name );
		self::assertNotSame( $name, ChangeJournalFile::new_file_name() );
		self::assertTrue( ChangeJournalFile::is_valid_file_name( $name ) );
		self::assertFalse( ChangeJournalFile::is_valid_file_name( '../journal-' . str_repeat( 'a', 32 ) . '.json' ) );
		self::assertFalse( ChangeJournalFile::is_valid_file_name( 'journal-' . str_repeat( 'A', 32 ) . '.json' ) );
	}

	/**
	 * @return array{process:resource,pipes:array<int,resource>}
	 */
	private function spawn_writer( string $path, int $worker, int $count, int $pause_ms ): array {
		$script = dirname( __DIR__, 2 ) . '/fixtures/rescue/journal-writer.php';
		$cmd    = [ PHP_BINARY, $script, $path, (string) $worker, (string) $count, (string) $pause_ms ];
		$process = proc_open( $cmd, [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
		self::assertIsResource( $process, 'Could not start the writer process.' );
		return [ 'process' => $process, 'pipes' => $pipes ];
	}

	/**
	 * Waits for a writer process and returns its exit code.
	 *
	 * Before PHP 8.3 only the first proc_get_status() call after the process ended reports its
	 * exit code, and proc_close() then returns -1, so the first code seen is kept.
	 *
	 * @param array{process:resource,pipes:array<int,resource>} $proc
	 * @param int|null                                           $exit_code Code a caller already read from proc_get_status().
	 */
	private function finish( array $proc, ?int $exit_code = null ): int {
		$deadline = microtime( true ) + 60.0;
		while ( null === $exit_code && microtime( true ) < $deadline ) {
			$status = proc_get_status( $proc['process'] );
			if ( ! $status['running'] ) {
				$exit_code = (int) $status['exitcode'];
				break;
			}
			usleep( 20000 );
		}
		foreach ( $proc['pipes'] as $pipe ) {
			stream_get_contents( $pipe );
			fclose( $pipe );
		}
		$closed = proc_close( $proc['process'] );
		return null !== $exit_code && -1 !== $exit_code ? $exit_code : $closed;
	}

	private static function remove_tree( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) ?: [] as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			is_dir( $path ) ? self::remove_tree( $path ) : @unlink( $path );
		}
		@rmdir( $dir );
	}
}
