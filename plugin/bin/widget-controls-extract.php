<?php
/**
 * Phase A.2 — Per-widget control extractor (Stonewright Elementor Mastery).
 *
 * Reads the inventory produced by Phase A.1
 * (`docs/superpowers/data/widget-inventory.json`) and, for each widget,
 * parses the PHP source with `nikic/php-parser` to extract the
 * `register_controls()` graph:
 *
 *   - Section boundaries (`start_controls_section('id', [...])`)
 *   - Tabs (`start_controls_tabs`/`tab`)
 *   - Controls (`add_control('key', [...])`)
 *   - Responsive controls (`add_responsive_control('key', [...])`)
 *   - Group controls (`add_group_control(Group_Control_*::get_type(), [...])`)
 *   - Repeaters (`new Repeater()` → `$rep->add_control(...)`)
 *
 * Also follows local helper calls (`$this->register_button_content_controls()`)
 * and includes controls coming from `use Foo_Trait` traits when the trait file
 * lives next to the widget (Elementor's `traits/` subdir).
 *
 * Output: one file per widget at
 * `docs/superpowers/data/widget-controls/<slug>.json`
 * plus an aggregate `docs/superpowers/data/widget-controls/_summary.json`.
 *
 * Resolution caveats — tolerant by design:
 *   - `Controls_Manager::TEXT` etc. resolved via a hard-coded map.
 *   - `Group_Control_X::get_type()` resolved via a hard-coded map.
 *   - `esc_html__('foo', ...)` / `__('foo', ...)` / `esc_attr__('foo', ...)`
 *      unwrapped to the literal string.
 *   - Unresolved literals fall through as `null`; the surrounding control
 *      entry still gets emitted with what we could read. The script logs
 *      counts of unresolved cases per widget into `_summary.json`.
 *
 * Usage:
 *   php plugin/bin/widget-controls-extract.php
 *
 * Run from any cwd; paths are absolute or anchored to repo root.
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

// ---------------------------------------------------------------------------
// Config.
// ---------------------------------------------------------------------------

$repo_root        = realpath( __DIR__ . '/../..' );
$inventory_path   = $repo_root . '/docs/superpowers/data/widget-inventory.json';
$output_dir       = $repo_root . '/docs/superpowers/data/widget-controls';
$summary_path     = $output_dir . '/_summary.json';

if ( ! is_dir( $output_dir ) ) {
	mkdir( $output_dir, 0777, true );
}

// ---------------------------------------------------------------------------
// Constant maps (Elementor source — verified against
// `elementor/includes/managers/controls.php` and
// `elementor/includes/controls/groups/*.php`).
// ---------------------------------------------------------------------------

$controls_manager_constants = [
	// Tabs.
	'TAB_CONTENT'    => 'content',
	'TAB_STYLE'      => 'style',
	'TAB_ADVANCED'   => 'advanced',
	'TAB_RESPONSIVE' => 'responsive',
	'TAB_LAYOUT'     => 'layout',
	'TAB_SETTINGS'   => 'settings',
	// Types.
	'TEXT'              => 'text',
	'NUMBER'            => 'number',
	'TEXTAREA'          => 'textarea',
	'SELECT'            => 'select',
	'SWITCHER'          => 'switcher',
	'BUTTON'            => 'button',
	'HIDDEN'            => 'hidden',
	'HEADING'           => 'heading',
	'RAW_HTML'          => 'raw_html',
	'NOTICE'            => 'notice',
	'DEPRECATED_NOTICE' => 'deprecated_notice',
	'ALERT'             => 'alert',
	'POPOVER_TOGGLE'    => 'popover_toggle',
	'SECTION'           => 'section',
	'TAB'               => 'tab',
	'TABS'              => 'tabs',
	'DIVIDER'           => 'divider',
	'COLOR'             => 'color',
	'MEDIA'             => 'media',
	'SLIDER'            => 'slider',
	'DIMENSIONS'        => 'dimensions',
	'CHOOSE'            => 'choose',
	'VISUAL_CHOICE'     => 'visual_choice',
	'WYSIWYG'           => 'wysiwyg',
	'CODE'              => 'code',
	'FONT'              => 'font',
	'IMAGE_DIMENSIONS'  => 'image_dimensions',
	'WP_WIDGET'         => 'wp_widget',
	'URL'               => 'url',
	'REPEATER'          => 'repeater',
	'ICON'              => 'icon',
	'ICONS'             => 'icons',
	'GALLERY'           => 'gallery',
	'STRUCTURE'         => 'structure',
	'SELECT2'           => 'select2',
	'DATE_TIME'         => 'date_time',
	'BOX_SHADOW'        => 'box_shadow',
	'TEXT_SHADOW'       => 'text_shadow',
	'ANIMATION'         => 'animation',
	'HOVER_ANIMATION'   => 'hover_animation',
	'EXIT_ANIMATION'    => 'exit_animation',
	'GAPS'              => 'gaps',
];

$group_control_types = [
	'Group_Control_Background'      => 'background',
	'Group_Control_Border'          => 'border',
	'Group_Control_Box_Shadow'      => 'box-shadow',
	'Group_Control_Css_Filter'      => 'css-filter',
	'Group_Control_Flex_Container'  => 'flex-container',
	'Group_Control_Flex_Item'       => 'flex-item',
	'Group_Control_Grid_Container'  => 'grid-container',
	'Group_Control_Image_Size'      => 'image-size',
	'Group_Control_Text_Shadow'     => 'text-shadow',
	'Group_Control_Text_Stroke'     => 'text-stroke',
	'Group_Control_Typography'      => 'typography',
];

// ---------------------------------------------------------------------------
// Helpers — generic AST resolution.
// ---------------------------------------------------------------------------

/**
 * Try to flatten a PhpParser expression node into a PHP scalar /
 * array. Returns `[true, <value>]` if resolved, `[false, null]` otherwise.
 */
