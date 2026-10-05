<?php
/**
 * Activation smoke test for the built plugin ZIP.
 *
 * Installs WordPress on SQLite in a scratch directory, installs the exact
 * plugin ZIP through WP-CLI, and activates it with OPENSSL_CONF pointing at a
 * missing file. That reproduces PHP builds which cannot find openssl.cnf
 * (Windows stacks such as Laragon, XAMPP, or WAMP), where activation used to
 * die while generating the OAuth signing key.
 *
 * Runs on Linux, macOS, and Windows runners: it needs only PHP with the zip,
 * openssl, pdo_sqlite, and curl or allow_url_fopen.
 *
 * Usage:
 *   php scripts/smoke-plugin-zip-activation.php --zip=dist/stonewright.zip [--wp=7.1.2] [--workdir=.smoke]
 *
 * Offline runs can pass local archives instead of downloading them:
 *   --wp-zip=wordpress.zip --sqlite-zip=sqlite-database-integration.zip --wp-cli=wp-cli.phar
 *
 * A supplied --workdir must not already exist. Only the directory created by
 * this run is eligible for cleanup.
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

const STONEWRIGHT_SMOKE_MAX_ENTRY = 180;

$options = getopt( '', [ 'zip:', 'wp::', 'workdir::', 'wp-zip::', 'sqlite-zip::', 'wp-cli::' ] );
$zip     = isset( $options['zip'] ) ? (string) $options['zip'] : '';
$version = isset( $options['wp'] ) ? (string) $options['wp'] : '7.1.2';
$workdir = isset( $options['workdir'] ) ? (string) $options['workdir'] : sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'stonewright-zip-smoke-' . getmypid();

if ( '' === $zip || ! is_file( $zip ) ) {
	fail( 'Pass --zip=<path to stonewright plugin zip>.' );
}
$zip = (string) realpath( $zip );

foreach ( [ 'zip', 'openssl', 'pdo_sqlite' ] as $extension ) {
	if ( ! extension_loaded( $extension ) ) {
		fail( "PHP extension {$extension} is required." );
	}
}

check_archive( $zip );

if ( file_exists( $workdir ) || is_link( $workdir ) ) {
	fail( 'Working directory already exists; supply a new path.' );
}
if ( ! mkdir( $workdir, 0700, true ) ) {
	fail( 'Cannot create the smoke working directory.' );
}
$created_workdir = realpath( $workdir );
if ( false === $created_workdir ) {
	fail( 'Cannot resolve the smoke working directory.' );
}
// Child processes run inside the site, so their paths must stay absolute.
$workdir = $created_workdir;
$site = $workdir . '/site';

step( "Downloading WordPress {$version}" );
extract_zip( local_or_download( $options['wp-zip'] ?? null, "https://wordpress.org/wordpress-{$version}.zip", $workdir . '/wordpress.zip' ), $workdir );
if ( ! rename( $workdir . '/wordpress', $site ) ) {
	fail( 'Cannot move the WordPress files into place.' );
}

step( 'Installing the SQLite database drop-in' );
$plugins = $site . '/wp-content/plugins';
extract_zip( local_or_download( $options['sqlite-zip'] ?? null, 'https://downloads.wordpress.org/plugin/sqlite-database-integration.latest-stable.zip', $workdir . '/sqlite.zip' ), $plugins );
$sqlite = $plugins . '/sqlite-database-integration';
file_put_contents(
	$site . '/wp-content/db.php',
	str_replace( '{SQLITE_IMPLEMENTATION_FOLDER_PATH}', str_replace( '\\', '/', $sqlite ), (string) file_get_contents( $sqlite . '/db.copy' ) )
);

$cli = local_or_download( $options['wp-cli'] ?? null, 'https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar', $workdir . '/wp-cli.phar' );

step( 'Installing WordPress' );
wp( $cli, $site, [ 'config', 'create', '--dbname=wp', '--dbuser=wp', '--dbpass=wp', '--dbhost=localhost', '--skip-check' ] );
wp( $cli, $site, [ 'core', 'install', '--url=http://example.test', '--title=Smoke', '--admin_user=admin', '--admin_password=password', '--admin_email=admin@example.test', '--skip-email' ] );

step( 'Installing the plugin ZIP' );
wp( $cli, $site, [ 'plugin', 'install', $zip ] );

step( 'Activating with a missing OpenSSL configuration' );
$broken_openssl = [ 'OPENSSL_CONF' => $workdir . '/missing/openssl.cnf' ];
wp( $cli, $site, [ 'plugin', 'activate', 'stonewright' ], $broken_openssl );
wp( $cli, $site, [ 'plugin', 'is-active', 'stonewright' ] );

$key   = wp( $cli, $site, [ 'option', 'get', 'stonewright_oauth_private_key' ], $broken_openssl, true );
$error = wp( $cli, $site, [ 'option', 'get', 'stonewright_oauth_key_error' ], $broken_openssl, true );
if ( ! str_contains( $key['stdout'], 'PRIVATE KEY' ) ) {
	fail( 'Plugin activated but no OAuth key was generated: ' . trim( $error['stdout'] . $error['stderr'] ) );
}

step( 'Loading admin and REST with the plugin active' );
wp( $cli, $site, [ 'eval', '$r = rest_do_request( new WP_REST_Request( "GET", "/stonewright/v1" ) ); if ( 200 !== $r->get_status() ) { exit( 1 ); }', '--user=admin' ], $broken_openssl );

remove_tree( $workdir );
fwrite( STDOUT, "Plugin ZIP activation smoke passed.\n" );

/**
 * Fail on entries that would exceed Windows MAX_PATH once extracted under a
 * typical wp-content/upgrade temporary directory.
 */
