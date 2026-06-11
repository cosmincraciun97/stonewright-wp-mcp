<?php
/**
 * Widget manifest synthesizer.
 *
 * Merges three independent data sources into the canonical widget manifest
 * consumed by widget abilities, smart detection, custom-widget creation, and
 * knowledge refresh:
 *
 *   1. docs/elementor/widget-registry-data/widget-inventory.json
 *   2. docs/elementor/widget-registry-data/widget-controls/*.json
 *   3. docs/knowledge/elementor/widgets/*.md
 *
 * Output: plugin/includes/Elementor/WidgetRegistry/manifest.json
 *
 * Per-widget record shape:
 *   {
 *     source:               'free' | 'pro' | 'wc',
 *     widget_type:          '<elementor get_name>',
 *     title:                '...',
 *     icon:                 'eicon-...',
 *     categories:           [...],
 *     keywords:             [...],
 *     file:                 '<absolute PHP path>',
 *     intent:               '...' | null,
 *     use_cases:            [...],
 *     settings_highlights:  [...],
 *     limits:               [...],
 *     sections: [
 *       { id, label, tab, condition,
 *         controls: [{ key, type, label, default, options?, condition?, dynamic?, responsive, description? }],
 *         group_controls: [{ group, name (prefix), label, selector, condition?, include?, exclude? }],
 *       },
 *       ...
 *     ],
 *     settings_index: { '<control_key>': { section, type, default, group } },
 *     group_activators: { '<prefix>_<group>': '<activator_value>' },
 *     required_for_render: [...]  (best-effort, populated from RENDER_HINTS),
 *     knowledge_sources: [ 'docs/knowledge/elementor/widgets/...md', ... ],
 *     control_count: int,
 *     extracted_at: ISO 8601,
 *   }
 *
 * `group_activators` maps the activator setting key (the `<prefix>_<group>`
 * Elementor toggle) to its required value (`'classic'` for background,
 * `'solid'` for border, `'custom'` for typography, etc.). The
 * StyleMapper::activate_groups() emits these whenever a sub-key of the
 * group is set; downstream widget abilities also fold them into the
 * settings dict for any group sub-key they accept.
 *
 * Usage: php plugin/bin/manifest-synthesize.php
 */
declare(strict_types=1);

$repo_root        = realpath( __DIR__ . '/../..' );
$inventory_path   = $repo_root . '/docs/elementor/widget-registry-data/widget-inventory.json';
$controls_dir     = $repo_root . '/docs/elementor/widget-registry-data/widget-controls';
$knowledge_dir    = $repo_root . '/docs/knowledge/elementor/widgets';
$manifest_dir     = $repo_root . '/plugin/includes/Elementor/WidgetRegistry';
$manifest_path    = $manifest_dir . '/manifest.json';

if ( ! is_dir( $manifest_dir ) ) {
	mkdir( $manifest_dir, 0777, true );
}

// ---------------------------------------------------------------------------
// Activator semantics — must agree with
// plugin/includes/Elementor/Renderer/StyleMapper.php::group_rules()
// ---------------------------------------------------------------------------
$group_activator_value = [
	'typography'      => 'custom',
	'border'          => 'solid',
	'background'      => 'classic',
	'box-shadow'      => 'yes',
	'text-shadow'     => 'yes',
	'css-filter'      => 'custom',
	'text-stroke'     => 'yes',
	'image-size'      => 'custom',   // image_size acts on its own; activator is rare
];

// Group base names — what the Elementor toggle key suffix actually is.
$group_activator_suffix = [
	'typography'      => 'typography',
	'border'          => 'border',
	'background'      => 'background',
	'box-shadow'      => 'box_shadow',
	'text-shadow'     => 'text_shadow',
	'css-filter'      => 'css_filter',
	'text-stroke'     => 'text_stroke',
	'image-size'      => 'image_size',
];

