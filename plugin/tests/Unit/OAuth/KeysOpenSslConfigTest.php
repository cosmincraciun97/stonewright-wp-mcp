<?php
/**
 * SPDX-FileCopyrightText: 2026 Stonewright contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\OAuth;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\OAuth\Keys;

/**
 * Regression coverage for activation on PHP builds that cannot find
 * openssl.cnf (Windows stacks such as Laragon or XAMPP).
 *
 * OpenSSL reads OPENSSL_CONF once per process, so each case runs Keys in a
 * child PHP process started with a missing configuration file.
 */
final class KeysOpenSslConfigTest extends TestCase {

	private string $scratch = '';

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options'] = [];
		$this->scratch = sys_get_temp_dir() . '/stonewright-keys-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->scratch, 0700, true );
	}

	protected function tearDown(): void {
		foreach ( (array) glob( $this->scratch . '/{,*/,*/*/}*', GLOB_BRACE ) as $path ) {
			if ( is_string( $path ) && is_file( $path ) ) {
				unlink( $path );
			}
		}
		foreach ( [ '/includes/OAuth', '/includes', '' ] as $dir ) {
			if ( is_dir( $this->scratch . $dir ) ) {
				rmdir( $this->scratch . $dir );
			}
		}
	}

	public function test_bundled_openssl_config_is_a_candidate(): void {
		$candidates = Keys::openssl_config_candidates();

		self::assertContains( realpath( STONEWRIGHT_DIR . 'data/openssl/openssl.cnf' ), array_map( 'realpath', $candidates ) );
	}

	public function test_keys_generate_when_the_process_openssl_config_is_missing(): void {
		$result = $this->run_child( STONEWRIGHT_DIR . 'includes/OAuth/Keys.php' );

		self::assertTrue( $result['ensured'], (string) $result['error'] );
		self::assertSame( '', $result['error'] );
		self::assertStringContainsString( 'BEGIN PRIVATE KEY', $result['private'] );
	}

	public function test_activation_path_records_the_openssl_error_instead_of_throwing(): void {
		// A copy without the bundled config reproduces a host where every
		// fallback is missing.
		mkdir( $this->scratch . '/includes/OAuth', 0700, true );
		copy( STONEWRIGHT_DIR . 'includes/OAuth/Keys.php', $this->scratch . '/includes/OAuth/Keys.php' );

		$result = $this->run_child( $this->scratch . '/includes/OAuth/Keys.php' );

		self::assertFalse( $result['ensured'] );
		self::assertSame( '', $result['private'] );
		self::assertStringStartsWith( 'openssl_pkey_new failed:', $result['error'] );
		self::assertStringContainsString( 'no such file', strtolower( $result['error'] ) );
	}

	/**
	 * @return array{ensured:bool,error:string,private:string}
	 */
	private function run_child( string $keys_file ): array {
		$script = $this->scratch . '/child.php';
		file_put_contents(
			$script,
			'<?php
			define( "ABSPATH", __DIR__ . "/" );
			$GLOBALS["o"] = [];
			function get_option( $k, $d = false ) { return $GLOBALS["o"][ $k ] ?? $d; }
			function add_option( $k, $v = "", $x = "", $a = true ) { if ( isset( $GLOBALS["o"][ $k ] ) ) { return false; } $GLOBALS["o"][ $k ] = $v; return true; }
			function update_option( $k, $v, $a = null ) { $GLOBALS["o"][ $k ] = $v; return true; }
			function delete_option( $k ) { unset( $GLOBALS["o"][ $k ] ); return true; }
			require ' . var_export( STONEWRIGHT_DIR . 'vendor/autoload.php', true ) . ';
			require ' . var_export( $keys_file, true ) . ';
			$ok = \Stonewright\WpMcp\OAuth\Keys::ensure();
			echo json_encode( [
				"ensured" => $ok,
				"error"   => (string) ( $GLOBALS["o"]["stonewright_oauth_key_error"] ?? "" ),
				"private" => (string) ( $GLOBALS["o"]["stonewright_oauth_private_key"] ?? "" ),
			] );'
		);

		$env                 = getenv();
		$env['OPENSSL_CONF'] = $this->scratch . '/missing/openssl.cnf';
		$process             = proc_open( [ PHP_BINARY, $script ], [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes, null, $env );
		self::assertIsResource( $process );
		$stdout = (string) stream_get_contents( $pipes[1] );
		$stderr = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		proc_close( $process );

		$decoded = json_decode( $stdout, true );
		self::assertIsArray( $decoded, 'Child PHP failed: ' . $stderr . $stdout );

		return [
			'ensured' => (bool) $decoded['ensured'],
			'error'   => (string) $decoded['error'],
			'private' => (string) $decoded['private'],
		];
	}
}
