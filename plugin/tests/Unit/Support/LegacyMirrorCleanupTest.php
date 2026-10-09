<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Core\PluginRegistration;
use Stonewright\WpMcp\Support\LegacyMirrorCleanup;

/**
 * Earlier versions published page exports under uploads/stonewright-mirror.
 * The cleanup guards that directory first, then removes only files that carry
 * the export format, and logs what it did.
 *
 * @covers \Stonewright\WpMcp\Support\LegacyMirrorCleanup
 */
final class LegacyMirrorCleanupTest extends TestCase {

	private string $uploads;
	private string $dir;
	private string $log;
	private string|false $previous_log;

	protected function setUp(): void {
		$this->uploads = sys_get_temp_dir() . '/sw-legacy-' . bin2hex( random_bytes( 6 ) );
		$this->dir     = $this->uploads . '/stonewright-mirror';
		mkdir( $this->uploads, 0777, true );
		$GLOBALS['stonewright_test_upload_dir'] = [ 'basedir' => $this->uploads, 'baseurl' => 'https://example.test/wp-content/uploads', 'error' => false ];
		$this->log          = $this->uploads . '/php.log';
		$this->previous_log = ini_get( 'error_log' );
		ini_set( 'error_log', $this->log );
	}

	protected function tearDown(): void {
		ini_set( 'error_log', false === $this->previous_log ? '' : $this->previous_log );
		unset( $GLOBALS['stonewright_test_upload_dir'], $GLOBALS['stonewright_test_options']['stonewright_version'] );
		$this->remove_tree( $this->uploads );
	}

	private function export_file( string $name, int $post_id = 1 ): void {
		file_put_contents( $this->dir . '/' . $name, (string) wp_json_encode( [ 'post_id' => $post_id, 'slug' => 'x', 'title' => 'T', 'exported' => '2026-01-01T00:00:00+00:00', 'elementor' => [] ] ) . "\n" );
	}

	private function remove_tree( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) ?: [] as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$path = $dir . '/' . $name;
			is_dir( $path ) && ! is_link( $path ) ? $this->remove_tree( $path ) : unlink( $path );
		}
		rmdir( $dir );
	}

	public function test_absent_directory_is_left_alone(): void {
		$result = LegacyMirrorCleanup::run();

		self::assertSame( 'absent', $result['status'] );
		self::assertDirectoryDoesNotExist( $this->dir );
	}

	public function test_directory_is_guarded_and_guards_deny_access(): void {
		mkdir( $this->dir, 0777, true );
		$this->export_file( 'home.json' );

		$result = LegacyMirrorCleanup::run();

		self::assertSame( 'cleaned', $result['status'] );
		self::assertTrue( $result['guarded'] );
		self::assertStringContainsString( '<?php', (string) file_get_contents( $this->dir . '/index.php' ) );
		$htaccess = (string) file_get_contents( $this->dir . '/.htaccess' );
		self::assertStringContainsString( 'Require all denied', $htaccess );
		self::assertStringContainsString( 'Deny from all', $htaccess );
		$web_config = (string) file_get_contents( $this->dir . '/web.config' );
		self::assertStringContainsString( '<deny users="*" />', $web_config );
		self::assertNotFalse( simplexml_load_string( $web_config ) );
	}

	public function test_only_files_in_the_export_format_are_removed(): void {
		mkdir( $this->dir, 0777, true );
		$this->export_file( 'home.json', 5 );
		$this->export_file( 'about-us.json', 6 );
		file_put_contents( $this->dir . '/other.json', '{"unrelated":true}' );
		file_put_contents( $this->dir . '/broken.json', '{not json' );
		file_put_contents( $this->dir . '/notes.txt', 'keep me' );
		mkdir( $this->dir . '/nested', 0777, true );
		file_put_contents( $this->dir . '/nested/inner.json', (string) wp_json_encode( [ 'post_id' => 1, 'elementor' => [] ] ) );

		$result = LegacyMirrorCleanup::run();

		self::assertSame( 2, $result['removed'] );
		self::assertSame( 4, $result['kept'] );
		self::assertFileDoesNotExist( $this->dir . '/home.json' );
		self::assertFileDoesNotExist( $this->dir . '/about-us.json' );
		self::assertFileExists( $this->dir . '/other.json' );
		self::assertFileExists( $this->dir . '/broken.json' );
		self::assertFileExists( $this->dir . '/notes.txt' );
		self::assertFileExists( $this->dir . '/nested/inner.json' );
	}

	public function test_run_is_idempotent_and_keeps_existing_guard_files(): void {
		mkdir( $this->dir, 0777, true );
		file_put_contents( $this->dir . '/.htaccess', "# custom\nRequire all denied\n" );
		$this->export_file( 'home.json' );

		LegacyMirrorCleanup::run();
		$second = LegacyMirrorCleanup::run();

		self::assertSame( 0, $second['removed'] );
		self::assertSame( "# custom\nRequire all denied\n", file_get_contents( $this->dir . '/.htaccess' ) );
	}

	public function test_symbolic_links_are_never_followed_or_removed(): void {
		mkdir( $this->dir, 0777, true );
		$outside = $this->uploads . '/outside.json';
		file_put_contents( $outside, (string) wp_json_encode( [ 'post_id' => 1, 'elementor' => [] ] ) );
		if ( ! @symlink( $outside, $this->dir . '/link.json' ) ) {
			self::markTestSkipped( 'Symbolic links are unavailable on this host.' );
		}

		$result = LegacyMirrorCleanup::run();

		self::assertSame( 0, $result['removed'] );
		self::assertFileExists( $outside );
		self::assertTrue( is_link( $this->dir . '/link.json' ) );
	}

	public function test_plugin_upgrade_runs_the_cleanup(): void {
		mkdir( $this->dir, 0777, true );
		$this->export_file( 'home.json', 5 );
		$GLOBALS['stonewright_test_options']['stonewright_version'] = '0.0.0-before-upgrade';

		PluginRegistration::maybe_upgrade();

		self::assertFileDoesNotExist( $this->dir . '/home.json' );
		self::assertFileExists( $this->dir . '/.htaccess' );
		self::assertSame( STONEWRIGHT_VERSION, $GLOBALS['stonewright_test_options']['stonewright_version'] );
	}

	public function test_the_outcome_is_logged_without_page_content(): void {
		mkdir( $this->dir, 0777, true );
		$this->export_file( 'home.json', 5 );

		LegacyMirrorCleanup::run();

		$log = (string) file_get_contents( $this->log );
		self::assertStringContainsString( 'stonewright.mirror_export.legacy_cleanup', $log );
		self::assertStringContainsString( '"removed":1', $log );
		self::assertStringNotContainsString( 'elementor', str_replace( 'legacy_cleanup', '', $log ) );
	}
}
