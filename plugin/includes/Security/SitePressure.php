<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

/**
 * How hard the site is working right now, as one of three levels, so optional work (schema repair, lesson
 * migrations, the journal rewrite, the rescue helper check, a wide health probe) can wait and an agent can be told
 * to ask less of the site.
 *
 * The signal is measured on demand and never on a plain request: a caller that is about to do optional work asks
 * level(). One measurement is one `SELECT 1`, timed, plus the machine load and the memory in use. It is stored in an
 * autoloaded option and reused for thirty seconds, so a slow site is asked once per half minute and not once per
 * request. A measurement that fails counts as normal: the signal must never be the thing that breaks a request.
 *
 * Thresholds: a database round trip of 250 ms is elevated and of one second critical; memory at 85% of the limit is
 * critical; load per core of 4 is elevated, and only together with a database that is slower than 100 ms, because a
 * busy machine with a quick database is not a reason to hold work back. Load alone never makes the level critical.
 *
 * The database time and the load describe the site and are shared through the stored measurement. The memory
 * describes the process that reads it, so it is read again by each request and never taken from the stored sample:
 * one request that used its whole memory limit must not hold optional work back from every other request. Memory
 * is a gate for that request's own optional work (level()) and never part of what an agent is told (site_level()).
 */
final class SitePressure {

	public const NORMAL   = 'normal';
	public const ELEVATED = 'elevated';
	public const CRITICAL = 'critical';

	public const LEVELS = [ self::NORMAL, self::ELEVATED, self::CRITICAL ];

	public const THRESHOLDS = [
		'db_ms_elevated'         => 250,
		'db_ms_critical'         => 1000,
		'load_per_core_elevated' => 4.0,
		'memory_pct_critical'    => 85,
	];

	/** Autoloaded option that holds the last measurement. */
	public const OPTION = 'stonewright_site_pressure';

	/** Seconds a measurement is reused. */
	public const REUSE_SECONDS = 30;

	/** A load reading only counts together with a database slower than this. */
	private const LOAD_NEEDS_DB_MS = 100;

	private static ?string $memo = null;

	private static ?string $site_memo = null;

	/** @var (callable(): float)|null */
	private static $probe_override = null;

	/** @var array{memory_pct: float, load_per_core: ?float}|null */
	private static ?array $environment_override = null;

	/**
	 * The measured values.
	 *
	 * @param (callable(): float)|null $probe Returns the database round trip in milliseconds; defaults to timing `SELECT 1`.
	 * @return array{db_ms: float, load_per_core: ?float, memory_pct: float, at: int}
	 */
	public static function sample( ?callable $probe = null ): array {
		$probe ??= self::$probe_override ?? [ self::class, 'probe_database' ];
		try {
			$db_ms = max( 0.0, (float) $probe() );
		} catch ( \Throwable $failure ) {
			unset( $failure );
			$db_ms = 0.0;
		}

		return [
			'db_ms'         => round( $db_ms, 1 ),
			'load_per_core' => self::$environment_override['load_per_core'] ?? self::load_per_core(),
			'memory_pct'    => self::current_memory_pct(),
			'at'            => time(),
		];
	}

	/**
	 * The level for a measurement.
	 *
	 * @param array<string, mixed> $sample
	 */
	public static function classify( array $sample ): string {
		$limits = self::thresholds();
		$db_ms  = (float) ( $sample['db_ms'] ?? 0 );
		$memory = (float) ( $sample['memory_pct'] ?? 0 );
		$load   = isset( $sample['load_per_core'] ) ? (float) $sample['load_per_core'] : null;

		if ( $db_ms >= $limits['db_ms_critical'] || $memory >= $limits['memory_pct_critical'] ) {
			return self::CRITICAL;
		}
		if ( $db_ms >= $limits['db_ms_elevated'] ) {
			return self::ELEVATED;
		}
		if ( null !== $load && $load >= $limits['load_per_core_elevated'] && $db_ms > self::LOAD_NEEDS_DB_MS ) {
			return self::ELEVATED;
		}

		return self::NORMAL;
	}

	/**
	 * The level for optional work now. Memoised for the request; across requests the stored measurement is reused
	 * while it is younger than REUSE_SECONDS. The memory this request uses counts here, because a request that is
	 * close to its own limit should not start optional work. Call it only on a path that already does work.
	 */
	public static function level( ?int $now = null ): string {
		if ( null !== self::$memo ) {
			return self::$memo;
		}
		$sample                = self::measurement( $now ?? time() );
		$sample['memory_pct'] = self::current_memory_pct();
		self::$memo            = self::classify( $sample );

		return self::$memo;
	}

	/**
	 * The level of the site, from the shared measurement alone (database time and load), without the memory of this
	 * request. This is what an agent is told: one request that just did heavy work can use most of its own memory
	 * while the site is fine, and the agent must not stop work for that.
	 */
	public static function site_level( ?int $now = null ): string {
		if ( null !== self::$site_memo ) {
			return self::$site_memo;
		}
		$sample                = self::measurement( $now ?? time() );
		$sample['memory_pct'] = 0.0;
		self::$site_memo       = self::classify( $sample );

		return self::$site_memo;
	}

