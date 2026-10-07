<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\RescueRuntime;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Tests\Unit\RescueRuntime\Support\MuRuntime;

/**
 * Real fatal errors, in a child PHP process that loads the real MU-plugin: the shutdown
 * handler records the incident, and neither raises an error of its own nor changes what the
 * request prints.
 *
 * The child runs on PHP_BINARY and on every binary listed in STONEWRIGHT_TEST_PHP_BINARIES
 * (separated like PATH), so the MU-plugin can be exercised on PHP 7.4 while the suite runs on 8.x.
 *
 * @coversNothing The MU-plugin lives outside includes/.
 */
final class RescueFatalProcessTest extends TestCase {

	private const NOW = 2_000_000_000;

	private string $root = '';

	protected function setUp(): void {
		if ( ! function_exists( 'proc_open' ) ) {
			self::markTestSkipped( 'proc_open is not available.' );
		}
		$this->root = sys_get_temp_dir() . '/sw-fatal-' . bin2hex( random_bytes( 5 ) );
		mkdir( $this->root . '/wp-content/themes/site-a', 0777, true );
		mkdir( $this->root . '/wp-content/plugins/stonewright', 0777, true );
		mkdir( $this->root . '/wp-content/uploads/stonewright-state', 0777, true );
		file_put_contents( $this->root . '/wp-content/plugins/stonewright/stonewright.php', "<?php\n" );
	}

	protected function tearDown(): void {
		MuRuntime::remove_tree( $this->root );
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function binaries_and_scenarios(): array {
		$binaries = [ PHP_BINARY ];
		foreach ( explode( PATH_SEPARATOR, (string) getenv( 'STONEWRIGHT_TEST_PHP_BINARIES' ) ) as $extra ) {
			if ( '' !== trim( $extra ) && is_file( trim( $extra ) ) && ! in_array( trim( $extra ), $binaries, true ) ) {
				$binaries[] = trim( $extra );
			}
		}
		$cases = [];
		foreach ( $binaries as $binary ) {
			$label = basename( dirname( $binary ) );
			foreach ( [ 'user_error', 'undefined_function', 'uncaught_exception', 'parse_error', 'memory' ] as $scenario ) {
				$cases[ $label . ' / ' . $scenario ] = [ $binary, $scenario ];
			}
		}
		return $cases;
	}

	private function theme_file( string $scenario ): string {
		$code = [
			'user_error'         => "<?php\ntrigger_error( 'Synthetic fatal', E_USER_ERROR );\n",
			'undefined_function' => "<?php\nsite_a_boot();\n",
			'uncaught_exception' => "<?php\nthrow new RuntimeException( 'Synthetic exception' );\n",
			'parse_error'        => "<?php\nif ( (\n",
			'memory'             => "<?php\nini_set( 'memory_limit', '24M' );\n\$held = array();\nwhile ( true ) {\n\t\$held[] = str_repeat( 'x', 1048576 );\n}\n",
			'extra_notice_first' => "<?php\ntrigger_error( 'Synthetic fatal', E_USER_ERROR );\n",
			'warning_only'       => "<?php\ntrigger_error( 'Just a warning', E_USER_WARNING );\n",
		][ $scenario ];
		$path = $this->root . '/wp-content/themes/site-a/functions.php';
		file_put_contents( $path, $code );
		return $path;
	}

	private function arm_journal(): string {
		$journal = $this->root . '/wp-content/uploads/stonewright-state/' . MuRuntime::JOURNAL;
		$entry   = MuRuntime::entry( 'cs-1', [ 'armed_at' => time() - 30 ] );
		file_put_contents( $journal, json_encode( [ 'version' => 1, 'updated_at' => 1000, 'entries' => [ $entry ] ], JSON_UNESCAPED_SLASHES ) );
		return $journal;
	}

	/** @return array{0: int, 1: string, 2: string} Exit code, stdout, the PHP error log. */
	private function run_child( string $binary, string $scenario ): array {
		$log = $this->root . '/php-error.log';
		$env = array_merge(
			(array) getenv(),
			[
				'RUNNER_MU'      => MuRuntime::path(),
				'RUNNER_OPTIONS' => (string) json_encode(
					[
						'stonewright_rescue_plugin'       => MuRuntime::PLUGIN,
						'active_plugins'                  => [ MuRuntime::PLUGIN ],
						'stonewright_rescue_journal_file' => MuRuntime::JOURNAL,
					]
				),
			]
		);
		$process = proc_open(
			[ $binary, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=' . $log, '-d', 'error_reporting=32767', '-d', 'html_errors=0', __DIR__ . '/Support/fatal-runner.php', $this->root, $scenario ],
			[ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ],
			$pipes,
			null,
			$env
		);
		self::assertIsResource( $process );
		$stdout = (string) stream_get_contents( $pipes[1] );
		$stderr = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$code = proc_close( $process );
		return [ $code, $stdout . $stderr, is_file( $log ) ? (string) file_get_contents( $log ) : '' ];
	}

	/** @dataProvider binaries_and_scenarios */
	public function test_a_real_fatal_error_in_a_touched_file_is_recorded( string $binary, string $scenario ): void {
		$this->theme_file( $scenario );
		$journal = $this->arm_journal();

		[ , $output, $log ] = $this->run_child( $binary, $scenario );

		$document = json_decode( (string) file_get_contents( $journal ), true );
		$entry    = $document['entries'][0];
		self::assertSame( 'incident', $entry['state'], $log . $output );
		self::assertSame( 'wp-content/themes/site-a/functions.php', $entry['incident']['file'] );
		self::assertSame( 'shutdown', $entry['incident']['source'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $entry['incident']['message_sha256'] );
		self::assertContains( $entry['incident']['type'], [ E_ERROR, E_PARSE, E_USER_ERROR ] );
		self::assertGreaterThan( 0, $entry['incident']['line'] );

		$problems = array_values(
			array_filter(
				preg_split( '/\R/', trim( $log ) ) ?: [],
				static fn ( string $line ): bool => '' !== $line && ! preg_match( '/PHP (Fatal|Parse) error/', $line ) && ! preg_match( '/^(Stack trace:|#\d+ |  thrown in)/', $line )
			)
		);
		self::assertSame( [], $problems, 'the handler raised no warning, notice or second fatal error: ' . $log );
		self::assertSame( 1, preg_match_all( '/PHP (Fatal|Parse) error/', $log ), 'exactly one fatal error was logged: ' . $log );
		self::assertSame( '', trim( $output ), 'the handler prints nothing' );
	}

	public function test_a_fatal_that_a_later_error_replaced_as_the_last_error_records_nothing(): void {
		$this->theme_file( 'extra_notice_first' );
		$journal = $this->arm_journal();
		$before  = md5_file( $journal );

		$this->run_child( PHP_BINARY, 'extra_notice_first' );

		self::assertSame( $before, md5_file( $journal ) );
	}

	public function test_a_warning_is_not_an_incident(): void {
		$this->theme_file( 'warning_only' );
		$journal = $this->arm_journal();
		$before  = md5_file( $journal );

		[ $code, $output ] = $this->run_child( PHP_BINARY, 'warning_only' );

		self::assertSame( 0, $code );
		self::assertStringContainsString( 'finished', $output );
		self::assertSame( $before, md5_file( $journal ) );
	}
}