function check_archive( string $zip ): void {
	$archive = new ZipArchive();
	if ( true !== $archive->open( $zip ) ) {
		fail( "Cannot open {$zip}." );
	}
	$longest = '';
	$has_cnf = false;
	for ( $i = 0; $i < $archive->numFiles; $i++ ) {
		$name = (string) $archive->getNameIndex( $i );
		if ( strlen( $name ) > strlen( $longest ) ) {
			$longest = $name;
		}
		$has_cnf = $has_cnf || 'stonewright/data/openssl/openssl.cnf' === $name;
	}
	$archive->close();
	if ( ! $has_cnf ) {
		fail( 'Plugin ZIP is missing stonewright/data/openssl/openssl.cnf.' );
	}
	if ( strlen( $longest ) > STONEWRIGHT_SMOKE_MAX_ENTRY ) {
		fail( sprintf( 'ZIP entry is %d characters, over the %d budget for Windows paths: %s', strlen( $longest ), STONEWRIGHT_SMOKE_MAX_ENTRY, $longest ) );
	}
	step( sprintf( 'Archive OK, longest entry %d characters', strlen( $longest ) ) );
}

/**
 * @param list<string>          $args
 * @param array<string, string> $env
 * @return array{stdout:string,stderr:string}
 */
function wp( string $cli, string $site, array $args, array $env = [], bool $allow_failure = false ): array {
	$command = array_merge( [ PHP_BINARY, $cli, '--path=' . $site, '--allow-root' ], $args );
	$process = proc_open( $command, [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes, $site, array_merge( getenv(), $env ) );
	if ( ! is_resource( $process ) ) {
		fail( 'Cannot start WP-CLI.' );
	}
	$stdout = (string) stream_get_contents( $pipes[1] );
	$stderr = (string) stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	$code = proc_close( $process );
	if ( 0 !== $code && ! $allow_failure ) {
		fail( 'wp ' . implode( ' ', $args ) . " exited {$code}\n" . $stdout . $stderr );
	}
	return [ 'stdout' => $stdout, 'stderr' => $stderr ];
}

function local_or_download( mixed $local, string $url, string $target ): string {
	if ( is_string( $local ) && '' !== $local ) {
		if ( ! is_file( $local ) ) {
			fail( "Missing local archive {$local}." );
		}
		copy( $local, $target );
		return $target;
	}
	return download( $url, $target );
}

function download( string $url, string $target ): string {
	$body = false;
	if ( function_exists( 'curl_init' ) ) {
		$handle = curl_init( $url );
		curl_setopt_array( $handle, [ CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_FAILONERROR => true, CURLOPT_TIMEOUT => 300 ] );
		$body = curl_exec( $handle );
	} else {
		$body = @file_get_contents( $url );
	}
	if ( ! is_string( $body ) || '' === $body ) {
		fail( "Download failed: {$url}" );
	}
	file_put_contents( $target, $body );
	return $target;
}

function extract_zip( string $zip, string $destination ): void {
	$archive = new ZipArchive();
	if ( true !== $archive->open( $zip ) || ! $archive->extractTo( $destination ) ) {
		fail( "Cannot extract {$zip}." );
	}
	$archive->close();
}

function remove_tree( string $path ): void {
	if ( ! file_exists( $path ) ) {
		return;
	}
	$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $items as $item ) {
		$item->isDir() && ! $item->isLink() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
	}
	rmdir( $path );
}

function step( string $message ): void {
	fwrite( STDOUT, "==> {$message}\n" );
}

function fail( string $message ): never {
	fwrite( STDERR, "Plugin ZIP activation smoke failed: {$message}\n" );
	exit( 1 );
}
