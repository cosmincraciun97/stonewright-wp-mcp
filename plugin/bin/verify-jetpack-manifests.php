<?php
/**
 * Verifies that every path emitted by Jetpack Autoloader exists in a package.
 *
 * Usage: php bin/verify-jetpack-manifests.php [plugin-root]
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

$root_argument = $argv[1] ?? dirname( __DIR__ );
$plugin_root   = realpath( $root_argument );

if ( false === $plugin_root || ! is_dir( $plugin_root ) ) {
	fwrite( STDERR, "Plugin root does not exist.\n" );
	exit( 2 );
}

$package_autoload = $plugin_root . '/vendor/autoload_packages.php';
$manifest_pattern = $plugin_root . '/vendor/composer/jetpack_autoload_*.php';
$manifests        = glob( $manifest_pattern ) ?: [];
$manifest_errors  = [];
$path_count       = 0;

if ( ! is_readable( $package_autoload ) ) {
	$manifest_errors[] = 'Missing vendor/autoload_packages.php.';
}

if ( [] === $manifests ) {
	$manifest_errors[] = 'No Jetpack Autoloader manifests found.';
}

/**
 * @param mixed  $value Manifest value.
 * @param string $key   Parent key.
 * @return list<string>
 */
function stonewright_manifest_paths( mixed $value, string $key = '' ): array {
	$paths = [];
	if ( 'path' === $key && is_string( $value ) ) {
		return [ $value ];
	}
	if ( 'path' === $key && is_array( $value ) ) {
		foreach ( $value as $path ) {
			if ( is_string( $path ) ) {
				$paths[] = $path;
			}
		}
		return $paths;
	}
	if ( is_array( $value ) ) {
		foreach ( $value as $child_key => $child ) {
			$paths = array_merge(
				$paths,
				stonewright_manifest_paths( $child, is_string( $child_key ) ? $child_key : '' )
			);
		}
	}
	return $paths;
}

foreach ( $manifests as $manifest ) {
	$data = require $manifest;
	if ( ! is_array( $data ) ) {
		$manifest_errors[] = basename( $manifest ) . ' did not return an array.';
		continue;
	}

	foreach ( stonewright_manifest_paths( $data ) as $manifest_path ) {
		++$path_count;
		if ( ! file_exists( $manifest_path ) ) {
			$manifest_errors[] = basename( $manifest ) . ' references a missing path.';
			continue;
		}

		$real_path = realpath( $manifest_path );
		if (
			false === $real_path
			|| (
				$real_path !== $plugin_root
				&& ! str_starts_with( $real_path, $plugin_root . DIRECTORY_SEPARATOR )
			)
		) {
			$manifest_errors[] = basename( $manifest ) . ' references a path outside the plugin package.';
		}
	}
}

if ( [] !== $manifest_errors ) {
	foreach ( array_unique( $manifest_errors ) as $manifest_error ) {
		fwrite( STDERR, $manifest_error . "\n" );
	}
	fwrite( STDERR, 'Jetpack manifest verification failed.' . "\n" );
	exit( 1 );
}

fwrite(
	STDOUT,
	sprintf(
		"Jetpack manifest verification OK (%d manifests, %d paths).\n",
		count( $manifests ),
		$path_count
	)
);
