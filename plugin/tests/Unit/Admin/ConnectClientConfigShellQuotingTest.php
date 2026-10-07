<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\ClientCatalog;
use Stonewright\WpMcp\Admin\ConnectClientConfig;

/**
 * The generated `claude mcp add` commands quote every value as one POSIX single-quoted
 * word, with a quoting routine of their own: it works on hosts that disable
 * escapeshellarg() and on a server whose platform is not the user's.
 *
 * @covers \Stonewright\WpMcp\Admin\ConnectClientConfig
 */
final class ConnectClientConfigShellQuotingTest extends TestCase {

	private const NASTY_USER     = "user' ; \$(noop)";
	private const NASTY_PASSWORD = "pa ss\"w'o\$HOME`noop`\\d;&|<>*\nline2";

	protected function setUp(): void {
		ClientCatalog::reset_for_tests();
		$GLOBALS['stonewright_test_options'] = [ 'stonewright_mcp_surface' => 'essential' ];
	}

	protected function tearDown(): void {
		ClientCatalog::reset_for_tests();
		$GLOBALS['stonewright_test_options'] = [];
	}

	private static function command( string $user, string $password, string $transport = 'stdio' ): string {
		$result = ConnectClientConfig::snippet_for( 'claude-code', $user, $password, $transport );
		self::assertIsArray( $result );
		return (string) $result['command'];
	}

	public function test_an_apostrophe_closes_and_reopens_the_quote(): void {
		$command = self::command( self::NASTY_USER, 'x' );

		self::assertStringContainsString( "--env STONEWRIGHT_WP_USERNAME='user'\\'' ; \$(noop)'", $command );
		self::assertStringContainsString( '--env STONEWRIGHT_WP_APP_PASSWORD=\'x\'', $command );
	}

	public function test_shell_metacharacters_stay_inside_single_quotes(): void {
		$command = self::command( 'admin', self::NASTY_PASSWORD );

		$quoted = "'" . str_replace( "'", "'\\''", self::NASTY_PASSWORD ) . "'";
		self::assertStringContainsString( '--env STONEWRIGHT_WP_APP_PASSWORD=' . $quoted, $command );
		self::assertSame( 0, substr_count( str_replace( $quoted, '', $command ), '`' ), 'No backtick is left outside the quotes.' );
	}

	public function test_a_missing_password_shows_the_placeholder_as_one_word(): void {
		self::assertStringContainsString( "--env STONEWRIGHT_WP_APP_PASSWORD='<your-application-password>'", self::command( 'admin', '' ) );
	}

	public function test_a_real_shell_reads_every_value_back_as_one_argument_and_runs_nothing(): void {
		$commands = [
			'stdio' => self::command( self::NASTY_USER, self::NASTY_PASSWORD ),
			'http'  => self::command( self::NASTY_USER, self::NASTY_PASSWORD, 'http' ),
		];
		$run = self::run_as_arguments( [ 'bash', 'sh' ], $commands );
		if ( null === $run ) {
			self::markTestSkipped( 'No POSIX shell is available.' );
		}
		[ $arguments, $errors ] = $run;

		self::assertSame( '', $errors, 'Nothing in the commands is executed.' );
		self::assertSame( [ 'mcp', 'add' ], array_slice( $arguments['stdio'], 0, 2 ) );
		self::assertContains( 'STONEWRIGHT_WP_USERNAME=' . self::NASTY_USER, $arguments['stdio'] );
		self::assertContains( sprintf( 'STONEWRIGHT_WP_APP_PASSWORD=%s', self::NASTY_PASSWORD ), $arguments['stdio'] );
		self::assertContains( 'STONEWRIGHT_WP_URL=https://example.test/', $arguments['stdio'] );
		self::assertSame( [ 'mcp', 'add', '--transport', 'http' ], array_slice( $arguments['http'], 0, 4 ) );
		self::assertContains( 'Authorization: Basic ' . base64_encode( self::NASTY_USER . ':' . self::NASTY_PASSWORD ), $arguments['http'] );
		self::assertContains( 'https://example.test/wp-json/mcp/stonewright', $arguments['http'] );
	}

