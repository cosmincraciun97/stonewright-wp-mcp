<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorWidget;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Sandbox\SandboxGuards;
use Stonewright\WpMcp\Elementor\WidgetBuilder\Compiler;
use Stonewright\WpMcp\Elementor\WidgetBuilder\RegisteredWidgets;
use Stonewright\WpMcp\Sandbox\SandboxFiles;
use Stonewright\WpMcp\Sandbox\StaticGuard;
use Stonewright\WpMcp\Security\Permissions;

/**
 * High-level `stonewright/elementor-create-custom-widget` surface.
 *
 * Single high-level surface for "create me a new Elementor widget."
 * Wraps the three building blocks (WidgetDefine ↔
 * Compiler / StaticGuard / SandboxFiles ↔ WidgetRegister) into one
 * call so the LLM doesn't have to orchestrate a three-step dance.
 *
 * Pipeline:
 *   1. Validate input shape.
 *   2. Translate {slug, title, props, template, ...} into the Compiler
 *      spec-array.
 *   3. `Compiler::compile()` → PHP source.
 *   4. `StaticGuard::scan()` — reject on findings.
 *   5. Write through SandboxFiles::write() (backup of the file it replaces,
 *      change-ledger row, size cap) to the sandbox draft dir as
 *      widget-<slug>.pending.php OR widget-<slug>.php depending on `activate`.
 *   6. (Optional, if activate=true) add the slug to the registered-
 *      widgets list so the WidgetLoader picks it up on the next request.
 *
 * The official Elementor recipe at
 * `docs/knowledge/elementor/custom-widget/recipe.md` was harvested in
 * the knowledge base; this ability is the executable, ready-to-call version of
 * that recipe.
 *
 * @stonewright-status sandboxed
 */
final class CreateCustomWidget extends AbilityKernel {

	use SandboxGuards;

	private const ABILITY = 'stonewright/elementor-create-custom-widget';

	public function name(): string {
		return self::ABILITY;
	}

	public function label(): string {
		return __( 'Create custom Elementor widget', 'stonewright' );
	}

	public function description(): string {
		return __(
			'Creates a new sandboxed Elementor widget from a high-level spec. USE THIS WHEN the design needs a widget that does not exist in the core Elementor / Pro / WooCommerce catalog (e.g. a custom pricing card, feature tile, branded testimonial layout). The widget is compiled through the Stonewright DSL → PHP pipeline (Compiler + StaticGuard) and stored in the sandbox draft dir as widget-<slug>.pending.draft (the sandbox writer keeps a backup of any file it replaces and records the write in the change history). If `activate=true` the widget is stored as the active widget-<slug>.draft and added to the registered-widgets list, and the WidgetLoader hooks it into Elementor on the next request; only registered widgets are loaded. Follows the official Elementor recipe (extends \\Elementor\\Widget_Base, implements get_name / get_title / get_icon / get_categories / register_controls / render).',
			'stonewright'
		);
	}

	public function category(): string {
		return 'elementor-widget';
	}

	/**
	 * Writes the widget file under its slug without looking for an earlier one, so a repeat replaces the earlier widget.
	 *
	 * @return array<string, mixed>
	 */
	public function meta(): array {
		return [
			'annotations' => [
				'destructive' => true,
			],
		];
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
					'pattern'     => '^[A-Za-z][A-Za-z0-9 _\-]{0,63}$',
					'description' => 'Elementor icon CSS class, e.g. "eicon-price-list", "eicon-star". Letters, digits, spaces, hyphens and underscores only.',
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
					'maxItems' => 20,
					'items' => [ 'type' => 'string', 'maxLength' => 40 ],
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
					'description' => 'When true, store the widget directly as the active widget-<slug>.draft file and add the slug to the registered-widgets list so Elementor picks it up. When false (default), stage it as widget-<slug>.pending.draft for review.',
					'default'     => false,
				],
				'confirmation_token' => [
					'type'        => 'string',
					'description' => 'Required only in production-safe mode. Issue it with stonewright/security-issue-confirmation-token for this ability and the full argument object: the token is bound to every other argument, so any change to the arguments needs a new token.',
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

				// 2. Production-safe token check. The token is bound to every
				// argument except confirmation_token itself.
				$token_error = $this->production_safe_token_error( $a, $a );
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
					'icon'            => $icon,
					'keywords'        => $keywords,
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
					// The audit wrapper records this rejection once, with its error code.
					return new \WP_Error(
						'stonewright_static_guard_rejected',
						__( 'StaticGuard rejected the compiled widget source.', 'stonewright' ),
						[ 'findings' => $findings, 'status' => 422 ]
					);
				}

				// 6. Write the file through the sandbox writer: backup of the file it
				// replaces, change-ledger row and size cap. If activating, store it under
				// the active widget name so the WidgetLoader can pick it up; otherwise stage
				// the pending name for review. Both are stored as .draft files.
				$ext      = $activate ? '.php' : '.pending.php';
				$filename = 'widget-' . $slug . $ext;
				$written  = SandboxFiles::write( $filename, $source );
				if ( $written instanceof \WP_Error ) {
					return $written;
				}
				$abs_path = SandboxFiles::stored_path( $filename );

				// 7. If activating, list the slug in the registered-widgets option (not
				// autoloaded): the loader loads only the widgets on that list.
				$registered = false;
				if ( $activate ) {
					RegisteredWidgets::add( $slug );
					$registered = true;
				}

				return $this->ok( [
					'slug'              => $slug,
					'class_name'        => Compiler::qualified_class_name( $slug ),
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
}
