<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Support;

/**
 * Handles `uploads/stonewright-mirror`, where earlier versions of the design
 * mirror export wrote one JSON file per exported page.
 *
 * The directory is guarded first (index.php, .htaccess and web.config deny
 * rules) so anything that stays in it is not served. Only regular files
 * directly inside the directory that carry the export format are then deleted;
 * symbolic links, subdirectories and every other file are left untouched. The
 * outcome is logged as counts only.
 */
final class LegacyMirrorCleanup {

	public const DIRECTORY = 'stonewright-mirror';

	private const MAX_BYTES = 16777216;

	private const HTACCESS = "# Stonewright: deny direct access.\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n";

	private const WEB_CONFIG = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n\t<system.webServer>\n\t\t<authorization>\n\t\t\t<deny users=\"*\" />\n\t\t</authorization>\n\t</system.webServer>\n</configuration>\n";

	private const INDEX = "<?php\n// Silence is golden.\n";

	/**
	 * @return array{status:string,guarded:bool,removed:int,kept:int}
	 */
	public static function run(): array {
		$upload = wp_upload_dir();
		$base   = isset( $upload['basedir'] ) && is_string( $upload['basedir'] ) ? $upload['basedir'] : '';
		$result = [
			'status'  => 'absent',
			'guarded' => false,
			'removed' => 0,
			'kept'    => 0,
		];
		if ( '' === $base || ! empty( $upload['error'] ) ) {
			return $result;
		}
		$dir = rtrim( $base, '/\\' ) . '/' . self::DIRECTORY;
		if ( is_link( $dir ) || ! is_dir( $dir ) ) {
			return $result;
		}

		$result['guarded'] = self::guard( $dir );
		$result['status']  = 'cleaned';

		$entries = scandir( $dir );
		foreach ( false === $entries ? [] : $entries as $name ) {
			if ( '.' === $name || '..' === $name || in_array( $name, [ 'index.php', '.htaccess', 'web.config' ], true ) ) {
				continue;
			}
			$path = $dir . '/' . $name;
			if ( self::is_export_file( $name, $path ) && @unlink( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
				++$result['removed'];
				continue;
			}
			++$result['kept'];
		}

		$context = [
			'removed' => $result['removed'],
			'kept'    => $result['kept'],
			'guarded' => $result['guarded'],
		];
		if ( $result['guarded'] ) {
			Logger::info( 'mirror_export.legacy_cleanup', $context );
		} else {
			Logger::warning( 'mirror_export.legacy_cleanup', $context );
		}
		return $result;
	}

	/** Writes the three guard files that are missing. Existing guard files are never overwritten. */
	private static function guard( string $dir ): bool {
		$ok = true;
		foreach ( [
			'index.php'  => self::INDEX,
			'.htaccess'  => self::HTACCESS,
			'web.config' => self::WEB_CONFIG,
		] as $name => $body ) {
			$path = $dir . '/' . $name;
			if ( is_file( $path ) ) {
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			if ( false === @file_put_contents( $path, $body ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$ok = false;
			}
		}
		return $ok;
	}

	private static function is_export_file( string $name, string $path ): bool {
		if ( 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]*\.json$/D', $name ) ) {
			return false;
		}
		if ( is_link( $path ) || ! is_file( $path ) ) {
			return false;
		}
		$size = filesize( $path );
		if ( false === $size || $size > self::MAX_BYTES ) {
			return false;
		}
		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $raw ) {
			return false;
		}
		$data = json_decode( $raw, true );
		return is_array( $data ) && isset( $data['post_id'] ) && array_key_exists( 'elementor', $data );
	}
}
