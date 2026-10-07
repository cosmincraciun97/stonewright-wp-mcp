<?php
/**
 * Installation of the rescue MU-plugin.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Core;

/**
 * Keeps plugin/mu/stonewright-rescue.php installed in the must-use plugins directory.
 *
 * The file is copied byte for byte: a temporary file in the target directory, checked against
 * the SHA-256 of the bundled file, then renamed over the target, so WordPress never loads a
 * half-written copy. The bundled file is parsed with the running PHP version first and is never
 * installed when it does not parse or is cut short.
 *
 * It is installed when the plugin is activated and again whenever the plugin version or the
 * plugin file name changes. On every admin page load its hash is compared with the bundled
 * file and a missing or modified copy is written again. When the directory cannot be written,
 * or file changes are turned off for the site, Stonewright goes on without the helper and an
 * admin notice says so.
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
		$status = self::status();
		if ( 'ok' === $status['state'] ) {
			self::record_success();
		} else {
			self::record_failure( $status['state'] );
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
		if ( self::recently_failed() ) {
			return;
		}
		if ( 'ok' === self::install()['state'] ) {
			update_option( self::OPTION_VERSION, $version, true );
		}
	}

	/** Action callback for admin_init. */
	public static function on_admin_init(): void {
		self::verify_and_repair();
	}

	/**
	 * Admin page load: compares the installed file with the bundled one and writes it again when it
	 * is missing or was changed. Also records where the journal file lives, for the helper.
	 *
	 * @return array{state:string,target:string,installed_sha256:?string,bundled_sha256:?string,writable:bool}
	 */
	public static function verify_and_repair(): array {
		self::remember_state_dir();
		$status = self::status();
		if ( ! in_array( $status['state'], [ 'missing', 'modified' ], true ) || self::recently_failed() ) {
			if ( 'ok' === $status['state'] ) {
				self::forget_failure();
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
		$status = self::status();
		$state  = $status['state'];
		if ( in_array( $state, [ 'missing', 'modified' ], true ) && self::recently_failed() ) {
			$state = 'unwritable';
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

	private static function record_failure( string $reason ): void {
		update_option( self::OPTION_STATE, [ 'failed_at' => self::now(), 'reason' => $reason ], true );
	}

	private static function record_success(): void {
		if ( [] !== get_option( self::OPTION_STATE, [] ) ) {
			update_option( self::OPTION_STATE, [], true );
		}
	}

	private static function forget_failure(): void {
		self::record_success();
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
