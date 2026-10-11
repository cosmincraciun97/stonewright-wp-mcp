#!/usr/bin/env php
<?php
/**
 * Generate docs/ability-truth-matrix.md from AbilityRegistry::list().
 *
 * Usage: php bin/generate-ability-matrix.php
 *
 * Run from the plugin/ directory or call via `composer docs:matrix`.
 * Writes to <repo-root>/docs/ability-truth-matrix.md.
 *
 * The script reads each ability class and detects:
 *   - Slug      : $ability->name()
 *   - MCP Tool  : slash-separated ability slug as exposed by MCP Adapter
 *   - Class     : FQCN
 *   - Desc      : first sentence of description() return value
 *   - R/W       : Write when the code calls one of the writing functions (wp_update_post,
 *                 update_post_meta, wp_insert_post, update_option, file_put_contents,
 *                 SandboxGuards, rename(), wpdb writes and the like), records its call through
 *                 the kernel wrapper that declares a write, or carries a confirmation gate (see
 *                 Support\AbilitySourceFacts)
 *   - Permission: text after "Permissions::" in the full permission_callback() body
 *                 (brace-counting scanner to avoid early-return truncation)
 *   - Token     : carries a production-safe confirmation gate (AbilitySourceFacts::token_gate_in),
 *                 the same fact as gates.token in the public API contract
 *   - Backup    : snapshots before it writes, directly or through a writer that does
 *                 (AbilitySourceFacts::backs_up), the same fact as gates.backup in the contract
 *   - Tests     : the test files that name the ability's class, slug or MCP tool name, or "none"
 *   - Validator : calls Validator::validate (DesignSpec or ThemeJson)
 *   - Status    : @stonewright-status docblock tag; default "stable"; "deprecated" when meta() says so
 *   - External  : calls that reach hosts outside the site (HTTP requests, downloads, oEmbed,
 *                 sideloads and the helper classes that do them)
 *   - Hints     : the MCP tool annotations the ability registers (Core\AbilityAnnotations)
 *
 * The facts that decide those hints are also written to plugin/data/ability-traits.php, which the
 * plugin reads when it registers the abilities.
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

use Stonewright\WpMcp\Core\AbilityAnnotations;
use Stonewright\WpMcp\Support\AbilitySourceFacts;

define( 'PLUGIN_DIR', dirname( __DIR__ ) );
define( 'REPO_ROOT', dirname( PLUGIN_DIR ) );
define( 'ABILITIES_DIR', PLUGIN_DIR . '/includes/Abilities' );
define( 'OUTPUT_FILE', REPO_ROOT . '/docs/ability-truth-matrix.md' );

/**
 * Load composer autoloader so we can call name() on ability instances.
 */
$autoload = PLUGIN_DIR . '/vendor/autoload.php';
if ( ! file_exists( $autoload ) ) {
	fwrite( STDERR, "ERROR: vendor/autoload.php not found. Run `composer install` first.\n" );
	exit( 1 );
}
require_once $autoload;

define( 'TRAITS_FILE', AbilityAnnotations::traits_path() );

/** A test file that names more than this many abilities is a sweep, not a test of one of them. */
define( 'TEST_SWEEP_LIMIT', 12 );

/** Most test files cited for one ability. */
define( 'TEST_CITE_LIMIT', 2 );

/**
 * Stub the minimum WordPress functions needed for autoloading.
 * We only call name(), description(), category() — none of these touch WP.
 */
if ( ! function_exists( 'wp_register_ability' ) ) {
	function wp_register_ability( string $name, array $args ): void {}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, mixed $default = false ): mixed {
		return $default;
	}
}
if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = '' ): string {
		return $text;
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	// The Elementor provider-discovery policy is read from the native contract files, which fingerprint schemas.
	function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false {
		return json_encode( $value, $flags, $depth );
	}
}
if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in(): bool {
		return false;
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $cap, ...$args ): bool {
		return false;
	}
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', PLUGIN_DIR . '/' );
}

/**
 * Minimal WP_Error stub.
 */
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct(
			public readonly string $code = '',
			public readonly string $message = '',
			public readonly mixed $data = '',
		) {}
		public function get_error_message(): string {
			return $this->message;
		}
		public function get_error_code(): string {
			return $this->code;
		}
	}
}

require_once PLUGIN_DIR . '/includes/Core/AbilityRegistry.php';

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * @return array<class-string>
 */
