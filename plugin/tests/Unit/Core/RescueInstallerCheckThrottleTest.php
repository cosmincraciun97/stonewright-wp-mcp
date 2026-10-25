<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Core\RescueInstaller;
use Stonewright\WpMcp\Tests\Unit\RescueRuntime\Support\MuRuntime;

/**
 * The helper's integrity check on admin page loads: one stat per request, and a full read and
 * hash only once per twelve hours, when the file's size or modification time moved, when no
 * check is remembered, or when an update asks for one.
 *
 * @covers \Stonewright\WpMcp\Core\RescueInstaller
 */
final class RescueInstallerCheckThrottleTest extends TestCase {

	private const STATE_OPTION = 'stonewright_rescue_mu_state';

	/** @var array<string, mixed> */
	private array $saved_options = [];

	private string $scratch = '';

	protected function setUp(): void {
		MuRuntime::load();
		$this->saved_options = $GLOBALS['stonewright_test_options'] ?? [];
		$this->scratch       = sys_get_temp_dir() . '/sw-throttle-' . bin2hex( random_bytes( 5 ) );
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

	private static function installed_hash(): ?string {
		clearstatcache();
		$hash = is_file( RescueInstaller::target_path() ) ? hash_file( 'sha256', RescueInstaller::target_path() ) : false;
		return false === $hash ? null : $hash;
	}

	/** @return array<string, mixed> */
	private static function state(): array {
		$state = $GLOBALS['stonewright_test_options'][ self::STATE_OPTION ] ?? [];
		return is_array( $state ) ? $state : [];
	}

	private static function set_state( mixed $state ): void {
		$GLOBALS['stonewright_test_options'][ self::STATE_OPTION ] = $state;
	}

	/** Changes one byte of the installed file and puts its size and modification time back, so a stat cannot tell. */
	private static function tamper_without_a_trace(): void {
		$path  = RescueInstaller::target_path();
		$mtime = (int) filemtime( $path );
		$bytes = (string) file_get_contents( $path );
		$bytes[2000] = chr( ( ord( $bytes[2000] ) + 1 ) % 256 );
		file_put_contents( $path, $bytes );
		touch( $path, $mtime );
		clearstatcache();
	}

	private static function age_the_check( int $seconds ): void {
		$state = self::state();
		self::assertArrayHasKey( 'checked_at', $state );
		$state['checked_at'] = (int) $state['checked_at'] - $seconds;
		self::set_state( $state );
	}

	private function audit_rows(): int {
		return count( array_filter( $GLOBALS['stonewright_test_wpdb_inserts'], static fn ( array $row ): bool => 'stonewright/rescue-helper-repair' === ( $row['data']['ability_name'] ?? '' ) ) );
	}

	public function test_an_install_remembers_when_it_checked_and_the_size_and_time_of_the_file(): void {
		RescueInstaller::install();

		$state = self::state();
		self::assertEqualsWithDelta( time(), $state['checked_at'], 5 );
		self::assertSame( filesize( RescueInstaller::target_path() ), $state['size'] );
		self::assertSame( filemtime( RescueInstaller::target_path() ), $state['mtime'] );
		self::assertArrayNotHasKey( 'failed_at', $state );
	}

	public function test_admin_init_reads_the_helper_once_per_twelve_hours(): void {
		RescueInstaller::install();
		self::tamper_without_a_trace();

		RescueInstaller::on_admin_init();
		self::assertNotSame( self::bundled_hash(), self::installed_hash(), 'size and time are as remembered and the check is recent: the file is not read' );

		self::age_the_check( RescueInstaller::CHECK_SECONDS - 60 );
		RescueInstaller::on_admin_init();
		self::assertNotSame( self::bundled_hash(), self::installed_hash(), 'a minute short of twelve hours' );

		self::age_the_check( 120 );
		RescueInstaller::on_admin_init();
		self::assertSame( self::bundled_hash(), self::installed_hash(), 'past twelve hours the file is read and repaired' );
		self::assertSame( 1, $this->audit_rows() );
		self::assertEqualsWithDelta( time(), self::state()['checked_at'], 5 );
	}

	public function test_a_clean_remembered_state_is_not_read_again_after_the_retry_interval(): void {
		RescueInstaller::install();
		self::tamper_without_a_trace();

		self::age_the_check( RescueInstaller::RETRY_SECONDS + 5 );
		RescueInstaller::on_admin_init();

		self::assertNotSame( self::bundled_hash(), self::installed_hash(), 'no failure is remembered: the five minute retry does not apply' );
	}

	public function test_a_failed_repair_of_a_changed_helper_is_tried_again_after_the_retry_interval_and_not_before(): void {
		RescueInstaller::install();
		file_put_contents( RescueInstaller::target_path(), "<?php\n// changed by someone\n" );
		clearstatcache();
		// What a repair that could not write the file leaves behind: the failure, and the file as it was then.
		$remember = static function ( int $seconds_ago ): void {
			clearstatcache();
			self::set_state(
				[
					'failed_at'  => time() - $seconds_ago,
					'reason'     => 'write_failed',
					'checked_at' => time() - $seconds_ago,
					'size'       => (int) filesize( RescueInstaller::target_path() ),
					'mtime'      => (int) filemtime( RescueInstaller::target_path() ),
				]
			);
		};

		$remember( 10 );
		RescueInstaller::on_admin_init();
		self::assertNotSame( self::bundled_hash(), self::installed_hash(), 'a failure seconds old is not tried again' );

		$remember( RescueInstaller::RETRY_SECONDS - 30 );
		RescueInstaller::on_admin_init();
		self::assertNotSame( self::bundled_hash(), self::installed_hash(), 'half a minute short of the retry interval' );

		$remember( RescueInstaller::RETRY_SECONDS + 1 );
		RescueInstaller::on_admin_init();
		self::assertSame( self::bundled_hash(), self::installed_hash(), 'past the retry interval the repair is tried again, not after twelve hours' );
		self::assertArrayNotHasKey( 'failed_at', self::state() );
	}

	public function test_the_check_interval_is_twelve_hours(): void {
		self::assertSame( 12 * 3600, RescueInstaller::CHECK_SECONDS );
	}

	public function test_a_changed_helper_file_is_checked_again_at_once(): void {
		RescueInstaller::install();
		file_put_contents( RescueInstaller::target_path(), "<?php\n// changed by someone\n" );

		RescueInstaller::on_admin_init();

		self::assertSame( self::bundled_hash(), self::installed_hash(), 'a different size is seen by the stat and repaired in the same request' );
		self::assertSame( 1, $this->audit_rows() );
	}

	public function test_a_helper_file_with_a_new_modification_time_is_checked_again_at_once(): void {
		RescueInstaller::install();
		self::tamper_without_a_trace();
		touch( RescueInstaller::target_path(), time() + 100 );
		clearstatcache();

		RescueInstaller::on_admin_init();

		self::assertSame( self::bundled_hash(), self::installed_hash() );
	}

	public function test_a_stale_stat_cache_cannot_hide_a_change(): void {
		RescueInstaller::install();
		$path = RescueInstaller::target_path();
		// Fills the stat cache with the size and time the state remembers.
		self::assertSame( self::state()['size'], filesize( $path ) );
		self::assertSame( self::state()['mtime'], filemtime( $path ) );

		file_put_contents( $path, "<?php\n// changed by someone\n" );
		RescueInstaller::on_admin_init();

		self::assertSame( self::bundled_hash(), self::installed_hash() );
	}

	public function test_a_missing_helper_is_installed_in_the_same_request(): void {
		RescueInstaller::install();
		unlink( RescueInstaller::target_path() );
		clearstatcache();

		RescueInstaller::on_admin_init();

		self::assertSame( self::bundled_hash(), self::installed_hash(), 'a failed stat is a change, not "unchanged"' );
		self::assertSame( 0, $this->audit_rows(), 'a file that was only missing is not a tampering' );
		self::assertSame( filesize( RescueInstaller::target_path() ), self::state()['size'] );
	}

	public function test_the_size_and_time_are_taken_after_a_repair_wrote_the_file(): void {
		RescueInstaller::install();
		file_put_contents( RescueInstaller::target_path(), "<?php\n// changed by someone\n" );
		touch( RescueInstaller::target_path(), time() - 5000 );
		clearstatcache();

		RescueInstaller::on_admin_init();

		clearstatcache();
		$state = self::state();
		self::assertSame( filesize( RescueInstaller::target_path() ), $state['size'], 'the size of the repaired file, not of the changed one' );
		self::assertSame( filemtime( RescueInstaller::target_path() ), $state['mtime'] );
		self::assertGreaterThan( time() - 100, $state['mtime'] );

		self::tamper_without_a_trace();
		RescueInstaller::on_admin_init();
		self::assertNotSame( self::bundled_hash(), self::installed_hash(), 'and the next request finds nothing new to read' );
	}

	/** @return array<string, array{0: mixed}> */
	public static function states_an_older_version_left(): array {
		return [
			'success of an older version' => [ [] ],
			'failure of an older version' => [ [ 'failed_at' => 1, 'reason' => 'write_failed' ] ],
			'garbage'                     => [ 'not an array' ],
			'only some of the keys'       => [ [ 'checked_at' => 1 ] ],
		];
	}

	/**
	 * @dataProvider states_an_older_version_left
	 */
	public function test_a_state_without_a_remembered_check_is_checked_once_and_then_remembered( mixed $old ): void {
		RescueInstaller::install();
		self::tamper_without_a_trace();
		self::set_state( $old );

		RescueInstaller::on_admin_init();

		self::assertSame( self::bundled_hash(), self::installed_hash(), 'no check remembered: the file is read once' );
		$state = self::state();
		self::assertEqualsWithDelta( time(), $state['checked_at'], 5 );
		self::assertSame( filesize( RescueInstaller::target_path() ), $state['size'] );
		self::assertSame( filemtime( RescueInstaller::target_path() ), $state['mtime'] );

		self::tamper_without_a_trace();
		RescueInstaller::on_admin_init();
		self::assertNotSame( self::bundled_hash(), self::installed_hash(), 'and then it is left alone' );
	}

	public function test_a_version_change_forces_a_check(): void {
		RescueInstaller::maybe_upgrade();
		self::tamper_without_a_trace();
		RescueInstaller::on_admin_init();
		self::assertNotSame( self::bundled_hash(), self::installed_hash(), 'the throttle holds' );

		$GLOBALS['stonewright_test_options']['stonewright_rescue_mu_version'] = '0.0.1-older';
		RescueInstaller::maybe_upgrade();

		self::assertSame( self::bundled_hash(), self::installed_hash() );
		self::assertSame( STONEWRIGHT_VERSION, $GLOBALS['stonewright_test_options']['stonewright_rescue_mu_version'] );
	}

	public function test_a_version_change_during_a_recent_failure_asks_for_the_next_admin_check(): void {
		RescueInstaller::install();
		self::tamper_without_a_trace();
		self::set_state( array_merge( self::state(), [ 'failed_at' => time() - 10, 'reason' => 'write_failed' ] ) );
		$GLOBALS['stonewright_test_options']['stonewright_rescue_mu_version'] = '0.0.1-older';

		RescueInstaller::maybe_upgrade();
		self::assertNotSame( self::bundled_hash(), self::installed_hash(), 'a try that failed seconds ago is not repeated' );
		self::assertArrayNotHasKey( 'checked_at', self::state() );
		self::assertSame( 'write_failed', self::state()['reason'] );

		self::set_state( array_merge( self::state(), [ 'failed_at' => time() - RescueInstaller::RETRY_SECONDS - 5 ] ) );
		RescueInstaller::on_admin_init();
		self::assertSame( self::bundled_hash(), self::installed_hash() );
	}

	public function test_an_update_forces_the_next_admin_check(): void {
		RescueInstaller::install();
		self::tamper_without_a_trace();
		RescueInstaller::on_admin_init();
		self::assertNotSame( self::bundled_hash(), self::installed_hash() );

		RescueInstaller::force_next_check();
		RescueInstaller::on_admin_init();

		self::assertSame( self::bundled_hash(), self::installed_hash() );
	}

	public function test_forcing_a_check_keeps_the_record_of_a_failure(): void {
		self::set_state( [ 'failed_at' => 123, 'reason' => 'write_failed', 'checked_at' => 5, 'size' => 1, 'mtime' => 2 ] );

		RescueInstaller::force_next_check();

		self::assertSame( [ 'failed_at' => 123, 'reason' => 'write_failed' ], self::state() );
	}

	public function test_forcing_a_check_with_nothing_remembered_writes_nothing(): void {
		unset( $GLOBALS['stonewright_test_options'][ self::STATE_OPTION ] );

		RescueInstaller::force_next_check();

		self::assertArrayNotHasKey( self::STATE_OPTION, $GLOBALS['stonewright_test_options'] );
	}

	public function test_a_helper_that_cannot_be_written_is_not_read_again_on_every_request(): void {
		file_put_contents( RescueInstaller::target_dir(), 'not a directory' );

		RescueInstaller::on_admin_init();

		$state = self::state();
		self::assertSame( 'unwritable', $state['reason'] );
		self::assertGreaterThan( 0, $state['failed_at'] );
		self::assertEqualsWithDelta( time(), $state['checked_at'], 5 );
		self::assertNull( $state['size'], 'a file that is not there is remembered as not there' );
		self::assertNull( $state['mtime'] );

		// The bundled file turns unreadable: a second full check would record source_invalid.
		RescueInstaller::override_source( $this->scratch . '/nowhere.php' );
		RescueInstaller::on_admin_init();
		self::assertSame( 'unwritable', self::state()['reason'], 'still missing, still remembered as missing, checked a moment ago: nothing is read' );
		self::assertSame( $state['checked_at'], self::state()['checked_at'] );
	}

	public function test_a_helper_that_is_still_missing_is_tried_again_after_the_retry_interval(): void {
		file_put_contents( RescueInstaller::target_dir(), 'not a directory' );
		RescueInstaller::on_admin_init();
		unlink( RescueInstaller::target_dir() );
		mkdir( RescueInstaller::target_dir(), 0777, true );

		RescueInstaller::on_admin_init();
		self::assertNull( self::installed_hash(), 'the failure is only seconds old' );

		$state = self::state();
		$state['checked_at'] -= RescueInstaller::RETRY_SECONDS + 5;
		$state['failed_at']  -= RescueInstaller::RETRY_SECONDS + 5;
		self::set_state( $state );
		RescueInstaller::on_admin_init();

		self::assertSame( self::bundled_hash(), self::installed_hash(), 'a folder that became writable gets the helper within minutes' );
		self::assertArrayNotHasKey( 'failed_at', self::state() );
	}

	public function test_the_notice_comes_from_the_remembered_state_without_reading_the_helper(): void {
		file_put_contents( RescueInstaller::target_dir(), 'not a directory' );
		RescueInstaller::on_admin_init();
		RescueInstaller::override_source( $this->scratch . '/nowhere.php' );

		ob_start();
		RescueInstaller::admin_notice();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'could not install its rescue helper', $html );
		self::assertStringContainsString( RescueInstaller::target_dir(), $html );
	}

