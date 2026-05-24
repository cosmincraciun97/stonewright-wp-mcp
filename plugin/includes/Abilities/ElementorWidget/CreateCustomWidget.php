<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorWidget;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Sandbox\SandboxGuards;
use Stonewright\WpMcp\Elementor\WidgetBuilder\Compiler;
use Stonewright\WpMcp\Sandbox\SandboxFiles;
use Stonewright\WpMcp\Sandbox\StaticGuard;
use Stonewright\WpMcp\Security\AuditLog;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Phase G.2 — `stonewright/elementor-create-custom-widget`.
 *
 * Single high-level surface for "create me a new Elementor widget."
 * Wraps the three building blocks from Phase 5 (WidgetDefine ↔
 * Compiler / StaticGuard / SandboxFiles ↔ WidgetRegister) into one
 * call so the LLM doesn't have to orchestrate a three-step dance.
 *
 * Pipeline:
 *   1. Validate input shape.
 *   2. Translate {slug, title, props, template, ...} into the Compiler
 *      spec-array.
 *   3. `Compiler::compile()` → PHP source.
 *   4. `StaticGuard::scan()` — reject on findings.
 *   5. Write to sandbox draft dir as widget-<slug>.pending.php OR
 *      widget-<slug>.php depending on `activate`.
 *   6. (Optional, if activate=true) append slug to the registered-
 *      widgets option so the WidgetLoader auto-picks it up on the
 *      next request.
 *
 * The official Elementor recipe at
 * `docs/knowledge/elementor/custom-widget/recipe.md` was harvested in
 * Phase 0; this ability is the executable, ready-to-call version of
 * that recipe.
 *
 * @stonewright-status sandboxed
 */
final class CreateCustomWidget extends AbilityKernel {

	use SandboxGuards;

	private const ABILITY    = 'stonewright/elementor-create-custom-widget';
	private const OPTION_KEY = 'stonewright_registered_widgets';

	public function name(): string {
		return self::ABILITY;
	}

	public function label(): string {
		return __( 'Create custom Elementor widget', 'stonewright' );
	}

	public function description(): string {
		return __(
			'Creates a new sandboxed Elementor widget from a high-level spec. USE THIS WHEN the design needs a widget that does not exist in the core Elementor / Pro / WooCommerce catalog (e.g. a custom pricing card, feature tile, branded testimonial layout). The widget is compiled through the Stonewright DSL → PHP pipeline (Compiler + StaticGuard) and dropped into the sandbox draft dir. If `activate=true` the widget is promoted immediately and the WidgetLoader hooks it into Elementor on the next request. Follows the official Elementor recipe (extends \\Elementor\\Widget_Base, implements get_name / get_title / get_icon / get_categories / register_controls / render).',
			'stonewright'
		);
	}