function resolve_value( Node $node, array $context ): array {
	global $controls_manager_constants, $group_control_types;

	if ( $node instanceof Node\Scalar\String_ ) {
		return [ true, $node->value ];
	}
	if ( $node instanceof Node\Scalar\LNumber || $node instanceof Node\Scalar\DNumber ) {
		return [ true, $node->value ];
	}
	if ( $node instanceof Node\Expr\ConstFetch ) {
		$name = strtolower( $node->name->toString() );
		if ( $name === 'true' ) {
			return [ true, true ];
		}
		if ( $name === 'false' ) {
			return [ true, false ];
		}
		if ( $name === 'null' ) {
			return [ true, null ];
		}
		return [ false, $node->name->toString() ];
	}
	if ( $node instanceof Node\Expr\ClassConstFetch ) {
		$class = $node->class instanceof Node\Name ? $node->class->toString() : '';
		$const = $node->name instanceof Node\Identifier ? $node->name->name : '';
		// Strip leading namespace if present (e.g. Elementor\Controls_Manager).
		$short = ltrim( substr( $class, (int) strrpos( $class, '\\' ) ), '\\' );
		if ( $short === 'Controls_Manager' && isset( $controls_manager_constants[ $const ] ) ) {
			return [ true, $controls_manager_constants[ $const ] ];
		}
		return [ false, $short . '::' . $const ];
	}
	if ( $node instanceof Node\Expr\StaticCall ) {
		// Group_Control_X::get_type() → 'x'.
		$class = $node->class instanceof Node\Name ? $node->class->toString() : '';
		$short = ltrim( substr( $class, (int) strrpos( $class, '\\' ) ), '\\' );
		$method = $node->name instanceof Node\Identifier ? $node->name->name : '';
		if ( $method === 'get_type' && isset( $group_control_types[ $short ] ) ) {
			return [ true, $group_control_types[ $short ] ];
		}
		return [ false, $short . '::' . $method . '()' ];
	}
	if ( $node instanceof Node\Expr\FuncCall ) {
		$name = $node->name instanceof Node\Name ? $node->name->toString() : '';
		// Translation wrappers.
		if ( in_array( $name, [ '__', 'esc_html__', 'esc_attr__', 'esc_html_x', 'esc_attr_x', '_x' ], true ) ) {
			if ( isset( $node->args[0] ) ) {
				return resolve_value( $node->args[0]->value, $context );
			}
		}
		return [ false, $name . '()' ];
	}
	if ( $node instanceof Node\Expr\Array_ ) {
		$out = [];
		$is_list = true;
		$next_idx = 0;
		foreach ( $node->items as $item ) {
			if ( $item === null ) {
				continue;
			}
			$key = null;
			if ( $item->key !== null ) {
				$is_list = false;
				$k = resolve_value( $item->key, $context );
				$key = $k[0] ? $k[1] : null;
			}
			$v = resolve_value( $item->value, $context );
			$value = $v[0] ? $v[1] : [ '__unresolved__' => $v[1] ];
			if ( $key === null ) {
				$out[] = $value;
			} else {
				$out[ (string) $key ] = $value;
			}
		}
		return [ true, $out ];
	}
	if ( $node instanceof Node\Expr\BinaryOp\Concat ) {
		$left  = resolve_value( $node->left, $context );
		$right = resolve_value( $node->right, $context );
		if ( $left[0] && $right[0] ) {
			return [ true, (string) $left[1] . (string) $right[1] ];
		}
	}
	if ( $node instanceof Node\Expr\Variable ) {
		// Cannot resolve runtime values.
		return [ false, '$' . $node->name ];
	}
	if ( $node instanceof Node\Expr\MethodCall ) {
		// $this->whatever() — not a value we can flatten.
		$name = $node->name instanceof Node\Identifier ? $node->name->name : '?';
		return [ false, '->' . $name . '()' ];
	}
	return [ false, get_class( $node ) ];
}