	public function test_no_notice_and_no_read_while_the_remembered_check_is_clean(): void {
		RescueInstaller::install();
		// A read of the bundled file would now find it missing, but a clean remembered state never reads it.
		RescueInstaller::override_source( $this->scratch . '/nowhere.php' );

		ob_start();
		RescueInstaller::admin_notice();

		self::assertSame( '', ob_get_clean() );
	}

	public function test_the_notice_still_looks_when_the_file_changed_since_the_remembered_check(): void {
		RescueInstaller::install();
		RescueInstaller::override_source( $this->scratch . '/nowhere.php' );
		file_put_contents( RescueInstaller::target_path(), "<?php\n// changed by someone\n" );

		ob_start();
		RescueInstaller::admin_notice();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'is damaged', $html, 'the size moved, so the notice does not trust the memory' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_changed_helper_that_cannot_be_repaired_is_remembered_and_not_read_again(): void {
		RescueInstaller::install();
		define( 'DISALLOW_FILE_MODS', true );
		file_put_contents( RescueInstaller::target_path(), "<?php\n// changed by someone\n" );

		RescueInstaller::on_admin_init();

		$state = self::state();
		self::assertSame( 'file_mods_disabled', $state['reason'] );
		self::assertSame( filesize( RescueInstaller::target_path() ), $state['size'], 'the size of the file that could not be replaced' );
		self::assertEqualsWithDelta( time(), $state['checked_at'], 5 );

		RescueInstaller::override_source( $this->scratch . '/nowhere.php' );
		RescueInstaller::on_admin_init();
		self::assertSame( 'file_mods_disabled', self::state()['reason'], 'nothing changed: no second read' );

		ob_start();
		RescueInstaller::admin_notice();
		$html = (string) ob_get_clean();
		self::assertStringContainsString( 'file changes are turned off', $html );
	}
}
