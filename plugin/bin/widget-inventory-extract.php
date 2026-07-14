<?php
/**
 * Widget Inventory Extractor.
 *
 * Scans the on-disk Elementor + Pro Elements widget files and emits a JSON
 * inventory consumed by per-widget control extraction and manifest synthesis.
 *
 * Usage:
 *   php plugin/bin/widget-inventory-extract.php /path/to/wp-content/plugins
 *   STONEWRIGHT_ELEMENTOR_PLUGINS_DIR=/path/to/wp-content/plugins php plugin/bin/widget-inventory-extract.php
 *
 * The script reads each PHP file's source and pulls:
 *   - get_name() return string
 *   - get_title() return string (unwrapped from esc_html__/__ helpers)
 *   - get_icon() return string
 *   - get_categories() return array
 *   - get_keywords() return array
 *
 * Files that don't `extends ... Widget_Base` are skipped (base / abstract
 * classes don't represent real widgets). Source attribution is derived from
 * the file path: `elementor/includes/widgets/` → `free`,
 * `pro-elements/modules/<X>/widgets/` → `pro` (unless X == 'woocommerce', then `wc`).
 *
 * Tolerant of:
 *   - Single- and double-quoted strings
 *   - Single-line `return [...]` arrays
 *   - Multi-line `return [...]` arrays (collapses whitespace)
 *   - esc_html__() / __() / esc_attr__() wrappers around titles
 *   - Trait usage (the class still has a get_name override even if logic comes from a trait)
 *
 * Skips:
 *   - `common-base.php`, `common-optimized.php`, `common.php`, `base.php`,
 *     `*-base.php`, `*-trait.php`, `module.php`, `base-widget.php`
 */
declare(strict_types=1);

// ---------------------------------------------------------------------------
// Configuration: source roots. Never persist machine-specific absolute paths.
// ---------------------------------------------------------------------------
$plugins_dir = $argv[1] ?? getenv( 'STONEWRIGHT_ELEMENTOR_PLUGINS_DIR' );
if ( ! is_string( $plugins_dir ) || '' === trim( $plugins_dir ) || ! is_dir( $plugins_dir ) ) {
	fwrite( STDERR, "Pass the wp-content/plugins directory as argv[1] or STONEWRIGHT_ELEMENTOR_PLUGINS_DIR.\n" );
	exit( 2 );
}
$plugins_dir = rtrim( str_replace( '\\', '/', realpath( $plugins_dir ) ?: $plugins_dir ), '/' );

$roots = [
	[
		'label'  => 'free',
		'glob'   => $plugins_dir . '/elementor/includes/widgets/*.php',
	],
	[
		'label'  => 'pro_or_wc', // resolved per file
		'glob'   => $plugins_dir . '/pro-elements/modules/*/widgets/*.php',
	],
];

$skip_basenames = [
	'common.php',
	'common-base.php',
	'common-optimized.php',
	'base.php',
	'base-widget.php',
	'base-app.php',
	'base-cta.php',
	'module.php',
];

/**
 * Skip patterns (regex against basename).
 * - any `*-base.php` (carousel/base.php, products-base.php, etc.)
 * - any `*-trait.php`
 * - any `*-deprecated.php`
 */
$skip_patterns = [
	'/-base\.php$/',
	'/-trait\.php$/',
	'/-deprecated\.php$/',
];

// ---------------------------------------------------------------------------
// Helpers.
// ---------------------------------------------------------------------------

/**
 * Pull the body of a method by name. Returns the substring between the
 * opening `{` and matching closing `}` of `function <name>(`.
 */