/**
 * Extract a control entry from `add_control` / `add_responsive_control`
 * arguments.
 */
function extract_control( array $args, bool $responsive, array $context ): ?array {
	if ( count( $args ) < 2 ) {
		return null;
	}
	$key_node  = $args[0]->value;
	$opts_node = $args[1]->value;

	$key_r  = resolve_value( $key_node, $context );
	$opts_r = resolve_value( $opts_node, $context );
	if ( ! $key_r[0] || ! is_string( $key_r[1] ) ) {
		return null;
	}
	$key = $key_r[1];
	$opts = is_array( $opts_r[1] ) ? $opts_r[1] : [];
	return [
		'key'        => $key,
		'type'       => $opts['type']  ?? null,
		'label'      => $opts['label'] ?? null,
		'default'    => array_key_exists( 'default', $opts ) ? $opts['default'] : null,
		'options'    => $opts['options']    ?? null,
		'condition'  => $opts['condition']  ?? ( $opts['conditions'] ?? null ),
		'dynamic'    => $opts['dynamic']    ?? null,
		'responsive' => $responsive,
		'description'=> $opts['description'] ?? null,
	];
}

/**
 * Extract a group-control entry from `add_group_control` arguments.
 */
function extract_group_control( array $args, array $context ): ?array {
	if ( count( $args ) < 1 ) {
		return null;
	}
	$type_node = $args[0]->value;
	$type_r    = resolve_value( $type_node, $context );
	$type      = ( $type_r[0] && is_string( $type_r[1] ) ) ? $type_r[1] : null;

	$opts = [];
	if ( isset( $args[1] ) ) {
		$opts_r = resolve_value( $args[1]->value, $context );
		$opts   = is_array( $opts_r[1] ) ? $opts_r[1] : [];
	}
	return [
		'group'     => $type,
		'name'      => $opts['name']      ?? null,  // prefix
		'label'     => $opts['label']     ?? null,
		'selector'  => $opts['selector']  ?? null,
		'condition' => $opts['condition'] ?? ( $opts['conditions'] ?? null ),
		'exclude'   => $opts['exclude']   ?? null,
		'include'   => $opts['include']   ?? null,
	];
}

