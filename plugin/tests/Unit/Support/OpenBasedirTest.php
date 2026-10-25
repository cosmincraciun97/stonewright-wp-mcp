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
		self::assertTrue( OpenBasedir::allows( 'C:\laragon\www\site\data\openssl.cnf', 'C:\laragon\www\site' ) );
		self::assertTrue( OpenBasedir::allows( 'C:/laragon/www/site/data/openssl.cnf', 'C:\laragon\www\site' ) );
		self::assertFalse( OpenBasedir::allows( 'C:\php\extras\ssl\openssl.cnf', 'C:\laragon\www\site' ) );
	}

	public function test_several_entries_allow_a_path_inside_any_of_them(): void {
		$setting = '/var/www/site' . PATH_SEPARATOR . '/tmp' . PATH_SEPARATOR . '/usr/share/php';

		self::assertTrue( OpenBasedir::allows( '/tmp/upload/file', $setting ) );
		self::assertTrue( OpenBasedir::allows( '/usr/share/php/PEAR.php', $setting ) );
		self::assertFalse( OpenBasedir::allows( '/etc/ssl/openssl.cnf', $setting ) );
	}

	public function test_the_setting_defaults_to_the_running_value(): void {
		self::assertSame( '' === (string) ini_get( 'open_basedir' ), OpenBasedir::allows( '/definitely/not/a/real/root/file' ) );
	}
}
