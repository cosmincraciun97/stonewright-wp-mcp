<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\RescueRuntime;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Core\RescueInstaller;
use Stonewright\WpMcp\Tests\Unit\RescueRuntime\Support\MuRuntime;

/**
 * Install, verify, repair and remove: the life of the helper file in the must-use directory.
 *
 * @covers \Stonewright\WpMcp\Core\RescueInstaller
 */
final class RescueInstallerTest extends TestCase {

	/** @var array<string, mixed> */
	private array $saved_options = [];

	private string $scratch = '';

	protected function setUp(): void {
		MuRuntime::load();
		$this->saved_options = $GLOBALS['stonewright_test_options'] ?? [];
		$this->scratch       = sys_get_temp_dir() . '/sw-installer-' . bin2hex( random_bytes( 5 ) );
		mkdir( $this->scratch, 0777, true );
		self::clean_mu_dir();
		RescueInstaller::override_source( null );
		$GLOBALS['stonewright_test_wpdb_inserts'] = [];
		$GLOBALS['stonewright_test_user_caps']    = [ 'manage_options' => true ];
	}

	protected function tearDown(): void {
		RescueInstaller::override_source( null );
		self::clean_mu_dir();
		MuRuntime::remove_tree( $this->scratch );
		$GLOBALS['stonewright_test_options']   = $this->saved_options;
		$GLOBALS['stonewright_test_user_caps'] = [];
	}

	private static function clean_mu_dir(): void {
		$dir = RescueInstaller::target_dir();
		if ( is_file( $dir ) ) {
			unlink( $dir );
		}
		MuRuntime::remove_tree( $dir );
	}

	private static function bundled_hash(): string {
		return (string) hash_file( 'sha256', dirname( __DIR__, 3 ) . '/mu/stonewright-rescue.php' );
	}

	/** @return list<string> */
	private static function mu_dir_listing(): array {
		$names = array_map( 'basename', glob( RescueInstaller::target_dir() . '/*' ) ?: [] );
		sort( $names );
		return $names;
	}

	private function bundle( string $code ): string {
		$path = $this->scratch . '/bundle.php';
		file_put_contents( $path, $code );
		RescueInstaller::override_source( $path );
		return $path;
	}

	private static function real_source(): string {
		return (string) file_get_contents( dirname( __DIR__, 3 ) . '/mu/stonewright-rescue.php' );
	}

	public function test_the_helper_is_missing_until_it_is_installed(): void {
		$status = RescueInstaller::status();

		self::assertSame( 'missing', $status['state'] );
		self::assertNull( $status['installed_sha256'] );
		self::assertSame( self::bundled_hash(), $status['bundled_sha256'] );
		self::assertTrue( $status['writable'] );
	}

	public function test_the_summary_says_whether_the_helper_is_installed_and_whether_safe_mode_can_start(): void {
		self::assertSame( [ 'state' => 'missing', 'safe_mode' => false ], RescueInstaller::summary() );

		RescueInstaller::install();
		self::assertSame( [ 'state' => 'installed', 'safe_mode' => true ], RescueInstaller::summary() );

		file_put_contents( RescueInstaller::target_path(), "<?php\n// changed\n" );
		self::assertSame( 'modified', RescueInstaller::summary()['state'] );
		self::assertFalse( RescueInstaller::summary()['safe_mode'] );
	}

	public function test_the_summary_carries_no_path_or_hash(): void {
		RescueInstaller::install();

		self::assertSame( [ 'state', 'safe_mode' ], array_keys( RescueInstaller::summary() ) );
	}

	public function test_install_copies_the_bundled_file_byte_for_byte(): void {
		$status = RescueInstaller::install();

		self::assertSame( 'ok', $status['state'] );
		self::assertSame( self::bundled_hash(), hash_file( 'sha256', RescueInstaller::target_path() ) );
		self::assertSame( [ 'stonewright-rescue.php' ], self::mu_dir_listing(), 'no temporary file is left behind' );
		self::assertSame( 'stonewright/stonewright.php', $GLOBALS['stonewright_test_options']['stonewright_rescue_plugin'] );
	}