	/**
	 * What an agent is told while the site is under load, or null when it is not. It follows site_level().
	 *
	 * @return array{level: string, db_ms: float, hint: string}|null
	 */
	public static function notice(): ?array {
		$level = self::site_level();
		if ( self::NORMAL === $level ) {
			return null;
		}
		$stored = get_option( self::OPTION, [] );

		return [
			'level' => $level,
			'db_ms' => is_array( $stored ) ? (float) ( $stored['db_ms'] ?? 0 ) : 0.0,
			'hint'  => __( 'This site is under load: batch writes into one apply per post, avoid Elementor saves and plugin changes until it recovers.', 'stonewright' ),
		];
	}

	/**
	 * The thresholds in force, after the stonewright_site_pressure_thresholds filter.
	 *
	 * @return array{db_ms_elevated: int|float, db_ms_critical: int|float, load_per_core_elevated: int|float, memory_pct_critical: int|float}
	 */
	public static function thresholds(): array {
		$filtered = apply_filters( 'stonewright_site_pressure_thresholds', self::THRESHOLDS );
		$limits   = self::THRESHOLDS;
		if ( is_array( $filtered ) ) {
			foreach ( $limits as $key => $default ) {
				if ( isset( $filtered[ $key ] ) && is_numeric( $filtered[ $key ] ) ) {
					$limits[ $key ] = $filtered[ $key ] + 0;
				}
			}
		}

		return $limits;
	}

	/**
	 * Replaces the database timing, for unit tests. Memory and load stay at the calm values reset_for_tests() pins,
	 * so the memory a long test run uses never reads as pressure.
	 *
	 * @param (callable(): float)|null $probe
	 */
	public static function set_probe( ?callable $probe ): void {
		self::$probe_override = $probe;
		self::$memo           = null;
		self::$site_memo      = null;
	}

	/**
	 * Pins the memory and load a measurement reports, for unit tests.
	 *
	 * @param array{memory_pct: float, load_per_core: ?float}|null $environment Null reads the real process again.
	 */
	public static function set_environment( ?array $environment ): void {
		self::$environment_override = $environment;
		self::$memo                 = null;
		self::$site_memo            = null;
	}

	/** Forgets the level this request already decided, so the next call reads the stored measurement again. */
	public static function forget_request(): void {
		self::$memo      = null;
		self::$site_memo = null;
	}

	/** Back to a quick database and a calm machine, for unit tests. */
	public static function reset_for_tests(): void {
		self::$memo                 = null;
		self::$site_memo            = null;
		self::$probe_override       = static fn (): float => 1.0;
		self::$environment_override = [ 'memory_pct' => 20.0, 'load_per_core' => null ];
	}

	/** Times one `SELECT 1`. A handle that cannot run it reads as quick. */
	public static function probe_database(): float {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) ) {
			return 0.0;
		}
		$started = microtime( true );
		$wpdb->query( 'SELECT 1' );

		return ( microtime( true ) - $started ) * 1000;
	}

	private static function load_per_core(): ?float {
		if ( ! function_exists( 'sys_getloadavg' ) ) {
			return null;
		}
		$load = sys_getloadavg();
		if ( ! is_array( $load ) || ! isset( $load[0] ) ) {
			return null;
		}
		$cores = self::cpu_count();

		return null === $cores ? null : round( (float) $load[0] / $cores, 2 );
	}

	private static function cpu_count(): ?int {
		if ( ! @is_readable( '/proc/cpuinfo' ) ) {
			return null;
		}
		$info = @file_get_contents( '/proc/cpuinfo' );
		if ( ! is_string( $info ) ) {
			return null;
		}
		$count = preg_match_all( '/^processor\s*:/m', $info );

		return $count > 0 ? $count : null;
	}

	/**
	 * The shared measurement: the stored one while it is younger than REUSE_SECONDS, else a new one, which is stored.
	 *
	 * @return array{db_ms: float, load_per_core: ?float, memory_pct: float, at: int}
	 */
	private static function measurement( int $now ): array {
		$stored = get_option( self::OPTION, [] );
		if ( is_array( $stored ) && isset( $stored['at'], $stored['db_ms'] ) && $now - (int) $stored['at'] >= 0 && $now - (int) $stored['at'] < self::REUSE_SECONDS ) {
			return [
				'db_ms'         => (float) $stored['db_ms'],
				'load_per_core' => isset( $stored['load_per_core'] ) ? (float) $stored['load_per_core'] : null,
				'memory_pct'    => (float) ( $stored['memory_pct'] ?? 0 ),
				'at'            => (int) $stored['at'],
			];
		}

		$sample       = self::sample();
		$sample['at'] = $now;
		update_option( self::OPTION, $sample, true );

		return $sample;
	}

	private static function current_memory_pct(): float {
		return self::$environment_override['memory_pct'] ?? self::memory_pct();
	}

	private static function memory_pct(): float {
		$limit = self::bytes( (string) ini_get( 'memory_limit' ) );
		if ( $limit <= 0 ) {
			return 0.0;
		}

		return round( memory_get_usage( true ) / $limit * 100, 1 );
	}

	/** A php.ini size ("256M", "1G", "-1") in bytes; zero or less for no limit. */
	private static function bytes( string $setting ): int {
		$setting = trim( $setting );
		if ( '' === $setting || '-1' === $setting ) {
			return 0;
		}
		$number = (int) $setting;
		return match ( strtolower( substr( $setting, -1 ) ) ) {
			'g'     => $number * 1024 * 1024 * 1024,
			'm'     => $number * 1024 * 1024,
			'k'     => $number * 1024,
			default => $number,
		};
	}
}
