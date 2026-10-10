<?php
/**
 * Compact on-disk copy of the change journal.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

/**
 * Reads and writes the JSON journal file under uploads/stonewright-state/.
 *
 * The file is the copy of the change journal that other readers load without the full
 * plugin: the rescue MU-plugin, and WP-CLI with most plugins skipped. This class uses no
 * WordPress function so a test or a child process can load it on its own.
 *
 * Writing follows one protocol, shared with the MU-plugin: take an exclusive flock on
 * "<journal file>.lock", write a temporary file in the same directory, rename it over the
 * journal. At most MAX_ENTRIES entries are kept, the oldest settled ones are dropped first,
 * and an entry never carries secrets, file contents, option values or request bodies.
 */
final class ChangeJournalFile {

	public const VERSION     = 1;
	public const MAX_ENTRIES = 50;

	/** The most the rescue helper reads of the file. A larger file is not read at all. */
	public const MAX_BYTES = 1048576;

	/** How long a writer waits for the lock before it gives up. */
	public const LOCK_TIMEOUT_MS = 3000;

	public const STATES = [ 'armed', 'verified', 'rolled_back', 'rollback_failed', 'probe_unavailable', 'incident' ];

	public const RESOURCE_TYPES = [ 'theme_file', 'option', 'plugin', 'sandbox', 'post', 'custom_code' ];

	public const RECIPE_TYPES = [ 'post_snapshot', 'option_restore', 'theme_backup', 'plugin_state', 'sandbox_file', 'none' ];

	/** States an entry is in once nothing is left to do for it. */
	private const SETTLED_STATES = [ 'verified', 'rolled_back' ];

	private const MAX_TEXT  = 191;
	private const MAX_PATHS = 20;
	private const MAX_PATH  = 255;

	public function __construct( private string $path ) {}

	public function path(): string {
		return $this->path;
	}

	public function lock_path(): string {
		return $this->path . '.lock';
	}

	/** A random file name for the journal: journal-<32 lowercase hex>.json. */
	public static function new_file_name(): string {
		return 'journal-' . bin2hex( random_bytes( 16 ) ) . '.json';
	}

	public static function is_valid_file_name( string $name ): bool {
		return 1 === preg_match( '/^journal-[a-f0-9]{32}\.json$/D', $name );
	}

	/**
	 * Create the state directory with the files that keep it closed to the web and to listings.
	 */
	public static function protect_directory( string $dir ): bool {
		if ( ! is_dir( $dir ) && ! @mkdir( $dir, self::dir_mode(), true ) && ! is_dir( $dir ) ) {
			return false;
		}
		$files = [
			'.htaccess'  => "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n",
			'index.php'  => "<?php\n// Silence is golden.\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
		];
		$ok = true;
		foreach ( $files as $name => $contents ) {
			$target = rtrim( $dir, '/\\' ) . '/' . $name;
			if ( is_file( $target ) ) {
				continue;
			}
			if ( false === @file_put_contents( $target, $contents, LOCK_EX ) ) {
				$ok = false;
				continue;
			}
			@chmod( $target, self::file_mode() );
		}
		return $ok;
	}

	/** Whether the file is larger than the helper reads. Such a file is not trusted, and it is replaced. */
	public function is_oversize(): bool {
		clearstatcache( true, $this->path );
		return is_file( $this->path ) && (int) @filesize( $this->path ) > self::MAX_BYTES;
	}

	/**
	 * The file's bytes, or null when it is missing, empty, unreadable or larger than MAX_BYTES.
	 * The read itself is capped, so a huge file never reaches memory.
	 */
	public function read_raw(): ?string {
		if ( ! is_file( $this->path ) ) {
			return null;
		}
		$raw = @file_get_contents( $this->path, false, null, 0, self::MAX_BYTES + 1 );
		return is_string( $raw ) && '' !== $raw && strlen( $raw ) <= self::MAX_BYTES ? $raw : null;
	}

