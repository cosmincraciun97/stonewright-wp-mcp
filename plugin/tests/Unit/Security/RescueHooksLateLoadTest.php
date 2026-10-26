<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Security\ChangeJournal;
use Stonewright\WpMcp\Security\ChangeJournalFile;
use Stonewright\WpMcp\Security\RescueHooks;
use Stonewright\WpMcp\Support\LateLoad;

/**
 * A request that loaded the plugin can reach admin_init after its folder was deleted. Each test runs in
 * its own process so the journal classes are genuinely not loaded yet.
 *
 * @covers \Stonewright\WpMcp\Security\RescueHooks
 */
final class RescueHooksLateLoadTest extends TestCase {

	private string $empty_root = '';

	protected function setUp(): void {
		$this->empty_root = sys_get_temp_dir() . '/stonewright-late-hook-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) ) . '/';
		mkdir( $this->empty_root, 0700, true );
	}

	protected function tearDown(): void {
		LateLoad::set_root( null );
		@rmdir( $this->empty_root );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_sync_journal_returns_without_importing_when_the_journal_file_class_is_unavailable(): void {
		LateLoad::set_root( $this->empty_root );

		RescueHooks::sync_journal();

		self::assertFalse( class_exists( ChangeJournal::class, false ), 'The journal must not be autoloaded from a folder that is gone.' );
		self::assertFalse( class_exists( ChangeJournalFile::class, false ) );
		self::assertFalse( get_option( ChangeJournal::SEEN_OPTION, false ), 'Nothing was imported or remembered.' );
	}

	/**
	 * The journal files themselves are still there, but a class the import reaches later (the one that masks what it
	 * records) is gone: the import must not start.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_sync_journal_returns_when_a_class_the_import_reaches_later_is_gone(): void {
		mkdir( $this->empty_root . 'includes/Security', 0700, true );
		$present = [ 'ChangeJournal', 'ChangeJournalFile', 'AuditLog' ];
		foreach ( $present as $name ) {
			file_put_contents( $this->empty_root . 'includes/Security/' . $name . '.php', "<?php\n" );
		}
		LateLoad::set_root( $this->empty_root );

		try {
			RescueHooks::sync_journal();

			self::assertFalse( class_exists( ChangeJournal::class, false ), 'SensitiveContent.php is missing, so the import was not started.' );
		} finally {
			foreach ( $present as $name ) {
				unlink( $this->empty_root . 'includes/Security/' . $name . '.php' );
			}
			rmdir( $this->empty_root . 'includes/Security' );
			rmdir( $this->empty_root . 'includes' );
		}
	}

	/**
	 * The autoloader that failed in the field maps each class to a path and requires it without checking that the
	 * file exists. Put one in front: asking it for the journal would end the process, so the test passing means the
	 * hook never asked.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_classmap_autoloader_that_requires_blindly_is_never_asked_for_a_vanished_class(): void {
		LateLoad::set_root( $this->empty_root );
		$asked = [];
		spl_autoload_register(
			static function ( string $class ) use ( &$asked ): void {
				if ( str_starts_with( $class, 'Stonewright\\WpMcp\\Security\\ChangeJournal' ) ) {
					$asked[] = $class;
					require __DIR__ . '/this-file-was-deleted-with-the-plugin-folder.php';
				}
			},
			true,
			true
		);

		RescueHooks::sync_journal();

		self::assertSame( [], $asked );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_sync_journal_still_runs_when_the_plugin_files_are_present(): void {
		RescueHooks::sync_journal();

		self::assertTrue( class_exists( ChangeJournal::class, false ) );
		self::assertTrue( class_exists( ChangeJournalFile::class, false ), 'Loading the journal loads its file class with it.' );
	}
}