	public function category(): string {
		return 'elementor-widget';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'slug', 'title', 'props', 'template' ],
			'properties'           => [
				'slug'        => [
					'type'    => 'string',
					'pattern' => '^[a-z][a-z0-9_-]{2,40}$',
					'description' => 'Lowercase widget slug. Becomes the runtime widget_type. Must start with a letter; letters/digits/underscore/hyphen only; 3-41 chars.',
				],
				'title'       => [
					'type'        => 'string',
					'minLength'   => 3,
					'maxLength'   => 80,
					'description' => 'Human-readable widget label shown in the Elementor sidebar.',
				],
				'icon'        => [
					'type'        => 'string',
					'description' => 'Elementor icon CSS class, e.g. "eicon-price-list", "eicon-star".',
					'default'     => 'eicon-code',
				],
				'category'    => [
					'type'        => 'string',
					'enum'        => [ 'basic', 'pro-elements', 'general', 'stonewright' ],
					'description' => 'Sidebar category to file the widget under.',
					'default'     => 'stonewright',
				],
				'keywords'    => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
					'description' => 'Search keywords shown in the Elementor finder.',
				],
				'props'       => [
					'type'        => 'array',
					'minItems'    => 1,
					'description' => 'List of widget controls. Each entry: { name (snake_case key), type (text/textarea/number/slider/color/select/url/image/switcher), label, default?, options?, responsive? }.',
					'items'       => [
						'type'                 => 'object',
						'additionalProperties' => true,
						'required'             => [ 'name', 'type' ],
						'properties'           => [
							'name'       => [ 'type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,40}$' ],
							'label'      => [ 'type' => 'string' ],
							'type'       => [
								'type' => 'string',
								'enum' => [ 'text', 'textarea', 'number', 'slider', 'color', 'select', 'url', 'image', 'switcher' ],
							],
							'default'    => [],
							'options'    => [ 'type' => 'object' ],
							'responsive' => [ 'type' => 'boolean' ],
						],
					],
				],
				'template'    => [
					'type'        => 'string',
					'maxLength'   => 32768,
					'description' => 'DSL render template — Twig-like syntax. {{ var }} for output, {% if path %}…{% endif %} for conditionals, {% for x in list %}…{% endfor %} for loops. See docs/knowledge/elementor/custom-widget/recipe.md for examples.',
				],
				'render_strategy' => [
					'type'    => 'string',
					'enum'    => [ 'twig', 'block-binding' ],
					'default' => 'twig',
					'description' => 'Compiler strategy: "twig" emits a render() that interpolates settings; "block-binding" emits a content_template().',
				],
				'activate'    => [
					'type'        => 'boolean',
					'description' => 'When true, immediately promote .pending.php → .php and append the slug to the registered-widgets option so Elementor picks it up. When false (default), stage as .pending.php for review.',
					'default'     => false,
				],
				'confirmation_token' => [
					'type'        => 'string',
					'description' => 'Required only in production-safe mode (Permissions::is_production_safe()).',
				],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'required'   => [ 'ok', 'slug', 'class_name', 'sandbox_file', 'widget_type', 'registered' ],
			'properties' => [
				'ok'           => [ 'type' => 'boolean' ],
				'slug'         => [ 'type' => 'string' ],
				'class_name'   => [ 'type' => 'string' ],
				'sandbox_file' => [ 'type' => 'string' ],
				'widget_type'  => [ 'type' => 'string' ],
				'registered'   => [ 'type' => 'boolean' ],
				'static_guard' => [
					'type'       => 'object',
					'properties' => [
						'passed'   => [ 'type' => 'boolean' ],
						'findings' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
					],
				],
				'php_source_length' => [ 'type' => 'integer' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::can_manage_sandbox();
	}

	protected function audit_redacted_keys(): array {
		return array_merge( parent::audit_redacted_keys(), [ 'template' ] );
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $a ): array|\WP_Error {
				// 1. DISALLOW_FILE_MODS guard.
				$file_mods_error = $this->file_mods_disabled_error();
				if ( null !== $file_mods_error ) {
					return $file_mods_error;
				}

				$slug     = isset( $a['slug'] )     && is_string( $a['slug'] )     ? $a['slug']     : '';
				$title    = isset( $a['title'] )    && is_string( $a['title'] )    ? $a['title']    : '';
				$icon     = isset( $a['icon'] )     && is_string( $a['icon'] )     ? $a['icon']     : 'eicon-code';
				$category = isset( $a['category'] ) && is_string( $a['category'] ) ? $a['category'] : 'stonewright';
				$keywords = ( isset( $a['keywords'] ) && is_array( $a['keywords'] ) )
					? array_values( array_filter( $a['keywords'], 'is_string' ) )
					: [];
				$props    = ( isset( $a['props'] ) && is_array( $a['props'] ) ) ? $a['props'] : [];
				$template = isset( $a['template'] ) && is_string( $a['template'] ) ? $a['template'] : '';
				$strategy = isset( $a['render_strategy'] ) && is_string( $a['render_strategy'] ) ? $a['render_strategy'] : 'twig';
				$activate = (bool) ( $a['activate'] ?? false );

				if ( $slug === '' || $title === '' || empty( $props ) || $template === '' ) {
					return new \WP_Error(
						'stonewright_invalid_input',
						__( 'slug, title, props, and template are required.', 'stonewright' ),
						[ 'status' => 400 ]
					);
				}

				// 2. Production-safe token check. Verify the slug + template
				// pair so the token can't be reused with a swapped template.
				$token_error = $this->production_safe_token_error(
					$a,
					[
						'slug'     => $slug,
						'title'    => $title,
						'template' => $template,
						'activate' => $activate,
					]
				);
				if ( null !== $token_error ) {
					return $token_error;
				}

				// 3. Translate high-level props → Compiler controls. The
				// Compiler accepts `name` as an alias for `id` so the
				// public-facing prop naming stays simple.
				$controls = [];
				foreach ( $props as $p ) {
					if ( ! is_array( $p ) ) {
						continue;
					}
					$ctrl = [
						'id'    => (string) ( $p['name'] ?? $p['id'] ?? '' ),
						'label' => (string) ( $p['label'] ?? ucfirst( str_replace( '_', ' ', (string) ( $p['name'] ?? '' ) ) ) ),
						'type'  => (string) ( $p['type'] ?? 'text' ),
					];
					if ( array_key_exists( 'default', $p ) ) {
						$ctrl['default'] = $p['default'];
					}
					if ( isset( $p['options'] ) && is_array( $p['options'] ) ) {
						$ctrl['options'] = $p['options'];
					}
					if ( isset( $p['responsive'] ) ) {
						$ctrl['responsive'] = (bool) $p['responsive'];
					}
					$controls[] = $ctrl;
				}

				// 4. Compile.
				$source = Compiler::compile( [
					'slug'            => $slug,
					'title'           => $title,
					'category'        => $category,
					'controls'        => $controls,
					'template'        => $template,
					'render_strategy' => $strategy,
				] );
				if ( $source instanceof \WP_Error ) {
					return $source;
				}

				// 5. StaticGuard — reject obvious payloads.
				$findings = StaticGuard::scan( $source );
				if ( ! empty( $findings ) ) {
					AuditLog::record(
						self::ABILITY,
						[ 'slug' => $slug, 'static_guard' => 'rejected' ],
						'error'
					);
					return new \WP_Error(
						'stonewright_static_guard_rejected',
						__( 'StaticGuard rejected the compiled widget source.', 'stonewright' ),
						[ 'findings' => $findings, 'status' => 422 ]
					);
				}

				// 6. Write the file. If activating, write directly as .php so
				// the WidgetLoader picks it up; otherwise stage .pending.php
				// for review.
				$dir       = SandboxFiles::draft_dir();
				$ext       = $activate ? '.php' : '.pending.php';
				$filename  = 'widget-' . $slug . $ext;
				$abs_path  = $dir . '/' . $filename;
				$bytes     = file_put_contents( $abs_path, $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				if ( false === $bytes ) {
					return new \WP_Error(
						'stonewright_widget_write_error',
						sprintf( __( 'Could not write widget file: %s', 'stonewright' ), $filename ),
						[ 'status' => 500 ]
					);
				}

				// 7. If activating, register the slug in the option list so
				// the loader sees it. The loader auto-scans the draft dir for
				// widget-*.php files anyway, but the option also fuels the
				// admin "active widgets" view.
				$registered = false;
				if ( $activate ) {
					$option = (array) get_option( self::OPTION_KEY, [] );
					if ( ! in_array( $slug, $option, true ) ) {
						$option[] = $slug;
						update_option( self::OPTION_KEY, $option );
					}
					$registered = true;
				}

				return $this->ok( [
					'slug'              => $slug,
					'class_name'        => 'Stonewright_Custom_Widget_' . self::pascal( $slug ),
					'sandbox_file'      => $abs_path,
					'widget_type'       => $slug,
					'registered'        => $registered,
					'static_guard'      => [
						'passed'   => true,
						'findings' => [],
					],
					'php_source_length' => strlen( (string) $source ),
				] );
			}
		);
	}

	private static function pascal( string $slug ): string {
		$parts = preg_split( '/[^A-Za-z0-9]+/', $slug ) ?: [];
		$out   = '';
		foreach ( $parts as $p ) {
			if ( $p === '' ) {
				continue;
			}
			$out .= ucfirst( strtolower( $p ) );
		}
		return $out !== '' ? $out : 'Widget';
	}
}