function get_registered_classes(): array {
	return \Stonewright\WpMcp\Core\AbilityRegistry::list();
}

/**
 * Source of an ability class and of every parent class up to (but not including) the
 * AbilityKernel and Ability base classes.
 */
function source_with_parents( string $class ): string {
	return AbilitySourceFacts::source_with_parents( $class );
}

function detect_rw( string $source ): string {
	return AbilitySourceFacts::is_write( $source ) ? 'Write' : 'Read';
}

/**
 * Extract the full body of permission_callback() using token_get_all().
 *
 * Uses PHP's lexer so braces inside strings, heredocs, nowdocs, and comments
 * are never counted — only real structural braces advance the depth counter.
 * This fixes the old character-walking scanner that miscounted when a string
 * literal or comment contained a brace character.
 */
function extract_permission_body( string $source ): string {
	$tokens = @token_get_all( $source );
	if ( ! is_array( $tokens ) ) {
		return '';
	}

	$count = count( $tokens );

	// Token types that carry text but are NOT structural braces.
	// Guard each constant — not all are present on every PHP version.
	$skip_types = [
		T_CONSTANT_ENCAPSED_STRING,
		T_ENCAPSED_AND_WHITESPACE,
		T_COMMENT,
		T_DOC_COMMENT,
	];
	foreach ( [ 'T_START_HEREDOC', 'T_END_HEREDOC', 'T_NOWDOC' ] as $const ) {
		if ( defined( $const ) ) {
			$skip_types[] = constant( $const );
		}
	}

	// ----- Step 1: find the 'function permission_callback' declaration -----
	$found_function = false;
	$i = 0;
	while ( $i < $count ) {
		$tok = $tokens[ $i ];
		if ( is_array( $tok ) && $tok[0] === T_FUNCTION ) {
			// Skip whitespace between 'function' and the name.
			$j = $i + 1;
			while ( $j < $count && is_array( $tokens[ $j ] ) && $tokens[ $j ][0] === T_WHITESPACE ) {
				$j++;
			}
			if ( $j < $count && is_array( $tokens[ $j ] ) && $tokens[ $j ][1] === 'permission_callback' ) {
				$found_function = true;
				$i = $j + 1;
				break;
			}
		}
		$i++;
	}

	if ( ! $found_function ) {
		return '';
	}

	// ----- Step 2: walk forward to the first '{' (opening brace of body) -----
	while ( $i < $count ) {
		$tok = $tokens[ $i ];
		if ( $tok === '{' || ( is_array( $tok ) && $tok[1] === '{' ) ) {
			$i++; // step past the opening brace
			break;
		}
		$i++;
	}

	// ----- Step 3: collect body tokens, balancing braces -----
	$depth  = 1;
	$body   = '';

	while ( $i < $count && $depth > 0 ) {
		$tok = $tokens[ $i ];

		if ( is_string( $tok ) ) {
			// Single-character tokens.
			if ( $tok === '{' ) {
				$depth++;
				$body .= $tok;
			} elseif ( $tok === '}' ) {
				$depth--;
				if ( $depth > 0 ) {
					$body .= $tok;
				}
				// depth === 0 → end of method body; do NOT append the closing brace.
			} else {
				$body .= $tok;
			}
		} else {
			// Array token: [ type, text, line ].
			$type = $tok[0];
			$text = $tok[1];

			if ( in_array( $type, $skip_types, true ) ) {
				// Strings/comments: append text verbatim but never count braces.
				$body .= $text;
			} else {
				// For all other token types, just append.
				$body .= $text;
			}
		}

		$i++;
	}

	return $body;
}

function detect_permission( string $source ): string {
	$body = extract_permission_body( $source );
	if ( '' === $body ) {
		// No permission_callback found — check parent source via full source.
		// Fall through to full-source scan as best-effort.
		$body = $source;
	}

	// Look for Permissions:: calls in the method body.
	if ( preg_match( '/return\s+Permissions::([a-zA-Z_]+\([^)]*\))/s', $body, $p ) ) {
		return 'Permissions::' . $p[1];
	}
	if ( preg_match( '/Permissions::([a-zA-Z_]+\([^)]*\))/s', $body, $p ) ) {
		return 'Permissions::' . $p[1] . ' (compound)';
	}

	return 'Permissions::read()';
}

