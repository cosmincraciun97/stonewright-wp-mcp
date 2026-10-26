<?php
/**
 * Installation of the rescue MU-plugin.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Core;

use Stonewright\WpMcp\Support\LateLoad;

/**
 * Keeps plugin/mu/stonewright-rescue.php installed in the must-use plugins directory.
 *
 * The file is copied byte for byte: a temporary file in the target directory, checked against
 * the SHA-256 of the bundled file, then renamed over the target, so WordPress never loads a
 * half-written copy. The bundled file is parsed with the running PHP version first and is never
 * installed when it does not parse or is cut short.
 *
 * It is installed when the plugin is activated and again whenever the plugin version or the
 * plugin file name changes. On an admin page load its size and modification time are compared with
 * the ones remembered; the file is read and its hash compared with the bundled file only when they
 * differ, when it is gone, when the last check is twelve hours old, when no check is remembered, or
 * after an update, and a missing or modified copy is then written again. When the directory cannot
 * be written, or file changes are turned off for the site, Stonewright goes on without the helper
 * and an admin notice says so.
 *
 * The option stonewright_rescue_mu_state holds the last check (checked_at, and the size and mtime
 * of the file as it stood, null when it was not there) and, when the helper could not be installed,
 * failed_at and the reason. It is small and autoloaded.
 *
 * The helper is code, not data: deleting the plugin always removes it, and it stays on disk but
 * does nothing while the plugin is deactivated.
 *
 * This class uses no other plugin class at load time, so uninstall.php can call remove() on its own.
 */
final class RescueInstaller {

	public const MU_FILE = 'stonewright-rescue.php';

	/** A tag in the header of the helper. A file without it is not ours, whatever it is called. */
	public const MARKER = '@stonewright-rescue-mu';

	/** The last line of the helper. A bundled file that does not end with it is cut short. */
	public const END_MARKER = 'STONEWRIGHT_RESCUE_MU_END */';

	/** Version of Stonewright_Rescue this plugin needs. */
	public const REQUIRED_VERSION = 1;

	public const OPTION_PLUGIN  = 'stonewright_rescue_plugin';
	public const OPTION_VERSION = 'stonewright_rescue_mu_version';
	public const OPTION_STATE   = 'stonewright_rescue_mu_state';
	public const OPTION_DIR     = 'stonewright_rescue_state_dir';

	/** Seconds after a failed install before another one is tried. */
	public const RETRY_SECONDS = 300;

	/** Seconds after which the installed file is read and hashed again, whatever its size and time say. */
	public const CHECK_SECONDS = 12 * HOUR_IN_SECONDS;

	private const MAX_BYTES = 262144;

	private static ?string $source_override = null;

	private static ?string $bundled_hash = null;

	/** @var array{0:bool,1:?string}|null Whether the bundled file was checked in this request, and the problem found. */
	private static ?array $problem = null;

	/** Replace the path of the bundled file. For tests. */
	public static function override_source( ?string $path ): void {
		self::$source_override = $path;
		self::$bundled_hash    = null;
		self::$problem         = null;
	}

	public static function source_path(): string {
		if ( null !== self::$source_override ) {
			return self::$source_override;
		}
		$dir = defined( 'STONEWRIGHT_DIR' ) ? (string) constant( 'STONEWRIGHT_DIR' ) : dirname( __DIR__, 2 ) . '/';
		return rtrim( $dir, '/\\' ) . '/mu/' . self::MU_FILE;
	}

	public static function target_dir(): string {
		$dir = defined( 'WPMU_PLUGIN_DIR' ) ? (string) constant( 'WPMU_PLUGIN_DIR' ) : ( defined( 'WP_CONTENT_DIR' ) ? (string) constant( 'WP_CONTENT_DIR' ) . '/mu-plugins' : '' );
		return rtrim( str_replace( '\\', '/', $dir ), '/' );
	}

	public static function target_path(): string {
		return self::target_dir() . '/' . self::MU_FILE;
	}

