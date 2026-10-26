<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Security\ChangeJournal;
use Stonewright\WpMcp\Security\ChangeJournalFile;

/**
 * A request that loaded the plugin reaches admin_init after a class file of the plugin is gone, as in a request that
 * outlives the deletion of the plugin folder. WordPress's autoloader must never be asked for that class: the hook
 * returns, nothing is raised and no error is left behind.
 *
 * This is the only test that runs against a real WordPress, with the plugin loaded by WordPress and the autoloader
 * WordPress puts in front of it (not the stubs of tests/bootstrap.php), so it is skipped everywhere else. It runs when
 * PHPUnit is started inside a WordPress that has Stonewright active, in a process where the change journal classes are
 * not loaded yet, for example in the wp-env test container:
 *
 *     npx wp-env run tests-cli --env-cwd=wp-content/plugins/stonewright vendor/bin/phpunit --group wp-env
 *
 * RescueHooksLateLoadTest covers the same guard with the plugin's own autoloader and a stand-in for the classmap
 * autoloader that requires its file without checking that it exists.
 *
 * @group wp-env
 * @covers \Stonewright\WpMcp\Support\LateLoad
 * @covers \Stonewright\WpMcp\Security\RescueHooks
 */
final class PartialDeletionTest extends TestCase {

	private string $file = '';

	private string $away = '';

	protected function setUp(): void {
		if ( ! self::real_wordpress_with_stonewright() ) {
			self::markTestSkipped( 'Needs a real WordPress with Stonewright active (the wp-env test container); the stubs of tests/bootstrap.php cannot show what the real autoloader does.' );
		}
		if ( class_exists( ChangeJournal::class, false ) || class_exists( ChangeJournalFile::class, false ) ) {
			self::markTestSkipped( 'The change journal classes are loaded already; run this test in a fresh process.' );
		}
		$this->file = rtrim( (string) constant( 'STONEWRIGHT_DIR' ), '/\\' ) . '/includes/Security/ChangeJournalFile.php';
		$this->away = $this->file . '.away';
		self::assertFileExists( $this->file );
		self::assertTrue( rename( $this->file, $this->away ), 'The class file can be moved away for the test.' );
	}

	protected function tearDown(): void {
		// Always put the file back, or the plugin stays broken.
		if ( '' !== $this->away && is_file( $this->away ) ) {
			rename( $this->away, $this->file );
		}
	}

	public function test_admin_init_and_rest_api_init_survive_a_missing_journal_file_class(): void {
		error_clear_last();

		do_action( 'admin_init' );
		do_action( 'rest_api_init' );

		self::assertNull( error_get_last(), 'No warning, notice or error was raised.' );
		self::assertFalse( class_exists( ChangeJournal::class, false ), 'The journal was not autoloaded from a folder that is incomplete.' );
	}

	/** WordPress itself defines its functions, and the plugin's own file was loaded by it. */
	private static function real_wordpress_with_stonewright(): bool {
		if ( ! defined( 'ABSPATH' ) || ! defined( 'STONEWRIGHT_DIR' ) || ! function_exists( 'wp_get_current_user' ) || ! function_exists( 'do_action' ) ) {
			return false;
		}
		$file = (string) ( new \ReflectionFunction( 'do_action' ) )->getFileName();
		return str_replace( '\\', '/', $file ) === str_replace( '\\', '/', rtrim( (string) ABSPATH, '/\\' ) ) . '/wp-includes/plugin.php'
			&& did_action( 'plugins_loaded' ) > 0
			&& class_exists( \Stonewright\WpMcp\Core\PluginRegistration::class, false );
	}
}