function detect_token( string $source ): string {
	// The same fact the public API contract records as gates.token.
	return AbilitySourceFacts::token_gate_in( $source ) ? 'Yes' : 'No';
}

// The public API contract reads the same detector, so the two documents never disagree.
function detect_backup( string $source ): string {
	// The same fact the public API contract records as gates.backup.
	return AbilitySourceFacts::backs_up( $source ) ? 'Yes' : 'No';
}
function detect_validator( string $source ): string {
	if ( strpos( $source, 'SettingsValidator::validate_tree' ) !== false ) {
		return 'Yes (Elementor)';
	}
	if ( strpos( $source, 'ThemeJson\\Validator' ) !== false || strpos( $source, 'ThemeJson\Validator' ) !== false ) {
		return 'Yes (ThemeJson)';
	}
	if (
		strpos( $source, 'DesignSpec\\Validator' ) !== false
		&& strpos( $source, 'Validator::validate' ) !== false
	) {
		return 'Yes (DesignSpec)';
	}
	if (
		strpos( $source, 'new ValidateSpec()' ) !== false
		|| strpos( $source, 'SpecToGutenberg()' ) !== false
		|| strpos( $source, 'SpecToElementorV3()' ) !== false
		|| strpos( $source, 'ApplyToPost()' ) !== false
		|| strpos( $source, 'new BuildPageFromSpec()' ) !== false
	) {
		return 'Yes (DesignSpec)';
	}
	// DesignDirectionService::save() always runs DirectionContractValidator.
	if ( strpos( $source, 'service->save(' ) !== false ) {
		return 'Yes (Direction)';
	}
	return 'No';
}

/**
 * Fix 5: Read the @stonewright-status docblock tag from the class source.
 * Falls back to 'stable' when absent.
 *
 * Valid values: stable | experimental | sandboxed | blocked
 */
function detect_status( string $source, bool $deprecated = false ): string {
	// An ability that reports itself deprecated in its meta() is shown as deprecated, whatever its tag says.
	if ( $deprecated ) {
		return 'deprecated';
	}
	if ( preg_match( '/@stonewright-status\s+(stable|experimental|sandboxed|blocked)/', $source, $m ) ) {
		return $m[1];
	}
	return 'stable';
}

/**
 * Test files that exercise each ability: the ones that name its class (Abilities\Domain\Name) or
 * its slug or MCP tool name (stonewright/name, stonewright-name). Nothing is guessed from the
 * domain, so an ability no test names shows "none".
 *
 * An ability that shares its write path with others through a base class (the generated widget
 * abilities, for one) that no test names itself is cited with the tests that name that base class.
 *
 * A file that names more than TEST_SWEEP_LIMIT abilities is a sweep (profiles, registration,
 * the matrix itself), not a test of one ability, and is cited only for an ability whose class name
 * it carries in its file name.
 *
 * @param array<string, array{slug: string, class: string, base: string}> $abilities Facts by class; base is the relative name of the ability base class it extends, or ''.
 * @return array<string, string[]> Relative test paths by ability class, best first.
 */
