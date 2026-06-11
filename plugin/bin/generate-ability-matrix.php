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
 *   - R/W       : presence of wp_update_post, update_post_meta, wp_insert_post,
 *                 update_option, file_put_contents, SandboxGuards, rename() calls,
 *                 wpdb->insert, wpdb->update, wpdb->delete
 *   - Permission: text after "Permissions::" in the full permission_callback() body
 *                 (brace-counting scanner to avoid early-return truncation)
 *   - Token     : uses ConfirmationGuard trait, ConfirmationToken::verify_or_error,
 *                 confirmation_token_error(), or production_safe_token_error()
 *   - Backup    : calls Backup::snapshot_post
 *   - Validator : calls Validator::validate (DesignSpec or ThemeJson)
 *   - Status    : @stonewright-status docblock tag; default "stable"
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

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

function class_to_file( string $class ): string {
	// Convert FQCN to file path relative to plugin/includes/.
	// Stonewright\WpMcp\Abilities\Content\BulkCreate -> includes/Abilities/Content/BulkCreate.php
	$relative = str_replace( 'Stonewright\\WpMcp\\', '', $class );
	$relative = str_replace( '\\', '/', $relative );
	return PLUGIN_DIR . '/includes/' . $relative . '.php';
}

function read_file( string $path ): string {
	if ( ! file_exists( $path ) ) {
		return '';
	}
	$content = file_get_contents( $path );
	return $content === false ? '' : $content;
}

/**
 * Walk the ReflectionClass parent chain and union source content from every
 * class up to (but not including) AbilityKernel / Ability base classes.
 */
function source_with_parents( string $class ): string {
	$stop_at = [
		'Stonewright\\WpMcp\\Abilities\\AbilityKernel',
		'Stonewright\\WpMcp\\Abilities\\Ability',
	];

	$sources = [];
	try {
		$ref = new ReflectionClass( $class );
		while ( $ref !== false ) {
			$name = $ref->getName();
			if ( in_array( $name, $stop_at, true ) ) {
				break;
			}
			$file = class_to_file( $name );
			$src  = read_file( $file );
			if ( '' !== $src ) {
				$sources[] = $src;
			}
			$ref = $ref->getParentClass();
		}
	} catch ( \ReflectionException $e ) {
		// Fall back to just the leaf class source.
		$sources[] = read_file( class_to_file( $class ) );
	}

	return implode( "\n", $sources );
}

/**
 * Patterns whose presence in ability source code indicate a write operation.
 * Extracted as a constant to avoid duplication and make maintenance easier.
 *
 * @var string[]
 */
const WRITE_PATTERNS = [
	'wp_update_post', 'wp_insert_post', 'update_post_meta', 'add_post_meta',
	'update_option', 'add_option', 'delete_option',
	'update_metadata', 'delete_metadata',
	'file_put_contents', 'rename(', 'unlink(', 'copy(',
	'SandboxGuards', 'snapshot_post',
	'wp_insert_attachment', 'media_handle_sideload',
	// wpdb direct write methods (Fix 4: memory write detection).
	'wpdb->insert(', 'wpdb->update(', 'wpdb->delete(',
	'$wpdb->insert', '$wpdb->update', '$wpdb->delete',
	// Memory helper write delegates.
	'Memory::put(', 'Memory::put_typed(', 'Memory::delete(',
	'Memory::delete_by_id(', 'Memory::update_by_id(',
	// Skills and design orchestrator delegates.
	'Skills::save(', 'Skills::delete(', 'SpecToGutenberg()', 'SpecToElementorV3()',
	'ElementorWriter::write',
	// Batch/orchestrator delegates.
	'new UploadMedia()', 'new BuildPageFromSpec()',
	// Confirmation-guarded abilities can mutate or destroy state.
	'ConfirmationGuard',
];

function detect_rw( string $source ): string {
	$source = source_without_strings_and_comments( $source );

	foreach ( WRITE_PATTERNS as $pattern ) {
		if ( strpos( $source, $pattern ) !== false ) {
			return 'Write';
		}
	}
	return 'Read';
}

/**
 * Strip strings and comments before source-pattern detection.
 *
 * Ability classes may mention write tools inside guidance strings. Those
 * references should not mark the ability itself as mutating state.
 */
