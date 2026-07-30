<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Sandbox;

use Stonewright\WpMcp\Security\AuditLog;

/**
 * Manages sandbox draft files and their mu-plugins twins.
 *
 * Draft files live in wp-content/stonewright-sandbox/ and are NEVER auto-loaded.
 * Activation copies a draft to mu-plugins after StaticGuard::scan() passes.
 */
final class SandboxFiles {

	/** Max allowed file size for a draft: 200 KB. */
	private const MAX_BYTES = 204800;

	/** Regex for valid sandbox file names. */
	private const NAME_REGEX = '/^[a-z0-9_-]+\.php$/';

	/** Maximum number of backup versions to retain per file. */
	private const MAX_BACKUPS = 10;

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
	 * Returns the draft sandbox directory path, creating it with security stubs
	 * if it does not already exist.
	 */
	public static function draft_dir(): string {
		$dir = WP_CONTENT_DIR . '/stonewright-sandbox';

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );

			// Deny direct HTTP access.
			$htaccess = $dir . '/.htaccess';
			if ( ! file_exists( $htaccess ) ) {
				file_put_contents( $htaccess, "deny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}

			// Prevent directory listing fallback.
			$index = $dir . '/index.php';
			if ( ! file_exists( $index ) ) {
				file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}

		return $dir;
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

		$drafts = glob( $draft_dir . '/*.php' );
		if ( false === $drafts ) {
			$drafts = [];
		}

		foreach ( $drafts as $path ) {
			$name     = basename( $path );
			$mu_twin  = $mu_dir . '/' . $prefix . $name;

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

		// Strip reserved names (e.g. index.php) and any file whose name does not
		// match the strict NAME_REGEX (e.g. backup .bak.php files from glob bleed).
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
	 * Backups are named <basename>.<unix_ts>.bak.php, e.g. my-snippet.php.1716000000.bak.php.
	 *
	 * @param string $basename PHP basename (e.g. "my-snippet.php").
	 * @return array<int, array{timestamp: int, path: string}>
	 */
	public static function backup_versions( string $basename ): array {
		$draft_dir = self::draft_dir();
		$escaped   = preg_quote( $basename, '/' );
		$pattern   = $draft_dir . '/' . $basename . '.*.bak.php';

		$files = glob( $pattern );
		if ( false === $files ) {
			return [];
		}

		$versions = [];
		foreach ( $files as $path ) {
			// Extract the timestamp portion: <basename>.<ts>.bak.php
			$filename = basename( $path );
			// Pattern: <basename>.<digits>.bak.php
			$match = preg_match(
				'/^' . $escaped . '\.(\d+)\.bak\.php$/',
				$filename,
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
		$path = self::draft_dir() . '/' . $basename;
		if ( ! file_exists( $path ) ) {
			return;
		}

		$ts          = time();
		$backup_path = $path . '.' . $ts . '.bak.php';
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

		$path = self::draft_dir() . '/' . $name;
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

		$path   = self::draft_dir() . '/' . $name;
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

		$path   = self::draft_dir() . '/' . $name;
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
	 * Deletes a draft file and its active mu-plugins twin (if any).
	 *
	 * @param string $name Basename.
	 * @return bool|\WP_Error
	 */
	public static function delete( string $name ): bool|\WP_Error {
		$guard = self::guard_name( $name );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$path = self::draft_dir() . '/' . $name;
		if ( ! file_exists( $path ) ) {
			return new \WP_Error( 'stonewright_sandbox_not_found', "Sandbox file not found: {$name}" );
		}

		// Remove draft.
		if ( ! unlink( $path ) ) {
			return new \WP_Error( 'stonewright_sandbox_delete_error', "Could not delete sandbox file: {$name}" );
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
	 * Runs StaticGuard on the draft and, if clean, copies it to mu-plugins.
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

		$mu_path = self::mu_dir() . '/' . self::active_prefix() . $name;

		// Ensure mu-plugins directory exists.
		if ( ! is_dir( self::mu_dir() ) ) {
			wp_mkdir_p( self::mu_dir() );
		}

		if ( ! copy( self::draft_dir() . '/' . $name, $mu_path ) ) {
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

		$mu_path      = self::mu_dir() . '/' . self::active_prefix() . $name;
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
				"Invalid sandbox file name: {$name}. Must match /^[a-z0-9_-]+\\.php$/"
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
}
