<?php
/**
 * SPDX-FileCopyrightText: 2026 Stonewright contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZipArchive;

/** Exercise the activation helper's filesystem and child-process paths without a network. */
final class SmokePluginZipActivationTest extends TestCase {

	private string $scratch = '';

	protected function setUp(): void {
		foreach ( [ 'zip', 'openssl', 'pdo_sqlite' ] as $extension ) {
			if ( ! extension_loaded( $extension ) ) {
				self::markTestSkipped( $extension . ' is required by the activation smoke helper.' );
			}
		}
		$this->scratch = sys_get_temp_dir() . '/stonewright-smoke-boundary-' . bin2hex( random_bytes( 8 ) );
		self::assertTrue( mkdir( $this->scratch, 0700 ) );
	}

	protected function tearDown(): void {
		if ( '' === $this->scratch ) {
			return;
		}
		foreach (
			[
				'/workdir/sentinel.txt',
				'/plugin.zip',
				'/wordpress.zip',
				'/sqlite.zip',
				'/wp-cli.php',
				'/.smoke/wordpress.zip',
				'/.smoke/sqlite.zip',
				'/.smoke/wp-cli.phar',
				'/.smoke/site/wp-content/db.php',
				'/.smoke/site/wp-content/plugins/sqlite-database-integration/db.copy',
			] as $file
		) {
			if ( is_file( $this->scratch . $file ) ) {
				unlink( $this->scratch . $file );
			}
		}
		foreach ( [ '/.smoke/site/wp-content/plugins/sqlite-database-integration', '/.smoke/site/wp-content/plugins', '/.smoke/site/wp-content', '/.smoke/site', '/.smoke', '/workdir', '' ] as $directory ) {
			if ( is_dir( $this->scratch . $directory ) ) {
				rmdir( $this->scratch . $directory );
			}
		}
	}

	public function test_existing_workdir_is_rejected_without_removing_files(): void {
		$workdir = $this->scratch . '/workdir';
		self::assertTrue( mkdir( $workdir, 0700 ) );
		file_put_contents( $workdir . '/sentinel.txt', 'synthetic-existing-content' );
		$zip_path = $this->scratch . '/plugin.zip';
		$archive = new ZipArchive();
		self::assertTrue( $archive->open( $zip_path, ZipArchive::CREATE ) );
		$archive->addFromString( 'stonewright/data/openssl/openssl.cnf', '[req]' );
		$archive->close();

		$command = $this->helper_command();
		$command[] = '--zip=' . $zip_path;
		$command[] = '--workdir=' . $workdir;
		$command[] = '--wp-zip=' . $this->scratch . '/missing-wordpress.zip';
		$process = proc_open( $command, [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
		self::assertIsResource( $process );
		stream_get_contents( $pipes[1] );
		$stderr = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$exit_code = proc_close( $process );

		self::assertSame( 1, $exit_code );
		self::assertFileExists( $workdir . '/sentinel.txt' );
		self::assertSame( 'synthetic-existing-content', file_get_contents( $workdir . '/sentinel.txt' ) );
		self::assertStringContainsString( 'Working directory already exists', $stderr );
	}

	public function test_relative_workdir_is_resolved_before_child_processes_run(): void {
		$this->write_archive( '/plugin.zip', [ 'stonewright/data/openssl/openssl.cnf' => '[req]' ] );
		$this->write_archive( '/wordpress.zip', [ 'wordpress/wp-content/plugins/' => '' ] );
		$this->write_archive( '/sqlite.zip', [ 'sqlite-database-integration/db.copy' => '{SQLITE_IMPLEMENTATION_FOLDER_PATH}' ] );
		file_put_contents(
			$this->scratch . '/wp-cli.php',
			'<?php
			foreach ( $argv as $arg ) {
				if ( str_starts_with( $arg, "--path=" ) && ! is_dir( substr( $arg, 7 ) ) ) {
					fwrite( STDERR, "Synthetic CLI cannot resolve site path.\n" );
					exit( 2 );
				}
			}
			if ( in_array( "stonewright_oauth_private_key", $argv, true ) ) {
				echo "SYNTHETIC PRIVATE KEY MARKER";
			}'
		);
		$command = $this->helper_command();
		$command[] = '--zip=' . $this->scratch . '/plugin.zip';
		$command[] = '--workdir=.smoke';
		$command[] = '--wp-zip=' . $this->scratch . '/wordpress.zip';
		$command[] = '--sqlite-zip=' . $this->scratch . '/sqlite.zip';
		$command[] = '--wp-cli=' . $this->scratch . '/wp-cli.php';
		$process = proc_open( $command, [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes, $this->scratch );
		self::assertIsResource( $process );
		$stdout = (string) stream_get_contents( $pipes[1] );
		$stderr = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );

		self::assertSame( 0, proc_close( $process ), $stdout . $stderr );
		self::assertStringContainsString( 'Plugin ZIP activation smoke passed.', $stdout );
		self::assertDirectoryDoesNotExist( $this->scratch . '/.smoke' );
	}

	/** @param array<string,string> $entries Synthetic archive entries. */
	private function write_archive( string $name, array $entries ): void {
		$archive = new ZipArchive();
		self::assertTrue( $archive->open( $this->scratch . $name, ZipArchive::CREATE ) );
		foreach ( $entries as $path => $content ) {
			if ( str_ends_with( $path, '/' ) ) {
				$archive->addEmptyDir( $path );
			} else {
				$archive->addFromString( $path, $content );
			}
		}
		$archive->close();
	}

	/** @return list<string> Child PHP with the smoke helper's required modules. */
	private function helper_command(): array {
		$extension_dir = (string) ini_get( 'extension_dir' );
		if ( ! str_starts_with( $extension_dir, '/' ) && ! str_starts_with( $extension_dir, '\\' ) && ':' !== substr( $extension_dir, 1, 1 ) ) {
			$extension_dir = dirname( PHP_BINARY ) . '/' . $extension_dir;
		}
		$command = [ PHP_BINARY, '-n', '-d', 'extension_dir=' . $extension_dir ];
		foreach ( [ 'pdo', 'pdo_sqlite', 'openssl', 'zip' ] as $extension ) {
			$prefix = 'dll' === PHP_SHLIB_SUFFIX ? 'php_' : '';
			$module = rtrim( $extension_dir, '/\\' ) . '/' . $prefix . $extension . '.' . PHP_SHLIB_SUFFIX;
			if ( is_file( $module ) ) {
				$command[] = '-d';
				$command[] = 'extension=' . $module;
			}
		}
		$command[] = dirname( STONEWRIGHT_DIR ) . '/scripts/smoke-plugin-zip-activation.php';
		return $command;
	}
}
