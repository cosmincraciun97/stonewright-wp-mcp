<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorWidgets;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Elementor\Schema\SettingsValidator;
use Stonewright\WpMcp\Elementor\WidgetRegistry\WidgetCatalog;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * Base class for per-widget Elementor V3 abilities.
 *
 * Every widget in the manifest gets a tiny final subclass that only
 * declares its slug; this class does the heavy lifting:
 *
 *   - `name()`                  → `stonewright/elementor-add-<slug>`
 *   - `label()`                 → "Add <Widget Title>"
 *   - `description()`           → intent prose + "USE THIS WHEN" stanza
 *                                 generated from harvested help-article
 *                                 use-cases.
 *   - `input_schema()`          → `{ post_id, parent_id, position?,
 *                                    settings: { <every manifest key> } }`
 *                                 with rich `description` for each key.
 *   - `execute()`               → snapshots → reads tree → finds parent →
 *                                 validates settings via
 *                                 {@see WidgetSettingsValidator} →
 *                                 auto-emits group activators →
 *                                 inserts widget → writes tree.
 *
 * Subclasses MUST override `slug()` (it's abstract). Everything else
 * inherits.
 *
 * Validation returns structured errors with
 * `data.violations = [{ path, code, expected, got }]` so the LLM can
 * self-correct in one round.
 */
abstract class WidgetAbilityBase extends AbilityKernel {
	public function meta(): array {
		return [
			'deprecated'  => true,
			'replacement' => 'stonewright/elementor-v3-batch-mutate',
			'sunset'      => '2.0.0',
		];
	}

	/** Each concrete subclass returns the widget slug it adds. */
	abstract protected function slug(): string;

	/**
	 * Manifest entry for this widget, lazily loaded once per instance.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $cached_entry = null;

	protected function entry(): array {
		if ( $this->cached_entry === null ) {
			$this->cached_entry = WidgetCatalog::entry( $this->slug() );
		}
		return $this->cached_entry;
	}

	public function name(): string {
		return 'stonewright/elementor-add-' . $this->slug();
	}

	public function label(): string {
		$title = (string) ( $this->entry()['title'] ?? $this->slug() );
		// translators: %s is the human-readable widget name (e.g. "Heading").
		return sprintf( __( 'Add %s widget', 'stonewright' ), $title );
	}

	public function category(): string {
		return 'elementor-widget';
	}

	public function description(): string {
		$entry  = $this->entry();
		$title  = (string) ( $entry['title']  ?? $this->slug() );
		$source = (string) ( $entry['source'] ?? 'free' );
		$intent = trim( (string) ( $entry['intent'] ?? '' ) );
		$cases  = is_array( $entry['use_cases'] ?? null ) ? array_slice( $entry['use_cases'], 0, 4 ) : [];

		$source_label = match ( $source ) {
			'pro' => 'Elementor Pro',
			'wc'  => 'WooCommerce (Elementor Pro)',
			default => 'Elementor (free)',
		};

		$lines  = [];
		$lines[] = sprintf( 'Deprecated compatibility ability for an %1$s "%2$s" widget. Prefer stonewright/elementor-schema plus stonewright/elementor-v3-batch-mutate.', $source_label, $title );

		if ( $intent !== '' ) {
			$lines[] = ' ' . self::clip( $intent, 240 );
		}

		$lines[] = ' USE THIS WHEN:';
		if ( empty( $cases ) ) {
			$lines[] = sprintf( ' you need to add a %s element to a layout.', strtolower( $title ) );
		} else {
			foreach ( $cases as $case ) {
				$lines[] = "\n  • " . self::clip( (string) $case, 120 );
			}
		}

		$required = $entry['required_for_render'] ?? [];
		if ( ! empty( $required ) ) {
			// Stringify defensively — no escaping needed: description text is
			// surfaced as tool docs, not HTML output.
			$req_strs = array_values( array_filter(
				array_map( static fn( $x ) => is_string( $x ) ? $x : null, (array) $required ),
				static fn( $x ) => is_string( $x ) && $x !== ''
			) );
			if ( ! empty( $req_strs ) ) {
				$lines[] = "\n  Required settings: " . implode( ', ', $req_strs ) . '.';
			}
		}

		$limits = is_array( $entry['limits'] ?? null ) ? array_slice( $entry['limits'], 0, 1 ) : [];
		if ( ! empty( $limits ) ) {
			$lines[] = "\n  Note: " . self::clip( (string) reset( $limits ), 150 );
		}

		return implode( '', $lines );
	}

	public function input_schema(): array {
		$entry     = $this->entry();
		$index     = is_array( $entry['settings_index'] ?? null ) ? $entry['settings_index'] : [];
		$activators = is_array( $entry['group_activators'] ?? null ) ? $entry['group_activators'] : [];

		$properties = [];
		foreach ( $index as $key => $meta ) {
			if ( ! is_string( $key ) || $key === '' ) {
				continue;
			}
			// `type` may be unresolved (`{__unresolved__: ...}` array) in
			// the manifest — defend against that so the JSON-schema build
			// stays clean.
			$raw_type   = $meta['type'] ?? 'text';
			$type       = is_string( $raw_type ) ? $raw_type : 'text';
			$group        = is_string( $meta['group']        ?? null ) ? $meta['group']        : null;
			$group_prefix = is_string( $meta['group_prefix'] ?? null ) ? $meta['group_prefix'] : null;
			$default      = $meta['default']      ?? null;
			$responsive   = (bool) ( $meta['responsive'] ?? false );
			$condition    = is_array( $meta['condition'] ?? null ) ? $meta['condition'] : null;

			$json_type = self::control_type_to_json( $type );
			$desc      = self::describe_control( $key, $type, $group, $group_prefix, $responsive, $condition );

			$properties[ $key ] = [
				'type'        => $json_type,
				'description' => $desc,
			];

			if ( $default !== null && is_scalar( $default ) ) {
				$properties[ $key ]['default'] = $default;
			}
		}

		// Activator keys (group toggles) — explicitly enumerable values.
		foreach ( $activators as $activator_key => $activator_value ) {
			$properties[ $activator_key ] = [
				'type'        => 'string',
				'description' => sprintf(
					'Group-control activator. Elementor only honours sub-keys of this group when this key is set to "%s". Stonewright auto-emits it whenever a sub-key is supplied — you only need to set it if you want to force a different group mode.',
					$activator_value
				),
				'default'     => $activator_value,
			];
		}

		$schema = [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'post_id'   => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'WordPress post / page / template ID to mutate.',
				],
				'parent_id' => [
					'type'        => 'string',
					'description' => 'Element ID (string, not numeric) of the parent container/section/column to insert into.',
				],
				'position'  => [
					'type'        => 'integer',
					'description' => 'Zero-based child position inside the parent. Omit to append at the end.',
				],
				'settings'  => [
					'type'                 => 'object',
					'description'          => 'Elementor widget settings dict — keys map 1:1 to the widget\'s Controls_Manager controls (see properties below).',
					'additionalProperties' => true,
					'properties'           => $properties,
				],
			],
			'required'             => [ 'post_id', 'parent_id' ],
		];

		if ( 'html' === $this->slug() ) {
			$schema['properties']['allow_html_widget'] = [
				'type'        => 'boolean',
				'description' => 'Must be true only when the user explicitly asked for an Elementor HTML widget. Do not set this for fallback layout or styling.',
				'default'     => false,
			];
		}

		return $schema;
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'post_id'     => [ 'type' => 'integer' ],
				'element_id'  => [ 'type' => 'string' ],
				'widget_type' => [ 'type' => 'string' ],
				'snapshot_id' => [ 'type' => 'string' ],
				'activated_groups' => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		$id = (int) ( $args['post_id'] ?? 0 );
		return Permissions::edit_post( $id );
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				if ( 'html' === $this->slug() && empty( $args['allow_html_widget'] ) ) {
					return new \WP_Error(
						'html_widget_requires_explicit_approval',
						__( 'Elementor HTML widgets are disabled by default. Use native Elementor widgets first, or pass allow_html_widget=true only when the user explicitly requested HTML.', 'stonewright' ),
						[ 'status' => 400 ]
					);
				}

				$post_id = (int) ( $args['post_id'] ?? 0 );
				if ( $post_id < 1 ) {
					return $this->error( 'invalid_post_id', __( 'post_id is required.', 'stonewright' ) );
				}
				if ( ! get_post( $post_id ) ) {
					return $this->error( 'not_found', __( 'Post not found.', 'stonewright' ) );
				}

				$settings_in = ( isset( $args['settings'] ) && is_array( $args['settings'] ) )
					? $args['settings']
					: [];

				$activators  = WidgetCatalog::group_activators( $this->slug() );
				$activated   = [];
				$settings    = $settings_in;

				// B.1 semantics, here at the per-widget ability layer: every
				// time the caller supplies a sub-key like `typography_font_size`
				// we ensure the matching activator key like `typography_typography`
				// is also present. Caller-supplied activator wins.
				foreach ( $activators as $activator_key => $activator_value ) {
					$prefix = self::control_prefix_for_activator( (string) $activator_key );
					if ( $prefix === null ) {
						continue;
					}
					$needs_activator = false;
					foreach ( $settings as $set_key => $_v ) {
						if ( $set_key === $activator_key ) {
							continue;
						}
						$canonical = preg_replace( '/_(?:tablet|mobile)$/', '', (string) $set_key );
						if ( is_string( $canonical ) && str_starts_with( $canonical, $prefix . '_' ) ) {
							$needs_activator = true;
							break;
						}
					}
					if ( $needs_activator && ! array_key_exists( $activator_key, $settings ) ) {
						$settings[ $activator_key ] = $activator_value;
						$activated[]                 = (string) $activator_key;
					}
				}

				$validated = SettingsValidator::validate( $this->slug(), $settings );
				if ( $validated instanceof \WP_Error ) {
					return $validated;
				}
				$settings = $validated['settings'];

				$snapshot_id = Backup::snapshot_post( $post_id );
				$tree        = ElementorData::read( $post_id );
				$parent_id   = (string) ( $args['parent_id'] ?? '' );
				$parent_path = ElementorData::find_path( $tree, $parent_id );
				if ( null === $parent_path ) {
					return $this->error( 'parent_not_found', __( 'Parent element not found.', 'stonewright' ) );
				}

				$widget = [
					'id'         => ElementorData::generate_id(),
					'elType'     => 'widget',
					'widgetType' => (string) ( $this->entry()['widget_type'] ?? $this->slug() ),
					'settings'   => empty( $settings ) ? new \stdClass() : $settings,
					'elements'   => [],
				];

				$position = isset( $args['position'] ) ? (int) $args['position'] : PHP_INT_MAX;
				$new_tree = ElementorData::insert( $tree, $parent_path, $position, $widget );

				if ( ! ElementorData::write( $post_id, $new_tree ) ) {
					return $this->error( 'write_failed', __( 'Could not save Elementor data.', 'stonewright' ) );
				}

				return [
					'post_id'          => $post_id,
					'element_id'       => $widget['id'],
					'widget_type'      => $widget['widgetType'],
					'snapshot_id'      => $snapshot_id,
					'activated_groups' => $activated,
				];
			}
		);
	}

	// -----------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------

	/**
	 * Given an activator key like `typography_typography`, return the
	 * prefix part — `typography`. Returns null if the key doesn't look
	 * like an `<x>_<x>` activator pattern.
	 */
	private static function control_prefix_for_activator( string $activator_key ): ?string {
		foreach ( [ 'typography', 'border', 'background', 'box_shadow', 'text_shadow', 'css_filter', 'text_stroke' ] as $base ) {
			$suffix = '_' . $base;
			if ( str_ends_with( $activator_key, $suffix ) ) {
				$prefix = substr( $activator_key, 0, -strlen( $suffix ) );
				return '' !== $prefix ? $prefix : null;
			}
		}
		return null;
	}

	private static function activator_prefix( string $activator_key ): ?string {
		// Match anything where the last segment is one of our known group bases.
		foreach ( [ 'typography', 'border', 'background', 'box_shadow', 'text_shadow', 'css_filter', 'text_stroke' ] as $base ) {
			$suffix = '_' . $base;
			if ( str_ends_with( $activator_key, $suffix ) ) {
				$prefix = substr( $activator_key, 0, -strlen( $suffix ) );
				if ( $prefix !== '' ) {
					return $prefix . '_' . $base; // typography_typography → prefix is "typography_typography" minus "_typography" = "typography"
				}
			}
		}
		// Fallback: <prefix>_<base> where prefix == base means activator IS the prefix.
		// e.g. "background_background" → prefix is "background".
		foreach ( [ 'typography', 'border', 'background', 'box_shadow', 'text_shadow', 'css_filter', 'text_stroke' ] as $base ) {
			if ( $activator_key === $base . '_' . $base ) {
				return $base;
			}
		}
		return null;
	}

	private static function clip( string $text, int $max ): string {
		$text = preg_replace( '/\s+/', ' ', trim( $text ) ) ?? '';
		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}
		return rtrim( mb_substr( $text, 0, $max - 1 ) ) . '…';
	}

	/** Map an Elementor control type to a JSON schema primitive type. */
	private static function control_type_to_json( string $type ): array {
		switch ( $type ) {
			case 'number':
				return [ 'number', 'string', 'null' ];
			case 'switcher':
				return [ 'string', 'boolean', 'null' ];
			case 'slider':
			case 'dimensions':
			case 'box_shadow':
			case 'text_shadow':
			case 'media':
			case 'icons':
			case 'icon':
			case 'gallery':
			case 'group_activator':
				return [ 'object', 'array', 'string', 'null' ];
			case 'repeater':
				return [ 'array', 'null' ];
			case 'select':
			case 'select2':
			case 'choose':
			case 'visual_choice':
			case 'color':
			case 'url':
			case 'date_time':
			case 'font':
			case 'animation':
			case 'hover_animation':
			case 'exit_animation':
			case 'code':
				return [ 'string', 'object', 'null' ];
			case 'text':
			case 'textarea':
			case 'wysiwyg':
			default:
				return [ 'string', 'number', 'null' ];
		}
	}

	private static function describe_control( string $key, string $type, ?string $group, ?string $group_prefix, bool $responsive, mixed $condition ): string {
		$bits = [];
		$bits[] = sprintf( 'Elementor control "%s" (type: %s).', $key, $type );
		if ( $group !== null && $group_prefix !== null ) {
			$bits[] = sprintf( 'Part of the "%s" group on prefix "%s".', $group, $group_prefix );
		}
		if ( $responsive ) {
			$bits[] = 'Responsive: also accepts `_tablet` / `_mobile` siblings (e.g. "' . $key . '_tablet").';
		}
		if ( is_array( $condition ) && ! empty( $condition ) ) {
			$bits[] = 'Conditional — only relevant when: ' . self::stringify_condition( $condition ) . '.';
		}
		return implode( ' ', $bits );
	}

	private static function stringify_condition( array $condition ): string {
		$parts = [];
		foreach ( $condition as $k => $v ) {
			$parts[] = is_array( $v )
				? $k . ' in (' . implode( ', ', array_map( static fn( $x ) => is_scalar( $x ) ? (string) $x : '?', $v ) ) . ')'
				: $k . '=' . ( is_scalar( $v ) ? (string) $v : '?' );
		}
		return implode( ' AND ', $parts );
	}
}
