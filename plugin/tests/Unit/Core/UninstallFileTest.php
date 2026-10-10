<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Tests\Unit\Core\Fixtures\UninstallSite;

/**
 * The real uninstall.php, included the way WordPress includes it when the plugin is
 * deleted. Every test runs in its own process, because WP_UNINSTALL_PLUGIN and
 * STONEWRIGHT_REMOVE_ALL_DATA are constants.
 */
final class UninstallFileTest extends TestCase {

	private static function file(): string {
		return dirname( __DIR__, 3 ) . '/uninstall.php';
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_deleting_the_plugin_keeps_everything_by_default(): void {
		define( 'WP_UNINSTALL_PLUGIN', 'stonewright/stonewright.php' );
		$wpdb = UninstallSite::reset();
		$GLOBALS['wpdb'] = $wpdb;
		$tables = $wpdb->tables;
		$options = $GLOBALS['stonewright_test_options'];
		$hooks = $GLOBALS['stonewright_test_scheduled_hooks'];

		include self::file();

		self::assertSame( [], $wpdb->statements, 'no statement was run' );
		self::assertSame( $tables, $wpdb->tables );
		self::assertSame( $options, $GLOBALS['stonewright_test_options'] );
		self::assertSame( $hooks, $GLOBALS['stonewright_test_scheduled_hooks'] );
		self::assertSame( [], UninstallSite::$log, 'the caches were left alone as well' );
	}

	private static function helper_file(): string {
		return WP_CONTENT_DIR . '/mu-plugins/stonewright-rescue.php';
	}

	private static function install_helper( string $contents ): void {
		if ( ! is_dir( dirname( self::helper_file() ) ) ) {
			mkdir( dirname( self::helper_file() ), 0777, true );
		}
		file_put_contents( self::helper_file(), $contents );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_deleting_the_plugin_removes_the_rescue_helper_even_when_the_data_is_kept(): void {
		define( 'WP_UNINSTALL_PLUGIN', 'stonewright/stonewright.php' );
		$wpdb = UninstallSite::reset();
		$GLOBALS['wpdb'] = $wpdb;
		$options = $GLOBALS['stonewright_test_options'];
		self::install_helper( "<?php\n/**\n * Plugin Name: Stonewright Rescue\n * @stonewright-rescue-mu\n */\n" );

		include self::file();

		self::assertFileDoesNotExist( self::helper_file(), 'the helper is code, not data: it always goes' );
		self::assertSame( [], $wpdb->statements, 'no data was touched' );
		self::assertSame( $options, $GLOBALS['stonewright_test_options'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_deleting_the_plugin_removes_the_rescue_helper_when_the_data_goes_too(): void {
		define( 'WP_UNINSTALL_PLUGIN', 'stonewright/stonewright.php' );
		define( 'STONEWRIGHT_REMOVE_ALL_DATA', true );
		$wpdb = UninstallSite::reset();
		$GLOBALS['wpdb'] = $wpdb;
		self::install_helper( "<?php\n// @stonewright-rescue-mu\n" );

		include self::file();

		self::assertFileDoesNotExist( self::helper_file() );
		self::assertSame( UninstallSite::FOREIGN_OPTIONS, array_keys( $GLOBALS['stonewright_test_options'] ), 'and the data went as before' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_must_use_file_of_the_same_name_that_is_not_the_helper_is_left_alone(): void {
		define( 'WP_UNINSTALL_PLUGIN', 'stonewright/stonewright.php' );
		define( 'STONEWRIGHT_REMOVE_ALL_DATA', true );
		$GLOBALS['wpdb'] = UninstallSite::reset();
		self::install_helper( "<?php\n// another plugin's file\n" );

		include self::file();

		self::assertFileExists( self::helper_file() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_deleting_the_plugin_without_a_helper_installed_is_fine(): void {
		define( 'WP_UNINSTALL_PLUGIN', 'stonewright/stonewright.php' );
		$wpdb = UninstallSite::reset();
		$GLOBALS['wpdb'] = $wpdb;

		include self::file();

		self::assertSame( [], $wpdb->statements );
		self::assertFileDoesNotExist( self::helper_file() );
	}

	/**
	 * A site folder with the helper in mu-plugins and the journal files in uploads/stonewright-state/.
	 *
	 * @return array{0:string,1:string,2:string} The site folder, its content folder and its uploads folder.
	 */
	private static function site_folder(): array {
		$site    = sys_get_temp_dir() . '/sw-uninstall-file-' . bin2hex( random_bytes( 5 ) );
		$content = $site . '/wp-content';
		$uploads = $content . '/uploads';
		mkdir( $content . '/mu-plugins', 0777, true );
		mkdir( $uploads . '/stonewright-state', 0777, true );
		file_put_contents( $content . '/mu-plugins/stonewright-rescue.php', "<?php\n// @stonewright-rescue-mu\n" );
		$journal = $uploads . '/stonewright-state/journal-' . str_repeat( 'b2', 16 ) . '.json';
		file_put_contents( $journal, '{"version":1,"updated_at":0,"entries":[]}' );
		file_put_contents( $journal . '.lock', '' );
		file_put_contents( $uploads . '/stonewright-state/.htaccess', 'Require all denied' );
		mkdir( $uploads . '/stonewright-state/blobs', 0777, true );
		file_put_contents( $uploads . '/stonewright-state/blobs/' . hash( 'sha256', 'synthetic image' ) . '.gz', (string) gzencode( 'synthetic image' ) );
		file_put_contents( $uploads . '/stonewright-state/blobs/index.php', "<?php\n// Silence is golden.\n" );
		return [ $site, $content, $uploads ];
	}

	private static function remove_folder( string $path ): void {
		if ( ! is_dir( $path ) ) {
			return;
		}
		foreach ( scandir( $path ) ?: [] as $item ) {
			if ( '.' !== $item && '..' !== $item ) {
				is_dir( $path . '/' . $item ) ? self::remove_folder( $path . '/' . $item ) : @unlink( $path . '/' . $item );
			}
		}
		@rmdir( $path );
	}

	/**
	 * Includes uninstall.php in a new PHP process that has no autoloader, the way WordPress includes it
	 * when the plugin is deleted: only what the file loads itself is there.
	 *
	 * @return array{exit:int,output:string,loaded:array<string,mixed>}
	 */
	private static function include_on_its_own( string $mode, string $content, string $uploads ): array {
		$command = [ PHP_BINARY, __DIR__ . '/Fixtures/uninstall-runner.php', dirname( __DIR__, 3 ), $content, $uploads, $mode ];
		$process = proc_open( $command, [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
		self::assertIsResource( $process );
		$output = (string) stream_get_contents( $pipes[1] ) . (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$exit   = proc_close( $process );
		$lines  = array_values( array_filter( array_map( 'trim', explode( "\n", $output ) ) ) );
		$loaded = json_decode( (string) end( $lines ), true );
		return [ 'exit' => $exit, 'output' => $output, 'loaded' => is_array( $loaded ) ? $loaded : [] ];
	}

	public function test_included_on_its_own_with_the_data_kept_it_removes_the_helper_and_leaves_the_journal(): void {
		[ $site, $content, $uploads ] = self::site_folder();

		try {
			$run = self::include_on_its_own( 'keep', $content, $uploads );

			self::assertSame( 0, $run['exit'], $run['output'] );
			self::assertFileDoesNotExist( $content . '/mu-plugins/stonewright-rescue.php' );
			self::assertCount( 2, glob( $uploads . '/stonewright-state/journal-*' ) ?: [], 'the journal files are data and stay' );
			self::assertSame( 0, $run['loaded']['autoloaders'] ?? -1, 'no autoloader was there to help' );
			self::assertTrue( $run['loaded']['installer'] ?? false );
			self::assertFalse( $run['loaded']['journal'] ?? true, 'the default path loads no data removal code' );
			self::assertFalse( $run['loaded']['blob_store'] ?? true, 'nor the blob store' );
			self::assertCount( 1, glob( $uploads . '/stonewright-state/blobs/*.gz' ) ?: [], 'the change history blobs are data and stay' );
		} finally {
			self::remove_folder( $site );
		}
	}

	public function test_included_on_its_own_with_the_data_removed_it_removes_the_helper_and_the_journal_files(): void {
		[ $site, $content, $uploads ] = self::site_folder();

		try {
			$run = self::include_on_its_own( 'remove', $content, $uploads );

			self::assertSame( 0, $run['exit'], $run['output'] );
			self::assertFileDoesNotExist( $content . '/mu-plugins/stonewright-rescue.php' );
			self::assertDirectoryDoesNotExist( $uploads . '/stonewright-state' );
			self::assertDirectoryExists( $uploads );
			self::assertSame( 0, $run['loaded']['autoloaders'] ?? -1, 'no autoloader was there to help' );
			self::assertTrue( $run['loaded']['journal'] ?? false );
			self::assertTrue( $run['loaded']['journal_file'] ?? false );
			self::assertTrue( $run['loaded']['blob_store'] ?? false, 'the blob store is loaded by uninstall.php, which has no autoloader' );
		} finally {
			self::remove_folder( $site );
		}
	}

	/** @return array<string, array{0: mixed}> */
	public static function values_that_are_not_true(): array {
		return [
			'false'             => [ false ],
			'the number 1'      => [ 1 ],
			'the string "true"' => [ 'true' ],
			'the string "yes"'  => [ 'yes' ],
		];
	}

	/**
	 * @dataProvider values_that_are_not_true
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_only_the_boolean_true_turns_removal_on( mixed $value ): void {
		define( 'WP_UNINSTALL_PLUGIN', 'stonewright/stonewright.php' );
		define( 'STONEWRIGHT_REMOVE_ALL_DATA', $value );
		$wpdb = UninstallSite::reset();
		$GLOBALS['wpdb'] = $wpdb;
		$tables = $wpdb->tables;
		$options = $GLOBALS['stonewright_test_options'];

		include self::file();

		self::assertSame( [], $wpdb->statements );
		self::assertSame( $tables, $wpdb->tables );
		self::assertSame( $options, $GLOBALS['stonewright_test_options'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_constant_removes_every_table_option_transient_and_event(): void {
		define( 'WP_UNINSTALL_PLUGIN', 'stonewright/stonewright.php' );
		define( 'STONEWRIGHT_REMOVE_ALL_DATA', true );
		$wpdb = UninstallSite::reset();
		$GLOBALS['wpdb'] = $wpdb;

		include self::file();

		$dropped = [];
		foreach ( $wpdb->statements as $statement ) {
			if ( preg_match( '/^DROP TABLE IF EXISTS `wptests_(.+)`$/', $statement, $match ) ) {
				$dropped[] = $match[1];
			}
		}
		$expected = UninstallSite::PLUGIN_TABLES;
		sort( $dropped );
		sort( $expected );
		self::assertSame( $expected, $dropped, 'exactly the plugin tables were dropped' );
		self::assertSame( [ 'options', 'postmeta', 'posts', 'stonewright_not_in_the_list', 'users' ], UninstallSite::remaining_tables( 1 ) );
		self::assertSame( UninstallSite::FOREIGN_OPTIONS, array_keys( $GLOBALS['stonewright_test_options'] ), 'every plugin option and transient is gone, nothing else' );
		self::assertFalse( get_option( 'stonewright_oauth_private_key' ) );
		self::assertFalse( get_option( 'stonewright_oauth_encryption_key' ) );
		self::assertSame( UninstallSite::FOREIGN_HOOKS, array_keys( $GLOBALS['stonewright_test_scheduled_hooks'] ), 'the plugin events are unscheduled, other events stay' );
		self::assertSame( [ 'flush' ], UninstallSite::$log );
	}
}
