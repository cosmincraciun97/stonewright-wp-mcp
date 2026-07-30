<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\V4;

/**
 * Compact, versioned Elementor Atomic schema repository.
 *
 * The bundled schemas cover only structures verified against Elementor's
 * public data-structure documentation. Installed runtimes may add or replace
 * schemas through the filter; unknown types and properties are never guessed.
 */
final class AtomicSchemaRepository {

	public const ELEMENT_VERSION = '0.0';
	public const PAGE_VERSION    = '0.4';

	/** @var array<string, array<string, mixed>>|null */
	private static ?array $schemas = null;

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		if ( null !== self::$schemas ) {
			return self::$schemas;
		}

		$schemas = [
			'e-div-block' => self::layout( 'Div', [] ),
			'e-flexbox'   => self::layout( 'Container', [ 'direction' => 'string', 'gap' => 'size' ] ),
			'e-grid'      => self::layout( 'Grid', [ 'columns' => 'string', 'rows' => 'string', 'gap' => 'size' ] ),
			'e-heading'   => self::widget( 'Heading', [ 'text' => [ 'key' => 'title', 'type' => 'html-v3' ], 'level' => [ 'key' => 'tag', 'type' => 'heading-level' ], 'link' => [ 'key' => 'link', 'type' => 'link' ] ] ),
			'e-paragraph' => self::widget( 'TextEditor', [ 'text' => [ 'key' => 'paragraph', 'type' => 'html-v3' ], 'link' => [ 'key' => 'link', 'type' => 'link' ] ] ),
			'e-image'     => self::widget( 'Image', [ 'url' => [ 'key' => 'image', 'type' => 'image-url' ], 'alt' => [ 'key' => 'image', 'type' => 'image-alt' ], 'link' => [ 'key' => 'link', 'type' => 'link' ] ] ),
			'e-button'    => self::widget( 'Button', [ 'text' => [ 'key' => 'text', 'type' => 'html-v3' ], 'link' => [ 'key' => 'link', 'type' => 'link' ] ] ),
			'e-divider'   => self::widget( 'Divider', [] ),
			'e-svg'       => self::widget( 'Icon', [ 'url' => [ 'key' => 'svg', 'type' => 'svg-src' ], 'link' => [ 'key' => 'link', 'type' => 'link' ] ] ),
		];

		$schemas = array_replace( $schemas, self::discover_runtime() );

		/**
		 * Supplies schemas discovered from the installed Elementor runtime.
		 * Each item must use the same compact shape as the bundled schemas.
		 *
		 * @param array<string, array<string, mixed>> $schemas
		 */
		$filtered = apply_filters( 'stonewright_elementor_v4_atomic_schemas', $schemas );
		self::$schemas = is_array( $filtered ) ? self::sanitize_schemas( $filtered ) : $schemas;

