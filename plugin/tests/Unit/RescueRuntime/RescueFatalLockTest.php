<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\RescueRuntime;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Tests\Unit\RescueRuntime\Support\MuRuntime;

/**
 * The incident write while another process writes the journal: the handler waits for the
 * lock, applies its change to what the other writer left, and gives up without touching the
 * file when the lock is held too long.
 *
 * @coversNothing The MU-plugin lives outside includes/.
 */
final class RescueFatalLockTest extends TestCase {

	private const NOW = 2_000_000_000;

	/** @var resource|null */
	private $process;

	/** @var array<int, resource> */
	private array $pipes = [];

	protected function setUp(): void {
		if ( ! function_exists( 'proc_open' ) ) {
			self::markTestSkipped( 'proc_open is not available.' );
		}
		MuRuntime::begin();
		MuRuntime::write_journal( [ MuRuntime::entry( 'cs-1', [ 'armed_at' => self::NOW - 30 ] ) ] );
	}

	protected function tearDown(): void {
		if ( is_resource( $this->process ) ) {
			foreach ( $this->pipes as $pipe ) {
				if ( is_resource( $pipe ) ) {
					fclose( $pipe );
				}
			}
			proc_close( $this->process );
		}
		MuRuntime::end();
	}

	/** Starts a child that holds the journal lock for $hold_ms and returns once it owns the lock. */
	private function hold_lock( int $hold_ms, string $append = '', bool $incident = false ): void {
		$command = [ PHP_BINARY, __DIR__ . '/Support/lock-holder.php', MuRuntime::journal_path(), (string) $hold_ms ];
		if ( '' !== $append || $incident ) {
			$command[] = $append;
		}
		if ( $incident ) {
			$command[] = 'incident';
		}
		$process = proc_open( $command, [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $this->pipes );
		self::assertIsResource( $process );
		$this->process = $process;
		self::assertSame( "locked\n", fgets( $this->pipes[1] ), 'the child owns the lock' );
	}

	public function test_the_handler_waits_for_a_writer_and_keeps_what_the_writer_wrote(): void {
		$this->hold_lock( 500, 'cs-late' );
		MuRuntime::boot();

		$started  = microtime( true );
		$recorded = \Stonewright_Rescue::record_fatal( MuRuntime::fatal(), self::NOW );
		$waited   = microtime( true ) - $started;

		self::assertSame( 'cs-1', $recorded );
		self::assertGreaterThan( 0.3, $waited, 'the handler waited for the lock' );
		self::assertSame( 'incident', MuRuntime::journal_entry( 'cs-1' )['state'] );
		self::assertNotNull( MuRuntime::journal_entry( 'cs-late' ), 'the entry the other writer added is still there' );
		self::assertSame( 'armed', MuRuntime::journal_entry( 'cs-late' )['state'] );
	}

	public function test_an_incident_another_request_recorded_while_the_handler_waited_is_kept(): void {
		$this->hold_lock( 400, '', true );
		MuRuntime::boot();

		$recorded = \Stonewright_Rescue::record_fatal( MuRuntime::fatal( 'wp-content/themes/site-a/functions.php', E_ERROR, 12 ), self::NOW );

		self::assertSame( 'cs-1', $recorded );
		self::assertSame( 99, MuRuntime::journal_entry( 'cs-1' )['incident']['line'], 'the first incident of an entry stays' );
	}

	public function test_the_handler_gives_up_without_touching_the_journal_when_the_lock_stays_held(): void {
		$this->hold_lock( \Stonewright_Rescue::LOCK_WAIT_MS + 1500 );
		MuRuntime::boot();
		$before = md5_file( MuRuntime::journal_path() );

		$recorded = \Stonewright_Rescue::record_fatal( MuRuntime::fatal(), self::NOW );

		self::assertNull( $recorded );
		self::assertSame( $before, md5_file( MuRuntime::journal_path() ) );
		self::assertSame( 'armed', MuRuntime::journal_entry( 'cs-1' )['state'] );
	}
}