	/**
	 * What the helper's installation looks like right now. Changes nothing.
	 *
	 * The state is ok (the installed file is the bundled file), missing, modified (a file that is not
	 * the bundled one is there), unwritable (it is missing or modified and the directory cannot be
	 * written), file_mods_disabled (it is missing or modified and the site forbids file changes) or
	 * source_invalid (the bundled file cannot be installed).
	 *
	 * @return array{state:string,target:string,installed_sha256:?string,bundled_sha256:?string,writable:bool}
	 */
	public static function status(): array {
		$target    = self::target_path();
		$bundled   = null === self::source_problem() ? self::bundled_hash() : null;
		$installed = is_file( $target ) ? self::file_hash( $target ) : null;
		$writable  = self::can_write();
		$state     = 'ok';
		if ( null === $bundled ) {
			$state = 'source_invalid';
		} elseif ( $installed !== $bundled ) {
			if ( null === $installed ) {
				$state = 'missing';
			} else {
				$state = 'modified';
			}
			if ( self::file_mods_disabled() ) {
				$state = 'file_mods_disabled';
			} elseif ( ! $writable ) {
				$state = 'unwritable';
			}
		}
		return [
			'state'            => $state,
			'target'           => $target,
			'installed_sha256' => $installed,
			'bundled_sha256'   => $bundled,
			'writable'         => $writable,
		];
	}

	/**
	 * The helper as an administrator or an agent needs to see it, with no path or hash.
	 *
	 * The state is installed (the current helper is in place and loaded), not_loaded (it is in place
	 * but this request did not load it yet), or the state of status() when it is not: missing,
	 * modified, unwritable, file_mods_disabled or source_invalid. safe_mode says whether a safe
	 * mode link can be issued.
	 *
	 * @return array{state:string,safe_mode:bool}
	 */
	public static function summary(): array {
		$state = self::status()['state'];
		$ready = 'ok' === $state && RescueRuntime::version() >= self::REQUIRED_VERSION;
		if ( 'ok' === $state ) {
			$state = $ready ? 'installed' : 'not_loaded';
		}
		return [ 'state' => $state, 'safe_mode' => $ready ];
	}

	/**
	 * Copies the bundled file into place, or reports why it did not.
	 *
	 * @return array{state:string,target:string,installed_sha256:?string,bundled_sha256:?string,writable:bool}
	 */
	public static function install(): array {
		$status = self::status();
		if ( 'ok' !== $status['state'] && in_array( $status['state'], [ 'missing', 'modified' ], true ) ) {
			if ( ! self::write_target() ) {
				self::record_failure( 'write_failed' );
				return self::status();
			}
		}
		self::remember();
		// The stat comes before the hash: a file that changes in between is looked at again next time.
		$memory = self::file_memory();
		$status = self::status();
		if ( 'ok' === $status['state'] ) {
			self::record_success( $memory );
		} else {
			self::record_failure( $status['state'], $memory );
		}
		return $status;
	}

	/**
	 * Activation: install now, whatever an earlier failure said.
	 */
	public static function on_activate(): void {
		self::forget_failure();
		self::install();
	}

	/**
	 * Deactivation: the helper stays on disk and does nothing while the plugin is inactive. The
	 * rescue keys and safe boot sessions that are still waiting are removed.
	 */
	public static function on_deactivate(): void {
		\Stonewright\WpMcp\Security\RescueKeys::revoke_all();
	}

	/**
	 * Installs the helper again when the plugin version or the plugin file name changed. Runs on init.
	 */
	public static function maybe_upgrade(): void {
		$version = defined( 'STONEWRIGHT_VERSION' ) ? (string) constant( 'STONEWRIGHT_VERSION' ) : '';
		if ( (string) get_option( self::OPTION_VERSION, '' ) === $version && (string) get_option( self::OPTION_PLUGIN, '' ) === self::plugin_basename() ) {
			return;
		}
		// Only now: a request with nothing to do (nearly every request) must not pay for the file checks.
		if ( ! self::late_load_ready() ) {
			return;
		}
		if ( self::recently_failed() ) {
			return;
		}
		if ( 'ok' === self::install()['state'] ) {
			update_option( self::OPTION_VERSION, $version, true );
		}
	}