function method_body(string $source, string $method): ?string {
	if ( ! preg_match( '/function\s+' . preg_quote( $method, '/' ) . '\s*\([^)]*\)\s*(?::[^{]+)?\s*\{/', $source, $m, PREG_OFFSET_CAPTURE ) ) {
		return null;
	}
	$start = $m[0][1] + strlen( $m[0][0] ) - 1; // points at the `{`
	$depth = 0;
	$len   = strlen( $source );
	for ( $i = $start; $i < $len; $i++ ) {
		$ch = $source[ $i ];
		if ( $ch === '{' ) {
			$depth++;
		} elseif ( $ch === '}' ) {
			$depth--;
			if ( $depth === 0 ) {
				return substr( $source, $start + 1, $i - $start - 1 );
			}
		}
	}
	return null;
}

/** Extract a string returned from a method body. */
function extract_string_return( ?string $body ): ?string {
	if ( $body === null ) {
		return null;
	}
	// Single quote.
	if ( preg_match( "/return\s+'([^']+)'/", $body, $m ) ) {
		return $m[1];
	}
	// Double quote — strip simple `\n` etc but otherwise pass through.
	if ( preg_match( '/return\s+"([^"]+)"/', $body, $m ) ) {
		return $m[1];
	}
	// __('...') / esc_html__('...') / esc_attr__('...') wrappers.
	if ( preg_match( "/return\s+(?:esc_html__|esc_attr__|__)\(\s*'([^']+)'/", $body, $m ) ) {
		return $m[1];
	}
	if ( preg_match( '/return\s+(?:esc_html__|esc_attr__|__)\(\s*"([^"]+)"/', $body, $m ) ) {
		return $m[1];
	}
	return null;
}

/**
 * Extract an array of strings returned from a method body.
 * Handles `return [ 'a', 'b' ];` and `return array( 'a', 'b' );` and
 * multi-line variants.
 */
function extract_array_return( ?string $body ): array {
	if ( $body === null ) {
		return [];
	}

	// Find the substring after the first `return`.
	if ( ! preg_match( '/return\s+(\[|array\s*\()/', $body, $m, PREG_OFFSET_CAPTURE ) ) {
		return [];
	}

	$opener      = $m[1][0];
	$start_index = $m[1][1] + strlen( $opener );
	$close_char  = ( $opener[0] === '[' ) ? ']' : ')';

	$depth = 1;
	$len   = strlen( $body );
	$end_index = $start_index;
	for ( $i = $start_index; $i < $len; $i++ ) {
		$ch = $body[ $i ];
		if ( $ch === '[' || $ch === '(' ) {
			$depth++;
		} elseif ( $ch === ']' || $ch === ')' ) {
			$depth--;
			if ( $depth === 0 ) {
				$end_index = $i;
				break;
			}
		}
	}

	$inner = substr( $body, $start_index, $end_index - $start_index );

	// Collapse whitespace and pull all single- and double-quoted strings.
	preg_match_all( "/(?<![\\\\])'([^']+)'|\"([^\"]+)\"/", $inner, $matches );
	$out = [];
	for ( $i = 0; $i < count( $matches[0] ); $i++ ) {
		$out[] = $matches[1][ $i ] !== '' ? $matches[1][ $i ] : $matches[2][ $i ];
	}
	return $out;
}

/**
 * Determine source ('free' | 'pro' | 'wc') from the absolute file path.
 */
function source_for_path( string $path ): string {
	$normalised = str_replace( '\\', '/', $path );
	if ( str_contains( $normalised, '/elementor/includes/widgets/' ) ) {
		return 'free';
	}
	if ( str_contains( $normalised, '/pro-elements/modules/woocommerce/widgets/' ) ) {
		return 'wc';
	}
	if ( str_contains( $normalised, '/pro-elements/modules/' ) ) {
		return 'pro';
	}
	return 'unknown';
}

/** Return a stable path relative to wp-content/plugins. */
function portable_source_path( string $path, string $plugins_dir ): string {
	$normalised = str_replace( '\\', '/', $path );
	$prefix     = rtrim( $plugins_dir, '/' ) . '/';
	return str_starts_with( $normalised, $prefix ) ? substr( $normalised, strlen( $prefix ) ) : basename( $normalised );
}

