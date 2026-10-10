<?php
/**
 * Content-addressed store for the images the change ledger keeps.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

use Stonewright\WpMcp\Support\DirectoryGuard;

/**
 * Keeps gzip-compressed blobs as files named after the sha256 of their uncompressed bytes, in a
 * folder under uploads/stonewright-state/ that is closed to the web at every level: each folder
 * the store creates, and the folder it sits in, carries index.php, .htaccess and web.config deny
 * rules.
 *
 * Writing the same bytes twice keeps one file. A read decompresses with a length cap and compares
 * the sha256 of the result with the file name, so a changed, truncated or swapped file is an error
 * and never content. A blob is limited in uncompressed size and in compressed size, and the folder
 * is limited in total; a blob that would pass a limit is refused and the caller records the change
 * without it.
 *
 * File names are built from a validated 64-character hash only, never from input. The store refuses
 * a root with a parent segment, a root or blob that is a symbolic link, and a blob file with a
 * second hard link. Files are written to a temporary name and renamed into place, so a reader
 * never sees half a blob and a write never follows a link.
 *
 * Only the erase path of this class runs when the plugin is uninstalled, so it uses no other
 * plugin class except ChangeJournal for the folder name.
 */
class BlobStore {

	/** Folder name under uploads/stonewright-state/. */
	public const DIRECTORY = 'blobs';

	/** The most a blob holds before compression. A larger image is not stored. */
	public const MAX_RAW_BYTES = 4194304;

	/** The most a blob file holds after compression. */
	public const MAX_BLOB_BYTES = 1048576;

	/** The most all blob files hold together. */
	public const DEFAULT_MAX_TOTAL_BYTES = 104857600;

	private const GUARD_FILES = [ 'index.php', '.htaccess', 'web.config' ];

	private string $root;

	private bool $ready = false;

	/**
	 * @param string $root      Absolute path of the blob folder. It need not exist yet.
	 * @param int    $max_total Most bytes all blob files may hold together.
	 * @param int    $max_blob  Most bytes one compressed blob file may hold.
	 * @param int    $max_raw   Most bytes one blob may hold before compression.
	 */
	public function __construct(
		string $root,
		private int $max_total = self::DEFAULT_MAX_TOTAL_BYTES,
		private int $max_blob = self::MAX_BLOB_BYTES,
		private int $max_raw = self::MAX_RAW_BYTES
	) {
		$this->root = rtrim( str_replace( '\\', '/', $root ), '/' );
	}

