<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\WidgetBuilder;

/**
 * DSL → PHP compiler for Stonewright Elementor widget templates.
 *
 * Grammar (strict subset — anything else is a compile error):
 *
 *   template   ::= chunk*
 *   chunk      ::= variable | conditional | loop | text
 *   variable   ::= '{{' path '}}'
 *   conditional::= '{% if path %}' chunk* ('{% else %}' chunk*)? '{% endif %}'
 *   loop       ::= '{% for ident in path %}' chunk* '{% endfor %}'
 *   path       ::= ident ('.' ident)*
 *   ident      ::= [a-z][a-z0-9_]*
 *   text       ::= (any chars not starting a DSL tag)
 *
 * Nesting limit: 3 levels of if/for.
 * Max template length: 32 KB.
 *
 * Emits only: esc_html(), esc_attr(), esc_url(), wp_kses_post().
 * Emits zero side-effect code at file scope beyond the class + registration hook.
 *
 * Disallowed sequences detected before tokenizing (defense layer 1):
 *   <?php  <?=  eval  assert  system  exec  passthru  popen  proc_open
 *   backticks  ${...}  $$  $GLOBALS  file_get_contents
 */
final class Compiler {

	/** Maximum template source size (bytes). */
	private const MAX_TEMPLATE_BYTES = 32768;

	/** Maximum nesting depth for if/for blocks. */
	private const MAX_DEPTH = 3;

	/** Disallowed literal sequences that must never appear in DSL source. */
	private const DISALLOWED_SEQUENCES = [
		'<?php',
		'<?=',
		'eval',
		'assert',
		'system',
		'exec',
		'passthru',
		'popen',
		'proc_open',
		'${',
		'$$',
		'$GLOBALS',
		'file_get_contents',
		'shell_exec',
		'create_function',
		'pcntl_',
		'posix_',
	];

	/** Characters used for backtick detection. */
	private const BACKTICK = '`';

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Compile a DSL widget spec or individual template parts into a PHP class source string.
	 *
	 * Two calling conventions are supported:
	 *
	 * 1. Static / positional (original API, used by WidgetDefine and all existing callers):
	 *
	 *      Compiler::compile( $slug, $label, $category, $controls, $template, $strategy )
	 *
	 * 2. Instance / spec-array (new API, used in tests and future tooling):
	 *
	 *      ( new Compiler() )->compile( $spec )
	 *
	 *    where $spec is:
	 *      [
	 *        'slug'            => string,
	 *        'title'|'label'   => string,
	 *        'category'        => string,
	 *        'controls'        => array,   // may use 'name' instead of 'id' per control
	 *        'template'        => string,
	 *        'render_strategy' => string,  // optional, default 'twig'
	 *      ]
	 *
	 * Each control may include 'responsive' => true to emit add_responsive_control() instead
	 * of add_control().
	 *
	 * @param string|array<string, mixed> $widget_slug_or_spec
	 * @param string               $label           (positional mode only)
	 * @param string               $category        (positional mode only)
	 * @param array<int, array<string, mixed>> $controls (positional mode only)
	 * @param string               $template        (positional mode only)
	 * @param string               $render_strategy (positional mode only)
	 * @return string|\WP_Error
	 */
	public static function compile(
		string|array $widget_slug_or_spec,
		string $label = '',
		string $category = '',
		array $controls = [],
		string $template = '',
		string $render_strategy = 'twig'
	): string|\WP_Error {
		// Spec-array calling convention.
		if ( is_array( $widget_slug_or_spec ) ) {
			$spec            = $widget_slug_or_spec;
			$widget_slug     = (string) ( $spec['slug'] ?? '' );
			$label           = (string) ( $spec['title'] ?? $spec['label'] ?? '' );
			$category        = (string) ( $spec['category'] ?? 'general' );
			$render_strategy = (string) ( $spec['render_strategy'] ?? 'twig' );
			$template        = (string) ( $spec['template'] ?? '' );

			// Normalize controls: allow 'name' as alias for 'id'.
			$controls = [];
			foreach ( (array) ( $spec['controls'] ?? [] ) as $ctrl ) {
				if ( ! isset( $ctrl['id'] ) && isset( $ctrl['name'] ) ) {
					$ctrl['id'] = $ctrl['name'];
				}
				$controls[] = $ctrl;
			}
		} else {
			$widget_slug = $widget_slug_or_spec;
		}

		return self::do_compile( $widget_slug, $label, $category, $controls, $template, $render_strategy );
	}

