<?php
/**
 * Validate the component licenses and the companion license boundary.
 *
 * The plugin and the Visual workspace are GPL-2.0-or-later: their Composer and package metadata,
 * the plugin header and every SPDX identifier in their source files must say so. The companion is
 * MIT: its package metadata must say so and no companion file may carry a copyleft license
 * identifier or notice.
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

// Standalone CLI script variables intentionally live in file scope.
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited

const STONEWRIGHT_LINT_SKIPPED_DIRECTORIES = [ 'node_modules', 'vendor', 'dist', 'build', 'coverage', '.git' ];

/**
 * Lists readable source files below one component folder, skipping dependency and build folders.
 *
 * @param string       $root       Repository root.
 * @param string       $directory  Component folder relative to the root.
 * @param list<string> $extensions Lower-case extensions to include; an empty string matches files without one.
 * @param list<string> $skip_paths Relative path prefixes to leave out.
 * @return list<string> Paths relative to the root, with forward slashes.
 */
function stonewright_lint_files( string $root, string $directory, array $extensions, array $skip_paths = [] ): array {
	$base = $root . '/' . $directory;
	if ( ! is_dir( $base ) ) {
		return [];
	}

	$iterator = new RecursiveIteratorIterator(
		new RecursiveCallbackFilterIterator(
			new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ),
			static fn( SplFileInfo $entry ): bool => ! ( $entry->isDir() && in_array( $entry->getFilename(), STONEWRIGHT_LINT_SKIPPED_DIRECTORIES, true ) )
		)
	);

	$files = [];
	foreach ( $iterator as $entry ) {
		if ( ! $entry instanceof SplFileInfo || ! $entry->isFile() || 'package-lock.json' === $entry->getFilename() ) {
			continue;
		}
		if ( ! in_array( strtolower( $entry->getExtension() ), $extensions, true ) ) {
			continue;
		}
		$relative = $directory . '/' . str_replace( '\\', '/', substr( $entry->getPathname(), strlen( $base ) + 1 ) );
		foreach ( $skip_paths as $skip_path ) {
			if ( str_starts_with( $relative, $skip_path ) ) {
				continue 2;
			}
		}
		$files[] = $relative;
	}
	sort( $files );

	return $files;
}

/**
 * Lists the license expressions named by SPDX license tags in a text.
 *
 * @return list<string>
 */
function stonewright_lint_spdx_expressions( string $contents ): array {
	$identifier = '[A-Za-z0-9][A-Za-z0-9.+-]*';
	$pattern    = '/SPDX-License-Identifier:[ \t(]*(' . $identifier . '(?:[ \t()]+(?:AND|OR|WITH)[ \t(]+' . $identifier . ')*)/';
	if ( ! preg_match_all( $pattern, $contents, $matches ) ) {
		return [];
	}

	return array_map( static fn( string $expression ): string => rtrim( $expression, ". \t" ), $matches[1] );
}

/**
 * Reads a JSON file into an array.
 *
 * @return array<string, mixed>
 */
function stonewright_lint_json( string $path ): array {
	$decoded = json_decode( (string) file_get_contents( $path ), true );

	return is_array( $decoded ) ? $decoded : [];
}

$root   = dirname( __DIR__, 2 );
$errors = [];

$plugin_composer = stonewright_lint_json( $root . '/plugin/composer.json' );
$visual_package  = stonewright_lint_json( $root . '/visual/package.json' );
$companion       = stonewright_lint_json( $root . '/companion/package.json' );

if ( 'GPL-2.0-or-later' !== ( $plugin_composer['license'] ?? null ) ) {
	$errors[] = 'Plugin Composer license must be GPL-2.0-or-later.';
}
if ( 'GPL-2.0-or-later' !== ( $visual_package['license'] ?? null ) ) {
	$errors[] = 'Visual package license must be GPL-2.0-or-later.';
}
if ( 'MIT' !== ( $companion['license'] ?? null ) ) {
	$errors[] = 'Companion package license must remain MIT.';
}

$plugin_header = (string) file_get_contents( $root . '/plugin/stonewright.php' );
if ( ! str_contains( $plugin_header, 'License: GPL-2.0-or-later' ) ) {
	$errors[] = 'Plugin header license must be GPL-2.0-or-later.';
}
if ( ! str_contains( $plugin_header, 'License URI: https://www.gnu.org/licenses/gpl-2.0.html' ) ) {
	$errors[] = 'Plugin header license URI must be https://www.gnu.org/licenses/gpl-2.0.html.';
}

// Every SPDX identifier in the plugin and Visual sources names the component license.
$gpl_files = array_merge(
	stonewright_lint_files( $root, 'plugin', [ 'php', 'js', 'mjs', 'cjs', 'ts', 'tsx', 'css', 'html' ], [ 'plugin/assets/visual/' ] ),
	stonewright_lint_files( $root, 'visual', [ 'ts', 'tsx', 'js', 'mjs', 'cjs', 'css', 'md' ] )
);
foreach ( $gpl_files as $relative ) {
	foreach ( stonewright_lint_spdx_expressions( (string) file_get_contents( $root . '/' . $relative ) ) as $expression ) {
		if ( 'GPL-2.0-or-later' !== $expression ) {
			$errors[] = "SPDX identifier must be GPL-2.0-or-later, found {$expression}: {$relative}";
		}
	}
}

// Copyleft-licensed code may not enter the MIT companion.
$copyleft_identifier = '/^(?:(?:A|L)?GPL|MPL|EPL|CDDL|EUPL|OSL|SSPL|CECILL|CC-BY-(?:NC-)?SA)(?:-|$)/i';
$copyleft_notice     = '/GNU\s+(?:Affero\s+|Lesser\s+|Library\s+)?General\s+Public\s+License/i';
$companion_files     = stonewright_lint_files(
	$root,
	'companion',
	[ '', 'ts', 'tsx', 'js', 'mjs', 'cjs', 'json', 'md', 'cs', 'ps1', 'sh', 'yml', 'yaml', 'txt', 'html', 'css', 'example' ]
);
foreach ( $companion_files as $relative ) {
	$contents = (string) file_get_contents( $root . '/' . $relative );
	foreach ( stonewright_lint_spdx_expressions( $contents ) as $expression ) {
		foreach ( preg_split( '/[\s()]+/', $expression, -1, PREG_SPLIT_NO_EMPTY ) ?: [] as $token ) {
			if ( preg_match( $copyleft_identifier, $token ) ) {
				$errors[] = "Copyleft license identifier {$token} may not enter the MIT companion: {$relative}";
			}
		}
	}
	if ( preg_match( $copyleft_notice, $contents ) ) {
		$errors[] = "Copyleft license notice may not enter the MIT companion: {$relative}";
	}
}

if ( [] !== $errors ) {
	foreach ( $errors as $error ) {
		fwrite( STDERR, "ERROR: {$error}\n" );
	}
	exit( 1 );
}

fwrite(
	STDOUT,
	sprintf(
		"License lint OK: plugin and Visual GPL-2.0-or-later (%d source files), companion MIT (%d files without copyleft code).\n",
		count( $gpl_files ),
		count( $companion_files )
	)
);