	/** Absolute path of the default folder, or null when the uploads folder cannot be used. */
	public static function default_root(): ?string {
		if ( ! function_exists( 'wp_upload_dir' ) ) {
			return null;
		}
		$uploads = wp_upload_dir( null, false );
		if ( ! is_array( $uploads ) || ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) || ! is_string( $uploads['basedir'] ) ) {
			return null;
		}
		return rtrim( str_replace( '\\', '/', $uploads['basedir'] ), '/' ) . '/' . ChangeJournal::STATE_DIR . '/' . self::DIRECTORY;
	}

	/** The store in the default folder, or null when the uploads folder cannot be used. */
	public static function default( ?int $max_total = null ): ?self {
		$root = self::default_root();
		if ( null === $root ) {
			return null;
		}
		return null === $max_total ? new self( $root ) : new self( $root, max( 1, $max_total ) );
	}

	/**
	 * Delete the default folder with everything the store wrote in it, for a full data removal.
	 *
	 * @return bool Whether no blob is left.
	 */
	public static function erase_default(): bool {
		$root = self::default_root();
		return null === $root || ( new self( $root ) )->erase();
	}

	public static function is_valid_hash( string $hash ): bool {
		return 1 === preg_match( '/^[a-f0-9]{64}$/D', $hash );
	}

	public function root(): string {
		return $this->root;
	}

	/**
	 * Store bytes. The same bytes give the same blob and write nothing new.
	 *
	 * @return array{sha256:string,raw_bytes:int,stored_bytes:int,created:bool}|\WP_Error
	 */
	public function put( string $bytes ): array|\WP_Error {
		if ( strlen( $bytes ) > $this->max_raw ) {
			return $this->error( 'stonewright_blob_too_large', 'The image is larger than a stored image may be.' );
		}
		$ready = $this->ensure_directory();
		if ( $ready instanceof \WP_Error ) {
			return $ready;
		}
		$sha  = hash( 'sha256', $bytes );
		$file = $this->path( $sha );
		if ( $this->is_link_path( $file ) ) {
			return $this->error( 'stonewright_blob_link_refused', 'The blob path is a link.' );
		}

		$replaced = 0;
		if ( file_exists( $file ) ) {
			$existing = $this->read_verified( $sha );
			if ( is_string( $existing ) ) {
				// The age of the file is what retention uses to leave a blob that is about to be referenced.
				@touch( $file );
				return [
					'sha256'       => $sha,
					'raw_bytes'    => strlen( $bytes ),
					'stored_bytes' => (int) @filesize( $file ),
					'created'      => false,
				];
			}
			if ( 'stonewright_blob_link_refused' === $existing->get_error_code() ) {
				return $existing;
			}
			// A file that does not match its name is replaced by the correct content.
			$replaced = (int) @filesize( $file );
		}

		$compressed = gzencode( $bytes, 6 );
		if ( false === $compressed ) {
			return $this->error( 'stonewright_blob_write_failed', 'The image could not be compressed.' );
		}
		if ( strlen( $compressed ) > $this->max_blob ) {
			return $this->error( 'stonewright_blob_too_large', 'The compressed image is larger than a stored image may be.' );
		}
		if ( $this->total_bytes() - $replaced + strlen( $compressed ) > $this->max_total ) {
			return $this->error( 'stonewright_blob_store_full', 'The blob folder is full.' );
		}

		$temp = $file . '.tmp-' . bin2hex( random_bytes( 4 ) );
		if ( false === @file_put_contents( $temp, $compressed, LOCK_EX ) ) {
			@unlink( $temp );
			return $this->error( 'stonewright_blob_write_failed', 'The blob could not be written.' );
		}
		@chmod( $temp, 0600 );
		$moved = false;
		for ( $attempt = 0; $attempt < 3 && ! $moved; $attempt++ ) {
			$moved = @rename( $temp, $file );
			if ( ! $moved ) {
				usleep( 5000 );
			}
		}
		if ( ! $moved ) {
			@unlink( $temp );
			return $this->error( 'stonewright_blob_write_failed', 'The blob could not be moved into place.' );
		}
		return [
			'sha256'       => $sha,
			'raw_bytes'    => strlen( $bytes ),
			'stored_bytes' => strlen( $compressed ),
			'created'      => true,
		];
	}

	/**
	 * The bytes of a blob, after the integrity check.
	 *
	 * @return string|\WP_Error
	 */
	public function get( string $sha256 ): string|\WP_Error {
		if ( ! self::is_valid_hash( $sha256 ) ) {
			return $this->error( 'stonewright_blob_invalid_hash', 'The blob name is not a sha256.' );
		}
		return $this->read_verified( $sha256 );
	}

	public function has( string $sha256 ): bool {
		if ( ! self::is_valid_hash( $sha256 ) || ! $this->root_usable() ) {
			return false;
		}
		$file = $this->path( $sha256 );
		return is_file( $file ) && ! $this->is_link_path( $file );
	}

	/** Bytes of the compressed file, or 0 when there is no such blob. */
	public function stored_size( string $sha256 ): int {
		return $this->has( $sha256 ) ? max( 0, (int) @filesize( $this->path( $sha256 ) ) ) : 0;
	}

	/** Delete one blob. A link, an unknown name and a missing file delete nothing. */
	public function delete( string $sha256 ): bool {
		if ( ! $this->has( $sha256 ) ) {
			return false;
		}
		return @unlink( $this->path( $sha256 ) );
	}

	/** Bytes of all blob files. */
	public function total_bytes(): int {
		$total = 0;
		foreach ( $this->hashes() as $hash ) {
			$total += max( 0, (int) @filesize( $this->path( $hash ) ) );
		}
		return $total;
	}

	/**
	 * Names of the blobs in the folder.
	 *
	 * @return list<string>
	 */
	public function hashes(): array {
		if ( ! $this->root_usable() ) {
			return [];
		}
		$items  = scandir( $this->root );
		$hashes = [];
		foreach ( false === $items ? [] : $items as $item ) {
			if ( 1 === preg_match( '/^([a-f0-9]{64})\.gz$/D', $item, $match ) && is_file( $this->root . '/' . $item ) && ! $this->is_link_path( $this->root . '/' . $item ) ) {
				$hashes[] = $match[1];
			}
		}
		return $hashes;
	}

	/**
	 * Delete the blobs that are not referenced and are at least $min_age seconds old. A blob that was
	 * written or found again a moment ago may be about to be referenced, so it stays.
	 *
	 * @param array<string,mixed> $referenced Hash => anything, for every blob a row uses.
	 * @return array{deleted:int,freed:int}
	 */
	public function sweep( array $referenced, int $min_age ): array {
		$deleted = 0;
		$freed   = 0;
		$now     = time();
		foreach ( $this->hashes() as $hash ) {
			if ( isset( $referenced[ $hash ] ) ) {
				continue;
			}
			$file  = $this->path( $hash );
			$mtime = (int) @filemtime( $file );
			if ( $now - $mtime < $min_age ) {
				continue;
			}
			$size = (int) @filesize( $file );
			if ( @unlink( $file ) ) {
				++$deleted;
				$freed += max( 0, $size );
			}
		}
		return [
			'deleted' => $deleted,
			'freed'   => $freed,
		];
	}

	/**
	 * Delete every blob, the temporary files and the deny files, then the folder. A file the store did
	 * not write stays, and so do the deny files and the folder then.
	 *
	 * @return bool Whether no blob is left.
	 */
	public function erase(): bool {
		if ( '' === $this->root || ! is_dir( $this->root ) || $this->is_link_path( $this->root ) ) {
			return ! is_dir( $this->root );
		}
		$items   = scandir( $this->root );
		$foreign = false;
		$erased  = true;
		foreach ( false === $items ? [] : $items as $item ) {
			if ( '.' === $item || '..' === $item || in_array( $item, self::GUARD_FILES, true ) ) {
				continue;
			}
			$path = $this->root . '/' . $item;
			if ( ! is_file( $path ) || 1 !== preg_match( '/^[a-f0-9]{64}\.gz(?:\.tmp-[a-f0-9]+)?$/D', $item ) ) {
				$foreign = true;
				continue;
			}
			if ( ! @unlink( $path ) && file_exists( $path ) ) {
				$erased = false;
			}
		}
		if ( ! $foreign && $erased ) {
			foreach ( self::GUARD_FILES as $guard ) {
				@unlink( $this->root . '/' . $guard );
			}
			@rmdir( $this->root );
		}
		return $erased;
	}

	/**
	 * Whether a path is a symbolic link. A subclass can answer for a test. The answer can change between two
	 * calls, so a caller checks again after it creates a folder.
	 *
	 * @phpstan-impure
	 */
	protected function is_link_path( string $path ): bool {
		clearstatcache( true, $path );
		return is_link( $path );
	}

	private function path( string $sha256 ): string {
		return $this->root . '/' . $sha256 . '.gz';
	}

	/**
	 * Whether the folder may be read: a root that is set, has no parent segment, and is not a link.
	 */
	private function root_usable(): bool {
		return $this->root_problem() === null && is_dir( $this->root );
	}

	/** @return string|null The error code when the root must not be used, else null. */
	private function root_problem(): ?string {
		if ( '' === $this->root || str_contains( $this->root, "\0" ) || in_array( '..', explode( '/', $this->root ), true ) ) {
			return 'stonewright_blob_unavailable';
		}
		if ( $this->is_link_path( $this->root ) || $this->is_link_path( dirname( $this->root ) ) ) {
			return 'stonewright_blob_link_refused';
		}
		return null;
	}

	/**
	 * Create the folder, with the deny files on every level that is new and on the folder and its parent.
	 *
	 * @return true|\WP_Error
	 */
	private function ensure_directory(): bool|\WP_Error {
		$problem = $this->root_problem();
		if ( null !== $problem ) {
			return $this->error( $problem, 'The blob folder cannot be used.' );
		}
		if ( $this->ready && is_dir( $this->root ) ) {
			return true;
		}
		$levels  = [];
		$current = $this->root;
		while ( '' !== $current && ! is_dir( $current ) ) {
			array_unshift( $levels, $current );
			$parent = dirname( $current );
			if ( $parent === $current ) {
				break;
			}
			$current = $parent;
		}
		foreach ( $levels as $level ) {
			if ( $this->is_link_path( $level ) ) {
				return $this->error( 'stonewright_blob_link_refused', 'A level of the blob folder is a link.' );
			}
			if ( ! @mkdir( $level, self::dir_mode() ) && ! is_dir( $level ) ) {
				return $this->error( 'stonewright_blob_unavailable', 'The blob folder could not be created.' );
			}
			if ( $this->is_link_path( $level ) ) {
				return $this->error( 'stonewright_blob_link_refused', 'A level of the blob folder is a link.' );
			}
		}
		$guarded = array_values( array_unique( array_merge( $levels, [ dirname( $this->root ), $this->root ] ) ) );
		foreach ( $guarded as $dir ) {
			if ( ! is_dir( $dir ) || ! DirectoryGuard::apply( $dir ) ) {
				return $this->error( 'stonewright_blob_unavailable', 'The deny files could not be written.' );
			}
		}
		$this->ready = true;
		return true;
	}

	/**
	 * @return string|\WP_Error The decompressed bytes of a blob whose content matches its name.
	 */
	private function read_verified( string $sha256 ): string|\WP_Error {
		$problem = $this->root_problem();
		if ( null !== $problem ) {
			return $this->error( $problem, 'The blob folder cannot be used.' );
		}
		$file = $this->path( $sha256 );
		if ( $this->is_link_path( $file ) ) {
			return $this->error( 'stonewright_blob_link_refused', 'The blob path is a link.' );
		}
		if ( ! is_file( $file ) ) {
			return $this->error( 'stonewright_blob_missing', 'The blob does not exist.' );
		}
		$stat = @stat( $file );
		if ( is_array( $stat ) && (int) ( $stat['nlink'] ?? 1 ) > 1 ) {
			return $this->error( 'stonewright_blob_link_refused', 'The blob file has more than one name.' );
		}
		$compressed = @file_get_contents( $file, false, null, 0, $this->max_blob + 1 );
		if ( ! is_string( $compressed ) || '' === $compressed || strlen( $compressed ) > $this->max_blob ) {
			return $this->error( 'stonewright_blob_corrupt', 'The blob cannot be read.' );
		}
		$raw = @gzdecode( $compressed, $this->max_raw + 1 );
		if ( ! is_string( $raw ) || strlen( $raw ) > $this->max_raw || ! hash_equals( $sha256, hash( 'sha256', $raw ) ) ) {
			return $this->error( 'stonewright_blob_corrupt', 'The blob does not match its name.' );
		}
		return $raw;
	}

	private function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, $message );
	}

	private static function dir_mode(): int {
		return defined( 'FS_CHMOD_DIR' ) ? (int) constant( 'FS_CHMOD_DIR' ) : 0755;
	}
}
