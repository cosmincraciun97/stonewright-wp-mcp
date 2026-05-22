<?php
declare( strict_types=1 );

/**
 * Stonewright security audit script.
 *
 * Scans plugin/includes/ for policy violations and exits non-zero if any are found.
 *
 * Usage:
 *   php plugin/bin/security-audit.php [--quiet]
 *
 * Composer shortcut:
 *   cd plugin && composer security:audit
 *
 * Exit codes:
 *   0  — No findings. Safe to release.
 *   1  — One or more findings. Fix before release.
 */

$quiet   = in_array( '--quiet', $argv ?? [], true );
$base    = dirname( __DIR__ );
$src_dir = $base . '/includes';

if ( ! is_dir( $src_dir ) ) {
	fwrite( STDERR, "[stonewright-audit] ERROR: includes/ not found at {$src_dir}\n" );
	exit( 1 );
}

// ---------------------------------------------------------------------------
// Helpers.
// ---------------------------------------------------------------------------

/** @return array<int, string> Absolute file paths. */
function sw_collect_php_files( string $dir ): array {
	$files    = [];
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS )
	);
	foreach ( $iterator as $file ) {
		if ( $file instanceof SplFileInfo && 'php' === $file->getExtension() ) {
			$files[] = $file->getPathname();
		}
	}
	return $files;
}

/** @return array<int, array{file:string,line:int,text:string}> */
function sw_grep_files( array $files, string $pattern, array $skip_basenames = [] ): array {
	$hits = [];
	foreach ( $files as $path ) {
		if ( in_array( basename( $path ), $skip_basenames, true ) ) {
			continue;
		}
		$lines = file( $path, FILE_IGNORE_NEW_LINES );
		if ( false === $lines ) {
			continue;
		}
		foreach ( $lines as $no => $line ) {
			if ( preg_match( $pattern, $line ) ) {
				$hits[] = [ 'file' => $path, 'line' => $no + 1, 'text' => trim( $line ) ];
			}
		}
	}
	return $hits;
}

function sw_report( string $title, array $hits, bool $quiet ): void {
	if ( [] === $hits ) {
		if ( ! $quiet ) {
			echo "  [PASS] {$title}\n";
		}
		return;
	}
	echo "  [FAIL] {$title}\n";
	foreach ( $hits as $h ) {
		echo "         {$h['file']}:{$h['line']}: {$h['text']}\n";
	}
}

// ---------------------------------------------------------------------------
// Audit checks.
// ---------------------------------------------------------------------------

$php_files = sw_collect_php_files( $src_dir );
$findings  = 0;

if ( ! $quiet ) {
	echo "[stonewright-audit] Scanning " . count( $php_files ) . " PHP files in {$src_dir}\n\n";
}

// ----- Check 1: No bare eval() calls. -----
// Detection-only files are allowlisted because they reference the token as a string.
$eval_hits = sw_grep_files(
	$php_files,
	// Match the PHP eval keyword followed by (, but not inside a string or comment.
	'/(?<![\'"])\\beval\s*\(/',
	[ 'StaticGuard.php', 'Compiler.php', 'DesignSpec.php' ]
);
sw_report( 'Check 1 — No bare eval() calls in source', $eval_hits, $quiet );
$findings += count( $eval_hits );

// ----- Check 2: No create_function() calls. -----
$cf_hits = sw_grep_files(
	$php_files,
	'/\bcreate_function\s*\(/',
	[ 'StaticGuard.php', 'Compiler.php' ]
);
sw_report( 'Check 2 — No create_function() calls', $cf_hits, $quiet );
$findings += count( $cf_hits );

// ----- Check 3: No assert($string) patterns. -----
// We flag assert( followed by a string literal or a variable.
$assert_hits = sw_grep_files(
	$php_files,
	'/\bassert\s*\(\s*["\'\$]/',
	[ 'StaticGuard.php', 'Compiler.php' ]
);
sw_report( 'Check 3 — No assert($string) calls', $assert_hits, $quiet );
$findings += count( $assert_hits );