	/**
	 * Internal compile implementation called by both the static and instance entry points.
	 *
	 * @param string               $widget_slug
	 * @param string               $label
	 * @param string               $category
	 * @param array<int, array<string, mixed>> $controls
	 * @param string               $template
	 * @param string               $render_strategy
	 * @return string|\WP_Error
	 */
	private static function do_compile(
		string $widget_slug,
		string $label,
		string $category,
		array $controls,
		string $template,
		string $render_strategy
	): string|\WP_Error {

		// --- Pre-scan (layer 1) ---
		$pre = self::pre_scan( $template );
		if ( is_wp_error( $pre ) ) {
			return $pre;
		}

		// --- Tokenize ---
		$tokens = self::tokenize( $template );
		if ( is_wp_error( $tokens ) ) {
			return $tokens;
		}

		// --- Parse AST ---
		$ast = self::parse( $tokens );
		if ( is_wp_error( $ast ) ) {
			return $ast;
		}

		// --- Emit PHP ---
		return self::emit_class( $widget_slug, $label, $category, $controls, $ast, $render_strategy );
	}

	// -------------------------------------------------------------------------
	// Layer 1 — literal sequence pre-scan
	// -------------------------------------------------------------------------

	/**
	 * @return bool|\WP_Error
	 */
	private static function pre_scan( string $template ): bool|\WP_Error {
		if ( strlen( $template ) > self::MAX_TEMPLATE_BYTES ) {
			return new \WP_Error(
				'stonewright_compiler_template_too_large',
				sprintf( 'Template exceeds maximum size of %d bytes.', self::MAX_TEMPLATE_BYTES )
			);
		}

		// Backtick.
		if ( false !== strpos( $template, self::BACKTICK ) ) {
			return new \WP_Error(
				'stonewright_compiler_disallowed_sequence',
				'Disallowed sequence in template: backtick execution operator.'
			);
		}

		// I2 — Unicode normalization before stripos scan to prevent full-width
		// bypass (e.g. ｅｖａｌ). Tokenize/parse on the original template since
		// the DSL grammar is ASCII-only.
		$normalized = $template;
		if ( class_exists( '\\Normalizer' ) ) {
			$norm = \Normalizer::normalize( $template, \Normalizer::FORM_KC );
			if ( false !== $norm && '' !== $norm ) {
				$normalized = $norm;
			}
		}

		foreach ( self::DISALLOWED_SEQUENCES as $seq ) {
			if ( false !== stripos( $normalized, $seq ) ) {
				return new \WP_Error(
					'stonewright_compiler_disallowed_sequence',
					sprintf( 'Disallowed sequence in template: %s', $seq )
				);
			}
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Layer 2 — tokenizer
	// -------------------------------------------------------------------------

	/**
	 * Tokenize the DSL source into a flat array of tagged tokens.
	 *
	 * Each token: ['type' => string, 'value' => string]
	 * Types: 'var', 'tag_if', 'tag_else', 'tag_endif', 'tag_for', 'tag_endfor', 'text'
	 *
	 * @return array<int, array{type: string, value: string}>|\WP_Error
	 */
	private static function tokenize( string $src ): array|\WP_Error {
		$tokens = [];
		$pos    = 0;
		$len    = strlen( $src );

		while ( $pos < $len ) {
			// Match double-brace variable expression.
			if ( '{' === $src[ $pos ] && $pos + 1 < $len && '{' === $src[ $pos + 1 ] ) {
				$close = strpos( $src, '}}', $pos + 2 );
				if ( false === $close ) {
					return new \WP_Error(
						'stonewright_compiler_unclosed_variable',
						'Unclosed {{ variable expression in template.'
					);
				}
				$inner = trim( substr( $src, $pos + 2, $close - $pos - 2 ) );

				// Validate path: only ident.ident.ident form. No function calls.
				if ( ! self::is_valid_path( $inner ) ) {
					return new \WP_Error(
						'stonewright_compiler_invalid_path',
						sprintf( 'Invalid path expression in {{ }}: "%s". Only dot-separated identifiers allowed.', $inner )
					);
				}

				$tokens[] = [ 'type' => 'var', 'value' => $inner ];
				$pos      = $close + 2;
				continue;
			}

			// Try {% tag %}
			if ( '{' === $src[ $pos ] && $pos + 1 < $len && '%' === $src[ $pos + 1 ] ) {
				$close = strpos( $src, '%}', $pos + 2 );
				if ( false === $close ) {
					return new \WP_Error(
						'stonewright_compiler_unclosed_tag',
						'Unclosed {% tag in template.'
					);
				}
				$inner = trim( substr( $src, $pos + 2, $close - $pos - 2 ) );
				$tag   = self::parse_tag( $inner );
				if ( is_wp_error( $tag ) ) {
					return $tag;
				}
				$tokens[] = $tag;
				$pos      = $close + 2;
				continue;
			}

			// Plain text — accumulate until next {{ or {%
			$next_var = strpos( $src, '{{', $pos );
			$next_tag = strpos( $src, '{%', $pos );

			if ( false === $next_var && false === $next_tag ) {
				$tokens[] = [ 'type' => 'text', 'value' => substr( $src, $pos ) ];
				break;
			}

			if ( false === $next_var ) {
				$next = (int) $next_tag;
			} elseif ( false === $next_tag ) {
				$next = (int) $next_var;
			} else {
				$next = min( (int) $next_var, (int) $next_tag );
			}

			if ( $next > $pos ) {
				$tokens[] = [ 'type' => 'text', 'value' => substr( $src, $pos, $next - $pos ) ];
			}
			$pos = $next;
		}

		return $tokens;
	}

	/**
	 * Parse a single {% ... %} inner string into a token.
	 *
	 * @return array{type: string, value: string}|\WP_Error
	 */
	private static function parse_tag( string $inner ): array|\WP_Error {
		// if <path>
		if ( preg_match( '/^if\s+([a-z][a-z0-9_.]*)\s*$/i', $inner, $m ) ) {
			$path = $m[1];
			if ( ! self::is_valid_path( $path ) ) {
				return new \WP_Error(
					'stonewright_compiler_invalid_path',
					sprintf( 'Invalid path in if tag: "%s".', $path )
				);
			}
			return [ 'type' => 'tag_if', 'value' => $path ];
		}

		// else
		if ( 'else' === $inner ) {
			return [ 'type' => 'tag_else', 'value' => '' ];
		}

		// endif
		if ( 'endif' === $inner ) {
			return [ 'type' => 'tag_endif', 'value' => '' ];
		}

		// for <ident> in <path>
		if ( preg_match( '/^for\s+([a-z][a-z0-9_]*)\s+in\s+([a-z][a-z0-9_.]*)\s*$/i', $inner, $m ) ) {
			$iter = $m[1];
			$path = $m[2];
			if ( ! self::is_valid_path( $path ) ) {
				return new \WP_Error(
					'stonewright_compiler_invalid_path',
					sprintf( 'Invalid path in for tag: "%s".', $path )
				);
			}
			return [ 'type' => 'tag_for', 'value' => $iter . '|' . $path ];
		}

		// endfor
		if ( 'endfor' === $inner ) {
			return [ 'type' => 'tag_endfor', 'value' => '' ];
		}

		return new \WP_Error(
			'stonewright_compiler_unknown_directive',
			sprintf( 'Unknown DSL directive: "{%% %s %%}". Only if/else/endif/for/endfor allowed.', $inner )
		);
	}

	// -------------------------------------------------------------------------
	// Layer 3 — recursive descent parser → AST
	// -------------------------------------------------------------------------

	/**
	 * Parse a flat token array into a nested AST.
	 *
	 * @param array<int, array{type: string, value: string}> $tokens
	 * @return array<int, mixed>|\WP_Error AST node array on success.
	 */
	private static function parse( array $tokens ): array|\WP_Error {
		$pos = 0;
		return self::parse_chunk_list( $tokens, $pos, 0 );
	}

	/**
	 * Parse a sequence of chunks at the given nesting depth.
	 *
	 * @param array<int, array{type: string, value: string}> $tokens
	 * @param int                                            $pos    Current position (passed by reference).
	 * @param int                                            $depth  Current nesting depth.
	 * @param string|null                                    $stop   Token type that terminates this list.
	 * @return array<int, mixed>|\WP_Error
	 */
	private static function parse_chunk_list(
		array $tokens,
		int &$pos,
		int $depth,
		?string $stop = null
	): array|\WP_Error {
		if ( $depth > self::MAX_DEPTH ) {
			return new \WP_Error(
				'stonewright_compiler_nesting_too_deep',
				sprintf( 'Template nesting exceeds maximum depth of %d. Flatten your template.', self::MAX_DEPTH )
			);
		}

		$nodes = [];
		$count = count( $tokens );

		while ( $pos < $count ) {
			$token = $tokens[ $pos ];
			$type  = $token['type'];

			// Check for stop condition.
			if ( null !== $stop && in_array( $type, [ $stop, 'tag_else', 'tag_endif', 'tag_endfor' ], true ) ) {
				break;
			}

			switch ( $type ) {
				case 'text':
					$nodes[] = [ 'type' => 'text', 'value' => $token['value'] ];
					++$pos;
					break;

				case 'var':
					$nodes[] = [ 'type' => 'var', 'path' => $token['value'] ];
					++$pos;
					break;

				case 'tag_if':
					++$pos;
					$then_nodes = self::parse_chunk_list( $tokens, $pos, $depth + 1, 'tag_endif' );
					if ( is_wp_error( $then_nodes ) ) {
						return $then_nodes;
					}

					$else_nodes = [];
					if ( $pos < $count && 'tag_else' === $tokens[ $pos ]['type'] ) {
						++$pos;
						$else_nodes = self::parse_chunk_list( $tokens, $pos, $depth + 1, 'tag_endif' );
						if ( is_wp_error( $else_nodes ) ) {
							return $else_nodes;
						}
					}

					if ( $pos >= $count || 'tag_endif' !== $tokens[ $pos ]['type'] ) {
						return new \WP_Error(
							'stonewright_compiler_missing_endif',
							'Missing {% endif %} for {% if %} block.'
						);
					}
					++$pos; // consume endif.

					$nodes[] = [
						'type'       => 'if',
						'path'       => $token['value'],
						'then'       => $then_nodes,
						'else'       => $else_nodes,
					];
					break;

				case 'tag_for':
					[ $iter, $path ] = explode( '|', $token['value'], 2 );
					++$pos;
					$body = self::parse_chunk_list( $tokens, $pos, $depth + 1, 'tag_endfor' );
					if ( is_wp_error( $body ) ) {
						return $body;
					}
					if ( $pos >= $count || 'tag_endfor' !== $tokens[ $pos ]['type'] ) {
						return new \WP_Error(
							'stonewright_compiler_missing_endfor',
							'Missing {% endfor %} for {% for %} block.'
						);
					}
					++$pos; // consume endfor.

					$nodes[] = [
						'type' => 'for',
						'iter' => $iter,
						'path' => $path,
						'body' => $body,
					];
					break;

				case 'tag_else':
				case 'tag_endif':
				case 'tag_endfor':
					// Unmatched closing tags.
					return new \WP_Error(
						'stonewright_compiler_unmatched_tag',
						sprintf( 'Unexpected "{%% %s %%}" without matching opening tag.', str_replace( 'tag_', '', $type ) )
					);

				default:
					return new \WP_Error(
						'stonewright_compiler_unknown_token',
						sprintf( 'Unknown token type: %s', $type )
					);
			}
		}

		return $nodes;
	}

	// -------------------------------------------------------------------------
	// Layer 4 — PHP emitter
	// -------------------------------------------------------------------------

	/**
	 * Emit a full PHP class source from the AST.
	 *
	 * @param array<int, array<string, mixed>> $controls
	 * @param array<int, mixed>               $ast
	 */
	private static function emit_class(
		string $widget_slug,
		string $label,
		string $category,
		array $controls,
		array $ast,
		string $render_strategy
	): string {
		$class_name  = self::slug_to_class( $widget_slug );
		$controls_php = self::emit_controls( $controls );
		$render_php   = self::emit_render_body( $ast, $render_strategy );

		// phpcs:disable WordPress.WP.AlternativeFunctions
		$escaped_label    = addslashes( $label );
		$escaped_category = addslashes( $category );
		$escaped_slug     = addslashes( $widget_slug );
		// PHP function/constant names cannot contain hyphens. Replace with underscores.
		$safe_fn_slug     = str_replace( '-', '_', $widget_slug );
		// phpcs:enable

		$source = <<<PHP
<?php
declare( strict_types=1 );
/**
 * Stonewright-generated Elementor widget: {$escaped_slug}
 * Generated by Stonewright\WpMcp\Elementor\WidgetBuilder\Compiler.
 * DO NOT EDIT — regenerate via stonewright/elementor.widget_define.
 *
 * Uses bracketed namespace syntax (PHP RFC) so the global registration function
 * can coexist with the generated class's dedicated namespace in a single file.
 */

// I3 — Generated class in dedicated namespace; bracketed form required so that
// the global registration function (namespace {}) can follow in the same file.
namespace Stonewright\\GeneratedWidgets {

	if ( ! defined( 'ABSPATH' ) ) {
		return;
	}

	if ( ! class_exists( '\\Elementor\\Widget_Base' ) ) {
		return;
	}

	final class {$class_name} extends \\Elementor\\Widget_Base {

		public function get_name(): string {
			return '{$escaped_slug}';
		}

		public function get_title(): string {
			return esc_html__( '{$escaped_label}', 'stonewright' );
		}

		public function get_icon(): string {
			return 'eicon-code';
		}

		public function get_categories(): array {
			return [ '{$escaped_category}' ];
		}

		protected function register_controls(): void {
{$controls_php}
		}

		protected function render(): void {
			\$settings = \$this->get_settings_for_display();
{$render_php}
		}
	}
}

// C1 — Loader calls stonewright_register_widget_{$safe_fn_slug}_with_manager(\$manager)
// directly after require_once, so no secondary add_action hook is needed.
namespace {

	if ( ! function_exists( 'stonewright_register_widget_{$safe_fn_slug}_with_manager' ) ) {
		function stonewright_register_widget_{$safe_fn_slug}_with_manager( \\Elementor\\Widgets_Manager \$manager ): void {
			\$manager->register( new \\Stonewright\\GeneratedWidgets\\{$class_name}() );
		}
	}
}

PHP;

		return $source;
	}

	/**
	 * Emit register_controls() body from the controls array.
	 *
	 * @param array<int, array<string, mixed>> $controls
	 */
	private static function emit_controls( array $controls ): string {
		if ( empty( $controls ) ) {
			return "\t\t// No controls defined.\n";
		}

		$lines   = [];
		$lines[] = "\t\t\$this->start_controls_section(";
		$lines[] = "\t\t\t'section_content',";
		$lines[] = "\t\t\t[";
		$lines[] = "\t\t\t\t'label' => esc_html__( 'Content', 'stonewright' ),";
		$lines[] = "\t\t\t\t'tab'   => \\Elementor\\Controls_Manager::TAB_CONTENT,";
		$lines[] = "\t\t\t]";
		$lines[] = "\t\t);";
		$lines[] = '';

		foreach ( $controls as $control ) {
			$id      = (string) ( $control['id'] ?? '' );
			$lbl     = addslashes( (string) ( $control['label'] ?? $id ) );
			$type    = self::control_type_constant( (string) ( $control['type'] ?? 'text' ) );
			$default = self::emit_default_value( $control['default'] ?? '' );

			$method  = ! empty( $control['responsive'] ) ? 'add_responsive_control' : 'add_control';
			$lines[] = sprintf( "\t\t\$this->%s(", $method );
			$lines[] = "\t\t\t" . var_export( $id, true ) . ',';
			$lines[] = "\t\t\t[";
			$lines[] = "\t\t\t\t'label'   => esc_html__( '{$lbl}', 'stonewright' ),";
			$lines[] = "\t\t\t\t'type'    => {$type},";
			$lines[] = "\t\t\t\t'default' => {$default},";

			if ( 'select' === ( $control['type'] ?? '' ) && ! empty( $control['options'] ) && is_array( $control['options'] ) ) {
				$opts_php = self::emit_options_array( $control['options'] );
				$lines[]  = "\t\t\t\t'options' => {$opts_php},";
			}

			$lines[] = "\t\t\t]";
			$lines[] = "\t\t);";
			$lines[] = '';
		}

		$lines[] = "\t\t\$this->end_controls_section();";

		return implode( "\n", $lines );
	}

	/**
	 * Emit the render() body from AST nodes.
	 *
	 * @param array<int, mixed> $ast
	 */
	private static function emit_render_body( array $ast, string $render_strategy ): string {
		$indent = "\t\t";
		return self::emit_nodes( $ast, $indent, $render_strategy, [] );
	}

	/**
	 * Recursively emit PHP from AST nodes.
	 *
	 * @param array<int, mixed>    $nodes
	 * @param array<int, string>   $loop_iters Active loop iterator variable names.
	 */
	private static function emit_nodes( array $nodes, string $indent, string $render_strategy, array $loop_iters ): string {
		$php = '';
		foreach ( $nodes as $node ) {
			$php .= self::emit_node( $node, $indent, $render_strategy, $loop_iters );
		}
		return $php;
	}

	/**
	 * Emit a single AST node.
	 *
	 * @param array<string, mixed> $node
	 * @param array<int, string>   $loop_iters Active loop iterator variable names (I4).
	 */
	private static function emit_node( array $node, string $indent, string $render_strategy, array $loop_iters ): string {
		switch ( $node['type'] ) {
			case 'text':
				$raw = (string) $node['value'];
				if ( '' === trim( $raw ) ) {
					// Emit whitespace-only text as-is if it contains newlines.
					if ( str_contains( $raw, "\n" ) ) {
						return $indent . 'echo esc_html( ' . var_export( $raw, true ) . " );\n";
					}
					return '';
				}
				// M1 — Escape literal text to prevent XSS if DSL source contains raw HTML.
				return $indent . 'echo esc_html( ' . var_export( $raw, true ) . " );\n";

			case 'var':
				$path_php    = self::path_to_php( (string) $node['path'], $loop_iters );
				$escape_call = ( 'block-binding' === $render_strategy ) ? 'wp_kses_post' : 'esc_html';
				return $indent . "echo {$escape_call}( {$path_php} );\n";

			case 'if':
				$cond   = self::path_to_php( (string) $node['path'], $loop_iters );
				$php    = $indent . "if ( {$cond} ) {\n";
				$php   .= self::emit_nodes( (array) $node['then'], $indent . "\t", $render_strategy, $loop_iters );
				if ( ! empty( $node['else'] ) ) {
					$php .= $indent . "} else {\n";
					$php .= self::emit_nodes( (array) $node['else'], $indent . "\t", $render_strategy, $loop_iters );
				}
				$php .= $indent . "}\n";
				return $php;

			case 'for':
				$iter     = (string) $node['iter'];
				$path_php = self::path_to_php( (string) $node['path'], $loop_iters );
				// Validate iter name — only simple ident, no dollar sign in AST.
				$safe_iter       = preg_replace( '/[^a-z0-9_]/', '', $iter ) ?? $iter;
				// I4 — add this iterator to the active set for body emission.
				$inner_iters     = $loop_iters;
				$inner_iters[]   = $safe_iter;
				$php  = $indent . "foreach ( (array) ( {$path_php} ) as \${$safe_iter} ) {\n";
				$php .= self::emit_nodes( (array) $node['body'], $indent . "\t", $render_strategy, $inner_iters );
				$php .= $indent . "}\n";
				return $php;

			default:
				return '';
		}
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Convert a dot-path like "foo.bar.baz" to PHP:
	 *   $settings['foo']['bar']['baz'] ?? ''
	 *
	 * I4 — If the first segment matches an active loop iterator, emit $iter
	 * instead of $settings['iter']. Subsequent segments become subscripts.
	 *
	 * @param array<int, string> $loop_iters Active loop iterator names.
	 */
	private static function path_to_php( string $path, array $loop_iters = [] ): string {
		$parts = explode( '.', $path );
		$first = $parts[0];
		$rest  = array_slice( $parts, 1 );

		if ( ! empty( $loop_iters ) && in_array( $first, $loop_iters, true ) ) {
			// First segment is a loop iterator variable.
			$php = '$' . $first;
			foreach ( $rest as $part ) {
				$php .= "['" . addslashes( $part ) . "']";
			}
		} else {
			$php = '$settings';
			foreach ( $parts as $part ) {
				$php .= "['" . addslashes( $part ) . "']";
			}
		}

		return "( {$php} ?? '' )";
	}

	/**
	 * Validate a dot-path: each segment must be [a-z][a-z0-9_]*.
	 * N2 — No /i flag: uppercase segments are invalid (prevents bypass).
	 */
	private static function is_valid_path( string $path ): bool {
		if ( '' === $path ) {
			return false;
		}
		$parts = explode( '.', $path );
		foreach ( $parts as $part ) {
			if ( ! preg_match( '/^[a-z][a-z0-9_]*$/', $part ) ) {
				return false;
			}
			// Reject anything that looks like a function call in the path.
			if ( str_contains( $part, '(' ) || str_contains( $part, ')' ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Convert widget_slug to PascalCase class name.
	 */
	private static function slug_to_class( string $slug ): string {
		$parts = preg_split( '/[_-]+/', $slug ) ?: [];
		return 'Stonewright_Widget_' . implode( '_', array_map( 'ucfirst', $parts ) );
	}

	/**
	 * Map DSL control type → Elementor Controls_Manager constant expression string.
	 */
	private static function control_type_constant( string $type ): string {
		return match ( $type ) {
			'textarea' => '\\Elementor\\Controls_Manager::TEXTAREA',
			'number'   => '\\Elementor\\Controls_Manager::NUMBER',
			'slider'   => '\\Elementor\\Controls_Manager::SLIDER',
			'color'    => '\\Elementor\\Controls_Manager::COLOR',
			'select'   => '\\Elementor\\Controls_Manager::SELECT',
			'url'      => '\\Elementor\\Controls_Manager::URL',
			'image'    => '\\Elementor\\Controls_Manager::MEDIA',
			'switcher' => '\\Elementor\\Controls_Manager::SWITCHER',
			default    => '\\Elementor\\Controls_Manager::TEXT',
		};
	}

	/**
	 * Emit a PHP literal for a control default value.
	 */
	private static function emit_default_value( mixed $default ): string {
		if ( is_string( $default ) ) {
			return var_export( $default, true );
		}
		if ( is_int( $default ) || is_float( $default ) ) {
			return var_export( $default, true );
		}
		if ( is_bool( $default ) ) {
			return $default ? 'true' : 'false';
		}
		if ( null === $default ) {
			return "''";
		}
		return "''";
	}

	/**
	 * Emit a PHP array literal for select options.
	 *
	 * @param array<string, mixed> $options
	 */
	private static function emit_options_array( array $options ): string {
		$pairs = [];
		foreach ( $options as $k => $v ) {
			$pairs[] = var_export( (string) $k, true ) . ' => ' . var_export( (string) $v, true );
		}
		return '[ ' . implode( ', ', $pairs ) . ' ]';
	}
}