function source_without_strings_and_comments( string $source ): string {
	$tokens = @token_get_all( $source );
	if ( ! is_array( $tokens ) ) {
		return $source;
	}

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

	$clean = '';
	foreach ( $tokens as $token ) {
		if ( is_array( $token ) ) {
			if ( in_array( $token[0], $skip_types, true ) ) {
				continue;
			}
			$clean .= $token[1];
			continue;
		}
		$clean .= $token;
	}

	return $clean;
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
	// Fix 2: also detect the two SandboxGuards/ConfirmationGuard helper methods.
	if (
		strpos( $source, 'use ConfirmationGuard' ) !== false
		|| strpos( $source, 'ConfirmationToken::verify_or_error' ) !== false
		|| strpos( $source, 'require_confirmation' ) !== false
		|| strpos( $source, 'require_sandbox_confirmation' ) !== false
		|| strpos( $source, 'confirmation_token_error(' ) !== false
		|| strpos( $source, 'production_safe_token_error(' ) !== false
		|| strpos( $source, 'new BuildPageFromSpec()' ) !== false
	) {
		return 'Yes';
	}
	return 'No';
}

function detect_backup( string $source ): string {
	return (
		strpos( $source, 'Backup::snapshot_post' ) !== false
		|| strpos( $source, 'SpecToGutenberg()' ) !== false
		|| strpos( $source, 'SpecToElementorV3()' ) !== false
		|| strpos( $source, 'ApplyToPost()' ) !== false
		|| strpos( $source, 'new BuildPageFromSpec()' ) !== false
	) ? 'Yes' : 'No';
}

