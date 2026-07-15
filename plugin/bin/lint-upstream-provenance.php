<?php
/**
 * Validate the upstream reuse ledger and imported source headers.
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

// Standalone CLI script variables intentionally live in file scope.
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited

$root        = dirname( __DIR__, 2 );
$ledger_path = $root . '/docs/upstream-code-reuse.md';
$ledger      = file_get_contents( $ledger_path );

if ( false === $ledger ) {
	fwrite( STDERR, "Cannot read docs/upstream-code-reuse.md\n" );
	exit( 1 );
}

$errors = [];
$rows   = [];
// getenv() returns false when unset; cast after the fallback chain so rtrim always gets a string.
$upstream_root = rtrim(
	(string) ( getenv( 'STONEWRIGHT_UPSTREAM_ROOT' ) ?: getenv( 'STONEWRIGHT_NOVAMIRA_ROOT' ) ?: '' ),
	'/\\'
);

foreach ( preg_split( '/\R/', $ledger ) ?: [] as $line ) {
	if ( ! preg_match( '/^\| `([^`]+)` \| `([a-f0-9]{64})` \| `([^`]+)` \| ([^|]+) \| ([^|]+) \|/', $line, $matches ) ) {
		continue;
	}

	$rows[] = [
		'source'      => $matches[1],
		'hash'        => $matches[2],
		'destination' => $matches[3],
		'reuse'       => trim( $matches[4] ),
		'license'     => trim( $matches[5] ),
	];
}

if ( [] === $rows ) {
	$errors[] = 'Reuse ledger has no machine-readable import rows.';
}

$seen_destinations = [];
foreach ( $rows as $row ) {
	$destination = $row['destination'];
	if ( isset( $seen_destinations[ $destination ] ) ) {
		$errors[] = "Duplicate destination in ledger: {$destination}";
		continue;
	}
	$seen_destinations[ $destination ] = true;

	if ( str_starts_with( $destination, 'companion/' ) && str_contains( $row['license'], 'AGPL' ) ) {
		$errors[] = "AGPL-derived source may not enter the MIT companion: {$destination}";
	}

	$path = $root . '/' . $destination;
	if ( ! is_file( $path ) ) {
		$errors[] = "Ledger destination is missing: {$destination}";
		continue;
	}

	$contents = file_get_contents( $path );
	if ( false === $contents ) {
		$errors[] = "Cannot read ledger destination: {$destination}";
		continue;
	}

	if ( ! str_contains( $contents, "Source SHA-256: {$row['hash']}" ) ) {
		$errors[] = "Source hash header mismatch: {$destination}";
	}
	if ( ! str_contains( $contents, "Derived from {$row['source']}" ) ) {
		$errors[] = "Source path header mismatch: {$destination}";
	}
	if ( str_contains( $row['license'], 'AGPL' ) && ! str_contains( $contents, 'SPDX-License-Identifier: AGPL-3.0-or-later' ) ) {
		$errors[] = "AGPL SPDX header missing: {$destination}";
	}
	if ( ! str_contains( $contents, 'SPDX-FileCopyrightText:' ) ) {
		$errors[] = "Copyright header missing: {$destination}";
	}
	if ( preg_match( '#(?:[A-Z]:[\\\\/]|/Users/)[^\r\n]+#', $contents ) ) {
		$errors[] = "Private absolute path found in imported runtime source: {$destination}";
	}
	if ( '' !== $upstream_root ) {
		$source_path = $upstream_root . '/' . $row['source'];
		if ( ! is_file( $source_path ) ) {
			$errors[] = "Configured upstream source is missing: {$row['source']}";
		} elseif ( hash_file( 'sha256', $source_path ) !== $row['hash'] ) {
			$errors[] = "Configured upstream source hash changed: {$row['source']}";
		}
	}
}

$plugin_composer = json_decode( (string) file_get_contents( $root . '/plugin/composer.json' ), true );
$visual_package  = json_decode( (string) file_get_contents( $root . '/visual/package.json' ), true );
$companion       = json_decode( (string) file_get_contents( $root . '/companion/package.json' ), true );

if ( 'AGPL-3.0-or-later' !== ( $plugin_composer['license'] ?? null ) ) {
	$errors[] = 'Plugin Composer license must be AGPL-3.0-or-later.';
}
if ( 'AGPL-3.0-or-later' !== ( $visual_package['license'] ?? null ) ) {
	$errors[] = 'Visual package license must be AGPL-3.0-or-later.';
}
if ( 'MIT' !== ( $companion['license'] ?? null ) ) {
	$errors[] = 'Companion package license must remain MIT.';
}

$plugin_header = (string) file_get_contents( $root . '/plugin/stonewright.php' );
if ( ! str_contains( $plugin_header, 'License: AGPL-3.0-or-later' ) ) {
	$errors[] = 'Plugin header license must be AGPL-3.0-or-later.';
}

if ( [] !== $errors ) {
	foreach ( $errors as $error ) {
		fwrite( STDERR, "ERROR: {$error}\n" );
	}
	exit( 1 );
}

fwrite( STDOUT, sprintf( "Provenance OK: %d imported files verified%s.\n", count( $rows ), '' !== $upstream_root ? ' against the source snapshot' : '' ) );
