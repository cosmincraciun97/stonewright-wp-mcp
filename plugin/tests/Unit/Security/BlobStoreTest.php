<?php
/**
 * The content-addressed blob store behind the change ledger.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Security\BlobStore;

/**
 * @covers \Stonewright\WpMcp\Security\BlobStore
 */
final class BlobStoreTest extends TestCase {

	private string $base = '';

	protected function setUp(): void {
		$this->base = str_replace( '\\', '/', sys_get_temp_dir() ) . '/sw-blob-' . bin2hex( random_bytes( 5 ) );
		mkdir( $this->base, 0777, true );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['stonewright_test_upload_dir'] );
		self::remove_tree( $this->base );
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

	private function store( int $total = BlobStore::DEFAULT_MAX_TOTAL_BYTES, int $blob = BlobStore::MAX_BLOB_BYTES, int $raw = BlobStore::MAX_RAW_BYTES ): BlobStore {
		return new BlobStore( $this->base . '/stonewright-state/blobs', $total, $blob, $raw );
	}

	/** @return list<string> */
	private function blob_files(): array {
		return array_map( 'basename', glob( $this->base . '/stonewright-state/blobs/*.gz' ) ?: [] );
	}

	public function test_a_blob_is_stored_gzipped_under_the_sha256_of_its_bytes(): void {
		$bytes  = str_repeat( "synthetic content for site-a\n", 40 );
		$stored = $this->store()->put( $bytes );

		self::assertIsArray( $stored );
		self::assertSame( hash( 'sha256', $bytes ), $stored['sha256'] );
		self::assertSame( strlen( $bytes ), $stored['raw_bytes'] );
		self::assertTrue( $stored['created'] );
		$file = $this->base . '/stonewright-state/blobs/' . $stored['sha256'] . '.gz';
		self::assertFileExists( $file );
		self::assertSame( filesize( $file ), $stored['stored_bytes'] );
		self::assertLessThan( strlen( $bytes ), $stored['stored_bytes'], 'the file is compressed' );
		self::assertSame( $bytes, gzdecode( (string) file_get_contents( $file ) ) );
	}

	public function test_the_same_bytes_are_stored_once(): void {
		$store = $this->store();
		$first = $store->put( 'one shared image' );
		$total = $store->total_bytes();
		$again = $store->put( 'one shared image' );

		self::assertIsArray( $first );
		self::assertIsArray( $again );
		self::assertSame( $first['sha256'], $again['sha256'] );
		self::assertFalse( $again['created'] );
		self::assertCount( 1, $this->blob_files() );
		self::assertSame( $total, $store->total_bytes() );
	}

	public function test_a_blob_reads_back_unchanged(): void {
		$store  = $this->store();
		$bytes  = "line 1\nline 2\n\0binary-safe\xff";
		$stored = $store->put( $bytes );

		self::assertIsArray( $stored );
		self::assertSame( $bytes, $store->get( $stored['sha256'] ) );
		self::assertTrue( $store->has( $stored['sha256'] ) );
		self::assertSame( $stored['stored_bytes'], $store->stored_size( $stored['sha256'] ) );
	}

	public function test_a_blob_whose_content_no_longer_matches_its_name_fails_the_integrity_check(): void {
		$store  = $this->store();
		$stored = $store->put( 'the original image' );
		self::assertIsArray( $stored );
		$file = $this->base . '/stonewright-state/blobs/' . $stored['sha256'] . '.gz';

		file_put_contents( $file, (string) gzencode( 'a different image' ) );
		$swapped = $store->get( $stored['sha256'] );
		self::assertInstanceOf( \WP_Error::class, $swapped );
		self::assertSame( 'stonewright_blob_corrupt', $swapped->get_error_code() );

		file_put_contents( $file, 'not gzip at all' );
		$garbage = $store->get( $stored['sha256'] );
		self::assertInstanceOf( \WP_Error::class, $garbage );
		self::assertSame( 'stonewright_blob_corrupt', $garbage->get_error_code() );

		file_put_contents( $file, substr( (string) gzencode( 'the original image' ), 0, 12 ) );
		$cut = $store->get( $stored['sha256'] );
		self::assertInstanceOf( \WP_Error::class, $cut );
		self::assertSame( 'stonewright_blob_corrupt', $cut->get_error_code() );
	}