	/**
	 * Action callback for admin_init. The helper is read and hashed only when check_due() says so;
	 * every other request costs one stat.
	 */
	public static function on_admin_init(): void {
		if ( ! self::late_load_ready() ) {
			return;
		}
		if ( self::check_due() ) {
			self::verify_and_repair();
			return;
		}
		self::remember_state_dir();
	}

	/**
	 * The next admin page load reads the helper whatever the last check says. Action callback for
	 * upgrader_process_complete, which fires at a real update only. A record of a failed install stays, so
	 * that one is not retried at once.
	 */
	public static function force_next_check(): void {
		$state = get_option( self::OPTION_STATE, [] );
		if ( ! is_array( $state ) ) {
			return;
		}
		$kept = array_diff_key( $state, array_flip( [ 'checked_at', 'size', 'mtime', 'version' ] ) );
		if ( $kept !== $state ) {
			update_option( self::OPTION_STATE, $kept, true );
		}
	}

	/**
	 * Compares the installed file with the bundled one and writes it again when it is missing or was
	 * changed, and remembers the check. Also records where the journal file lives, for the helper.
	 * Always reads the file: on_admin_init() decides whether that is needed.
	 *
	 * @return array{state:string,target:string,installed_sha256:?string,bundled_sha256:?string,writable:bool}
	 */
	public static function verify_and_repair(): array {
		self::remember_state_dir();
		$memory = self::file_memory();
		$status = self::status();
		if ( ! in_array( $status['state'], [ 'missing', 'modified' ], true ) || self::recently_failed() ) {
			if ( 'ok' === $status['state'] ) {
				self::record_success( $memory );
			} elseif ( self::recently_failed() ) {
				// Not tried again yet: the failure stays as it was and the check is remembered beside it.
				$failure = (array) get_option( self::OPTION_STATE, [] );
				update_option( self::OPTION_STATE, [ 'failed_at' => (int) ( $failure['failed_at'] ?? 0 ), 'reason' => (string) ( $failure['reason'] ?? $status['state'] ) ] + $memory, true );
			} else {
				self::record_failure( $status['state'], $memory );
			}
			return $status;
		}
		$tampered = 'modified' === $status['state'];
		$result   = self::install();
		if ( $tampered && 'ok' === $result['state'] ) {
			self::audit_repair();
		}
		return $result;
	}

	/**
	 * Whether the helper is installed and the copy WordPress loaded in this request is current.
	 */
	public static function is_ready(): bool {
		return 'ok' === self::status()['state'] && RescueRuntime::version() >= self::REQUIRED_VERSION;
	}

	/**
	 * Deletes the installed helper when it is ours (it carries the marker). Used by uninstall.php,
	 * before the setting that keeps the plugin data is consulted.
	 *
	 * @return bool Whether no helper is left at the target.
	 */
	public static function remove(): bool {
		$path = self::target_path();
		if ( ! is_file( $path ) ) {
			return true;
		}
		$head = @file_get_contents( $path, false, null, 0, 4096 );
		if ( ! is_string( $head ) || false === strpos( $head, self::MARKER ) ) {
			return false;
		}
		$removed = @unlink( $path );
		clearstatcache( true, $path );
		return $removed || ! file_exists( $path );
	}