	public function test_the_snippets_render_with_escapeshellarg_disabled(): void {
		if ( ! function_exists( 'proc_open' ) ) {
			self::markTestSkipped( 'proc_open is not available.' );
		}
		$script = tempnam( sys_get_temp_dir(), 'sw-shellarg-' );
		self::assertIsString( $script );
		file_put_contents(
			$script,
			'<?php require ' . var_export( dirname( __DIR__, 2 ) . '/bootstrap.php', true ) . ';'
			. '$GLOBALS["stonewright_test_options"] = [ "stonewright_mcp_surface" => "essential" ];'
			. 'echo json_encode( [ "available" => function_exists( "escapeshellarg" ),'
			. ' "stdio" => \Stonewright\WpMcp\Admin\ConnectClientConfig::snippet_for( "claude-code", ' . var_export( self::NASTY_USER, true ) . ', ' . var_export( self::NASTY_PASSWORD, true ) . ' ),'
			. ' "http" => \Stonewright\WpMcp\Admin\ConnectClientConfig::snippet_for( "claude-code", ' . var_export( self::NASTY_USER, true ) . ', "x", "http" ) ] );'
		);
		try {
			$process = proc_open( [ PHP_BINARY, '-d', 'disable_functions=escapeshellarg', $script ], [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
			self::assertIsResource( $process );
			$output = (string) stream_get_contents( $pipes[1] );
			$errors = (string) stream_get_contents( $pipes[2] );
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			proc_close( $process );
		} finally {
			@unlink( $script );
		}

		$decoded = json_decode( $output, true );
		self::assertIsArray( $decoded, 'The snippet code must run without escapeshellarg(): ' . $errors . $output );
		self::assertFalse( $decoded['available'], 'The child process really has escapeshellarg() disabled.' );
		self::assertSame( self::command( self::NASTY_USER, self::NASTY_PASSWORD ), $decoded['stdio']['command'] );
		self::assertSame( self::command( self::NASTY_USER, 'x', 'http' ), $decoded['http']['command'] );
	}

	/**
	 * Run each command with `claude` replaced by a function that prints its arguments, one
	 * NUL-terminated word each, and `noop` and `npx` by ones that complain when executed.
	 * The script goes in on stdin, so no host command-line quoting can touch it.
	 *
	 * @param list<string>          $shells   Shell programs to try, in order.
	 * @param array<string, string> $commands Label => command.
	 * @return array{0: array<string, list<string>>, 1: string}|null Arguments per label and stderr, or null without a shell.
	 */
	private static function run_as_arguments( array $shells, array $commands ): ?array {
		$print  = 'printf \'%s\0\' ';
		$script = 'claude() { ' . $print . '"$@"; }' . "\n"
			. 'noop() { echo EXECUTED >&2; }' . "\n"
			. 'npx() { echo EXECUTED >&2; }' . "\n";
		foreach ( $commands as $label => $command ) {
			$script .= $command . "\n" . $print . "'--end-of-" . $label . "--'\n";
		}
		foreach ( $shells as $shell ) {
			$process = @proc_open( [ $shell ], [ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
			if ( ! is_resource( $process ) ) {
				continue;
			}
			fwrite( $pipes[0], $script );
			fclose( $pipes[0] );
			$output = (string) stream_get_contents( $pipes[1] );
			$errors = (string) stream_get_contents( $pipes[2] );
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			$exit = proc_close( $process );
			if ( 127 === $exit ) {
				continue;
			}
			$words  = explode( chr( 0 ), $output );
			array_pop( $words );
			$result = [];
			$batch  = [];
			foreach ( $words as $word ) {
				if ( 1 === preg_match( '/^--end-of-([a-z]+)--$/D', $word, $matches ) ) {
					$result[ $matches[1] ] = $batch;
					$batch = [];
					continue;
				}
				$batch[] = $word;
			}
			return [ $result, $errors ];
		}
		return null;
	}
}