	public function test_a_blob_that_inflates_past_the_cap_is_not_read(): void {
		$store  = $this->store( BlobStore::DEFAULT_MAX_TOTAL_BYTES, BlobStore::MAX_BLOB_BYTES, 4096 );
		$bomb   = str_repeat( 'A', 200000 );
		$sha    = hash( 'sha256', $bomb );
		$file   = $this->base . '/stonewright-state/blobs/' . $sha . '.gz';
		$stored = $store->put( 'seed so the folder exists' );
		self::assertIsArray( $stored );
		file_put_contents( $file, (string) gzencode( $bomb ) );

		$read = $store->get( $sha );

		self::assertInstanceOf( \WP_Error::class, $read );
		self::assertSame( 'stonewright_blob_corrupt', $read->get_error_code() );
	}

	public function test_a_missing_blob_is_an_error_not_an_empty_string(): void {
		$read = $this->store()->get( str_repeat( 'a', 64 ) );

		self::assertInstanceOf( \WP_Error::class, $read );
		self::assertSame( 'stonewright_blob_missing', $read->get_error_code() );
	}

	/** @return array<string, array{0: string}> */
	public static function badHashes(): array {
		return [
			'traversal'   => [ '../../wp-config' ],
			'path'        => [ 'blobs/' . str_repeat( 'a', 56 ) ],
			'uppercase'   => [ str_repeat( 'A', 64 ) ],
			'short'       => [ 'abc123' ],
			'extension'   => [ str_repeat( 'a', 60 ) . '.gz' ],
			'null byte'   => [ str_repeat( 'a', 63 ) . "\0" ],
			'empty'       => [ '' ],
		];
	}

	/** @dataProvider badHashes */
	public function test_a_name_that_is_not_a_sha256_never_reaches_the_file_system( string $name ): void {
		$store = $this->store();
		$store->put( 'seed' );

		$read = $store->get( $name );
		self::assertInstanceOf( \WP_Error::class, $read );
		self::assertSame( 'stonewright_blob_invalid_hash', $read->get_error_code() );
		self::assertFalse( $store->has( $name ) );
		self::assertFalse( $store->delete( $name ) );
		self::assertSame( 0, $store->stored_size( $name ) );
		self::assertCount( 1, $this->blob_files() );
	}

	public function test_an_image_over_the_raw_cap_is_refused(): void {
		$store  = $this->store( BlobStore::DEFAULT_MAX_TOTAL_BYTES, BlobStore::MAX_BLOB_BYTES, 1000 );
		$result = $store->put( str_repeat( 'x', 1001 ) );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_blob_too_large', $result->get_error_code() );
		self::assertSame( [], $this->blob_files() );
		self::assertIsArray( $store->put( str_repeat( 'x', 1000 ) ), 'the cap itself is allowed' );
	}

	public function test_an_image_that_stays_over_the_compressed_cap_is_refused(): void {
		$store  = $this->store( BlobStore::DEFAULT_MAX_TOTAL_BYTES, 512, BlobStore::MAX_RAW_BYTES );
		$result = $store->put( random_bytes( 2048 ) );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_blob_too_large', $result->get_error_code() );
		self::assertSame( [], $this->blob_files() );
		self::assertSame( [], glob( $this->base . '/stonewright-state/blobs/*.tmp*' ) ?: [], 'no temporary file is left' );
	}

	public function test_the_total_cap_refuses_a_new_blob_but_still_dedupes_an_existing_one(): void {
		$store = $this->store( 700 );
		$first = $store->put( random_bytes( 400 ) );
		self::assertIsArray( $first );

		$second = $store->put( random_bytes( 400 ) );
		self::assertInstanceOf( \WP_Error::class, $second );
		self::assertSame( 'stonewright_blob_store_full', $second->get_error_code() );
		self::assertCount( 1, $this->blob_files() );

		$known = $store->put( (string) $store->get( $first['sha256'] ) );
		self::assertIsArray( $known );
		self::assertFalse( $known['created'] );
	}

	public function test_every_folder_the_store_creates_carries_the_deny_files(): void {
		self::assertIsArray( $this->store()->put( 'seed' ) );

		foreach ( [ $this->base . '/stonewright-state', $this->base . '/stonewright-state/blobs' ] as $dir ) {
			foreach ( [ 'index.php', '.htaccess', 'web.config' ] as $guard ) {
				self::assertFileExists( $dir . '/' . $guard, $dir . ' ' . $guard );
			}
			self::assertStringContainsString( 'Require all denied', (string) file_get_contents( $dir . '/.htaccess' ) );
			self::assertStringContainsString( 'Deny from all', (string) file_get_contents( $dir . '/.htaccess' ) );
			self::assertStringContainsString( '<deny users="*" />', (string) file_get_contents( $dir . '/web.config' ) );
			self::assertStringContainsString( 'Silence is golden', (string) file_get_contents( $dir . '/index.php' ) );
		}
	}