	/**
	 * Says so on admin_notices when the helper cannot be installed. Shown to administrators, on the
	 * Plugins screen, the Dashboard and the Stonewright pages.
	 */
	public static function admin_notice(): void {
		if ( ! current_user_can( 'manage_options' ) || ! self::on_notice_screen() ) {
			return;
		}
		$state = self::remembered_notice_state();
		if ( null === $state ) {
			$state = self::status()['state'];
			if ( in_array( $state, [ 'missing', 'modified' ], true ) && self::recently_failed() ) {
				$state = 'unwritable';
			}
		}
		if ( 'unwritable' === $state ) {
			$text = sprintf(
				/* translators: %s: folder path. */
				__( 'Stonewright could not install its rescue helper in the folder %s, because the folder is not writable or the file could not be written. Stonewright keeps working, but a fatal error after a change is not recorded automatically and safe mode is not available. Make the folder writable, or copy mu/stonewright-rescue.php from the Stonewright plugin folder into it.', 'stonewright' ),
				self::target_dir()
			);
		} elseif ( 'file_mods_disabled' === $state ) {
			$text = sprintf(
				/* translators: %s: folder path. */
				__( 'Stonewright did not install its rescue helper because file changes are turned off for this site. A fatal error after a change is not recorded automatically and safe mode is not available. To use them, copy mu/stonewright-rescue.php from the Stonewright plugin folder into %s.', 'stonewright' ),
				self::target_dir()
			);
		} elseif ( 'source_invalid' === $state ) {
			$text = __( 'The rescue helper that ships with Stonewright is damaged, so it was not installed. Reinstall the Stonewright plugin.', 'stonewright' );
		} else {
			return;
		}
		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Stonewright rescue helper.', 'stonewright' ) . '</strong> ' . esc_html( $text ) . '</p></div>';
	}

	// -----------------------------------------------------------------------
	// Internals.
	// -----------------------------------------------------------------------

	/** The reason the bundled file cannot be installed, or null when it can. */
	private static function source_problem(): ?string {
		if ( null === self::$problem ) {
			self::$problem = [ true, self::check_source() ];
		}
		return self::$problem[1];
	}