/**
 * Extract a section header.
 */
function extract_section( array $args, array $context ): ?array {
	if ( count( $args ) < 1 ) {
		return null;
	}
	$id_r = resolve_value( $args[0]->value, $context );
	$id   = ( $id_r[0] && is_string( $id_r[1] ) ) ? $id_r[1] : null;

	$opts = [];
	if ( isset( $args[1] ) ) {
		$opts_r = resolve_value( $args[1]->value, $context );
		$opts   = is_array( $opts_r[1] ) ? $opts_r[1] : [];
	}
	return [
		'id'        => $id,
		'label'     => $opts['label']     ?? null,
		'tab'       => $opts['tab']       ?? null,
		'condition' => $opts['condition'] ?? ( $opts['conditions'] ?? null ),
	];
}

// ---------------------------------------------------------------------------
// Visitor — walks a single method's statements, fans out into helper
// methods declared inside the class or its traits.
// ---------------------------------------------------------------------------

class ControlsCollector extends NodeVisitorAbstract {

	public array $sections          = [];
	public array $current_section   = []; // stack
	public array $tab_stack         = [];
	public array $group_controls    = [];
	public array $repeaters         = [];
	public array $diagnostics       = [
		'unresolved_args'         => 0,
		'unresolved_helper_calls' => [],
		'recursion_depth_hits'    => 0,
	];

	private array $method_bodies = [];   // method name => Stmt[]
	private array $visited       = [];   // method names already walked (recursion guard)
	private array $context       = [];   // namespace etc.

	public function __construct( array $method_bodies, array $context ) {
		$this->method_bodies = $method_bodies;
		$this->context       = $context;
	}

	public function walk_method( string $method_name, int $depth = 0 ): void {
		if ( $depth > 8 ) {
			$this->diagnostics['recursion_depth_hits']++;
			return;
		}
		if ( isset( $this->visited[ $method_name ] ) ) {
			return;
		}
		$this->visited[ $method_name ] = true;
		if ( ! isset( $this->method_bodies[ $method_name ] ) ) {
			$this->diagnostics['unresolved_helper_calls'][] = $method_name;
			return;
		}
		foreach ( $this->method_bodies[ $method_name ] as $stmt ) {
			$this->process_stmt( $stmt, $depth );
		}
	}

	private function process_stmt( Node $node, int $depth ): void {
		// Walk into compound nodes.
		if ( $node instanceof Node\Stmt\If_ ) {
			foreach ( $node->stmts as $s ) {
				$this->process_stmt( $s, $depth );
			}
			foreach ( $node->elseifs as $elseif ) {
				foreach ( $elseif->stmts as $s ) {
					$this->process_stmt( $s, $depth );
				}
			}
			if ( $node->else ) {
				foreach ( $node->else->stmts as $s ) {
					$this->process_stmt( $s, $depth );
				}
			}
			return;
		}
		if ( $node instanceof Node\Stmt\Foreach_ ) {
			foreach ( $node->stmts as $s ) {
				$this->process_stmt( $s, $depth );
			}
			return;
		}
		if ( $node instanceof Node\Stmt\For_ || $node instanceof Node\Stmt\While_ ) {
			foreach ( $node->stmts as $s ) {
				$this->process_stmt( $s, $depth );
			}
			return;
		}
		if ( $node instanceof Node\Stmt\TryCatch ) {
			foreach ( $node->stmts as $s ) {
				$this->process_stmt( $s, $depth );
			}
			return;
		}
		if ( $node instanceof Node\Stmt\Expression ) {
			$this->process_expr( $node->expr, $depth );
			return;
		}
		// Anything else is ignored.
	}