	public function test_a_root_with_deeper_new_folders_guards_every_level_it_creates(): void {
		$store = new BlobStore( $this->base . '/stonewright-private/ledger/blobs' );
		self::assertIsArray( $store->put( 'seed' ) );

		foreach ( [ 'stonewright-private', 'stonewright-private/ledger', 'stonewright-private/ledger/blobs' ] as $level ) {
			self::assertFileExists( $this->base . '/' . $level . '/.htaccess', $level );
			self::assertFileExists( $this->base . '/' . $level . '/index.php', $level );
			self::assertFileExists( $this->base . '/' . $level . '/web.config', $level );
		}
	}

	public function test_existing_deny_files_are_not_overwritten(): void {
		$state = $this->base . '/stonewright-state';
		mkdir( $state, 0777, true );
		file_put_contents( $state . '/.htaccess', 'Require all denied # site owner edit' );

		self::assertIsArray( $this->store()->put( 'seed' ) );

		self::assertSame( 'Require all denied # site owner edit', file_get_contents( $state . '/.htaccess' ) );
	}

	public function test_a_root_with_a_parent_segment_is_not_used(): void {
		$store  = new BlobStore( $this->base . '/stonewright-state/../outside' );
		$result = $store->put( 'seed' );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_blob_unavailable', $result->get_error_code() );
		self::assertDirectoryDoesNotExist( $this->base . '/outside' );
	}

	public function test_a_linked_root_is_refused_for_every_operation(): void {
		$real = $this->store();
		$put  = $real->put( 'seed' );
		self::assertIsArray( $put );

		$store = new class( $this->base . '/stonewright-state/blobs' ) extends BlobStore {
			protected function is_link_path( string $path ): bool {
				return str_ends_with( $path, '/stonewright-state/blobs' );
			}
		};

		foreach ( [ $store->put( 'more' ), $store->get( $put['sha256'] ) ] as $result ) {
			self::assertInstanceOf( \WP_Error::class, $result );
			self::assertSame( 'stonewright_blob_link_refused', $result->get_error_code() );
		}
		self::assertFalse( $store->delete( $put['sha256'] ) );
		self::assertFileExists( $this->base . '/stonewright-state/blobs/' . $put['sha256'] . '.gz' );
	}

	public function test_a_linked_blob_file_is_neither_read_nor_replaced(): void {
		$real = $this->store();
		$put  = $real->put( 'a shared image' );
		self::assertIsArray( $put );
		$linked = $put['sha256'];

		$store = new class( $this->base . '/stonewright-state/blobs', $linked ) extends BlobStore {
			public function __construct( string $root, private string $linked ) {
				parent::__construct( $root );
			}

			protected function is_link_path( string $path ): bool {
				return str_ends_with( $path, '/' . $this->linked . '.gz' );
			}
		};

		$read = $store->get( $linked );
		self::assertInstanceOf( \WP_Error::class, $read );
		self::assertSame( 'stonewright_blob_link_refused', $read->get_error_code() );

		$again = $store->put( 'a shared image' );
		self::assertInstanceOf( \WP_Error::class, $again );
		self::assertSame( 'stonewright_blob_link_refused', $again->get_error_code() );
	}

	public function test_a_real_symlinked_blob_pointing_outside_the_store_is_refused(): void {
		$store = $this->store();
		self::assertIsArray( $store->put( 'seed' ) );
		$outside = $this->base . '/outside-secret.gz';
		file_put_contents( $outside, (string) gzencode( 'outside content' ) );
		$sha  = hash( 'sha256', 'outside content' );
		$link = $this->base . '/stonewright-state/blobs/' . $sha . '.gz';
		if ( ! @symlink( $outside, $link ) ) {
			self::markTestSkipped( 'This system does not allow creating symbolic links; the link seam tests above cover the logic.' );
		}

		$read = $store->get( $sha );

		self::assertInstanceOf( \WP_Error::class, $read );
		self::assertSame( 'stonewright_blob_link_refused', $read->get_error_code(), 'the content matches the name, and the link is still refused' );
		self::assertFalse( $store->has( $sha ) );
	}

