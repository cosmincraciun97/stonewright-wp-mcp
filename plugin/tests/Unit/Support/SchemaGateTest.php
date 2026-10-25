<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Stonewright\WpMcp\Support\OptionLock;
use Stonewright\WpMcp\Support\SchemaGate;

/**
 * @covers \Stonewright\WpMcp\Support\SchemaGate
 */
final class SchemaGateTest extends TestCase {

	private const VERSION_OPTION = 'stonewright_memory_schema_version';
	private const BACKOFF        = 'stonewright_schema_backoff_memory';

	private string|false $previous_error_log = false;

	private mixed $original_wpdb;

	private string $log_file = '';

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$this->reset_globals();
		SchemaGate::reset_for_tests( 'memory' );

		$this->log_file           = (string) tempnam( sys_get_temp_dir(), 'sw-gate-log-' );
		$this->previous_error_log = ini_get( 'error_log' );
		ini_set( 'error_log', $this->log_file );
	}

	protected function tearDown(): void {
		ini_set( 'error_log', false === $this->previous_error_log ? '' : $this->previous_error_log );
		if ( '' !== $this->log_file && file_exists( $this->log_file ) ) {
			unlink( $this->log_file );
		}
		$this->reset_globals();
		SchemaGate::reset_for_tests( 'memory' );
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
	}

	private function reset_globals(): void {
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_option_autoload'] = [];
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_transient_ttls']  = [];
		unset( $GLOBALS['stonewright_test_after_add_option'] );
	}

	public function test_current_version_runs_no_check_and_no_install(): void {
		$GLOBALS['stonewright_test_options'][ self::VERSION_OPTION ] = 4;
		$fail = static function (): never {
			self::fail( 'A current version must not run the install or the health check.' );
		};

		self::assertTrue( SchemaGate::maybe_install( 'memory', self::VERSION_OPTION, 4, $fail, $fail ) );
		self::assertTrue( SchemaGate::maybe_install( 'memory', self::VERSION_OPTION, 3, $fail, $fail ), 'A newer stored version counts as current.' );
	}

	public function test_failed_install_starts_backoff_and_skips_until_it_expires(): void {
		$installs = 0;
		$install  = static function () use ( &$installs ): void {
			++$installs;
		};
		$healthy  = static fn(): bool => false;

		self::assertFalse( SchemaGate::maybe_install( 'memory', self::VERSION_OPTION, 4, $install, $healthy ) );
		self::assertSame( 1, $installs );
		self::assertSame( SchemaGate::BACKOFF_SECONDS, $GLOBALS['stonewright_test_transient_ttls'][ self::BACKOFF ] );
		self::assertSame( 0, (int) get_option( self::VERSION_OPTION, 0 ), 'A failed install never records the version.' );
		self::assertStringContainsString( 'stonewright.schema_install_failed', (string) file_get_contents( $this->log_file ) );

		self::assertFalse( SchemaGate::maybe_install( 'memory', self::VERSION_OPTION, 4, $install, $healthy ) );
		self::assertSame( 1, $installs, 'The back-off skips the install.' );

		// The transient stub keeps no clock: dropping the row is what its expiry does.
		delete_transient( self::BACKOFF );

		self::assertFalse( SchemaGate::maybe_install( 'memory', self::VERSION_OPTION, 4, $install, $healthy ) );
		self::assertSame( 2, $installs );
	}

	public function test_lock_held_by_another_process_skips_install_without_backoff(): void {
		self::assertTrue( OptionLock::acquire( 'schema_memory', 60 ) );
		$fail = static function (): never {
			self::fail( 'The install must not run while another process holds the lock.' );
		};

		self::assertFalse( SchemaGate::maybe_install( 'memory', self::VERSION_OPTION, 4, $fail, $fail ) );
		self::assertArrayNotHasKey( self::BACKOFF, $GLOBALS['stonewright_test_transients'] );
		self::assertFalse( OptionLock::acquire( 'schema_memory', 60 ), 'The holder keeps its lock.' );
	}

	public function test_successful_install_records_version_autoloaded_and_clears_backoff(): void {
		$installs = 0;

		self::assertTrue(
			SchemaGate::maybe_install(
				'memory',
				self::VERSION_OPTION,
				4,
				static function () use ( &$installs ): void {
					++$installs;
				},
				static fn(): bool => true
			)
		);

		self::assertSame( 1, $installs );
		self::assertSame( 4, $GLOBALS['stonewright_test_options'][ self::VERSION_OPTION ] );
		self::assertTrue( $GLOBALS['stonewright_test_option_autoload'][ self::VERSION_OPTION ] );
		self::assertArrayNotHasKey( self::BACKOFF, $GLOBALS['stonewright_test_transients'] );
		self::assertTrue( OptionLock::acquire( 'schema_memory', 60 ), 'The lock is released.' );
	}

	public function test_repair_installs_even_when_the_version_is_current(): void {
		$GLOBALS['stonewright_test_options'][ self::VERSION_OPTION ] = 4;
		$installs = 0;

		self::assertTrue(
			SchemaGate::repair(
				'memory',
				self::VERSION_OPTION,
				4,
				static function () use ( &$installs ): void {
					++$installs;
				},
				static fn(): bool => true
			)
		);
		self::assertSame( 1, $installs );
		self::assertTrue( OptionLock::acquire( 'schema_memory', 60 ), 'The lock is released.' );
	}

	public function test_repair_runs_the_install_during_an_active_backoff(): void {
		// A failed attempt (activation, a request, the daily job) started a back-off.
		self::assertFalse( SchemaGate::maybe_install( 'memory', self::VERSION_OPTION, 4, static function (): void {}, static fn(): bool => false ) );
		self::assertArrayHasKey( self::BACKOFF, $GLOBALS['stonewright_test_transients'] );
		$installs = 0;

		$repaired = SchemaGate::repair(
			'memory',
			self::VERSION_OPTION,
			4,
			static function () use ( &$installs ): void {
				++$installs;
			},
			static fn(): bool => true
		);

		self::assertTrue( $repaired );
		self::assertSame( 1, $installs, 'A repair is asked for by a person or a daily job: the back-off does not stop it.' );
		self::assertArrayNotHasKey( self::BACKOFF, $GLOBALS['stonewright_test_transients'], 'A successful repair clears the back-off.' );
		self::assertSame( 4, $GLOBALS['stonewright_test_options'][ self::VERSION_OPTION ] );
	}

	public function test_a_failed_repair_during_a_backoff_keeps_it_going(): void {
		set_transient( self::BACKOFF, 1, SchemaGate::BACKOFF_SECONDS );

		self::assertFalse( SchemaGate::repair( 'memory', self::VERSION_OPTION, 4, static function (): void {}, static fn(): bool => false ) );
		self::assertSame( SchemaGate::BACKOFF_SECONDS, $GLOBALS['stonewright_test_transient_ttls'][ self::BACKOFF ] );
	}

	public function test_repair_still_skips_when_another_process_holds_the_lock(): void {
		self::assertTrue( OptionLock::acquire( 'schema_memory', 60 ) );
		$fail = static function (): never {
			self::fail( 'The install must not run while another process holds the lock.' );
		};

		self::assertFalse( SchemaGate::repair( 'memory', self::VERSION_OPTION, 4, $fail, $fail ) );
	}

	public function test_a_write_during_a_backoff_is_neither_repaired_nor_retried(): void {
		$GLOBALS['wpdb'] = (object) [ 'last_error' => "Unknown column 'event_type' in 'field list'" ];
		set_transient( self::BACKOFF, 1, SchemaGate::BACKOFF_SECONDS );
		$writes = 0;
		$write  = static function () use ( &$writes ): int|false {
			++$writes;
			return false;
		};
		$repair = static function (): bool {
			self::fail( 'A failing database is not asked again while the back-off lasts.' );
		};

		self::assertFalse( SchemaGate::write_or_repair( 'memory', $write, $repair ) );
		self::assertSame( 1, $writes );
	}

	public function test_repair_that_stays_unhealthy_starts_backoff(): void {
		$GLOBALS['stonewright_test_options'][ self::VERSION_OPTION ] = 4;

		self::assertFalse( SchemaGate::repair( 'memory', self::VERSION_OPTION, 4, static function (): void {}, static fn(): bool => false ) );
		self::assertSame( SchemaGate::BACKOFF_SECONDS, $GLOBALS['stonewright_test_transient_ttls'][ self::BACKOFF ] );
	}

	public function test_an_install_that_throws_backs_off_and_rethrows(): void {
		try {
			SchemaGate::maybe_install(
				'memory',
				self::VERSION_OPTION,
				4,
				static function (): void {
					throw new RuntimeException( 'dbDelta blew up' );
				},
				static fn(): bool => true
			);
			self::fail( 'The install exception must reach the caller.' );
		} catch ( RuntimeException $e ) {
			self::assertSame( 'dbDelta blew up', $e->getMessage() );
		}

		self::assertSame( SchemaGate::BACKOFF_SECONDS, $GLOBALS['stonewright_test_transient_ttls'][ self::BACKOFF ] );
		self::assertSame( 0, (int) get_option( self::VERSION_OPTION, 0 ) );
		self::assertTrue( OptionLock::acquire( 'schema_memory', 60 ), 'The lock is released even when the install throws.' );
		self::assertStringContainsString( 'stonewright.schema_install_failed', (string) file_get_contents( $this->log_file ) );
	}

	public function test_a_version_installed_by_the_lock_holder_is_not_installed_again(): void {
		// The previous holder finished between our version read and our own lock: the re-read finds the version current.
		$GLOBALS['stonewright_test_after_add_option'] = static function (): void {
			$GLOBALS['stonewright_test_options'][ self::VERSION_OPTION ] = 4;
		};
		$fail = static function (): never {
			self::fail( 'The install must not run again.' );
		};

		self::assertTrue( SchemaGate::maybe_install( 'memory', self::VERSION_OPTION, 4, $fail, $fail ) );
		self::assertTrue( OptionLock::acquire( 'schema_memory', 60 ), 'The lock is released.' );
	}

	public function test_a_write_that_hits_a_missing_column_repairs_once_and_retries_once(): void {
		$GLOBALS['wpdb'] = (object) [ 'last_error' => "Unknown column 'event_type' in 'field list'" ];
		$writes          = 0;
		$repairs         = 0;
		$write           = static function () use ( &$writes ): int|false {
			++$writes;
			return 1 === $writes ? false : 1;
		};
		$repair          = static function () use ( &$repairs ): bool {
			++$repairs;
			return true;
		};

		self::assertSame( 1, SchemaGate::write_or_repair( 'memory', $write, $repair ) );
		self::assertSame( 2, $writes );
		self::assertSame( 1, $repairs );
	}

	public function test_a_second_failed_write_in_the_same_request_does_not_repair_again(): void {
		$GLOBALS['wpdb'] = (object) [ 'last_error' => "Table 'db.wp_x' doesn't exist" ];
		$repairs         = 0;
		$repair          = static function () use ( &$repairs ): bool {
			++$repairs;
			return false;
		};
		$write           = static fn(): int|false => false;

		self::assertFalse( SchemaGate::write_or_repair( 'memory', $write, $repair ) );
		self::assertFalse( SchemaGate::write_or_repair( 'memory', $write, $repair ) );
		self::assertSame( 1, $repairs );
	}

	public function test_a_write_that_fails_for_another_reason_is_left_alone(): void {
		$GLOBALS['wpdb'] = (object) [ 'last_error' => "Duplicate entry 'a' for key 'scope_key'" ];
		$writes          = 0;
		$write           = static function () use ( &$writes ): int|false {
			++$writes;
			return false;
		};
		$repair          = static function (): bool {
			self::fail( 'A duplicate key is not a schema problem.' );
		};

		self::assertFalse( SchemaGate::write_or_repair( 'memory', $write, $repair ) );
		self::assertSame( 1, $writes );
	}

	public function test_a_write_that_succeeds_never_repairs(): void {
		$repair = static function (): bool {
			self::fail( 'A successful write needs no repair.' );
		};

		self::assertSame( 1, SchemaGate::write_or_repair( 'memory', static fn(): int|false => 1, $repair ) );
	}

	/**
	 * @return array<string, array{string, bool}>
	 */
	public static function schema_errors(): array {
		return [
			'unknown column'  => [ "Unknown column 'event_type' in 'field list'", true ],
			'missing table'   => [ "Table 'db.wp_stonewright_audit' doesn't exist", true ],
			'sqlite style'    => [ 'no such table: wp_stonewright_audit', true ],
			'uppercase'       => [ 'UNKNOWN COLUMN x', true ],
			'a duplicate key' => [ "Duplicate entry 'a' for key 'scope_key'", false ],
			'a lock timeout'  => [ 'Lock wait timeout exceeded; try restarting transaction', false ],
			'an empty error'  => [ '', false ],
		];
	}

	/**
	 * @dataProvider schema_errors
	 */
	public function test_looks_like_schema_error( string $last_error, bool $expected ): void {
		self::assertSame( $expected, SchemaGate::looks_like_schema_error( $last_error ) );
	}
}