function detect_validator( string $source ): string {
	if ( strpos( $source, 'ThemeJson\\Validator' ) !== false || strpos( $source, 'ThemeJson\Validator' ) !== false ) {
		return 'Yes (ThemeJson)';
	}
	if ( strpos( $source, 'Validator::validate' ) !== false ) {
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
	return 'No';
}

/**
 * Fix 5: Read the @stonewright-status docblock tag from the class source.
 * Falls back to 'stable' when absent.
 *
 * Valid values: stable | experimental | sandboxed | blocked
 */
function detect_status( string $source ): string {
	if ( preg_match( '/@stonewright-status\s+(stable|experimental|sandboxed|blocked)/', $source, $m ) ) {
		return $m[1];
	}
	return 'stable';
}

function find_test_file( string $class ): string {
	$short   = substr( $class, strrpos( $class, '\\' ) + 1 );
	$domain  = '';
	if ( preg_match( '/Abilities\\\\([^\\\\]+)\\\\/', $class, $m ) ) {
		$domain = $m[1];
	}

	// Look for a well-known test file keyed to the domain.
	$domain_tests = [
		'ElementorV3'    => 'tests/Integration/ElementorWriterTest.php',
		'ElementorV4'    => 'tests/Integration/ElementorWriterTest.php',
		'ElementorWidget' => 'tests/Unit/WidgetDefineTest.php',
		'FSE'            => 'tests/Unit/FseWriteSafetyTest.php',
		'Gutenberg'      => 'tests/Unit/GutenbergRendererTest.php',
		'Content'        => 'tests/Unit/ContentCapabilityTest.php',
		'Design'         => 'tests/Integration/DesignIngestionTest.php',
		'WpCli'          => 'tests/Unit/WpCli/WpCliAbilitiesTest.php',
		'Sandbox'        => 'tests/Unit/SandboxManifestTest.php',
		'Memory'         => 'tests/Unit/AbilityKernelAuditTest.php',
		'System'         => 'tests/Unit/AbilityKernelAuditTest.php',
		'Site'           => 'tests/Unit/AbilityKernelAuditTest.php',
		'Security'       => 'tests/Unit/ConfirmationTokenTest.php',
		'Media'          => 'tests/Unit/AssetSideloaderTest.php',
		'Patterns'       => 'tests/Unit/AbilityKernelAuditTest.php',
	];
	return $domain_tests[ $domain ] ?? 'tests/Unit/AbilityKernelAuditTest.php';
}

function short_class( string $class ): string {
	// Return the relative FQCN after Stonewright\WpMcp\Abilities\.
	return str_replace( 'Stonewright\\WpMcp\\Abilities\\', '', $class );
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

/** @var array<string, array<array{slug:string,mcp_tool:string,class:string,desc:string,rw:string,perm:string,token:string,backup:string,validator:string,status:string,tests:string}>> */
$groups = [];

$category_order = [
	'Security', 'Site', 'Content', 'Media',
	'Gutenberg', 'Patterns', 'FSE',
	'ElementorV3', 'ElementorV4', 'ElementorWidget',
	'Design', 'WpCli', 'Memory', 'System', 'Sandbox',
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
	'WpCli'           => 'WP-CLI',
	'Memory'          => 'Memory',
	'System'          => 'System',
	'Sandbox'         => 'Sandbox',
];

foreach ( get_registered_classes() as $class ) {
	// Fix 3: walk parent class chain and union sources.
	$source = source_with_parents( $class );
	$file   = class_to_file( $class );

	// Determine category from namespace.
	$cat = 'Other';
	if ( preg_match( '/Abilities\\\\([^\\\\]+)\\\\/', $class, $m ) ) {
		$cat = $m[1];
	}

	// Instantiate to call name() / description().
	$slug = '';
	$desc = '';
	try {
		$ability  = new $class();
		$slug     = $ability->name();
		$raw_desc = $ability->description();
		// Take first sentence.
		$desc = preg_split( '/(?<=[.!?])\s/', $raw_desc )[0] ?? $raw_desc;
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
		'status'    => detect_status( $source ),
		'tests'     => find_test_file( $class ),
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
$lines[] = '- **R/W**: `Read` = no WP state mutation; `Write` = mutates posts, options, meta, or files.';
$lines[] = '- **MCP Tool**: the callable MCP tool name. The WordPress MCP Adapter exposes `stonewright/foo` as `stonewright-foo`.';
$lines[] = '- **Permission**: the `Permissions::` method called from `permission_callback()`.';
$lines[] = '- **Token**: `ConfirmationGuard` trait or explicit `ConfirmationToken::verify_or_error()` call.';
$lines[] = '- **Backup**: calls `Backup::snapshot_post()` before mutation.';
$lines[] = '- **Validator**: calls `DesignSpec\\Validator::validate()` or `ThemeJson\\Validator::validate()`.';
$lines[] = '- **Status**: `stable` | `experimental` | `sandboxed` | `blocked` (from `@stonewright-status` docblock tag).';
$lines[] = '- **Tests**: primary test file for this ability.';
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
	$lines[] = '| Slug | MCP Tool | Class | Description | R/W | Permission | Token | Backup | Validator | Status | Tests |';
	$lines[] = '|---|---|---|---|---|---|---|---|---|---|---|';

	foreach ( $rows as $row ) {
		$line    = '| `' . $row['slug'] . '` ';
		$line   .= '| `' . $row['mcp_tool'] . '` ';
		$line   .= '| `' . $row['class'] . '` ';
		$line   .= '| ' . str_replace( '|', '\\|', $row['desc'] ) . ' ';
		$line   .= '| ' . $row['rw'] . ' ';
		$line   .= '| `' . $row['perm'] . '` ';
		$line   .= '| ' . $row['token'] . ' ';
		$line   .= '| ' . $row['backup'] . ' ';
		$line   .= '| ' . $row['validator'] . ' ';
		$line   .= '| ' . $row['status'] . ' ';
		$line   .= '| `' . $row['tests'] . '` |';
		$lines[] = $line;
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
	$lines[] = '| Slug | MCP Tool | Class | Description | R/W | Permission | Token | Backup | Validator | Status | Tests |';
	$lines[] = '|---|---|---|---|---|---|---|---|---|---|---|';
	foreach ( $rows as $row ) {
		$line    = '| `' . $row['slug'] . '` ';
		$line   .= '| `' . $row['mcp_tool'] . '` ';
		$line   .= '| `' . $row['class'] . '` ';
		$line   .= '| ' . str_replace( '|', '\\|', $row['desc'] ) . ' ';
		$line   .= '| ' . $row['rw'] . ' ';
		$line   .= '| `' . $row['perm'] . '` ';
		$line   .= '| ' . $row['token'] . ' ';
		$line   .= '| ' . $row['backup'] . ' ';
		$line   .= '| ' . $row['validator'] . ' ';
		$line   .= '| ' . $row['status'] . ' ';
		$line   .= '| `' . $row['tests'] . '` |';
		$lines[] = $line;
	}
	$lines[] = '';
}

$lines[] = '---';
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