	/**
	 * The journal as stored. A missing, unreadable, oversize or half-written file reads as an empty document.
	 *
	 * @return array{version:int,updated_at:int,entries:list<array<string,mixed>>}
	 */
	public function read(): array {
		$raw = $this->read_raw();
		if ( null === $raw ) {
			return self::empty_document();
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? self::normalize_document( $decoded ) : self::empty_document();
	}

	/**
	 * Read, change and write the journal while holding the exclusive lock.
	 *
	 * The mutator receives the stored document and returns the next one; returning anything
	 * but an array leaves the file as it is. A writer that cannot take the lock in time, or
	 * cannot write, gets null and the file keeps its previous content.
	 *
	 * @param callable(array{version:int,updated_at:int,entries:list<array<string,mixed>>}):mixed $mutator
	 * @return array{version:int,updated_at:int,entries:list<array<string,mixed>>}|null
	 */
	public function transaction( callable $mutator, int $timeout_ms = self::LOCK_TIMEOUT_MS ): ?array {
		$dir = dirname( $this->path );
		if ( ! is_dir( $dir ) && ! @mkdir( $dir, self::dir_mode(), true ) && ! is_dir( $dir ) ) {
			return null;
		}
		$lock = @fopen( $this->lock_path(), 'c' );
		if ( false === $lock ) {
			return null;
		}
		$deadline = microtime( true ) + max( 0, $timeout_ms ) / 1000;
		$locked   = false;
		do {
			$locked = flock( $lock, LOCK_EX | LOCK_NB );
			if ( $locked ) {
				break;
			}
			usleep( 4000 );
		} while ( microtime( true ) < $deadline );
		if ( ! $locked ) {
			fclose( $lock );
			return null;
		}

		try {
			$current = $this->read();
			$next    = $mutator( $current );
			if ( ! is_array( $next ) ) {
				return $current;
			}
			$next               = self::normalize_document( $next );
			$next['updated_at'] = time();
			return $this->write_atomically( $next ) ? $next : null;
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
	}

	/**
	 * @return array{version:int,updated_at:int,entries:list<array<string,mixed>>}
	 */
	public static function empty_document(): array {
		return [
			'version'    => self::VERSION,
			'updated_at' => 0,
			'entries'    => [],
		];
	}

	/**
	 * Keep only what the shared contract allows, and no more than MAX_ENTRIES entries.
	 *
	 * @param array<mixed> $document
	 * @return array{version:int,updated_at:int,entries:list<array<string,mixed>>}
	 */
	public static function normalize_document( array $document ): array {
		$entries = [];
		$raw     = isset( $document['entries'] ) && is_array( $document['entries'] ) ? $document['entries'] : [];
		foreach ( $raw as $entry ) {
			$clean = self::normalize_entry( $entry );
			if ( null !== $clean ) {
				$entries[] = $clean;
			}
		}
		return [
			'version'    => self::VERSION,
			'updated_at' => isset( $document['updated_at'] ) && is_numeric( $document['updated_at'] ) ? max( 0, (int) $document['updated_at'] ) : 0,
			'entries'    => self::prune( $entries, self::MAX_ENTRIES ),
		];
	}

	/**
	 * @return array<string,mixed>|null Null when the value is not a usable entry.
	 */
	public static function normalize_entry( mixed $entry ): ?array {
		if ( ! is_array( $entry ) ) {
			return null;
		}
		$id = isset( $entry['id'] ) && is_scalar( $entry['id'] ) ? (string) $entry['id'] : '';
		if ( 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,95}$/D', $id ) ) {
			return null;
		}

		$ability = isset( $entry['ability'] ) && is_scalar( $entry['ability'] ) ? strtolower( (string) $entry['ability'] ) : '';
		if ( 1 !== preg_match( '/^[a-z0-9][a-z0-9_-]{0,47}\/[a-z0-9][a-z0-9_-]{0,63}$/D', $ability ) ) {
			$ability = '';
		}

		$type = isset( $entry['resource_type'] ) && is_scalar( $entry['resource_type'] ) ? (string) $entry['resource_type'] : '';
		$type = in_array( $type, self::RESOURCE_TYPES, true ) ? $type : 'option';

		$recipe      = isset( $entry['recipe'] ) && is_array( $entry['recipe'] ) ? $entry['recipe'] : [];
		$recipe_type = isset( $recipe['type'] ) && is_scalar( $recipe['type'] ) ? (string) $recipe['type'] : 'none';
		$recipe_type = in_array( $recipe_type, self::RECIPE_TYPES, true ) ? $recipe_type : 'none';

		$state = isset( $entry['state'] ) && is_scalar( $entry['state'] ) ? (string) $entry['state'] : 'armed';
		$state = in_array( $state, self::STATES, true ) ? $state : 'armed';

		$armed_at = isset( $entry['armed_at'] ) && is_numeric( $entry['armed_at'] ) ? max( 0, (int) $entry['armed_at'] ) : 0;

		return [
			'id'             => $id,
			'ability'        => $ability,
			'resource_type'  => $type,
			'resource_key'   => self::text( $entry['resource_key'] ?? '' ),
			'recipe'         => [
				'type' => $recipe_type,
				'ref'  => self::text( $recipe['ref'] ?? '' ),
			],
			'paths'          => self::paths( $entry['paths'] ?? [] ),
			'armed_at'       => $armed_at,
			'probe_deadline' => isset( $entry['probe_deadline'] ) && is_numeric( $entry['probe_deadline'] ) ? max( 0, (int) $entry['probe_deadline'] ) : $armed_at,
			'state'          => $state,
			'incident'       => self::incident( $entry['incident'] ?? null ),
		];
	}

	/**
	 * Drop the oldest settled entries first, then the oldest of the rest, down to $max entries.
	 * The entries that stay keep their order.
	 *
	 * @param list<array<string,mixed>> $entries
	 * @return list<array<string,mixed>>
	 */
	public static function prune( array $entries, int $max = self::MAX_ENTRIES ): array {
		$excess = count( $entries ) - $max;
		if ( $excess <= 0 ) {
			return array_values( $entries );
		}
		$order = array_keys( $entries );
		usort(
			$order,
			static function ( int $a, int $b ) use ( $entries ): int {
				$settled_a = in_array( $entries[ $a ]['state'] ?? '', self::SETTLED_STATES, true ) ? 0 : 1;
				$settled_b = in_array( $entries[ $b ]['state'] ?? '', self::SETTLED_STATES, true ) ? 0 : 1;
				if ( $settled_a !== $settled_b ) {
					return $settled_a <=> $settled_b;
				}
				$armed = (int) ( $entries[ $a ]['armed_at'] ?? 0 ) <=> (int) ( $entries[ $b ]['armed_at'] ?? 0 );
				return 0 !== $armed ? $armed : $a <=> $b;
			}
		);
		$drop = array_flip( array_slice( $order, 0, $excess ) );
		$kept = [];
		foreach ( $entries as $index => $entry ) {
			if ( ! isset( $drop[ $index ] ) ) {
				$kept[] = $entry;
			}
		}
		return $kept;
	}

	/**
	 * @param array{version:int,updated_at:int,entries:list<array<string,mixed>>} $document
	 */
	private function write_atomically( array $document ): bool {
		$json = json_encode( $document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) ) {
			return false;
		}
		$temp = $this->path . '.tmp-' . bin2hex( random_bytes( 4 ) );
		if ( false === @file_put_contents( $temp, $json, LOCK_EX ) ) {
			@unlink( $temp );
			return false;
		}
		@chmod( $temp, self::file_mode() );
		// A reader that holds the target open can make the rename fail on Windows hosts; retry briefly.
		for ( $attempt = 0; $attempt < 25; $attempt++ ) {
			if ( @rename( $temp, $this->path ) ) {
				return true;
			}
			usleep( 8000 );
		}
		@unlink( $temp );
		return false;
	}