		return self::$schemas;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function for_atomic_type( string $atomic_type ): ?array {
		$schemas = self::all();
		return $schemas[ $atomic_type ] ?? null;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function for_design_type( string $design_type ): ?array {
		foreach ( self::all() as $atomic_type => $schema ) {
			$aliases = isset( $schema['design_types'] ) && is_array( $schema['design_types'] ) ? $schema['design_types'] : [];
			if ( in_array( $design_type, $aliases, true ) ) {
				$schema['atomic_type'] = $atomic_type;
				return $schema;
			}
		}
		return null;
	}

	public static function fingerprint(): string {
		$payload = wp_json_encode( self::all(), JSON_UNESCAPED_SLASHES );
		return hash( 'sha256', false === $payload ? '' : $payload );
	}

	public static function invalidate(): void {
		self::$schemas = null;
	}

	/**
	 * Discovers every installed Atomic layout/widget and its prop JSON schemas.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function discover_runtime(): array {
		if ( ! class_exists( '\\Elementor\\Plugin' ) ) {
			return [];
		}
		$out = [];
		try {
			$sources = [];
			$elements_manager = \Elementor\Plugin::$instance->elements_manager ?? null;
			if ( is_object( $elements_manager ) && method_exists( $elements_manager, 'get_element_types' ) ) {
				$sources['layout'] = $elements_manager->get_element_types();
			}
			$widgets_manager = \Elementor\Plugin::$instance->widgets_manager ?? null;
			if ( is_object( $widgets_manager ) && method_exists( $widgets_manager, 'get_widget_types' ) ) {
				$sources['widget'] = $widgets_manager->get_widget_types();
			}
			foreach ( $sources as $kind => $instances ) {
				if ( ! is_array( $instances ) ) {
continue; }
				foreach ( $instances as $registered_type => $instance ) {
					$type = (string) $registered_type;
					if ( ! str_starts_with( $type, 'e-' ) || ! is_object( $instance ) || ! method_exists( $instance, 'get_props_schema' ) ) {
continue; }
					$props = [];
					$runtime_props = call_user_func( [ $instance, 'get_props_schema' ] );
					if ( ! is_array( $runtime_props ) ) {
continue; }
					foreach ( $runtime_props as $name => $prop_schema ) {
						if ( ! is_object( $prop_schema ) || ! method_exists( $prop_schema, 'to_json_schema' ) ) {
continue; }
						$json_schema = $prop_schema->to_json_schema();
						$props[ (string) $name ] = [ 'key' => (string) $name, 'type' => 'raw-json', 'json_schema' => $json_schema ];
					}
					$out[ $type ] = [
						'kind'          => $kind,
						'design_types'  => [ $type ],
						'version'       => self::ELEMENT_VERSION,
						'props'         => $props,
						'source'        => 'live_runtime',
						'runtime_class' => get_class( $instance ),
					];
				}
			}
		} catch ( \Throwable $error ) {
			return [];
		}
		return $out;
	}

	/**
	 * @param array<string, string> $props
	 * @return array<string, mixed>
	 */
	private static function layout( string $design_type, array $props ): array {
		$mapped = [];
		foreach ( $props as $name => $type ) {
			$mapped[ $name ] = [ 'key' => 'gap' === $name ? 'gap' : 'flex-' . $name, 'type' => 'style-' . $type ];
		}
		if ( 'Grid' === $design_type ) {
			$mapped = [
				'columns' => [ 'key' => 'grid-template-columns', 'type' => 'style-string' ],
				'rows'    => [ 'key' => 'grid-template-rows', 'type' => 'style-string' ],
				'gap'     => [ 'key' => 'gap', 'type' => 'style-size' ],
			];
		}
		return [
			'kind'         => 'layout',
			'design_types' => 'Container' === $design_type ? [ 'Section', 'Column', 'Container' ] : [ $design_type ],
			'version'      => self::ELEMENT_VERSION,
			'props'        => $mapped,
			'source'       => 'elementor_official_docs',
		];
	}

	/**
	 * @param array<string, array<string, string>> $props
	 * @return array<string, mixed>
	 */
	private static function widget( string $design_type, array $props ): array {
		return [
			'kind'         => 'widget',
			'design_types' => [ $design_type ],
			'version'      => self::ELEMENT_VERSION,
			'props'        => $props,
			'source'       => 'elementor_official_docs',
		];
	}

	/**
	 * @param array<string, mixed> $schemas
	 * @return array<string, array<string, mixed>>
	 */
	private static function sanitize_schemas( array $schemas ): array {
		$out = [];
		foreach ( $schemas as $type => $schema ) {
			if ( ! is_string( $type ) || ! str_starts_with( $type, 'e-' ) || ! is_array( $schema ) ) {
				continue;
			}
			if ( ! in_array( $schema['kind'] ?? '', [ 'layout', 'widget' ], true ) || empty( $schema['version'] ) || ! isset( $schema['props'] ) || ! is_array( $schema['props'] ) ) {
				continue;
			}
			$out[ $type ] = $schema;
		}
		return $out;
	}
}