function should_skip( string $path, array $skip_basenames, array $skip_patterns ): bool {
	$basename = basename( $path );
	if ( in_array( $basename, $skip_basenames, true ) ) {
		return true;
	}
	foreach ( $skip_patterns as $pattern ) {
		if ( preg_match( $pattern, $basename ) ) {
			return true;
		}
	}
	// Skip files in `traits/` subdirs.
	if ( str_contains( str_replace( '\\', '/', $path ), '/widgets/traits/' ) ) {
		return true;
	}
	return false;
}

// ---------------------------------------------------------------------------
// Run.
// ---------------------------------------------------------------------------

$widgets = [];
$skipped = [];
$failed  = [];

foreach ( $roots as $root ) {
	$files = glob( $root['glob'] ) ?: [];
	foreach ( $files as $path ) {
		if ( should_skip( $path, $skip_basenames, $skip_patterns ) ) {
			$skipped[] = $path;
			continue;
		}

		$source = file_get_contents( $path );
		if ( $source === false ) {
			$failed[] = [ 'path' => $path, 'reason' => 'unreadable' ];
			continue;
		}

		// Real widget = has `extends ... Widget_Base` or a known Pro base class.
		if ( ! preg_match( '/class\s+\w+\s+extends\s+[\w\\\\]*(Widget_Base|Base_Widget|Widget_Heading|Widget_Image|Common_Widget|Widget_Common|Carousel_Base|Posts_Base|Products_Base|Widget_Button|Widget_Icon_Box)/', $source ) ) {
			$skipped[] = $path;
			continue;
		}

		$name       = extract_string_return( method_body( $source, 'get_name' ) );
		$title      = extract_string_return( method_body( $source, 'get_title' ) );
		$icon       = extract_string_return( method_body( $source, 'get_icon' ) );
		$categories = extract_array_return( method_body( $source, 'get_categories' ) );
		$keywords   = extract_array_return( method_body( $source, 'get_keywords' ) );

		// Some Pro widgets inherit get_categories() / get_keywords() from a
		// base class — we'll know from the empty array. Mark them as
		// inheriting so manifest enrichment can walk the parent chain manually.
		$widgets[] = [
			'slug'                => $name,
			'title'               => $title,
			'icon'                => $icon,
			'categories'          => $categories,
			'keywords'            => $keywords,
			'source'              => source_for_path( $path ),
			'file'                => portable_source_path( $path, $plugins_dir ),
			'inherits_categories' => empty( $categories ),
			'inherits_keywords'   => empty( $keywords ),
		];
	}
}

// Sort by source then slug for deterministic output.
usort(
	$widgets,
	function ( array $a, array $b ): int {
		$sa = $a['source'] <=> $b['source'];
		if ( $sa !== 0 ) {
			return $sa;
		}
		return ( $a['slug'] ?? '' ) <=> ( $b['slug'] ?? '' );
	}
);

$summary = [
	'free' => count( array_filter( $widgets, fn( $w ) => $w['source'] === 'free' ) ),
	'pro'  => count( array_filter( $widgets, fn( $w ) => $w['source'] === 'pro' ) ),
	'wc'   => count( array_filter( $widgets, fn( $w ) => $w['source'] === 'wc' ) ),
];

$out = [
	'version'           => '1.0.0',
	'generated_at'      => gmdate( 'c' ),
	'generator'         => 'plugin/bin/widget-inventory-extract.php',
	'elementor_version' => null, // resolved by a follow-up step if needed
	'pro_version'       => null,
	'summary'           => array_merge( $summary, [ 'total' => count( $widgets ) ] ),
	'widgets'           => $widgets,
	'skipped_files'     => array_map( fn( $p ) => portable_source_path( $p, $plugins_dir ), $skipped ),
	'failed_files'      => $failed,
];

echo json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
