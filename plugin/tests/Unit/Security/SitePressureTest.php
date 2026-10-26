<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Security\ErrorPatterns;
use Stonewright\WpMcp\Security\SitePressure;
use Stonewright\WpMcp\Support\SchemaGate;

/**
 * @covers \Stonewright\WpMcp\Security\SitePressure
 */
final class SitePressureTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']    = [];
		$GLOBALS['stonewright_test_filters']    = [];
		$GLOBALS['stonewright_test_transients'] = [];
		SitePressure::reset_for_tests();
	}

	protected function tearDown(): void {
		SitePressure::reset_for_tests();
		$GLOBALS['stonewright_test_options']    = [];
		$GLOBALS['stonewright_test_filters']    = [];
		$GLOBALS['stonewright_test_transients'] = [];
	}

	/**
	 * @param array<string, float|int|null> $overrides
	 * @return array{db_ms: float, load_per_core: ?float, memory_pct: float, at: int}
	 */
	private static function sample( array $overrides = [] ): array {
		return array_merge( [ 'db_ms' => 5.0, 'load_per_core' => null, 'memory_pct' => 20.0, 'at' => 1000 ], $overrides );
	}

	public function test_levels_follow_the_thresholds(): void {
		$this->assertSame( 'normal', SitePressure::classify( self::sample() ) );
		$this->assertSame( 'normal', SitePressure::classify( self::sample( [ 'db_ms' => 249.0 ] ) ) );
		$this->assertSame( 'elevated', SitePressure::classify( self::sample( [ 'db_ms' => 250.0 ] ) ) );
		$this->assertSame( 'elevated', SitePressure::classify( self::sample( [ 'db_ms' => 999.0 ] ) ) );
		$this->assertSame( 'critical', SitePressure::classify( self::sample( [ 'db_ms' => 1000.0 ] ) ) );
		$this->assertSame( 'critical', SitePressure::classify( self::sample( [ 'memory_pct' => 85.0 ] ) ) );
		$this->assertSame( 'normal', SitePressure::classify( self::sample( [ 'memory_pct' => 84.9 ] ) ) );
	}

	public function test_load_alone_never_reaches_critical(): void {
		// A busy machine with a quick database is not a reason to hold work back.
		$this->assertSame( 'normal', SitePressure::classify( self::sample( [ 'load_per_core' => 40.0, 'db_ms' => 20.0 ] ) ) );
		$this->assertSame( 'normal', SitePressure::classify( self::sample( [ 'load_per_core' => 40.0, 'db_ms' => 100.0 ] ) ) );
		// With a database that is slower than usual, high load makes it elevated and no more.
		$this->assertSame( 'elevated', SitePressure::classify( self::sample( [ 'load_per_core' => 4.0, 'db_ms' => 101.0 ] ) ) );
		$this->assertSame( 'elevated', SitePressure::classify( self::sample( [ 'load_per_core' => 400.0, 'db_ms' => 900.0 ] ) ) );
		$this->assertSame( 'normal', SitePressure::classify( self::sample( [ 'load_per_core' => 3.9, 'db_ms' => 200.0 ] ) ) );
	}

	public function test_thresholds_can_be_changed_with_a_filter(): void {
		add_filter(
			'stonewright_site_pressure_thresholds',
			static fn ( array $thresholds ): array => array_merge( $thresholds, [ 'db_ms_elevated' => 50 ] )
		);
		$this->assertSame( 'elevated', SitePressure::classify( self::sample( [ 'db_ms' => 60.0 ] ) ) );
	}

	public function test_sampling_runs_at_most_once_per_thirty_seconds_and_only_on_demand(): void {
		$calls = 0;
		SitePressure::set_probe(
			static function () use ( &$calls ): float {
				++$calls;
				return 12.0;
			}
		);
		$this->assertSame( 0, $calls, 'Nothing is sampled until a caller asks.' );

		$this->assertSame( 'normal', SitePressure::level( 1000 ) );
		$this->assertSame( 1, $calls );

		// Same request: memoised. Later request within thirty seconds: the stored sample.
		$this->assertSame( 'normal', SitePressure::level( 1010 ) );
		SitePressure::forget_request();
		$this->assertSame( 'normal', SitePressure::level( 1029 ) );
		$this->assertSame( 1, $calls );

		SitePressure::forget_request();
		$this->assertSame( 'normal', SitePressure::level( 1030 ) );
		$this->assertSame( 2, $calls, 'A stored sample thirty seconds old is replaced.' );
	}

	public function test_a_stored_sample_is_an_autoloaded_option(): void {
		SitePressure::set_probe( static fn (): float => 400.0 );
		$this->assertSame( 'elevated', SitePressure::level( 5000 ) );
		$stored = get_option( SitePressure::OPTION );
		$this->assertIsArray( $stored );
		$this->assertSame( 5000, $stored['at'] );
		$this->assertTrue( $GLOBALS['stonewright_test_option_autoload'][ SitePressure::OPTION ] );
	}

	public function test_a_stored_sample_from_another_request_decides_the_level(): void {
		update_option( SitePressure::OPTION, self::sample( [ 'db_ms' => 1500.0, 'at' => 2000 ] ), true );
		SitePressure::set_probe(
			function (): float {
				$this->fail( 'A fresh stored sample must not be measured again.' );
			}
		);
		$this->assertSame( 'critical', SitePressure::level( 2010 ) );
	}

	public function test_a_failing_probe_counts_as_normal(): void {
		SitePressure::set_probe(
			static function (): float {
				throw new \RuntimeException( 'database gone' );
			}
		);
		$this->assertSame( 'normal', SitePressure::level( 1 ) );
	}

	public function test_notice_is_null_when_normal(): void {
		SitePressure::set_probe( static fn (): float => 3.0 );
		$this->assertNull( SitePressure::notice() );
	}

	public function test_notice_names_the_level_and_tells_the_agent_what_to_do(): void {
		SitePressure::set_probe( static fn (): float => 1200.0 );
		$notice = SitePressure::notice();
		$this->assertIsArray( $notice );
		$this->assertSame( 'critical', $notice['level'] );
		$this->assertSame( 1200.0, $notice['db_ms'] );
		$this->assertStringContainsString( 'batch writes into one apply per post', $notice['hint'] );
	}

	public function test_the_real_probe_times_one_query_and_reads_quick_without_a_database_handle(): void {
		$this->assertGreaterThanOrEqual( 0.0, SitePressure::probe_database() );
		SitePressure::set_environment( null );
		$sample = SitePressure::sample( static fn (): float => 7.0 );
		$this->assertSame( 7.0, $sample['db_ms'] );
		$this->assertGreaterThanOrEqual( 0.0, $sample['memory_pct'] );
	}

	public function test_a_repair_under_critical_pressure_is_skipped_and_backs_off_for_a_minute(): void {
		SitePressure::set_probe( static fn (): float => 2000.0 );
		$repaired = SchemaGate::repair(
			'pressure_demo',
			'stonewright_pressure_demo_version',
			3,
			function (): void {
				$this->fail( 'A repair must not run while the site is critical.' );
			},
			static fn (): bool => true
		);

		$this->assertFalse( $repaired );
		$this->assertSame( SchemaGate::PRESSURE_BACKOFF_SECONDS, $GLOBALS['stonewright_test_transient_ttls']['stonewright_schema_backoff_pressure_demo'] );
		$this->assertFalse( get_option( 'stonewright_pressure_demo_version', false ) );
	}

	public function test_a_repair_on_a_calm_or_elevated_site_still_runs(): void {
		SitePressure::set_probe( static fn (): float => 400.0 );
		$ran      = false;
		$repaired = SchemaGate::repair(
			'pressure_demo_ok',
			'stonewright_pressure_demo_ok_version',
			3,
			static function () use ( &$ran ): void {
				$ran = true;
			},
			static fn (): bool => true
		);

		$this->assertTrue( $repaired );
		$this->assertTrue( $ran );
	}

	public function test_pending_lesson_migrations_wait_while_the_site_is_under_pressure(): void {
		SitePressure::set_probe( static fn (): float => 400.0 );
		$result = ErrorPatterns::run_pending_migrations();

		$this->assertTrue( $result['legacy']['deferred'] );
		$this->assertTrue( $result['draft']['deferred'] );
		$this->assertFalse( get_option( ErrorPatterns::LEGACY_LESSON_MIGRATION_OPTION, false ), 'No attempt is recorded for a deferred migration.' );
	}

	public function test_finished_lesson_migrations_do_not_measure_the_pressure(): void {
		update_option( ErrorPatterns::LEGACY_LESSON_MIGRATION_OPTION, '1', true );
		update_option( ErrorPatterns::DRAFT_LESSON_RETURN_OPTION, 'abandoned', true );
		SitePressure::set_probe(
			function (): float {
				$this->fail( 'Nothing is pending, so the site is not measured.' );
			}
		);

		$result = ErrorPatterns::run_pending_migrations();
		$this->assertArrayNotHasKey( 'deferred', $result['legacy'] );
	}

	public function test_consumers_consult_the_level_before_optional_work(): void {
		$root = dirname( __DIR__, 3 ) . '/includes/';
		foreach (
			[
				'Support/SchemaGate.php'        => 'SitePressure::level()',
				'Security/ErrorPatterns.php'    => 'SitePressure::level()',
				'Security/ChangeJournal.php'    => 'SitePressure::level()',
				'Security/HealthProbe.php'      => 'SitePressure::level()',
				'Core/RescueInstaller.php'      => 'SitePressure::level()',
			] as $file => $needle
		) {
			$this->assertStringContainsString( $needle, (string) file_get_contents( $root . $file ), $file . ' consults the pressure level.' );
		}
	}

	public function test_the_memory_of_another_request_never_decides_this_requests_level(): void {
		// A heavy request stored 95% memory beside a quick database; this request is calm.
		update_option( SitePressure::OPTION, self::sample( [ 'db_ms' => 4.0, 'memory_pct' => 95.0, 'at' => 2000 ] ), true );
		SitePressure::set_probe(
			function (): float {
				$this->fail( 'A fresh stored sample must not be measured again.' );
			}
		);
		$this->assertSame( 'normal', SitePressure::level( 2010 ) );

		// The memory this process uses still counts for this process.
		SitePressure::set_environment( [ 'memory_pct' => 90.0, 'load_per_core' => null ] );
		$this->assertSame( 'critical', SitePressure::level( 2011 ) );

		// A slow database is shared through the stored sample.
		SitePressure::set_environment( [ 'memory_pct' => 10.0, 'load_per_core' => null ] );
		update_option( SitePressure::OPTION, self::sample( [ 'db_ms' => 1500.0, 'memory_pct' => 10.0, 'at' => 2000 ] ), true );
		$this->assertSame( 'critical', SitePressure::level( 2012 ) );
	}

	public function test_the_memory_of_this_request_never_reaches_the_agent_notice(): void {
		// An MCP request that just did a heavy write can sit at 90% of its own limit while the site is fine.
		SitePressure::set_environment( [ 'memory_pct' => 90.0, 'load_per_core' => null ] );
		SitePressure::set_probe( static fn (): float => 4.0 );

		$this->assertSame( 'critical', SitePressure::level(), 'The request holds optional work back for itself.' );
		$this->assertNull( SitePressure::notice(), 'The agent is not told the site is under load.' );
		$this->assertSame( 'normal', SitePressure::site_level() );
	}

	public function test_the_agent_notice_follows_the_shared_measurement(): void {
		SitePressure::set_environment( [ 'memory_pct' => 5.0, 'load_per_core' => null ] );
		SitePressure::set_probe( static fn (): float => 1500.0 );

		$notice = SitePressure::notice();

		$this->assertIsArray( $notice );
		$this->assertSame( 'critical', $notice['level'] );
		$this->assertSame( 1500.0, $notice['db_ms'] );
	}
}