function build_test_index( array $abilities ): array {
	$plugin_dir = PLUGIN_DIR;
	$files      = [];
	foreach ( [ 'tests/Unit', 'tests/Integration' ] as $dir ) {
		if ( ! is_dir( $plugin_dir . '/' . $dir ) ) {
			continue;
		}
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $plugin_dir . '/' . $dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( $file->isFile() && str_ends_with( $file->getFilename(), 'Test.php' ) ) {
				$files[] = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $plugin_dir ) + 1 ) );
			}
		}
	}
	sort( $files, SORT_STRING );

	// Per file: ability class => number of mentions, and base class => number of mentions.
	$mentions      = [];
	$base_mentions = [];
	foreach ( $files as $file ) {
		$content = (string) file_get_contents( $plugin_dir . '/' . $file );
		foreach ( $abilities as $class => $facts ) {
			$count  = 2 * preg_match_all( '/Abilities\\\\' . preg_quote( $facts['class'], '/' ) . '(?![A-Za-z0-9_])/', $content );
			$bare   = preg_quote( substr( $facts['slug'], strlen( 'stonewright/' ) ), '/' );
			$count += preg_match_all( '/stonewright[\/-]' . $bare . '(?![A-Za-z0-9_-])/', $content );
			if ( $count > 0 ) {
				$mentions[ $file ][ $class ] = $count;
			}
			if ( '' !== $facts['base'] && ! isset( $base_mentions[ $file ][ $facts['base'] ] ) ) {
				$base_mentions[ $file ][ $facts['base'] ] = preg_match_all( '/Abilities\\\\' . preg_quote( $facts['base'], '/' ) . '(?![A-Za-z0-9_])/', $content );
			}
		}
	}

	$index = [];
	foreach ( $abilities as $class => $facts ) {
		$short  = substr( $facts['class'], (int) strrpos( '\\' . $facts['class'], '\\' ) );
		$scored = [];
		foreach ( $mentions as $file => $by_class ) {
			if ( ! isset( $by_class[ $class ] ) ) {
				continue;
			}
			$named = str_contains( basename( $file ), $short );
			if ( count( $by_class ) > TEST_SWEEP_LIMIT && ! $named ) {
				continue;
			}
			// A file named after the class first, then the file that names this ability most,
			// and among equals the one that names the fewest other abilities.
			$scored[ $file ] = [ $named ? 1 : 0, $by_class[ $class ], -count( $by_class ) ];
		}
		uasort(
			$scored,
			static fn( array $a, array $b ): int => $b <=> $a
		);
		if ( [] === $scored && '' !== $facts['base'] ) {
			foreach ( $base_mentions as $file => $by_base ) {
				if ( ( $by_base[ $facts['base'] ] ?? 0 ) > 0 && count( $mentions[ $file ] ?? [] ) <= TEST_SWEEP_LIMIT ) {
					$scored[ $file ] = [ 0, $by_base[ $facts['base'] ], 0 ];
				}
			}
			uasort( $scored, static fn( array $a, array $b ): int => $b <=> $a );
		}
		$index[ $class ] = array_slice( array_keys( $scored ), 0, TEST_CITE_LIMIT );
	}

	return $index;
}
/**
 * The Tests cell: every cited file in backticks, or "none" when no test names the ability.
 *
 * @param string[] $files Relative test paths.
 */
function ability_base_class( string $class ): string {
	$parent = get_parent_class( $class );
	if ( false === $parent || \Stonewright\WpMcp\Abilities\AbilityKernel::class === $parent ) {
		return '';
	}
	return short_class( $parent );
}

function format_test_files( array $files ): string {
	if ( [] === $files ) {
		return 'none';
	}
	return implode( ', ', array_map( static fn( string $file ): string => '`' . $file . '`', $files ) );
}

function short_class( string $class ): string {
	// Return the relative FQCN after Stonewright\WpMcp\Abilities\.
	return str_replace( 'Stonewright\\WpMcp\\Abilities\\', '', $class );
}

/**
 * The MCP tool annotations of an ability, as one short phrase for the matrix.
 *
 * @param array<string, bool|string> $hints Final annotations of the ability.
 */
function describe_hints( array $hints ): string {
	if ( ! empty( $hints['readonly'] ) ) {
		$parts = [ 'read-only' ];
	} else {
		$parts = [ empty( $hints['destructive'] ) ? 'additive' : 'destructive' ];
		if ( ! empty( $hints['idempotent'] ) ) {
			$parts[] = 'idempotent';
		}
	}
	if ( ! empty( $hints['openWorldHint'] ) ) {
		$parts[] = 'open-world';
	}
	return implode( ', ', $parts );
}

/**
 * Header lines of one matrix table.
 *
 * @return string[]
 */
function matrix_table_header(): array {
	return [
		'| Slug | MCP Tool | Class | Description | R/W | Permission | Token | Backup | Validator | Status | Tests | External | Hints |',
		'|---|---|---|---|---|---|---|---|---|---|---|---|---|',
	];
}

/**
 * One matrix table row.
 *
 * @param array<string, string> $row Row data.
 */
function matrix_table_row( array $row ): string {
	$line  = '| `' . $row['slug'] . '` ';
	$line .= '| `' . $row['mcp_tool'] . '` ';
	$line .= '| `' . $row['class'] . '` ';
	$line .= '| ' . str_replace( '|', '\\|', $row['desc'] ) . ' ';
	$line .= '| ' . $row['rw'] . ' ';
	$line .= '| `' . $row['perm'] . '` ';
	$line .= '| ' . $row['token'] . ' ';
	$line .= '| ' . $row['backup'] . ' ';
	$line .= '| ' . $row['validator'] . ' ';
	$line .= '| ' . $row['status'] . ' ';
	$line .= '| ' . $row['tests'] . ' ';
	$line .= '| ' . $row['external'] . ' ';
	$line .= '| ' . $row['hints'] . ' |';
	return $line;
}

