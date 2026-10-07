<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Sandbox;

use Stonewright\WpMcp\Security\AuditLog;

/**
 * Manages sandbox draft files and their mu-plugins twins.
 *
 * Draft files live in wp-content/stonewright-sandbox/ and are NEVER auto-loaded.
 * They are stored as `<name>.draft` and their backups as `<name>.<time>.bak`:
 * neither name can be run as PHP by a web server, whatever it does with
 * .htaccess. Activation runs StaticGuard::scan() and then writes the only
 * executable copy, to mu-plugins, with a first statement that stops the file at
 * once when it is loaded outside WordPress.
 */
final class SandboxFiles {

	/** Max allowed file size for a draft: 200 KB. */
	private const MAX_BYTES = 204800;

	/**
	 * Valid sandbox file names. The same text is the pattern attribute of the
	 * file name field, so the hyphen stays escaped: a trailing bare hyphen is
	 * not a valid character class under the unicodeSets flag browsers use.
	 */
	private const NAME_PATTERN = '[a-z0-9_\-]+\.php';

	/** Regex for valid sandbox file names. */
	private const NAME_REGEX = '/^' . self::NAME_PATTERN . '$/D';

	/** Maximum number of backup versions to retain per file. */
	private const MAX_BACKUPS = 10;

	/** Extension of a stored draft. */
	public const DRAFT_EXTENSION = 'draft';

	/** Extension of a stored backup. */
	private const BACKUP_EXTENSION = 'bak';

	/** Option that records the storage layout the folder has been prepared for. */
	public const LAYOUT_OPTION = 'stonewright_sandbox_layout';

	/** Current storage layout: drafts and backups without a PHP extension. */
	private const LAYOUT_VERSION = '2';

	/** File names a web server may hand to a PHP handler (also as a double extension). */
	private const RUNNABLE_NAME = '/\.(?:php[0-9]?|phtml|pht|phar|phps)(?:\.|$)/i';

	/** Guard statement placed first in every activated file. */
	private const LOADER_GUARD = "defined( 'ABSPATH' ) || exit;";

	/**
	 * File names that may exist in the sandbox directory but must never be
	 * exposed or modified through the UI or MCP layer.
	 * index.php is the directory-listing protection stub ("Silence is golden").
	 */
	public const RESERVED_NAMES = [ 'index.php' ];

	// -------------------------------------------------------------------------
	// Directory helpers
	// -------------------------------------------------------------------------

	/**
	 * Returns the draft sandbox directory path. The first call after an install
	 * or update creates the directory, brings its guard files up to date and
	 * moves drafts and backups written by earlier versions to the current
	 * storage names.
	 */
	public static function draft_dir(): string {
		$dir = WP_CONTENT_DIR . '/stonewright-sandbox';

		if ( self::LAYOUT_VERSION !== (string) get_option( self::LAYOUT_OPTION, '' ) || ! is_file( $dir . '/.htaccess' ) ) {
			self::prepare_directory( $dir );
		}

		return $dir;
	}

	/**
	 * Returns the path where a draft is stored.
	 *
	 * @param string $name Draft name as listed, e.g. "my-snippet.php".
	 */
	public static function stored_path( string $name ): string {
		return self::draft_dir() . '/' . self::stored_name( $name );
	}

	/**
	 * Returns the storage file name of a draft: "my-snippet.php" is stored as
	 * "my-snippet.draft".
	 *
	 * @param string $name Draft name as listed.
	 */
	public static function stored_name( string $name ): string {
		return self::stem( $name ) . '.' . self::DRAFT_EXTENSION;
	}

	/**
	 * Returns the filename prefix applied to active (mu-plugins) twins.
	 */
	public static function active_prefix(): string {
		return 'stonewright-sandbox-';
	}

	/**
	 * Returns the mu-plugins directory path.
	 */
	public static function mu_dir(): string {
		if ( defined( 'WPMU_PLUGIN_DIR' ) ) {
			return WPMU_PLUGIN_DIR;
		}
		return WP_CONTENT_DIR . '/mu-plugins';
	}