	public function test_install_creates_the_must_use_directory(): void {
		self::assertDirectoryDoesNotExist( RescueInstaller::target_dir() );

		RescueInstaller::install();

		self::assertDirectoryExists( RescueInstaller::target_dir() );
	}

	public function test_install_leaves_an_installed_copy_alone(): void {
		RescueInstaller::install();
		$before = filemtime( RescueInstaller::target_path() );
		clearstatcache();
		sleep( 1 );

		RescueInstaller::install();

		clearstatcache();
		self::assertSame( $before, filemtime( RescueInstaller::target_path() ) );
	}

	public function test_a_modified_copy_is_reported_and_replaced_on_admin_load(): void {
		RescueInstaller::install();
		file_put_contents( RescueInstaller::target_path(), "<?php\n// changed by someone\n" );

		self::assertSame( 'modified', RescueInstaller::status()['state'] );
		$result = RescueInstaller::verify_and_repair();

		self::assertSame( 'ok', $result['state'] );
		self::assertSame( self::bundled_hash(), hash_file( 'sha256', RescueInstaller::target_path() ) );
	}

	public function test_a_repair_of_a_modified_copy_is_audited_and_names_no_content(): void {
		RescueInstaller::install();
		file_put_contents( RescueInstaller::target_path(), "<?php\n// changed by someone\n" );

		RescueInstaller::verify_and_repair();

		$rows = array_values( array_filter( $GLOBALS['stonewright_test_wpdb_inserts'], static fn ( array $row ): bool => 'stonewright/rescue-helper-repair' === ( $row['data']['ability_name'] ?? '' ) ) );
		self::assertCount( 1, $rows );
		self::assertStringNotContainsString( 'changed by someone', json_encode( $rows ) ?: '' );
	}

	public function test_a_missing_copy_is_installed_on_admin_load_without_an_audit_row(): void {
		self::assertSame( 'ok', RescueInstaller::verify_and_repair()['state'] );
		self::assertFileExists( RescueInstaller::target_path() );
		self::assertSame( [], $GLOBALS['stonewright_test_wpdb_inserts'] );
	}

	public function test_an_intact_copy_costs_no_write_on_admin_load(): void {
		RescueInstaller::install();
		$before = filemtime( RescueInstaller::target_path() );
		clearstatcache();
		sleep( 1 );

		self::assertSame( 'ok', RescueInstaller::verify_and_repair()['state'] );

		clearstatcache();
		self::assertSame( $before, filemtime( RescueInstaller::target_path() ) );
	}

	public function test_an_unwritable_directory_is_reported_and_nothing_is_thrown(): void {
		// A file where the directory should be: it can be neither created nor written to.
		file_put_contents( RescueInstaller::target_dir(), 'not a directory' );

		$status = RescueInstaller::install();

		self::assertSame( 'unwritable', $status['state'] );
		self::assertFalse( $status['writable'] );
		self::assertFileDoesNotExist( RescueInstaller::target_path() );
	}

