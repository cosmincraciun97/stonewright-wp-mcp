<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Support\OpenBasedir;

/**
 * @covers \Stonewright\WpMcp\Support\OpenBasedir
 */
final class OpenBasedirTest extends TestCase {

	public function test_an_empty_setting_allows_every_path(): void {
		self::assertTrue( OpenBasedir::allows( '/anywhere/openssl.cnf', '' ) );
	}

	public function test_a_path_inside_an_entry_is_allowed(): void {
		self::assertTrue( OpenBasedir::allows( '/var/www/site/wp-content/plugins/x/openssl.cnf', '/var/www/site' ) );
		self::assertTrue( OpenBasedir::allows( '/var/www/site', '/var/www/site' ), 'the entry itself is allowed' );
		self::assertTrue( OpenBasedir::allows( '/var/www/site/openssl.cnf', '/var/www/site/' ), 'a trailing slash on the entry makes no difference' );
	}

	public function test_a_path_outside_every_entry_is_refused(): void {
		self::assertFalse( OpenBasedir::allows( '/usr/local/php/extras/ssl/openssl.cnf', '/var/www/site' ) );
		self::assertFalse( OpenBasedir::allows( '/var/www/site-other/openssl.cnf', '/var/www/site' ), 'a sibling sharing the name prefix is outside' );
	}

	public function test_windows_separators_are_normalised(): void {
		self::assertTrue( OpenBasedir::allows( 'C:\www\site\data\openssl.cnf', 'C:\www\site' ) );
		self::assertTrue( OpenBasedir::allows( 'C:/www/site/data/openssl.cnf', 'C:\www\site' ) );
		self::assertFalse( OpenBasedir::allows( 'C:\php\extras\ssl\openssl.cnf', 'C:\www\site' ) );
	}

	public function test_several_entries_allow_a_path_inside_any_of_them(): void {
		$setting = '/var/www/site' . PATH_SEPARATOR . '/tmp' . PATH_SEPARATOR . '/usr/share/php';

		self::assertTrue( OpenBasedir::allows( '/tmp/upload/file', $setting ) );
		self::assertTrue( OpenBasedir::allows( '/usr/share/php/PEAR.php', $setting ) );
		self::assertFalse( OpenBasedir::allows( '/etc/ssl/openssl.cnf', $setting ) );
	}

	public function test_dot_dot_segments_cannot_escape_an_allowed_root(): void {
		self::assertFalse( OpenBasedir::allows( '/allowed/../etc/openssl.cnf', '/allowed' ) );
		self::assertFalse( OpenBasedir::allows( '/allowed/sub/../../etc/openssl.cnf', '/allowed' ) );
		self::assertFalse( OpenBasedir::allows( 'C:\\site\\..\\php\\extras\\openssl.cnf', 'C:\\site' ) );
		self::assertFalse( OpenBasedir::allows( '/allowed/../../etc/openssl.cnf', '/allowed' ), 'a climb past the root stays at the root' );
		self::assertTrue( OpenBasedir::allows( '/allowed/sub/../openssl.cnf', '/allowed' ) );
		self::assertTrue( OpenBasedir::allows( '/allowed/./sub//openssl.cnf', '/allowed' ) );
	}

	public function test_a_prefix_collision_is_still_refused(): void {
		self::assertFalse( OpenBasedir::allows( '/allowed-not/openssl.cnf', '/allowed' ) );
		self::assertFalse( OpenBasedir::allows( '/allowedx', '/allowed' . PATH_SEPARATOR . '/other' ) );
		self::assertFalse( OpenBasedir::allows( '/allowed/../allowed2/openssl.cnf', '/allowed' ) );
	}

	public function test_an_entry_is_also_allowed_under_its_real_location(): void {
		$base = sys_get_temp_dir() . '/sw-open-basedir-' . bin2hex( random_bytes( 4 ) );
		$real = $base . '/real';
		$link = $base . '/link';
		mkdir( $real, 0777, true );
		try {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! @symlink( $real, $link ) ) {
				self::markTestSkipped( 'This platform does not allow creating symlinks.' );
			}
			$resolved = str_replace( '\\', '/', (string) realpath( $link ) );

			self::assertTrue( OpenBasedir::allows( $resolved . '/data/openssl.cnf', $link ), 'a path under the real location of a symlinked entry' );
			self::assertFalse( OpenBasedir::allows( $resolved . '-other/openssl.cnf', $link ) );
			self::assertFalse( OpenBasedir::allows( $resolved . '/../elsewhere/openssl.cnf', $link ) );
		} finally {
			// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			if ( is_link( $link ) ) {
				@unlink( $link );
				@rmdir( $link );
			}
			@rmdir( $real );
			@rmdir( $base );
			// phpcs:enable
		}
	}

	public function test_the_setting_defaults_to_the_running_value(): void {
		self::assertSame( '' === (string) ini_get( 'open_basedir' ), OpenBasedir::allows( '/definitely/not/a/real/root/file' ) );
	}
}