	// -------------------------------------------------------------------------
	// Listing
	// -------------------------------------------------------------------------

	/**
	 * Lists all draft files and their activation status.
	 *
	 * @return array<int, array{name: string, status: string, size: int, modified: int, path: string}>
	 */
	public static function list_files(): array {
		$draft_dir = self::draft_dir();
		$mu_dir    = self::mu_dir();
		$prefix    = self::active_prefix();
		$results   = [];

		$drafts = glob( $draft_dir . '/*.' . self::DRAFT_EXTENSION );
		if ( false === $drafts ) {
			$drafts = [];
		}

		foreach ( $drafts as $path ) {
			$name    = basename( $path, '.' . self::DRAFT_EXTENSION ) . '.php';
			$mu_twin = $mu_dir . '/' . $prefix . $name;

			if ( file_exists( $mu_twin . '.crashed' ) ) {
				$status = 'crashed';
			} elseif ( file_exists( $mu_twin . '.disabled' ) ) {
				$status = 'disabled';
			} elseif ( file_exists( $mu_twin ) ) {
				$status = 'active';
			} else {
				$status = 'draft';
			}

			$results[] = [
				'name'     => $name,
				'status'   => $status,
				'size'     => (int) filesize( $path ),
				'modified' => (int) filemtime( $path ),
				'path'     => $path,
			];
		}

		// Strip reserved names and any stored file whose name does not match the
		// strict NAME_REGEX (e.g. a pending widget file).
		$results = array_values(
			array_filter(
				$results,
				static fn( array $f ) => self::valid_name( $f['name'] ) && ! in_array( $f['name'], self::RESERVED_NAMES, true )
			)
		);

		return $results;
	}

	// -------------------------------------------------------------------------
	// Backup versions
	// -------------------------------------------------------------------------

	/**
	 * Returns a list of backup version entries for a sandbox file, sorted by
	 * timestamp descending (newest first). Each entry is:
	 *   {timestamp: int, path: string}
	 *
	 * Backups are named <stem>.<unix_ts>.bak, e.g. my-snippet.1716000000.bak.
	 *
	 * @param string $basename PHP basename (e.g. "my-snippet.php").
	 * @return array<int, array{timestamp: int, path: string}>
	 */
	public static function backup_versions( string $basename ): array {
		$draft_dir = self::draft_dir();
		$stem      = self::stem( $basename );
		$escaped   = preg_quote( $stem, '/' );

		$files = glob( $draft_dir . '/' . $stem . '.*.' . self::BACKUP_EXTENSION );
		if ( false === $files ) {
			return [];
		}

		$versions = [];
		foreach ( $files as $path ) {
			// Pattern: <stem>.<digits>.bak
			$match = preg_match(
				'/^' . $escaped . '\.(\d+)\.' . self::BACKUP_EXTENSION . '$/D',
				basename( $path ),
				$m
			);
			if ( $match && isset( $m[1] ) ) {
				$versions[] = [
					'timestamp' => (int) $m[1],
					'path'      => $path,
				];
			}
		}

		// Sort newest first.
		usort( $versions, static fn( array $a, array $b ): int => $b['timestamp'] - $a['timestamp'] );

		return $versions;
	}