	private function process_expr( Node $expr, int $depth ): void {
		// $this->method(...)
		if ( $expr instanceof Node\Expr\MethodCall && $expr->var instanceof Node\Expr\Variable && $expr->var->name === 'this' ) {
			$method = $expr->name instanceof Node\Identifier ? $expr->name->name : null;
			if ( $method === null ) {
				return;
			}
			$args = $expr->args;
			switch ( $method ) {
				case 'start_controls_section':
					$section = extract_section( $args, $this->context );
					if ( $section !== null ) {
						$section['controls']       = [];
						$section['group_controls'] = [];
						$section['repeaters']      = [];
						$this->current_section[]   = count( $this->sections );
						$this->sections[]          = $section;
					}
					return;
				case 'end_controls_section':
					array_pop( $this->current_section );
					return;
				case 'start_controls_tabs':
					$this->tab_stack[] = [ 'kind' => 'tabs' ];
					return;
				case 'end_controls_tabs':
					array_pop( $this->tab_stack );
					return;
				case 'start_controls_tab':
				case 'end_controls_tab':
					return;
				case 'add_control':
				case 'add_responsive_control':
					$control = extract_control( $args, $method === 'add_responsive_control', $this->context );
					if ( $control !== null && ! empty( $this->current_section ) ) {
						$idx = end( $this->current_section );
						$this->sections[ $idx ]['controls'][] = $control;
					}
					return;
				case 'add_group_control':
					$group = extract_group_control( $args, $this->context );
					if ( $group !== null ) {
						$this->group_controls[] = $group;
						if ( ! empty( $this->current_section ) ) {
							$idx = end( $this->current_section );
							$this->sections[ $idx ]['group_controls'][] = $group;
						}
					}
					return;
				default:
					// Helper method on $this — recurse if known, else log.
					if ( isset( $this->method_bodies[ $method ] ) ) {
						$this->walk_method( $method, $depth + 1 );
					} else {
						// Heuristic: methods starting with `register_` or `add_` and
						// no body in this class are probably trait-provided
						// or unsupported (`add_existing_section_controls`, etc.)
						if ( str_starts_with( $method, 'register_' ) || str_starts_with( $method, 'add_existing' ) ) {
							$this->diagnostics['unresolved_helper_calls'][] = $method;
						}
					}
					return;
			}
		}
		// $repeater->add_control('field', [...]) is the inner repeater pattern.
		if ( $expr instanceof Node\Expr\MethodCall && $expr->var instanceof Node\Expr\Variable ) {
			$method = $expr->name instanceof Node\Identifier ? $expr->name->name : null;
			$obj    = $expr->var->name;
			if ( $method === 'add_control' && $obj !== 'this' ) {
				$control = extract_control( $expr->args, false, $this->context );
				if ( $control !== null ) {
					// Attach to last-seen repeater bucket keyed by var name.
					if ( ! isset( $this->repeaters[ $obj ] ) ) {
						$this->repeaters[ $obj ] = [ 'var' => $obj, 'fields' => [] ];
					}
					$this->repeaters[ $obj ]['fields'][] = $control;
				}
				return;
			}
		}
		// Assignment expressions can contain new Repeater(); track $repeater variables.
		if ( $expr instanceof Node\Expr\Assign && $expr->expr instanceof Node\Expr\New_ ) {
			$class = $expr->expr->class instanceof Node\Name ? $expr->expr->class->toString() : '';
			if ( str_ends_with( $class, 'Repeater' ) && $expr->var instanceof Node\Expr\Variable ) {
				$obj = $expr->var->name;
				$this->repeaters[ $obj ] = [ 'var' => $obj, 'fields' => [] ];
			}
		}
	}
}

// ---------------------------------------------------------------------------
// File-level orchestration.
// ---------------------------------------------------------------------------