// ----- Check 4: No __return_true in permission_callback context. -----
$rt_hits = sw_grep_files( $php_files, '/__return_true/', [] );
sw_report( 'Check 4 — No __return_true in permission callbacks', $rt_hits, $quiet );
$findings += count( $rt_hits );

// ----- Check 5: Every ability has input_schema() and output_schema(). -----
// We verify the Ability interface is satisfied by checking the abstract base classes.
// At parse level: any class in Abilities/ that implements Ability but lacks both
// method declarations is a violation.
$missing_schema = [];
foreach ( $php_files as $path ) {
	if ( ! str_contains( $path, '/Abilities/' ) ) {
		continue;
	}
	$content = (string) file_get_contents( $path );
	// Only check concrete ability classes (implement Ability interface or extend AbilityKernel).
	if (
		! preg_match( '/\bimplements\b.*\bAbility\b/', $content ) &&
		! preg_match( '/\bextends\b.*\bAbilityKernel\b/', $content )
	) {
		continue;
	}
	// AbilityKernel itself provides defaults; skip it.
	if ( str_contains( $path, 'AbilityKernel.php' ) ) {
		continue;
	}
	$has_input  = (bool) preg_match( '/function\s+input_schema\s*\(/', $content );
	$has_output = (bool) preg_match( '/function\s+output_schema\s*\(/', $content );
	// If the class extends AbilityKernel it inherits both, so only flag when it
	// directly implements Ability without extending the kernel.
	$extends_kernel = (bool) preg_match( '/extends\s+AbilityKernel/', $content );
	if ( ! $extends_kernel && ( ! $has_input || ! $has_output ) ) {
		$missing_schema[] = [ 'file' => $path, 'line' => 1, 'text' => 'Missing input_schema() or output_schema()' ];
	}
}
sw_report( 'Check 5 — All abilities declare input_schema() and output_schema()', $missing_schema, $quiet );
$findings += count( $missing_schema );

// ----- Check 6: No REST write route without permission_callback. -----
// Scan for register_rest_route calls that include a write method (POST/PUT/PATCH/DELETE)
// but do not also declare a permission_callback in the same literal block.
$rest_hits = [];
foreach ( $php_files as $path ) {
	$content = (string) file_get_contents( $path );
	// Find each register_rest_route block.
	if ( ! preg_match_all(
		'/register_rest_route\s*\([^;]+?;/s',
		$content,
		$blocks
	) ) {
		continue;
	}
	foreach ( $blocks[0] as $block ) {
		$has_write_method = (bool) preg_match(
			'/[\'"]methods[\'"]\s*=>\s*[\'"](?:POST|PUT|PATCH|DELETE|WP_REST_Server::CREATABLE|WP_REST_Server::EDITABLE|WP_REST_Server::DELETABLE)[\'"]/',
			$block
		);
		$has_permission   = (bool) preg_match( '/permission_callback/', $block );
		if ( $has_write_method && ! $has_permission ) {
			// Find approximate line number.
			$before = substr( $content, 0, (int) strpos( $content, $block ) );
			$line   = substr_count( $before, "\n" ) + 1;
			$rest_hits[] = [
				'file' => $path,
				'line' => $line,
				'text' => 'register_rest_route with write method missing permission_callback',
			];
		}
	}
}
sw_report( 'Check 6 — REST write routes must have permission_callback', $rest_hits, $quiet );
$findings += count( $rest_hits );

// ---------------------------------------------------------------------------
// Summary.
// ---------------------------------------------------------------------------

if ( ! $quiet ) {
	echo "\n";
}

if ( 0 === $findings ) {
	if ( ! $quiet ) {
		echo "[stonewright-audit] All checks passed. No security findings.\n";
	}
	exit( 0 );
}

echo "[stonewright-audit] FAILED: {$findings} finding(s) above. Fix before release.\n";
exit( 1 );