	/**
	 * Creates a backup of an existing draft file before overwriting.
	 * Prunes older backups to stay within MAX_BACKUPS.
	 *
	 * @param string $basename PHP basename.
	 */
	private static function backup_before_write( string $basename ): void {
		$path = self::stored_path( $basename );
		if ( ! file_exists( $path ) ) {
			return;
		}

		$backup_path = self::draft_dir() . '/' . self::stem( $basename ) . '.' . time() . '.' . self::BACKUP_EXTENSION;
		@copy( $path, $backup_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_copy

		// Prune backups beyond the limit.
		$versions = self::backup_versions( $basename );
		if ( count( $versions ) > self::MAX_BACKUPS ) {
			$to_prune = array_slice( $versions, self::MAX_BACKUPS );
			foreach ( $to_prune as $old ) {
				if ( file_exists( $old['path'] ) ) {
					@unlink( $old['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}
			}
		}
	}

	// -------------------------------------------------------------------------
	// Read
	// -------------------------------------------------------------------------

	/**
	 * Reads a draft file's contents.
	 *
	 * @param string $name Basename only (e.g. "my-snippet.php").
	 * @return string|\WP_Error
	 */
	public static function read( string $name ): string|\WP_Error {
		$guard = self::guard_name( $name );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$path = self::stored_path( $name );
		if ( ! file_exists( $path ) ) {
			return new \WP_Error( 'stonewright_sandbox_not_found', "Sandbox file not found: {$name}" );
		}

		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $contents ) {
			return new \WP_Error( 'stonewright_sandbox_read_error', "Could not read sandbox file: {$name}" );
		}

		return $contents;
	}

	// -------------------------------------------------------------------------
	// Write
	// -------------------------------------------------------------------------

	/**
	 * Creates or overwrites a draft file.
	 *
	 * @param string $name     Basename (must match NAME_REGEX).
	 * @param string $contents PHP source code.
	 * @return bool|\WP_Error
	 */
	public static function write( string $name, string $contents ): bool|\WP_Error {
		$guard = self::guard_name( $name );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		if ( strlen( $contents ) > self::MAX_BYTES ) {
			return new \WP_Error(
				'stonewright_sandbox_too_large',
				sprintf( 'File exceeds maximum size of %d bytes.', self::MAX_BYTES )
			);
		}

		// Backup existing content before overwriting.
		self::backup_before_write( $name );

		$path   = self::stored_path( $name );
		$result = file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === $result ) {
			return new \WP_Error( 'stonewright_sandbox_write_error', "Could not write sandbox file: {$name}" );
		}

		AuditLog::record(
			'sandbox.write',
			[
				'name'         => $name,
				'user'         => get_current_user_id(),
				'content_sha8' => substr( hash( 'sha256', $contents ), 0, 8 ),
			]
		);
		return true;
	}

	// -------------------------------------------------------------------------
	// Edit (exact-string replace)
	// -------------------------------------------------------------------------

	/**
	 * Performs an exact-string replacement within a draft file.
	 *
	 * @param string $name       Basename.
	 * @param string $old_string The exact string to find.
	 * @param string $new_string Replacement string.
	 * @return bool|\WP_Error
	 */
	public static function edit( string $name, string $old_string, string $new_string ): bool|\WP_Error {
		$guard = self::guard_name( $name );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$contents = self::read( $name );
		if ( is_wp_error( $contents ) ) {
			return $contents;
		}

		$occurrences = substr_count( $contents, $old_string );

		if ( 0 === $occurrences ) {
			return new \WP_Error( 'stonewright_sandbox_string_not_found', "old_string not found in {$name}." );
		}

		if ( $occurrences > 1 ) {
			return new \WP_Error(
				'stonewright_sandbox_ambiguous_string',
				"old_string appears {$occurrences} times in {$name}; must be unique."
			);
		}

		$new_contents = str_replace( $old_string, $new_string, $contents );

		if ( strlen( $new_contents ) > self::MAX_BYTES ) {
			return new \WP_Error(
				'stonewright_sandbox_too_large',
				sprintf( 'Edited file would exceed maximum size of %d bytes.', self::MAX_BYTES )
			);
		}

		// Backup existing content before overwriting.
		self::backup_before_write( $name );

		$path   = self::stored_path( $name );
		$result = file_put_contents( $path, $new_contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === $result ) {
			return new \WP_Error( 'stonewright_sandbox_write_error', "Could not write sandbox file: {$name}" );
		}

		AuditLog::record( 'sandbox.write', [ 'name' => $name ] );
		return true;
	}

	// -------------------------------------------------------------------------
	// Delete
	// -------------------------------------------------------------------------

	/**
	 * Deletes a draft file, its backups and its active mu-plugins twin (if any).
	 *
	 * @param string $name Basename.
	 * @return bool|\WP_Error
	 */
	public static function delete( string $name ): bool|\WP_Error {
		$guard = self::guard_name( $name );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$path    = self::stored_path( $name );
		$backups = self::backup_versions( $name );
		if ( ! file_exists( $path ) && [] === $backups ) {
			return new \WP_Error( 'stonewright_sandbox_not_found', "Sandbox file not found: {$name}" );
		}

		// Remove draft.
		if ( file_exists( $path ) && ! unlink( $path ) ) {
			return new \WP_Error( 'stonewright_sandbox_delete_error', "Could not delete sandbox file: {$name}" );
		}

		// Remove every backup of the draft, including ones that outlived it.
		foreach ( $backups as $backup ) {
			if ( file_exists( $backup['path'] ) ) {
				@unlink( $backup['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}

		// Remove any mu-plugins twin (all suffixes).
		self::remove_mu_twins( $name );

		AuditLog::record( 'sandbox.delete', [ 'name' => $name ] );
		return true;
	}

	// -------------------------------------------------------------------------
	// Activate / Deactivate
	// -------------------------------------------------------------------------

	/**
	 * Runs StaticGuard on the draft and, if clean, writes it to mu-plugins with
	 * a loader guard as its first statement.
	 *
	 * @param string $name Basename.
	 * @return bool|\WP_Error
	 */
	public static function activate( string $name ): bool|\WP_Error {
		$guard = self::guard_name( $name );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$contents = self::read( $name );
		if ( is_wp_error( $contents ) ) {
			return $contents;
		}

		$errors = StaticGuard::scan( $contents );
		if ( ! empty( $errors ) ) {
			return new \WP_Error(
				'stonewright_sandbox_static_guard',
				'Static guard blocked activation: ' . implode( '; ', $errors ),
				[ 'violations' => $errors ]
			);
		}

		$guarded = self::with_loader_guard( $contents );
		if ( is_wp_error( $guarded ) ) {
			return $guarded;
		}

		$mu_path = self::mu_dir() . '/' . self::active_prefix() . $name;

		// Ensure mu-plugins directory exists.
		if ( ! is_dir( self::mu_dir() ) ) {
			wp_mkdir_p( self::mu_dir() );
		}

		if ( false === file_put_contents( $mu_path, $guarded ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return new \WP_Error( 'stonewright_sandbox_activate_error', "Could not copy to mu-plugins: {$name}" );
		}

		AuditLog::record( 'sandbox.activate', [ 'name' => $name ] );
		return true;
	}

	/**
	 * Removes the mu-plugins twin, leaving the draft intact.
	 *
	 * @param string $name Basename.
	 * @return bool|\WP_Error
	 */
	public static function deactivate( string $name ): bool|\WP_Error {
		$guard = self::guard_name( $name );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$mu_path = self::mu_dir() . '/' . self::active_prefix() . $name;
		if ( ! file_exists( $mu_path ) ) {
			return new \WP_Error( 'stonewright_sandbox_not_active', "File is not active in mu-plugins: {$name}" );
		}

		if ( ! unlink( $mu_path ) ) {
			return new \WP_Error( 'stonewright_sandbox_deactivate_error', "Could not remove mu-plugins twin: {$name}" );
		}

		AuditLog::record( 'sandbox.deactivate', [ 'name' => $name ] );
		return true;
	}

	// -------------------------------------------------------------------------
	// Disable / Enable
	// -------------------------------------------------------------------------

	/**
	 * Renames the mu-plugins twin to add a .disabled suffix (stops PHP loading it).
	 *
	 * @param string $name Basename.
	 * @return bool|\WP_Error
	 */
	public static function disable( string $name ): bool|\WP_Error {
		$guard = self::guard_name( $name );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$mu_path       = self::mu_dir() . '/' . self::active_prefix() . $name;
		$disabled_path = $mu_path . '.disabled';

		if ( ! file_exists( $mu_path ) ) {
			return new \WP_Error( 'stonewright_sandbox_not_active', "File is not active in mu-plugins: {$name}" );
		}

		if ( ! rename( $mu_path, $disabled_path ) ) {
			return new \WP_Error( 'stonewright_sandbox_disable_error', "Could not disable mu-plugins twin: {$name}" );
		}

		AuditLog::record( 'sandbox.disable', [ 'name' => $name ] );
		return true;
	}

	/**
	 * Removes the .disabled suffix from a mu-plugins twin.
	 *
	 * @param string $name Basename.
	 * @return bool|\WP_Error
	 */
	public static function enable( string $name ): bool|\WP_Error {
		$guard = self::guard_name( $name );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$disabled_path = self::mu_dir() . '/' . self::active_prefix() . $name . '.disabled';
		$mu_path       = self::mu_dir() . '/' . self::active_prefix() . $name;

		if ( ! file_exists( $disabled_path ) ) {
			return new \WP_Error( 'stonewright_sandbox_not_disabled', "No disabled twin found for: {$name}" );
		}

		if ( ! rename( $disabled_path, $mu_path ) ) {
			return new \WP_Error( 'stonewright_sandbox_enable_error', "Could not re-enable mu-plugins twin: {$name}" );
		}

		AuditLog::record( 'sandbox.enable', [ 'name' => $name ] );
		return true;
	}

	// -------------------------------------------------------------------------
	// Name validation
	// -------------------------------------------------------------------------

	/**
	 * Returns true if the given name matches the allowed pattern.
	 *
	 * @param string $name Candidate filename.
	 */
	public static function valid_name( string $name ): bool {
		return (bool) preg_match( self::NAME_REGEX, $name );
	}

	/**
	 * Returns the file name rule as an HTML pattern attribute value.
	 */
	public static function name_pattern(): string {
		return self::NAME_PATTERN;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Validates a name parameter and rejects path traversal.
	 *
	 * @param string $name Raw caller-supplied name.
	 * @return bool|\WP_Error
	 */
	private static function guard_name( string $name ): bool|\WP_Error {
		// Only allow basenames — reject anything that contains a path separator.
		if ( $name !== basename( $name ) ) {
			return new \WP_Error( 'stonewright_sandbox_invalid_name', 'Path traversal detected in name.' );
		}

		if ( ! self::valid_name( $name ) ) {
			return new \WP_Error(
				'stonewright_sandbox_invalid_name',
				"Invalid sandbox file name: {$name}. Must match /^[a-z0-9_\\-]+\\.php$/"
			);
		}

		if ( in_array( $name, self::RESERVED_NAMES, true ) ) {
			return new \WP_Error(
				'stonewright_sandbox_reserved_name',
				"Reserved file name: {$name}. This file is protected and cannot be modified."
			);
		}

		return true;
	}

	/**
	 * Removes all mu-plugins twins for a given draft name (any suffix variant).
	 *
	 * @param string $name Draft basename.
	 */
	private static function remove_mu_twins( string $name ): void {
		$base    = self::mu_dir() . '/' . self::active_prefix() . $name;
		$targets = [ $base, $base . '.disabled', $base . '.crashed' ];

		foreach ( $targets as $target ) {
			if ( file_exists( $target ) ) {
				@unlink( $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
	}

	/**
	 * Draft name without its trailing ".php".
	 */
	private static function stem( string $name ): string {
		return str_ends_with( $name, '.php' ) ? substr( $name, 0, -4 ) : $name;
	}

	// -------------------------------------------------------------------------
	// Folder preparation: guard files and storage migration
	// -------------------------------------------------------------------------

	/**
	 * Creates the folder, brings its guard files up to date and moves files of
	 * earlier layouts to the current names. The layout option is set only when
	 * everything succeeded, so a partial run is retried on the next call.
	 */
	private static function prepare_directory( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$guards_written = self::write_guard_files( $dir );
		$failed         = self::migrate_earlier_files( $dir );

		if ( $guards_written && 0 === $failed ) {
			update_option( self::LAYOUT_OPTION, self::LAYOUT_VERSION );
		}
	}

	/**
	 * Writes the files that keep the folder from being served: an Apache 2.4
	 * rule with an Apache 2.2 fallback, an IIS rule, and an index file that
	 * stops at once outside WordPress.
	 *
	 * @return bool False when a guard file could not be written.
	 */
	private static function write_guard_files( string $dir ): bool {
		$files = [
			'.htaccess'  => "# Stonewright sandbox: no direct web access.\n"
				. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
				. "<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
				. "<configuration><system.webServer><security><authorization><remove users=\"*\" roles=\"\" verbs=\"\" /><add accessType=\"Deny\" users=\"*\" /></authorization></security></system.webServer></configuration>\n",
			'index.php'  => "<?php\n" . self::LOADER_GUARD . "\n// Silence is golden.\n",
		];

		$ok = true;
		foreach ( $files as $name => $contents ) {
			$path = $dir . '/' . $name;
			if ( is_file( $path ) && file_get_contents( $path ) === $contents ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				continue;
			}
			if ( false === file_put_contents( $path, $contents ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				$ok = false;
			}
		}

		return $ok;
	}

	/**
	 * Moves drafts and backups written by earlier versions (".php" names) to the
	 * current storage names. A file that cannot take its new name because that
	 * name is taken, and any other file in the folder that a web server could
	 * run as PHP, is renamed to a ".quarantined" name instead: nothing is
	 * overwritten and nothing is deleted.
	 *
	 * @return int Number of files that could not be moved.
	 */
	private static function migrate_earlier_files( string $dir ): int {
		$entries = scandir( $dir );
		if ( false === $entries ) {
			return 1;
		}

		$failed = 0;
		foreach ( $entries as $entry ) {
			if ( in_array( $entry, [ '.', '..', 'index.php' ], true ) ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_link( $path ) || ! is_file( $path ) || 1 !== preg_match( self::RUNNABLE_NAME, $entry ) ) {
				continue;
			}

			$target = null;
			if ( 1 === preg_match( '/^([a-z0-9_\-]+)\.php\.(\d+)\.bak\.php$/D', $entry, $m ) ) {
				$target = $m[1] . '.' . $m[2] . '.' . self::BACKUP_EXTENSION;
			} elseif ( 1 === preg_match( '/^(widget-[a-z][a-z0-9_\-]{2,40}\.pending)\.php$/D', $entry, $m ) ) {
				$target = $m[1] . '.' . self::DRAFT_EXTENSION;
			} elseif ( self::valid_name( $entry ) ) {
				$target = self::stored_name( $entry );
			}

			if ( null !== $target && ! file_exists( $dir . '/' . $target ) && self::move_file( $path, $dir . '/' . $target ) ) {
				continue;
			}

			if ( ! self::move_file( $path, self::quarantine_path( $dir, $entry ) ) ) {
				++$failed;
			}
		}

		return $failed;
	}

	/**
	 * Name a quarantined file takes: its PHP-like extensions lose their dot so
	 * no handler mapped to a double extension can match it.
	 */
	private static function quarantine_path( string $dir, string $entry ): string {
		$base = (string) preg_replace( '/\.(php[0-9]?|phtml|pht|phar|phps)(?=\.|$)/i', '_$1', $entry ) . '.quarantined';
		$path = $dir . '/' . $base;
		$n    = 1;
		while ( file_exists( $path ) ) {
			$path = $dir . '/' . $n . '-' . $base;
			++$n;
		}
		return $path;
	}

	private static function move_file( string $from, string $to ): bool {
		if ( @rename( $from, $to ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
			return true;
		}
		if ( @copy( $from, $to ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_copy
			if ( @unlink( $from ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return true;
			}
			@unlink( $to ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		return false;
	}

	// -------------------------------------------------------------------------
	// Loader guard for activated files
	// -------------------------------------------------------------------------

	/**
	 * Returns the source with `defined( 'ABSPATH' ) || exit;` as its first
	 * statement, so the activated file stops at once when a web server runs it
	 * outside WordPress. The statement goes after the open tag and after any
	 * leading declare and namespace statements, which PHP requires to come
	 * first, on the same line so line numbers do not move. A file without a PHP
	 * open tag has nothing to run and is returned unchanged.
	 *
	 * @return string|\WP_Error
	 */
	private static function with_loader_guard( string $source ): string|\WP_Error {
		$tokens = token_get_all( $source );
		$count  = count( $tokens );

		$open = null;
		foreach ( $tokens as $index => $token ) {
			if ( is_array( $token ) && T_OPEN_TAG === $token[0] ) {
				$open = $index;
				break;
			}
		}
		if ( null === $open ) {
			return $source;
		}

		$insert_after = $open;
		for ( $i = $open + 1; $i < $count; $i++ ) {
			$id = is_array( $tokens[ $i ] ) ? $tokens[ $i ][0] : null;
			if ( T_WHITESPACE === $id || T_COMMENT === $id || T_DOC_COMMENT === $id ) {
				continue;
			}
			if ( T_DECLARE === $id ) {
				$end = self::declare_statement_end( $tokens, $i );
				if ( null === $end ) {
					return self::guard_error();
				}
				$insert_after = $end;
				$i            = $end;
				continue;
			}
			if ( T_NAMESPACE === $id ) {
				$end = self::namespace_declaration_end( $tokens, $i );
				if ( null !== $end ) {
					$insert_after = $end;
				}
			}
			break;
		}

		// Byte offset right after the token that ends the leading statements.
		$offset = 0;
		foreach ( $tokens as $index => $token ) {
			$text = is_array( $token ) ? $token[1] : $token;
			if ( $index === $insert_after ) {
				// An open tag carries its trailing newline: the guard goes before it.
				$offset += $index === $open ? strlen( rtrim( $text ) ) : strlen( $text );
				break;
			}
			$offset += strlen( $text );
		}

		return substr( $source, 0, $offset ) . ' ' . self::LOADER_GUARD . substr( $source, $offset );
	}

	/**
	 * Index of the ";" that ends a declare(...) statement, or null for the block
	 * form (declare(...) { } or declare(...): ), which cannot take a statement
	 * in front of it.
	 *
	 * @param array<int, mixed> $tokens
	 */
	private static function declare_statement_end( array $tokens, int $declare ): ?int {
		$count = count( $tokens );
		$depth = 0;
		$i     = $declare + 1;
		for ( ; $i < $count; $i++ ) {
			if ( '(' === $tokens[ $i ] ) {
				++$depth;
			} elseif ( ')' === $tokens[ $i ] ) {
				--$depth;
				if ( 0 === $depth ) {
					break;
				}
			}
		}
		for ( ++$i; $i < $count; $i++ ) {
			$id = is_array( $tokens[ $i ] ) ? $tokens[ $i ][0] : null;
			if ( T_WHITESPACE === $id || T_COMMENT === $id || T_DOC_COMMENT === $id ) {
				continue;
			}
			return ';' === $tokens[ $i ] ? $i : null;
		}
		return null;
	}

	/**
	 * Index of the ";" or "{" that ends a namespace declaration, or null when
	 * the token is the namespace operator inside an expression.
	 *
	 * @param array<int, mixed> $tokens
	 */
	private static function namespace_declaration_end( array $tokens, int $namespace ): ?int {
		$count = count( $tokens );
		$seen  = false;
		for ( $i = $namespace + 1; $i < $count; $i++ ) {
			$id = is_array( $tokens[ $i ] ) ? $tokens[ $i ][0] : null;
			if ( T_WHITESPACE === $id || T_COMMENT === $id || T_DOC_COMMENT === $id ) {
				continue;
			}
			if ( ';' === $tokens[ $i ] || '{' === $tokens[ $i ] ) {
				return $i;
			}
			if ( ! $seen && ( T_STRING === $id || T_NAME_QUALIFIED === $id ) ) {
				$seen = true;
				continue;
			}
			return null;
		}
		return null;
	}

	private static function guard_error(): \WP_Error {
		return new \WP_Error(
			'stonewright_sandbox_activate_error',
			'Could not place the loader guard: the file starts with a declare() block. Use declare(strict_types=1); as a plain statement.'
		);
	}
}