/**
 * Parse a PHP file and return the class node (the one that extends a Widget
 * base) plus the list of `use Trait` names declared inside it.
 */
function parse_widget_class( string $path ): array {
	$parser = ( new ParserFactory() )->createForNewestSupportedVersion();
	$src    = file_get_contents( $path );
	if ( $src === false ) {
		return [ null, [], null ];
	}
	try {
		$ast = $parser->parse( $src );
	} catch ( Throwable $e ) {
		return [ null, [], $e->getMessage() ];
	}
	if ( $ast === null ) {
		return [ null, [], 'parser returned null' ];
	}

	$namespace = null;
	$class     = null;
	$traits    = [];

	$walker = static function ( $stmts ) use ( &$walker, &$namespace, &$class, &$traits ): void {
		foreach ( $stmts as $stmt ) {
			if ( $stmt instanceof Node\Stmt\Namespace_ ) {
				$namespace = $stmt->name ? $stmt->name->toString() : null;
				$walker( $stmt->stmts );
				continue;
			}
			if ( $stmt instanceof Node\Stmt\Class_ ) {
				$class = $stmt;
				foreach ( $stmt->stmts as $inner ) {
					if ( $inner instanceof Node\Stmt\TraitUse ) {
						foreach ( $inner->traits as $trait_name ) {
							$traits[] = $trait_name->toString();
						}
					}
				}
				continue;
			}
		}
	};
	$walker( $ast );

	return [ $class, $traits, [ 'namespace' => $namespace ] ];
}

/**
 * Build a method-bodies map from a class node, plus all trait files we can
 * resolve next to the widget file.
 */
function build_method_bodies( Node\Stmt\Class_ $class, array $traits, string $widget_file ): array {
	$bodies = [];

	foreach ( $class->stmts as $stmt ) {
		if ( $stmt instanceof Node\Stmt\ClassMethod ) {
			$bodies[ $stmt->name->name ] = $stmt->stmts ?? [];
		}
	}

	if ( ! empty( $traits ) ) {
		$widget_dir = dirname( $widget_file );
		$candidate_dirs = [
			$widget_dir . '/traits',
			dirname( $widget_dir ) . '/traits',
			dirname( $widget_dir, 2 ) . '/traits',
		];
		foreach ( $traits as $trait_fqn ) {
			$short = ltrim( substr( $trait_fqn, (int) strrpos( $trait_fqn, '\\' ) ), '\\' );
			$filename_candidates = [
				strtolower( str_replace( '_', '-', $short ) ) . '.php',
				strtolower( $short ) . '.php',
			];
			foreach ( $candidate_dirs as $dir ) {
				foreach ( $filename_candidates as $fn ) {
					$try = $dir . '/' . $fn;
					if ( is_file( $try ) ) {
						$bodies = array_merge( $bodies, parse_trait_methods( $try ) );
						break 2;
					}
				}
			}
		}
	}

	return $bodies;
}

/** Parse a trait file and return its method-name => stmts map. */
function parse_trait_methods( string $path ): array {
	$parser = ( new ParserFactory() )->createForNewestSupportedVersion();
	$src    = file_get_contents( $path );
	if ( $src === false ) {
		return [];
	}
	try {
		$ast = $parser->parse( $src );
	} catch ( Throwable $e ) {
		return [];
	}
	if ( $ast === null ) {
		return [];
	}
	$out = [];

	$walker = static function ( $stmts ) use ( &$walker, &$out ): void {
		foreach ( $stmts as $stmt ) {
			if ( $stmt instanceof Node\Stmt\Namespace_ ) {
				$walker( $stmt->stmts );
				continue;
			}
			if ( $stmt instanceof Node\Stmt\Trait_ ) {
				foreach ( $stmt->stmts as $inner ) {
					if ( $inner instanceof Node\Stmt\ClassMethod ) {
						$out[ $inner->name->name ] = $inner->stmts ?? [];
					}
				}
			}
		}
	};
	$walker( $ast );
	return $out;
}

