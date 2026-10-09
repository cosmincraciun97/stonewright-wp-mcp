<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Support;

/**
 * Writes the deny guards that keep a folder under uploads from being served:
 * an index.php, an .htaccess rule for Apache and a web.config rule for IIS.
 */
final class DirectoryGuard {

	public const FILES = [ 'index.php', '.htaccess', 'web.config' ];

	private const HTACCESS = "# Stonewright: deny direct access.\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n";

	private const WEB_CONFIG = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n\t<system.webServer>\n\t\t<authorization>\n\t\t\t<deny users=\"*\" />\n\t\t</authorization>\n\t</system.webServer>\n</configuration>\n";

	private const INDEX = "<?php\n// Silence is golden.\n";

	/**
	 * Writes the guard files that are missing. Existing guard files are never overwritten.
	 *
	 * @return bool True when all three guard files exist afterwards.
	 */
	public static function apply( string $dir ): bool {
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
}