	private static function check_source(): ?string {
		$path = self::source_path();
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return 'unreadable';
		}
		$size = filesize( $path );
		if ( false === $size || $size < 1024 || $size > self::MAX_BYTES ) {
			return 'size';
		}
		$code = file_get_contents( $path );
		if ( ! is_string( $code ) || 0 !== strpos( $code, '<?php' ) || false === strpos( substr( $code, 0, 4096 ), self::MARKER ) ) {
			return 'not_the_helper';
		}
		if ( ! str_ends_with( rtrim( $code ), self::END_MARKER ) ) {
			return 'incomplete';
		}
		if ( function_exists( 'token_get_all' ) && defined( 'TOKEN_PARSE' ) ) {
			try {
				$tokens = token_get_all( $code, TOKEN_PARSE );
				unset( $tokens );
			} catch ( \Throwable $failure ) {
				unset( $failure );
				return 'syntax';
			}
		}
		return null;
	}

	private static function bundled_hash(): ?string {
		if ( null === self::$bundled_hash ) {
			self::$bundled_hash = self::file_hash( self::source_path() );
		}
		return self::$bundled_hash;
	}

	private static function file_hash( string $path ): ?string {
		$hash = is_file( $path ) ? @hash_file( 'sha256', $path ) : false;
		return is_string( $hash ) ? $hash : null;
	}

	/** Whether the target directory is, or can be made, writable. */
	private static function can_write(): bool {
		$dir = self::target_dir();
		while ( '' !== $dir && ! is_dir( $dir ) ) {
			if ( file_exists( $dir ) ) {
				return false;
			}
			$parent = dirname( $dir );
			if ( $parent === $dir ) {
				return false;
			}
			$dir = $parent;
		}
		return '' !== $dir && is_writable( $dir );
	}

	private static function file_mods_disabled(): bool {
		return defined( 'DISALLOW_FILE_MODS' ) && true === constant( 'DISALLOW_FILE_MODS' );
	}

	/**
	 * Writes the bundled file next to the target and renames it into place.
	 */
	private static function write_target(): bool {
		$dir = self::target_dir();
		if ( file_exists( $dir ) && ! is_dir( $dir ) ) {
			return false;
		}
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		$bundled = self::bundled_hash();
		if ( null === $bundled || ! is_dir( $dir ) || ! is_writable( $dir ) ) {
			return false;
		}
		$temp = $dir . '/' . self::MU_FILE . '.tmp-' . bin2hex( random_bytes( 4 ) );
		if ( ! @copy( self::source_path(), $temp ) ) {
			@unlink( $temp );
			return false;
		}
		if ( self::file_hash( $temp ) !== $bundled ) {
			@unlink( $temp );
			return false;
		}
		@chmod( $temp, defined( 'FS_CHMOD_FILE' ) ? (int) constant( 'FS_CHMOD_FILE' ) : 0644 );
		$target = self::target_path();
		for ( $attempt = 0; $attempt < 10; $attempt++ ) {
			if ( @rename( $temp, $target ) ) {
				clearstatcache( true, $target );
				return self::file_hash( $target ) === $bundled;
			}
			usleep( 10000 );
		}
		@unlink( $temp );
		return false;
	}

	private static function plugin_basename(): string {
		$file = defined( 'STONEWRIGHT_FILE' ) ? (string) constant( 'STONEWRIGHT_FILE' ) : ( defined( 'STONEWRIGHT_DIR' ) ? (string) constant( 'STONEWRIGHT_DIR' ) . 'stonewright.php' : 'stonewright/stonewright.php' );
		return plugin_basename( $file );
	}

	/** Tells the helper which plugin it belongs to, and where the journal file is. */
	private static function remember(): void {
		if ( (string) get_option( self::OPTION_PLUGIN, '' ) !== self::plugin_basename() ) {
			update_option( self::OPTION_PLUGIN, self::plugin_basename(), true );
		}
		self::remember_state_dir();
	}

	/**
	 * False when a class this class loads late is gone from disk, as in a request that outlives the deletion
	 * of the plugin folder: the hooks that run after plugins_loaded return then instead of reaching the autoloader.
	 */
	private static function late_load_ready(): bool {
		return LateLoad::ready(
			\Stonewright\WpMcp\Security\ChangeJournal::class,
			\Stonewright\WpMcp\Security\ChangeJournalFile::class,
			\Stonewright\WpMcp\Security\AuditLog::class
		);
	}

	private static function remember_state_dir(): void {
		if ( ! class_exists( \Stonewright\WpMcp\Security\ChangeJournal::class ) ) {
			return;
		}
		$dir = \Stonewright\WpMcp\Security\ChangeJournal::state_directory();
		if ( '' !== $dir && (string) get_option( self::OPTION_DIR, '' ) !== $dir ) {
			update_option( self::OPTION_DIR, $dir, true );
		}
	}

	private static function now(): int {
		return time();
	}

	private static function recently_failed(): bool {
		$state = get_option( self::OPTION_STATE, [] );
		return is_array( $state ) && (int) ( $state['failed_at'] ?? 0 ) > self::now() - self::RETRY_SECONDS;
	}

	/**
	 * Size and modification time of the installed file, null for both when it is not there. The stat
	 * cache is cleared first so that a change made earlier in this request is seen.
	 *
	 * @return array{size:?int,mtime:?int}
	 */
	private static function file_stat(): array {
		$path = self::target_path();
		clearstatcache( true, $path );
		$size  = is_file( $path ) ? @filesize( $path ) : false;
		$mtime = false === $size ? false : @filemtime( $path );
		if ( false === $size || false === $mtime ) {
			return [ 'size' => null, 'mtime' => null ];
		}
		return [ 'size' => (int) $size, 'mtime' => (int) $mtime ];
	}

	private static function running_version(): string {
		return defined( 'STONEWRIGHT_VERSION' ) ? (string) constant( 'STONEWRIGHT_VERSION' ) : '';
	}

	/**
	 * What a check remembers: when it ran, how the file looked and which plugin version ran the check.
	 *
	 * @return array{checked_at:int,size:?int,mtime:?int,version:string}
	 */
	private static function file_memory(): array {
		return [ 'checked_at' => self::now(), 'version' => self::running_version() ] + self::file_stat();
	}

	/**
	 * The remembered state when it carries a complete check, null for no state, an older shape or garbage.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function remembered_check(): ?array {
		$state = get_option( self::OPTION_STATE, [] );
		if ( ! is_array( $state ) || ! isset( $state['checked_at'] ) || ! is_int( $state['checked_at'] ) || ! array_key_exists( 'size', $state ) || ! array_key_exists( 'mtime', $state ) ) {
			return null;
		}
		return $state;
	}

	/**
	 * Whether the installed file still has the size and time of the remembered check (both absent counts).
	 *
	 * @param array<string,mixed> $state The remembered check.
	 */
	private static function looks_unchanged( array $state ): bool {
		$now = self::file_stat();
		return $now['size'] === ( null === $state['size'] ? null : (int) $state['size'] )
			&& $now['mtime'] === ( null === $state['mtime'] ? null : (int) $state['mtime'] );
	}

	/**
	 * Whether this admin request reads and hashes the helper: no check is remembered, the last one is
	 * twelve hours old, another plugin version made it (an older record without a version counts), or the
	 * file's size or time are not the remembered ones. A file that is not
	 * there counts as a change, except when the last check saw it missing too. A remembered failure,
	 * missing file or a repair that could not be written, is tried again after RETRY_SECONDS, as a
	 * failed install always was.
	 */
	private static function check_due(): bool {
		$state = self::remembered_check();
		if ( null === $state ) {
			return true;
		}
		$age = self::now() - $state['checked_at'];
		if ( (string) ( $state['version'] ?? '' ) !== self::running_version() ) {
			return true;
		}
		if ( $age >= self::CHECK_SECONDS || $age < -self::CHECK_SECONDS || ! self::looks_unchanged( $state ) ) {
			return true;
		}
		if ( isset( $state['failed_at'] ) ) {
			// The same clock as recently_failed(), so a retry that is due is not held back again.
			return self::now() - (int) $state['failed_at'] >= self::RETRY_SECONDS;
		}
		return null === $state['size'] && $age >= self::RETRY_SECONDS;
	}

	/**
	 * What the notice says, from the remembered check and without reading the helper: ok for a clean
	 * check of a file that still looks the same, the state of a failed install, or null when the
	 * memory cannot be trusted (none, an older shape, a file that moved) and status() must look.
	 */
	private static function remembered_notice_state(): ?string {
		$state = self::remembered_check();
		if ( null === $state || ! self::looks_unchanged( $state ) ) {
			return null;
		}
		$reason = (string) ( $state['reason'] ?? '' );
		if ( '' === $reason ) {
			return null === $state['size'] ? null : 'ok';
		}
		if ( 'write_failed' === $reason ) {
			return 'unwritable';
		}
		return in_array( $reason, [ 'unwritable', 'file_mods_disabled', 'source_invalid' ], true ) ? $reason : null;
	}

	/**
	 * A failed install or check. failed_at holds back the next install for RETRY_SECONDS; the memory
	 * keeps the file from being read again for twelve hours.
	 *
	 * @param array{checked_at:int,size:?int,mtime:?int,version:string}|null $memory Taken before the file was read, or now.
	 */
	private static function record_failure( string $reason, ?array $memory = null ): void {
		update_option( self::OPTION_STATE, [ 'failed_at' => self::now(), 'reason' => $reason ] + ( $memory ?? self::file_memory() ), true );
	}

	/**
	 * A check that found the helper in place.
	 *
	 * @param array{checked_at:int,size:?int,mtime:?int,version:string} $memory Taken before the file was read.
	 */
	private static function record_success( array $memory ): void {
		update_option( self::OPTION_STATE, $memory, true );
	}

	private static function forget_failure(): void {
		if ( [] !== get_option( self::OPTION_STATE, [] ) ) {
			update_option( self::OPTION_STATE, [], true );
		}
	}

	private static function on_notice_screen(): bool {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return true;
		}
		$screen = get_current_screen();
		$id     = is_object( $screen ) ? (string) $screen->id : '';
		return '' === $id || in_array( $id, [ 'plugins', 'dashboard' ], true ) || str_contains( $id, 'stonewright' );
	}

	/** A modified helper was replaced: one audit row, no detail about the other file. */
	private static function audit_repair(): void {
		try {
			\Stonewright\WpMcp\Security\AuditLog::record(
				'stonewright/rescue-helper-repair',
				[
					'_meta' => [
						'operation_class'  => 'rescue_helper_repair',
						'resource_type'    => 'mu_plugin',
						'resource_ref'     => self::MU_FILE,
						'execution_status' => 'executed',
					],
				],
				'ok'
			);
		} catch ( \Throwable $failure ) {
			unset( $failure );
		}
	}
}