	public function test_a_blob_file_with_a_second_hard_link_is_refused(): void {
		$store = $this->store();
		$put   = $store->put( 'a hard linked image' );
		self::assertIsArray( $put );
		$file = $this->base . '/stonewright-state/blobs/' . $put['sha256'] . '.gz';
		if ( ! @link( $file, $this->base . '/second-name.gz' ) ) {
			self::markTestSkipped( 'This system does not allow hard links.' );
		}

		$read = $store->get( $put['sha256'] );

		self::assertInstanceOf( \WP_Error::class, $read );
		self::assertSame( 'stonewright_blob_link_refused', $read->get_error_code() );
	}

	public function test_delete_removes_only_the_named_blob(): void {
		$store = $this->store();
		$a     = $store->put( 'image a' );
		$b     = $store->put( 'image b' );
		self::assertIsArray( $a );
		self::assertIsArray( $b );

		self::assertTrue( $store->delete( $a['sha256'] ) );

		self::assertFalse( $store->has( $a['sha256'] ) );
		self::assertTrue( $store->has( $b['sha256'] ) );
		self::assertFalse( $store->delete( $a['sha256'] ), 'a second delete finds nothing' );
	}

	public function test_sweep_keeps_referenced_and_recent_blobs_and_removes_the_rest(): void {
		$store = $this->store();
		$keep  = $store->put( 'referenced image' );
		$old   = $store->put( 'orphan image' );
		$fresh = $store->put( 'recent orphan image' );
		self::assertIsArray( $keep );
		self::assertIsArray( $old );
		self::assertIsArray( $fresh );
		$dir = $this->base . '/stonewright-state/blobs/';
		touch( $dir . $keep['sha256'] . '.gz', time() - 7200 );
		touch( $dir . $old['sha256'] . '.gz', time() - 7200 );

		$swept = $store->sweep( [ $keep['sha256'] => true ], 3600 );

		self::assertSame( 1, $swept['deleted'] );
		self::assertSame( $old['stored_bytes'], $swept['freed'] );
		self::assertTrue( $store->has( $keep['sha256'] ) );
		self::assertFalse( $store->has( $old['sha256'] ) );
		self::assertTrue( $store->has( $fresh['sha256'] ), 'a blob written a moment ago may still be about to be referenced' );
	}

	public function test_a_dedupe_hit_renews_the_age_of_the_blob(): void {
		$store = $this->store();
		$put   = $store->put( 'image that is stored again' );
		self::assertIsArray( $put );
		$file = $this->base . '/stonewright-state/blobs/' . $put['sha256'] . '.gz';
		touch( $file, time() - 7200 );

		$store->put( 'image that is stored again' );

		self::assertSame( 0, $store->sweep( [], 3600 )['deleted'] );
	}

	public function test_erase_removes_the_blobs_and_the_deny_files_and_the_folder(): void {
		$store = $this->store();
		$store->put( 'one' );
		$store->put( 'two' );

		self::assertTrue( $store->erase() );

		self::assertDirectoryDoesNotExist( $this->base . '/stonewright-state/blobs' );
		self::assertDirectoryExists( $this->base . '/stonewright-state', 'the parent folder is not the store' );
	}

	public function test_erase_leaves_a_file_it_did_not_write_and_so_the_folder(): void {
		$store = $this->store();
		$store->put( 'one' );
		file_put_contents( $this->base . '/stonewright-state/blobs/notes.txt', 'left by someone else' );

		$store->erase();

		self::assertFileExists( $this->base . '/stonewright-state/blobs/notes.txt' );
		self::assertSame( [], $this->blob_files() );
	}

	public function test_the_default_store_lives_under_the_state_folder_of_the_uploads_directory(): void {
		$GLOBALS['stonewright_test_upload_dir'] = [
			'basedir' => $this->base,
			'baseurl' => 'https://example.test/wp-content/uploads',
			'error'   => false,
		];

		self::assertSame( $this->base . '/stonewright-state/blobs', BlobStore::default_root() );
		$store = BlobStore::default();
		self::assertInstanceOf( BlobStore::class, $store );
		self::assertIsArray( $store->put( 'seed' ) );
		self::assertCount( 1, $this->blob_files() );

		self::assertTrue( BlobStore::erase_default() );
		self::assertDirectoryDoesNotExist( $this->base . '/stonewright-state/blobs' );
	}
}