// ---------------------------------------------------------------------------
// RENDER_HINTS — hand-curated minimum-keys-to-render hints for known widgets.
// Page-building relies on knowing which props must be present;
// for the rest, we leave required_for_render empty and let the renderer
// fall back to defaults.
// ---------------------------------------------------------------------------
$render_hints = [
	'heading'        => [ 'title' ],
	'text-editor'    => [ 'editor' ],
	'paragraph'      => [ 'editor' ],
	'button'         => [ 'text' ],
	'image'          => [ 'image' ],
	'icon'           => [ 'selected_icon' ],
	'icon-box'       => [ 'title_text', 'selected_icon' ],
	'icon-list'      => [ 'icon_list' ],
	'spacer'         => [ 'space' ],
	'divider'        => [],
	'video'          => [ 'video_type' ],
	'image-box'      => [ 'image', 'title_text' ],
	'progress'       => [ 'percent' ],
	'star-rating'    => [ 'rating' ],
	'testimonial'    => [ 'testimonial_content' ],
	'tabs'           => [ 'tabs' ],
	'accordion'      => [ 'tabs' ],
	'toggle'         => [ 'tabs' ],
	'social-icons'   => [ 'social_icon_list' ],
	'nav-menu'       => [ 'menu' ],
	'countdown'      => [ 'due_date' ],
	'gallery'        => [ 'wp_gallery' ],
	'image-carousel' => [ 'carousel' ],
	'image-gallery'  => [ 'wp_gallery' ],
	'price-table'    => [ 'features_list' ],
	'price-list'     => [ 'price_list' ],
	'flip-box'       => [ 'title_text_a', 'title_text_b' ],
	'call-to-action' => [ 'title' ],
	'animated-headline' => [ 'before_text' ],
];

// ---------------------------------------------------------------------------
// Helpers.
// ---------------------------------------------------------------------------

function read_json( string $path ): ?array {
	if ( ! is_file( $path ) ) {
		return null;
	}
	$raw = file_get_contents( $path );
	if ( $raw === false ) {
		return null;
	}
	if ( substr( $raw, 0, 3 ) === "\xEF\xBB\xBF" ) {
		$raw = substr( $raw, 3 );
	}
	$d = json_decode( $raw, true );
	return is_array( $d ) ? $d : null;
}

/**
 * Find harvested help articles whose filename relates to a widget slug.
 * Tries several heuristics — exact `<slug>.md`, `<slug>-widget.md`,
 * `<slug>-widget-pro.md`, `<slug>-intent.md`, plus alternative widget
 * names (e.g. `google_maps` slug → `google-maps-widget.md`).
 * Returns up to 4 file paths in priority order.
 */
function find_knowledge_files( string $slug, string $knowledge_dir ): array {
	$dash = str_replace( '_', '-', $slug );
	$candidates = [
		"$slug.md",
		"$dash.md",
		"$slug-widget.md",
		"$dash-widget.md",
		"$slug-widget-pro.md",
		"$dash-widget-pro.md",
		"$slug-intent.md",
		"$dash-intent.md",
	];
	$matches = [];
	foreach ( $candidates as $cand ) {
		$path = $knowledge_dir . '/' . $cand;
		if ( is_file( $path ) ) {
			$matches[] = $path;
		}
	}
	// Fallback — glob anything starting with the slug.
	$fuzzy = glob( $knowledge_dir . '/' . $dash . '*.md' ) ?: [];
	foreach ( $fuzzy as $p ) {
		if ( ! in_array( $p, $matches, true ) ) {
			$matches[] = $p;
		}
	}
	return array_slice( $matches, 0, 4 );
}

/**
 * Parse a harvested markdown file. Returns:
 *   { purpose: string|null, use_cases: [], settings_highlights: [], limits: [] }
 */
function parse_knowledge( string $path ): array {
	$out = [
		'purpose'             => null,
		'use_cases'           => [],
		'settings_highlights' => [],
		'limits'              => [],
	];
	$raw = file_get_contents( $path );
	if ( $raw === false ) {
		return $out;
	}
	// Strip frontmatter.
	$body = $raw;
	if ( strpos( $raw, "---" ) === 0 ) {
		$end = strpos( $raw, "\n---", 3 );
		if ( $end !== false ) {
			$body = substr( $raw, $end + 4 );
		}
	}

	// Split into sections by `## Heading` lines.
	$sections = [];
	$current_h = null;
	$current_lines = [];
	foreach ( preg_split( "/\r?\n/", $body ) as $line ) {
		if ( preg_match( '/^##\s+(.+)$/', $line, $m ) ) {
			if ( $current_h !== null ) {
				$sections[ $current_h ] = $current_lines;
			}
			$current_h = strtolower( trim( $m[1] ) );
			$current_lines = [];
			continue;
		}
		if ( $current_h !== null ) {
			$current_lines[] = $line;
		}
	}
	if ( $current_h !== null ) {
		$sections[ $current_h ] = $current_lines;
	}

	// Purpose: first non-empty paragraph under "purpose".
	if ( isset( $sections['purpose'] ) ) {
		$buf = [];
		foreach ( $sections['purpose'] as $l ) {
			$t = trim( $l );
			if ( $t === '' && ! empty( $buf ) ) {
				break;
			}
			if ( $t !== '' ) {
				$buf[] = $t;
			}
		}
		$out['purpose'] = implode( ' ', $buf ) ?: null;
	}

	// Bullet collectors.
	foreach ( [ 'use this when' => 'use_cases', 'settings highlights' => 'settings_highlights', 'limits / gotchas' => 'limits' ] as $key => $field ) {
		if ( isset( $sections[ $key ] ) ) {
			foreach ( $sections[ $key ] as $l ) {
				if ( preg_match( '/^\s*[-*]\s+(.+)$/', $l, $m ) ) {
					$out[ $field ][] = trim( $m[1] );
				}
			}
		}
	}
	return $out;
}

