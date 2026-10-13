<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\DuplicateCopies;

/**
 * A second copy of Stonewright in the plugins folder. WP_PLUGIN_DIR is a constant, so each test runs in its own
 * process.
 *
 * @covers \Stonewright\WpMcp\Admin\DuplicateCopies
 */
final class DuplicateCopiesTest extends TestCase {

	private static function plugins_folder( string ...$folders ): string {
		$plugins = sys_get_temp_dir() . '/sw-copies-' . bin2hex( random_bytes( 5 ) );
		foreach ( $folders as $folder ) {
			mkdir( $plugins . '/' . $folder, 0777, true );
			file_put_contents( $plugins . '/' . $folder . '/stonewright.php', "<?php\n/**\n * Plugin Name: Stonewright\n */\n" );
			file_put_contents( $plugins . '/' . $folder . '/uninstall.php', "<?php\n" );
		}

		return $plugins;
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_before_the_other_copy_is_uninstalled_its_uninstall_file_is_removed(): void {
		define( 'WP_PLUGIN_DIR', self::plugins_folder( 'stonewright', 'stonewright-old' ) );
		define( 'STONEWRIGHT_FILE', WP_PLUGIN_DIR . '/stonewright/stonewright.php' );

		self::assertTrue( DuplicateCopies::is_other_copy( 'stonewright-old/stonewright.php' ) );
		DuplicateCopies::before_other_copy_uninstall( 'stonewright-old/stonewright.php' );

		self::assertFileDoesNotExist( WP_PLUGIN_DIR . '/stonewright-old/uninstall.php', 'WordPress finds no uninstall.php to include' );
		self::assertFileExists( WP_PLUGIN_DIR . '/stonewright-old/stonewright.php', 'the delete itself is left to WordPress' );
		self::assertFileExists( WP_PLUGIN_DIR . '/stonewright/uninstall.php', 'the running copy is untouched' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_running_copy_and_other_plugins_are_left_alone(): void {
		define( 'WP_PLUGIN_DIR', self::plugins_folder( 'stonewright', 'hello-dolly' ) );
		define( 'STONEWRIGHT_FILE', WP_PLUGIN_DIR . '/stonewright/stonewright.php' );
		file_put_contents( WP_PLUGIN_DIR . '/hello-dolly/stonewright.php', "<?php\n/**\n * Plugin Name: Hello Dolly\n */\n" );

		self::assertFalse( DuplicateCopies::is_other_copy( 'stonewright/stonewright.php' ) );
		self::assertFalse( DuplicateCopies::is_other_copy( 'hello-dolly/stonewright.php' ) );
		DuplicateCopies::before_other_copy_uninstall( 'stonewright/stonewright.php' );
		DuplicateCopies::before_other_copy_uninstall( 'hello-dolly/stonewright.php' );

		self::assertFileExists( WP_PLUGIN_DIR . '/stonewright/uninstall.php' );
		self::assertFileExists( WP_PLUGIN_DIR . '/hello-dolly/uninstall.php' );
	}
}