/**
 * The traits file the plugin reads when it registers abilities: the facts that decide the MCP
 * tool annotations of every ability, sorted by ability name.
 *
 * @param array<string, array{write: bool, external: bool}> $traits Facts by ability name.
 */
function traits_file_contents( array $traits ): string {
	ksort( $traits, SORT_STRING );

	$lines   = [];
	$lines[] = '<?php';
	$lines[] = '/**';
	$lines[] = ' * Facts that decide the MCP tool annotations of every Stonewright ability.';
	$lines[] = ' * SPDX-License-Identifier: GPL-2.0-or-later';
	$lines[] = ' *';
	$lines[] = ' * Generated by plugin/bin/generate-ability-matrix.php (composer docs:matrix). Do not edit';
	$lines[] = ' * by hand. Core\\AbilityAnnotations reads this file when abilities are registered.';
	$lines[] = ' *';
	$lines[] = ' * write:    the ability can change state; an ability that cannot is read-only.';
	$lines[] = ' * external: the ability can reach hosts outside the site (HTTP requests, downloads, oEmbed).';
	$lines[] = ' *';
	$lines[] = ' * @package Stonewright';
	$lines[] = ' */';
	$lines[] = '';
	$lines[] = 'return [';
	foreach ( $traits as $name => $facts ) {
		$lines[] = sprintf(
			"\t'%s' => [ 'write' => %s, 'external' => %s ],",
			$name,
			$facts['write'] ? 'true' : 'false',
			$facts['external'] ? 'true' : 'false'
		);
	}
	$lines[] = '];';

	return implode( "\n", $lines ) . "\n";
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

/** @var array<string, array<array{slug:string,mcp_tool:string,class:string,desc:string,rw:string,perm:string,token:string,backup:string,validator:string,status:string,tests:string,external:string,hints:string}>> */
$groups = [];

/** @var array<string, array{write: bool, external: bool}> $traits */
$traits = [];

$category_order = [
	'Security', 'Site', 'Content', 'Media',
	'Gutenberg', 'Patterns', 'FSE',
	'ElementorV3', 'ElementorV4', 'ElementorWidget',
	'Design', 'Runtime', 'WpCli', 'Memory', 'System', 'Sandbox',
];

$category_labels = [
	'Security'        => 'Security',
	'Site'            => 'Site',
	'Content'         => 'Content',
	'Media'           => 'Media',
	'Gutenberg'       => 'Gutenberg',
	'Patterns'        => 'Patterns',
	'FSE'             => 'FSE (Full Site Editing)',
	'ElementorV3'     => 'Elementor V3',
	'ElementorV4'     => 'Elementor V4 (Experimental)',
	'ElementorWidget' => 'Elementor Widget Builder',
	'Design'          => 'Design',
	'Runtime'         => 'Runtime',
	'WpCli'           => 'WP-CLI',
	'Memory'          => 'Memory',
	'System'          => 'System',
	'Sandbox'         => 'Sandbox',
];

/** @var array<string, array{slug: string, class: string, base: string}> $ability_names */
$ability_names = [];
foreach ( get_registered_classes() as $class ) {
	try {
		$ability_names[ $class ] = [
			'slug'    => ( new $class() )->name(),
			'class'   => short_class( $class ),
			'base'    => ability_base_class( $class ),
		];
	} catch ( \Throwable $e ) {
		// Reported once more in the main loop below.
		continue;
	}
}
$test_index = build_test_index( $ability_names );

foreach ( get_registered_classes() as $class ) {
	// Fix 3: walk parent class chain and union sources.
	$source = source_with_parents( $class );

	// Determine category from namespace.
	$cat = 'Other';
	if ( preg_match( '/Abilities\\\\([^\\\\]+)\\\\/', $class, $m ) ) {
		$cat = $m[1];
	}

	// Instantiate to call name() / description().
	$slug  = '';
	$desc  = '';
	$hints = 'unknown';
	$facts = AbilitySourceFacts::detect( $class );
	$deprecated = false;
	try {
		$ability  = new $class();
		$deprecated = true === ( $ability->meta()['deprecated'] ?? false );
		$slug     = $ability->name();
		$raw_desc = $ability->description();
		// Take first sentence.
		$desc  = preg_split( '/(?<=[.!?])\s/', $raw_desc )[0] ?? $raw_desc;
		$hints = describe_hints( AbilityAnnotations::for_ability( $ability, $facts ) );
		$traits[ $slug ] = $facts;
	} catch ( \Throwable $e ) {
		$slug = '(error: ' . $e->getMessage() . ')';
		$desc = '';
		fwrite( STDERR, sprintf(
			"WARNING: Could not instantiate %s: %s (%s:%d)\n",
			$class,
			$e->getMessage(),
			$e->getFile(),
			$e->getLine()
		) );
	}

	$groups[ $cat ][] = [
		'slug'      => $slug,
		'mcp_tool'  => str_replace( '/', '-', $slug ),
		'class'     => short_class( $class ),
		'desc'      => $desc,
		'rw'        => detect_rw( $source ),
		'perm'      => detect_permission( $source ),
		'token'     => detect_token( $source ),
		'backup'    => detect_backup( $source ),
		'validator' => detect_validator( $source ),
		'status'    => detect_status( $source, $deprecated ),
		'tests'     => format_test_files( $test_index[ $class ] ?? [] ),
		'external'  => $facts['external'] ? 'Yes' : 'No',
		'hints'     => $hints,
	];
}

// ---------------------------------------------------------------------------
// Build markdown
// ---------------------------------------------------------------------------

$lines   = [];
$lines[] = '# Stonewright Ability Truth Matrix';
$lines[] = '';
$lines[] = '> Auto-generated by `php bin/generate-ability-matrix.php` on ' . date( 'Y-m-d' ) . '.';
$lines[] = '> Do not edit this file by hand. Run `composer docs:matrix` to regenerate.';
$lines[] = '';
$lines[] = '**Legend**';
$lines[] = '';
$lines[] = '- **R/W**: `Read` = no WP state mutation; `Write` = mutates posts, options, meta, or files, records its call through the kernel wrapper for writes, or carries a confirmation gate.';
$lines[] = '- **MCP Tool**: the callable MCP tool name. The WordPress MCP Adapter exposes `stonewright/foo` as `stonewright-foo`.';
$lines[] = '- **Permission**: the `Permissions::` method called from `permission_callback()`.';
$lines[] = '- **Token**: the ability carries a production-safe confirmation gate (`ConfirmationGuard`, `ConfirmationToken::verify_or_error()`, the kernel write wrapper or an engine that verifies the token for it). A gate that applies to some modes of the ability only still counts. Same fact as `gates.token` in the public API contract.';
$lines[] = '- **Backup**: calls `Backup::snapshot_post()` before mutation, directly or through a writer that does (see `Support\AbilitySourceFacts`). Same fact as `gates.backup` in the public API contract.';
$lines[] = '- **Validator**: calls an Elementor, DesignSpec, ThemeJson, or Design Direction validator.';
$lines[] = '- **Status**: `stable` | `experimental` | `sandboxed` | `blocked` (from `@stonewright-status` docblock tag), or `deprecated` for an ability whose `meta()` reports `deprecated` (it names its replacement and sunset version there).';
$lines[] = '- **Tests**: the test files that name the ability\'s class, slug or MCP tool name (at most two, the most specific first); `none` when no test does. Files that name a dozen abilities or more, such as the profile and registration suites, are not cited.';
$lines[] = '- **External**: `Yes` = the code of the ability makes HTTP requests, downloads, oEmbed lookups or sideloads (what the source shows). Abilities that run arbitrary code or commands (`php-execute`, the WP-CLI runners, `execute-ability`) are marked `open-world` in Hints by an override although this column says `No`.';
$lines[] = '- **Hints**: the MCP tool annotations the ability registers. `read-only` abilities are also idempotent and never destructive; the others are `additive` (they only add) or `destructive`, optionally `idempotent`; `open-world` marks a tool that reaches hosts outside the site. They come from the R/W and External facts and the verb in the ability name, and an ability can override them in its `meta()`.';
$lines[] = '';

$total_abilities = 0;

foreach ( $category_order as $cat ) {
	if ( ! isset( $groups[ $cat ] ) ) {
		continue;
	}
	$rows            = $groups[ $cat ];
	$total_abilities += count( $rows );

	$label   = $category_labels[ $cat ] ?? $cat;
	$lines[] = '---';
	$lines[] = '';
	$lines[] = '## ' . $label;
	$lines[] = '';
	array_push( $lines, ...matrix_table_header() );

	foreach ( $rows as $row ) {
		$lines[] = matrix_table_row( $row );
	}

	$lines[] = '';
}

// Handle any categories not in the ordered list.
foreach ( $groups as $cat => $rows ) {
	if ( in_array( $cat, $category_order, true ) ) {
		continue;
	}
	$total_abilities += count( $rows );
	$lines[] = '---';
	$lines[] = '';
	$lines[] = '## ' . $cat;
	$lines[] = '';
	array_push( $lines, ...matrix_table_header() );
	foreach ( $rows as $row ) {
		$lines[] = matrix_table_row( $row );
	}
	$lines[] = '';
}

$lines[] = '---';
$lines[] = '';
$lines[] = '## Native-preferred upstream capabilities';
$lines[] = '';
$lines[] = '- `elementor/manage-default-styles`: **native-preferred**, certified only; runs only through `stonewright/elementor-native-execute` inside the snapshot, lock, readback, rollback, and audit closure.';
$lines[] = '- `elementor/build-composition`: **native-preferred**, certified only; same closure. On a published or private document the change is saved into an autosave and is reported as `staged_in_autosave`, never as applied.';
$lines[] = '- `elementor/get-page-structure`: **native-readback**, certified only; read-only, and it reads the published document rather than a pending autosave.';
$lines[] = '- `elementor/manage-classes`: certified, **native write refused** (`upstream_global_clear_cache`: it clears generated CSS site-wide); Stonewright\'s own class writers remain the supported path.';
$lines[] = '- `elementor/manage-global-variable`: certified, **native write refused** (`upstream_global_clear_cache`); Stonewright\'s own variable writers remain the supported path.';
$lines[] = '- `elementor/manage-elements`: **unsupported** (`upstream_global_clear_cache`, `staged_in_autosave`).';
$lines[] = '';
$lines[] = 'For `elementor/manage-default-styles`, certification requires active Elementor ownership; exact input/output object schemas and required fields; update/delete, tag, raw CSS, responsive, pseudo-state, patch/replace, and null semantics; `readonly=false`, `destructive=true`, `idempotent=false`; `CLASS_TYPE=class`; and a runtime operation limit exactly 20.';
$lines[] = '';
$lines[] = 'Certification is data-driven: one contract per ability under `plugin/data/elementor-native-contracts/` records the exact input, output and description fingerprints for each verified Elementor version range, the provider and runtime class, annotations, runtime constants and known side effects. Any identity, owner, annotation, constant, fingerprint, named-probe or version mismatch rejects the ability and reports each exact reason. An ability without a contract is `not_available_for_certification`.';
$lines[] = '';$lines[] = '---';
$lines[] = '';
$lines[] = '## Summary';
$lines[] = '';
$lines[] = 'Total abilities registered: **' . $total_abilities . '**';
$lines[] = '';
$lines[] = '> Verified by `tests/Unit/Documentation/AbilityTruthMatrixTest.php`.';
$lines[] = '> To regenerate: `composer docs:matrix`';
$lines[] = '';

$output = implode( "\n", $lines );

$written = file_put_contents( OUTPUT_FILE, $output );
if ( $written === false ) {
	fwrite( STDERR, "ERROR: Could not write to " . OUTPUT_FILE . "\n" );
	exit( 1 );
}

echo "Written " . $total_abilities . " abilities to " . OUTPUT_FILE . "\n";

if ( ! is_dir( dirname( TRAITS_FILE ) ) && ! mkdir( dirname( TRAITS_FILE ), 0755, true ) && ! is_dir( dirname( TRAITS_FILE ) ) ) {
	fwrite( STDERR, "ERROR: Could not create " . dirname( TRAITS_FILE ) . "\n" );
	exit( 1 );
}
if ( false === file_put_contents( TRAITS_FILE, traits_file_contents( $traits ) ) ) {
	fwrite( STDERR, "ERROR: Could not write to " . TRAITS_FILE . "\n" );
	exit( 1 );
}

echo "Written " . count( $traits ) . " ability traits to " . TRAITS_FILE . "\n";