/**
 * Build a flat settings_index from the control sections.
 * Returns { key => { section, type, default, group, group_prefix, responsive, condition } }
 */
function build_settings_index( array $sections ): array {
	$idx = [];
	foreach ( $sections as $s ) {
		$section_id = $s['id'] ?? null;
		foreach ( ( $s['controls'] ?? [] ) as $c ) {
			$key = $c['key'] ?? null;
			if ( $key === null ) {
				continue;
			}
			$idx[ $key ] = [
				'section'    => $section_id,
				'type'       => $c['type']      ?? null,
				'default'    => $c['default']   ?? null,
				'responsive' => (bool) ( $c['responsive'] ?? false ),
				'condition'  => $c['condition'] ?? null,
				'group'      => null,
				'group_prefix' => null,
			];
		}
		foreach ( ( $s['group_controls'] ?? [] ) as $g ) {
			$group  = $g['group'] ?? null;
			$prefix = $g['name']  ?? null;
			if ( ! is_string( $group ) || ! is_string( $prefix ) ) {
				continue;
			}
			// Mark the activator key as well — Elementor stores the group toggle
			// under <prefix>_<group_base>.
			global $group_activator_suffix;
			$suffix = $group_activator_suffix[ $group ] ?? str_replace( '-', '_', $group );
			$idx[ $prefix . '_' . $suffix ] = [
				'section'      => $section_id,
				'type'         => 'group_activator',
				'default'      => null,
				'responsive'   => false,
				'condition'    => $g['condition'] ?? null,
				'group'        => $group,
				'group_prefix' => $prefix,
			];
		}
	}
	return $idx;
}

/**
 * Build the group_activators map for a widget: { '<prefix>_<group_base>' => '<value>' }.
 */
function build_group_activators( array $sections ): array {
	global $group_activator_value, $group_activator_suffix;
	$out = [];
	foreach ( $sections as $s ) {
		foreach ( ( $s['group_controls'] ?? [] ) as $g ) {
			$group  = $g['group'] ?? null;
			$prefix = $g['name']  ?? null;
			if ( ! is_string( $group ) || ! is_string( $prefix ) ) {
				continue;
			}
			$suffix = $group_activator_suffix[ $group ] ?? str_replace( '-', '_', $group );
			$value  = $group_activator_value[ $group ] ?? 'custom';
			$out[ $prefix . '_' . $suffix ] = $value;
		}
	}
	return $out;
}

// ---------------------------------------------------------------------------
// Drive.
// ---------------------------------------------------------------------------

$inventory = read_json( $inventory_path );
if ( $inventory === null || empty( $inventory['widgets'] ) ) {
	fwrite( STDERR, "ERROR: invalid widget-inventory.json\n" );
	exit( 1 );
}

$manifest = [
	'version'           => '1.0.0',
	'generated_at'      => gmdate( 'c' ),
	'generator'         => 'plugin/bin/manifest-synthesize.php',
	'elementor_version' => $inventory['elementor_version'] ?? null,
	'pro_version'       => $inventory['pro_version']       ?? null,
	'sources'           => [
		'inventory'  => 'docs/elementor/widget-registry-data/widget-inventory.json',
		'controls'   => 'docs/elementor/widget-registry-data/widget-controls/',
		'knowledge'  => 'docs/knowledge/elementor/widgets/',
	],
	'group_activator_rules' => [
		'typography'  => 'custom',
		'border'      => 'solid',
		'background'  => 'classic',
		'box-shadow'  => 'yes',
		'text-shadow' => 'yes',
		'css-filter'  => 'custom',
		'text-stroke' => 'yes',
	],
	'totals' => [
		'inventory_widgets'   => count( $inventory['widgets'] ),
		'with_controls'       => 0,
		'with_knowledge'      => 0,
		'with_group_activators' => 0,
	],
	'widgets' => [],
];