	private static function text( mixed $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$text = (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', (string) $value );
		$text = trim( $text );
		if ( '' === $text ) {
			return '';
		}
		if ( self::looks_secret( $text ) ) {
			return '[redacted]';
		}
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, self::MAX_TEXT ) : substr( $text, 0, self::MAX_TEXT );
	}

	private static function looks_secret( string $text ): bool {
		return SensitiveContent::contains( $text )
			|| 1 === preg_match( '/\b(?:swc|swotl|sw_cc)_[A-Za-z0-9_.-]{12,}/', $text );
	}

	/**
	 * @return list<string>
	 */
	private static function paths( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}
		$out = [];
		foreach ( $value as $path ) {
			if ( ! is_string( $path ) ) {
				continue;
			}
			$path = trim( str_replace( '\\', '/', $path ) );
			$path = (string) preg_replace( '#^(?:\./)+#', '', $path );
			if ( '' === $path || strlen( $path ) > self::MAX_PATH || str_contains( $path, "\0" ) ) {
				continue;
			}
			if ( str_starts_with( $path, '/' ) || 1 === preg_match( '#^[A-Za-z]:#', $path ) || in_array( '..', explode( '/', $path ), true ) ) {
				continue;
			}
			if ( self::looks_secret( $path ) ) {
				continue;
			}
			$out[ $path ] = true;
			if ( count( $out ) >= self::MAX_PATHS ) {
				break;
			}
		}
		return array_keys( $out );
	}

	/**
	 * @return array{recorded_at:int,file:string,line:int,type:int,message_sha256:string,source:string}|null
	 */
	private static function incident( mixed $value ): ?array {
		if ( ! is_array( $value ) ) {
			return null;
		}
		$file = self::paths( [ $value['file'] ?? '' ] );
		$hash = isset( $value['message_sha256'] ) && is_scalar( $value['message_sha256'] ) ? strtolower( (string) $value['message_sha256'] ) : '';
		$source = isset( $value['source'] ) && is_scalar( $value['source'] ) ? (string) $value['source'] : 'shutdown';
		return [
			'recorded_at'    => isset( $value['recorded_at'] ) && is_numeric( $value['recorded_at'] ) ? max( 0, (int) $value['recorded_at'] ) : 0,
			'file'           => $file[0] ?? '',
			'line'           => isset( $value['line'] ) && is_numeric( $value['line'] ) ? max( 0, (int) $value['line'] ) : 0,
			'type'           => isset( $value['type'] ) && is_numeric( $value['type'] ) ? (int) $value['type'] : 0,
			'message_sha256' => 1 === preg_match( '/^[a-f0-9]{64}$/D', $hash ) ? $hash : '',
			'source'         => 1 === preg_match( '/^[a-z_]{1,24}$/D', $source ) ? $source : 'shutdown',
		];
	}

	private static function file_mode(): int {
		return defined( 'FS_CHMOD_FILE' ) ? (int) constant( 'FS_CHMOD_FILE' ) : 0644;
	}

	private static function dir_mode(): int {
		return defined( 'FS_CHMOD_DIR' ) ? (int) constant( 'FS_CHMOD_DIR' ) : 0755;
	}
}