// ---------------------------------------------------------------------------
// Drive.
// ---------------------------------------------------------------------------

$raw_inventory = file_get_contents( $inventory_path );
if ( $raw_inventory === false ) {
	fwrite( STDERR, "ERROR: cannot read widget-inventory.json\n" );
	exit( 1 );
}
// Strip UTF-8 BOM if present.
if ( substr( $raw_inventory, 0, 3 ) === "\xEF\xBB\xBF" ) {
	$raw_inventory = substr( $raw_inventory, 3 );
}
$inventory = json_decode( $raw_inventory, true );
if ( ! is_array( $inventory ) || empty( $inventory['widgets'] ) ) {
	fwrite( STDERR, "ERROR: invalid widget-inventory.json\n" );
	exit( 1 );
}

$summary = [
	'generated_at'       => gmdate( 'c' ),
	'generator'          => 'plugin/bin/widget-controls-extract.php',
	'inventory_source'   => 'docs/superpowers/data/widget-inventory.json',
	'widgets'            => count( $inventory['widgets'] ),
	'extracted'          => 0,
	'with_no_register'   => 0,
	'with_unresolved'    => 0,
	'parse_errors'       => 0,
	'failures'           => [],
	'unresolved_helpers' => [],
];

foreach ( $inventory['widgets'] as $w ) {
	$slug = $w['slug'] ?? null;
	$file = $w['file'] ?? null;
	if ( ! $slug || ! $file || ! is_file( $file ) ) {
		continue;
	}

	[ $class, $traits, $info ] = parse_widget_class( $file );
	if ( ! ( $class instanceof Node\Stmt\Class_ ) ) {
		$summary['parse_errors']++;
		$summary['failures'][] = [ 'slug' => $slug, 'file' => $file, 'reason' => is_string( $info ) ? $info : 'class not found' ];
		continue;
	}

	$method_bodies = build_method_bodies( $class, $traits, $file );

	if ( ! isset( $method_bodies['register_controls'] ) ) {
		$summary['with_no_register']++;
		$summary['failures'][] = [ 'slug' => $slug, 'file' => $file, 'reason' => 'no register_controls' ];
		continue;
	}

	$ctx = [ 'namespace' => $info['namespace'] ?? null, 'traits' => $traits ];
	$collector = new ControlsCollector( $method_bodies, $ctx );
	$collector->walk_method( 'register_controls' );

	$per_widget = [
		'slug'           => $slug,
		'source'         => $w['source'] ?? null,
		'title'          => $w['title'] ?? null,
		'file'           => $w['file'],
		'sections'       => $collector->sections,
		'group_controls' => $collector->group_controls,
		'repeaters'      => array_values( $collector->repeaters ),
		'diagnostics'    => $collector->diagnostics,
		'extracted_at'   => gmdate( 'c' ),
	];

	file_put_contents(
		$output_dir . '/' . $slug . '.json',
		json_encode( $per_widget, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n"
	);

	$summary['extracted']++;
	if ( ! empty( $collector->diagnostics['unresolved_helper_calls'] ) ) {
		$summary['with_unresolved']++;
		foreach ( $collector->diagnostics['unresolved_helper_calls'] as $helper ) {
			if ( ! isset( $summary['unresolved_helpers'][ $helper ] ) ) {
				$summary['unresolved_helpers'][ $helper ] = 0;
			}
			$summary['unresolved_helpers'][ $helper ]++;
		}
	}
}

file_put_contents(
	$summary_path,
	json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n"
);

fwrite( STDOUT, "Extracted " . $summary['extracted'] . " widgets, "
	. $summary['with_no_register'] . " skipped (no register_controls), "
	. $summary['parse_errors'] . " parse errors, "
	. $summary['with_unresolved'] . " with unresolved helpers.\n" );
fwrite( STDOUT, "Summary: " . $summary_path . "\n" );