foreach ( $inventory['widgets'] as $w ) {
	$slug = $w['slug'] ?? null;
	if ( ! is_string( $slug ) ) {
		continue;
	}

	$controls = read_json( $controls_dir . '/' . $slug . '.json' );
	if ( $controls !== null ) {
		$manifest['totals']['with_controls']++;
	}

	$knowledge_files = find_knowledge_files( $slug, $knowledge_dir );
	$knowledge       = [
		'purpose'             => null,
		'use_cases'           => [],
		'settings_highlights' => [],
		'limits'              => [],
	];
	foreach ( $knowledge_files as $kf ) {
		$parsed = parse_knowledge( $kf );
		if ( $knowledge['purpose'] === null && $parsed['purpose'] !== null ) {
			$knowledge['purpose'] = $parsed['purpose'];
		}
		$knowledge['use_cases']           = array_unique( array_merge( $knowledge['use_cases'],           $parsed['use_cases'] ) );
		$knowledge['settings_highlights'] = array_unique( array_merge( $knowledge['settings_highlights'], $parsed['settings_highlights'] ) );
		$knowledge['limits']              = array_unique( array_merge( $knowledge['limits'],              $parsed['limits'] ) );
	}
	if ( ! empty( $knowledge_files ) ) {
		$manifest['totals']['with_knowledge']++;
	}

	$sections        = $controls['sections']        ?? [];
	$group_controls  = $controls['group_controls']  ?? [];
	$repeaters       = $controls['repeaters']       ?? [];
	$settings_index  = build_settings_index( $sections );
	$group_activators = build_group_activators( $sections );

	if ( ! empty( $group_activators ) ) {
		$manifest['totals']['with_group_activators']++;
	}

	$manifest['widgets'][ $slug ] = [
		'slug'                => $slug,
		'source'              => $w['source']       ?? null,
		'widget_type'         => $slug,
		'title'               => $w['title']        ?? null,
		'icon'                => $w['icon']         ?? null,
		'categories'          => $w['categories']   ?? [],
		'keywords'            => $w['keywords']     ?? [],
		'file'                => $w['file']         ?? null,
		'intent'              => $knowledge['purpose'],
		'use_cases'           => array_values( $knowledge['use_cases'] ),
		'settings_highlights' => array_values( $knowledge['settings_highlights'] ),
		'limits'              => array_values( $knowledge['limits'] ),
		'sections'            => $sections,
		'group_controls'      => $group_controls,
		'repeaters'           => $repeaters,
		'settings_index'      => $settings_index,
		'group_activators'    => $group_activators,
		'required_for_render' => $render_hints[ $slug ] ?? [],
		'knowledge_sources'   => array_map( fn( $p ) => str_replace( $repo_root . DIRECTORY_SEPARATOR, '', str_replace( '\\', '/', $p ) ), $knowledge_files ),
		'control_count'       => count( $settings_index ),
	];
}

ksort( $manifest['widgets'] );

file_put_contents(
	$manifest_path,
	json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n"
);

$php_manifest_path = dirname( $manifest_path ) . '/manifest.php';
file_put_contents(
	$php_manifest_path,
	"<?php\n// phpcs:ignoreFile -- generated manifest cache.\ndeclare( strict_types=1 );\nreturn " . var_export( $manifest, true ) . ";\n"
);

fwrite( STDOUT, "Manifest written: $manifest_path\n" );
fwrite( STDOUT, "PHP Manifest written: $php_manifest_path\n" );
fwrite( STDOUT, "  inventory_widgets:     " . $manifest['totals']['inventory_widgets'] . "\n" );
fwrite( STDOUT, "  with_controls:         " . $manifest['totals']['with_controls'] . "\n" );
fwrite( STDOUT, "  with_knowledge:        " . $manifest['totals']['with_knowledge'] . "\n" );
fwrite( STDOUT, "  with_group_activators: " . $manifest['totals']['with_group_activators'] . "\n" );