	public function test_the_notice_says_so_when_the_directory_is_not_writable(): void {
		file_put_contents( RescueInstaller::target_dir(), 'not a directory' );
		RescueInstaller::install();

		ob_start();
		RescueInstaller::admin_notice();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'notice-warning', $html );
		self::assertStringContainsString( 'could not install its rescue helper', $html );
		self::assertStringContainsString( RescueInstaller::target_dir(), $html );
		self::assertStringContainsString( 'keeps working', $html );
	}

	public function test_the_notice_is_for_administrators_only(): void {
		file_put_contents( RescueInstaller::target_dir(), 'not a directory' );
		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => false ];

		ob_start();
		RescueInstaller::admin_notice();

		self::assertSame( '', ob_get_clean() );
	}

	public function test_no_notice_while_the_helper_is_installed(): void {
		RescueInstaller::install();

		ob_start();
		RescueInstaller::admin_notice();

		self::assertSame( '', ob_get_clean() );
	}

	public function test_a_failed_install_is_not_tried_again_on_every_admin_load(): void {
		file_put_contents( RescueInstaller::target_dir(), 'not a directory' );
		RescueInstaller::install();
		unlink( RescueInstaller::target_dir() );
		mkdir( RescueInstaller::target_dir(), 0777, true );
		$GLOBALS['stonewright_test_options']['stonewright_rescue_mu_state'] = [ 'failed_at' => time() - 10, 'reason' => 'write_failed' ];

		RescueInstaller::verify_and_repair();
		self::assertFileDoesNotExist( RescueInstaller::target_path(), 'a try that failed seconds ago is not repeated' );

		$GLOBALS['stonewright_test_options']['stonewright_rescue_mu_state'] = [ 'failed_at' => time() - RescueInstaller::RETRY_SECONDS - 5, 'reason' => 'write_failed' ];
		RescueInstaller::verify_and_repair();
		self::assertFileExists( RescueInstaller::target_path() );
		self::assertSame( [], $GLOBALS['stonewright_test_options']['stonewright_rescue_mu_state'], 'the failure is forgotten once it works' );
	}

	public function test_activation_installs_whatever_an_earlier_failure_said(): void {
		$GLOBALS['stonewright_test_options']['stonewright_rescue_mu_state'] = [ 'failed_at' => time() - 1, 'reason' => 'write_failed' ];

		RescueInstaller::on_activate();

		self::assertFileExists( RescueInstaller::target_path() );
	}

	public function test_a_bundled_file_that_does_not_parse_is_never_installed(): void {
		$this->bundle( str_replace( 'final class Stonewright_Rescue {', 'final class Stonewright_Rescue { public function broken( {', self::real_source() ) );

		self::assertSame( 'source_invalid', RescueInstaller::status()['state'] );
		self::assertSame( 'source_invalid', RescueInstaller::install()['state'] );
		self::assertDirectoryDoesNotExist( RescueInstaller::target_dir() );
	}

	public function test_a_bundled_file_that_is_cut_short_is_never_installed(): void {
		$source = self::real_source();
		$this->bundle( substr( $source, 0, (int) ( strlen( $source ) * 0.6 ) ) );

		self::assertSame( 'source_invalid', RescueInstaller::install()['state'] );
		self::assertDirectoryDoesNotExist( RescueInstaller::target_dir() );
	}

	public function test_a_file_that_is_not_the_helper_is_never_installed(): void {
		$this->bundle( "<?php\n" . str_repeat( "// padding\n", 200 ) . "echo 'something else';\n" . '/* ' . RescueInstaller::END_MARKER . "\n" );

		self::assertSame( 'source_invalid', RescueInstaller::install()['state'] );
	}

	public function test_a_missing_bundled_file_is_reported(): void {
		RescueInstaller::override_source( $this->scratch . '/nowhere.php' );

		self::assertSame( 'source_invalid', RescueInstaller::status()['state'] );
	}

	public function test_a_broken_bundle_does_not_replace_a_working_copy(): void {
		RescueInstaller::install();
		$installed = self::bundled_hash();
		$this->bundle( "<?php\n// @stonewright-rescue-mu\n" . str_repeat( "// x\n", 400 ) . "function broken( {\n/* STONEWRIGHT_RESCUE_MU_END */\n" );

		RescueInstaller::verify_and_repair();

		self::assertSame( $installed, hash_file( 'sha256', RescueInstaller::target_path() ), 'the copy that works stays' );
	}

	public function test_a_copy_that_differs_from_the_bundle_is_never_moved_into_place(): void {
		$path = $this->bundle( self::real_source() );
		RescueInstaller::status();
		// The bundled file changes after its hash was taken, as a copy that went wrong would.
		file_put_contents( $path, self::real_source() . "
// changed while it was being installed
" );

		$status = RescueInstaller::install();

		self::assertNotSame( 'ok', $status['state'] );
		self::assertFileDoesNotExist( RescueInstaller::target_path(), 'WordPress loads that path on the next request' );
		self::assertSame( [], self::mu_dir_listing(), 'and no temporary file is left' );
	}

	public function test_the_bundled_file_is_installed_as_it_is_even_with_a_new_plugin_version(): void {
		RescueInstaller::install();
		$GLOBALS['stonewright_test_options']['stonewright_rescue_mu_version'] = '0.0.1-older';
		file_put_contents( RescueInstaller::target_path(), self::real_source() . "\n// older copy\n" );

		RescueInstaller::maybe_upgrade();

		self::assertSame( self::bundled_hash(), hash_file( 'sha256', RescueInstaller::target_path() ) );
		self::assertSame( STONEWRIGHT_VERSION, $GLOBALS['stonewright_test_options']['stonewright_rescue_mu_version'] );
	}

	public function test_init_installs_when_the_version_or_the_plugin_name_changed_and_not_otherwise(): void {
		RescueInstaller::maybe_upgrade();
		self::assertFileExists( RescueInstaller::target_path() );
		self::assertSame( STONEWRIGHT_VERSION, $GLOBALS['stonewright_test_options']['stonewright_rescue_mu_version'] );

		unlink( RescueInstaller::target_path() );
		RescueInstaller::maybe_upgrade();
		self::assertFileDoesNotExist( RescueInstaller::target_path(), 'same version and plugin name: left to the admin page check' );

		$GLOBALS['stonewright_test_options']['stonewright_rescue_plugin'] = 'old-name/stonewright.php';
		RescueInstaller::maybe_upgrade();
		self::assertFileExists( RescueInstaller::target_path() );
		self::assertSame( 'stonewright/stonewright.php', $GLOBALS['stonewright_test_options']['stonewright_rescue_plugin'] );
	}

	public function test_the_state_directory_of_the_journal_is_recorded_for_the_helper(): void {
		RescueInstaller::verify_and_repair();

		$recorded = (string) ( $GLOBALS['stonewright_test_options']['stonewright_rescue_state_dir'] ?? '' );
		self::assertNotSame( '', $recorded );
		self::assertSame( \Stonewright\WpMcp\Security\ChangeJournal::state_directory(), $recorded );
	}

	public function test_the_helper_is_ready_only_when_installed_and_loaded_at_the_needed_version(): void {
		self::assertFalse( RescueInstaller::is_ready(), 'not installed' );

		RescueInstaller::install();
		self::assertTrue( RescueInstaller::is_ready() );

		file_put_contents( RescueInstaller::target_path(), "<?php\n" );
		self::assertFalse( RescueInstaller::is_ready(), 'modified' );
	}

	public function test_remove_deletes_the_helper_when_it_carries_the_marker(): void {
		RescueInstaller::install();

		self::assertTrue( RescueInstaller::remove() );
		self::assertFileDoesNotExist( RescueInstaller::target_path() );
	}

	public function test_remove_deletes_a_modified_helper_too(): void {
		RescueInstaller::install();
		file_put_contents( RescueInstaller::target_path(), "<?php\n// @stonewright-rescue-mu\n// an older or edited copy\n" );

		self::assertTrue( RescueInstaller::remove() );
		self::assertFileDoesNotExist( RescueInstaller::target_path() );
	}

	public function test_remove_leaves_a_file_of_the_same_name_that_is_not_the_helper(): void {
		mkdir( RescueInstaller::target_dir(), 0777, true );
		file_put_contents( RescueInstaller::target_path(), "<?php\n// somebody else's must-use plugin\n" );

		self::assertFalse( RescueInstaller::remove() );
		self::assertFileExists( RescueInstaller::target_path() );
	}

	public function test_remove_with_nothing_installed_is_fine(): void {
		self::assertTrue( RescueInstaller::remove() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_nothing_is_written_when_the_site_forbids_file_changes(): void {
		define( 'DISALLOW_FILE_MODS', true );
		RescueInstaller::override_source( null );

		self::assertSame( 'file_mods_disabled', RescueInstaller::status()['state'] );
		self::assertSame( 'file_mods_disabled', RescueInstaller::install()['state'] );
		self::assertDirectoryDoesNotExist( RescueInstaller::target_dir() );
		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true ];
		ob_start();
		RescueInstaller::admin_notice();
		$html = (string) ob_get_clean();
		self::assertStringContainsString( 'file changes are turned off', $html );
	}
}
