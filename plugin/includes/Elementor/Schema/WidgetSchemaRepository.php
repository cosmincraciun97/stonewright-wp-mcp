<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Schema;

use Stonewright\WpMcp\Elementor\WidgetRegistry\WidgetCatalog;

/**
 * Lazy, fingerprinted access to widget schemas from the live Elementor runtime.
 */
final class WidgetSchemaRepository {

	private const CACHE_TTL = 43200;
	private const CACHE_KEYS_OPTION = 'stonewright_elementor_schema_cache_keys';

	/** @var array<string, array<string, mixed>> */
	private static array $request_cache = [];

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function get( string $widget_type, bool $refresh = false ): array|\WP_Error {
		$widget_type = trim( $widget_type );
		if ( '' === $widget_type ) {
			return new \WP_Error( 'stonewright_widget_required', __( 'A widget type is required.', 'stonewright' ), [ 'status' => 400 ] );
		}

		$fingerprint = RuntimeFingerprint::describe();
		$cache_key   = self::cache_key( $widget_type, (string) $fingerprint['hash'] );
		if ( ! $refresh && isset( self::$request_cache[ $cache_key ] ) ) {
			return self::$request_cache[ $cache_key ];
		}
		if ( ! $refresh ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				self::$request_cache[ $cache_key ] = $cached;
				return $cached;
			}
		}

		$widget = self::live_widget( $widget_type );
		if ( ! is_object( $widget ) ) {
			return new \WP_Error(
				'stonewright_elementor_widget_unknown',
				sprintf( __( 'Widget "%s" is not registered in the live Elementor runtime.', 'stonewright' ), $widget_type ),
				[ 'status' => 404, 'capture_required' => true ]
			);
		}

		$controls = self::controls( $widget );
		$source   = self::source( $widget );
		$bundled  = WidgetCatalog::has( $widget_type ) ? WidgetCatalog::entry( $widget_type ) : [];
		$link_controls = array_keys(
			array_filter( $controls, static fn( array $control ): bool => 'url' === ( $control['type'] ?? '' ) )
		);
		$common_controls = array_keys(
			array_filter( $controls, static fn( array $control, string $key ): bool => str_starts_with( $key, '_' ) || 'advanced' === strtolower( (string) ( $control['tab'] ?? '' ) ), ARRAY_FILTER_USE_BOTH )
		);
		$record   = [
			'widget_type'         => $widget_type,
			'title'               => method_exists( $widget, 'get_title' ) ? (string) $widget->get_title() : $widget_type,
			'source_plugin'       => $source['plugin'],
			'source_version'      => $source['version'],
			'elementor_core'      => (string) ( $fingerprint['components']['elementor_core'] ?? '' ),
			'elementor_pro'       => (string) ( $fingerprint['components']['elementor_pro'] ?? '' ),
			'feature_flags'       => (array) ( $fingerprint['components']['features'] ?? [] ),
			'categories'          => method_exists( $widget, 'get_categories' ) ? array_values( (array) $widget->get_categories() ) : [],
			'availability'        => 'registered',
			'controls'            => $controls,
			'sections'            => self::sections( $controls ),
			'inherited_common_controls' => $common_controls,
			'required_for_render' => array_values( (array) ( $bundled['required_for_render'] ?? [] ) ),
			'link_capable_controls' => $link_controls,
			'semantic_role'       => self::semantic_role( $widget_type ),
			'pro_required'        => str_contains( strtolower( (string) $source['plugin'] ), 'elementor-pro' ),
			'license_requirement' => str_contains( strtolower( (string) $source['plugin'] ), 'elementor-pro' ) ? 'elementor-pro' : 'none-detected',
			'known_incompatibilities' => [],
			'runtime_fingerprint' => (string) $fingerprint['hash'],
			'captured_at'         => gmdate( DATE_ATOM ),
			'expires_at'          => gmdate( DATE_ATOM, time() + self::CACHE_TTL ),
			'provenance'          => [
				'widget'               => 'live_elementor_runtime',
				'controls'             => 'live_elementor_runtime',
				'required_for_render'  => [] !== $bundled ? 'bundled_verified_fixture' : 'not_available',
			],
			'field_provenance'    => [
				'title'          => 'live_elementor_runtime',
				'source_plugin'  => 'runtime_reflection_and_wordpress_plugin_metadata',
				'categories'     => 'live_elementor_runtime',
				'controls'       => 'live_elementor_runtime',
				'feature_flags'  => 'wordpress_options',
			],
		];
		$hash_input            = $record;
		unset( $hash_input['captured_at'], $hash_input['expires_at'] );
		$record['schema_hash']  = hash( 'sha256', (string) wp_json_encode( self::canonicalize( $hash_input ) ) );

		set_transient( $cache_key, $record, self::CACHE_TTL );
		self::remember_cache_key( $cache_key );
		self::$request_cache[ $cache_key ] = $record;
		return $record;
	}

	/**
	 * @return array{items:list<array<string,mixed>>,total:int,fingerprint:string}
	 */
	public static function list( string $query = '', int $page = 1, int $per_page = 50 ): array {
		$manager = self::manager();
		$widgets = is_object( $manager ) && method_exists( $manager, 'get_widget_types' ) ? (array) $manager->get_widget_types() : [];
		$query   = strtolower( trim( $query ) );
		$candidates = [];
		foreach ( $widgets as $name => $widget ) {
			if ( ! is_object( $widget ) ) {
				continue;
			}
			$title = method_exists( $widget, 'get_title' ) ? (string) $widget->get_title() : (string) $name;
			$keys  = method_exists( $widget, 'get_keywords' ) ? array_values( (array) $widget->get_keywords() ) : [];
			if ( '' !== $query && ! str_contains( strtolower( (string) $name . ' ' . $title . ' ' . implode( ' ', $keys ) ), $query ) ) {
				continue;
			}
			$candidates[] = [
				'widget_type'   => (string) $name,
				'title'         => $title,
			];
		}
		usort( $candidates, static fn( array $a, array $b ): int => strcmp( (string) $a['widget_type'], (string) $b['widget_type'] ) );
		$total    = count( $candidates );
		$page     = max( 1, $page );
		$per_page = max( 1, min( 100, $per_page ) );
		$items    = [];
		foreach ( array_slice( $candidates, ( $page - 1 ) * $per_page, $per_page ) as $candidate ) {
			$schema = self::get( (string) $candidate['widget_type'] );
			if ( $schema instanceof \WP_Error ) {
				continue;
			}
			$items[] = [
				'widget_type'    => (string) $candidate['widget_type'],
				'title'          => (string) $candidate['title'],
				'source_plugin'  => (string) $schema['source_plugin'],
				'source_version' => (string) $schema['source_version'],
				'schema_hash'    => (string) $schema['schema_hash'],
				'categories'     => (array) $schema['categories'],
			];
		}
		return [
			'items'       => $items,
			'total'       => $total,
			'fingerprint' => (string) ( RuntimeFingerprint::describe()['hash'] ?? '' ),
		];
	}

	public static function reset_request_cache(): void {
		self::$request_cache = [];
	}

	/**
	 * Clears every persistent schema shard tracked by Stonewright.
	 *
	 * @param mixed ...$ignored WordPress hook arguments.
	 */
	public static function invalidate( mixed ...$ignored ): void {
		unset( $ignored );
		foreach ( (array) get_option( self::CACHE_KEYS_OPTION, [] ) as $cache_key ) {
			if ( is_string( $cache_key ) && str_starts_with( $cache_key, 'stonewright_el_schema_' ) ) {
				delete_transient( $cache_key );
			}
		}
		update_option( self::CACHE_KEYS_OPTION, [], false );
		self::$request_cache = [];
	}

	private static function cache_key( string $widget_type, string $fingerprint ): string {
		return 'stonewright_el_schema_' . substr( hash( 'sha256', $fingerprint . ':' . $widget_type ), 0, 40 );
	}

	private static function remember_cache_key( string $cache_key ): void {
		$keys = array_values( array_filter( (array) get_option( self::CACHE_KEYS_OPTION, [] ), 'is_string' ) );
		if ( in_array( $cache_key, $keys, true ) ) {
			return;
		}
		$keys[] = $cache_key;
		update_option( self::CACHE_KEYS_OPTION, array_slice( $keys, -500 ), false );
	}

	private static function manager(): ?object {
		if ( ! class_exists( '\\Elementor\\Plugin' ) ) {
			return null;
		}
		return \Elementor\Plugin::$instance->widgets_manager ?? null;
	}

	private static function live_widget( string $widget_type ): ?object {
		$manager = self::manager();
		if ( ! is_object( $manager ) || ! method_exists( $manager, 'get_widget_types' ) ) {
			return null;
		}
		$widget = $manager->get_widget_types( $widget_type );
		return is_object( $widget ) ? $widget : null;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private static function controls( object $widget ): array {
		$raw = method_exists( $widget, 'get_controls' ) ? (array) $widget->get_controls() : [];
		$out = [];
		foreach ( $raw as $key => $control ) {
			if ( ! is_array( $control ) ) {
				continue;
			}
			$name = (string) $key;
			$out[ $name ] = self::normalize_control( $name, $control );
		}
		ksort( $out );
		return $out;
	}

	/**
	 * @param array<string, mixed> $control Raw Elementor control.
	 * @return array<string, mixed>
	 */
	private static function normalize_control( string $name, array $control ): array {
		$normalized = [
			'key'        => $name,
			'type'       => self::scalar_string( $control['type'] ?? '' ),
			'label'      => self::scalar_string( $control['label'] ?? '' ),
			'tab'        => self::scalar_string( $control['tab'] ?? '' ),
			'section'    => self::scalar_string( $control['section'] ?? '' ),
			'responsive' => (bool) ( $control['responsive'] ?? $control['is_responsive'] ?? false ),
			'dynamic'    => (array) ( $control['dynamic'] ?? [] ),
			'condition'  => (array) ( $control['condition'] ?? $control['conditions'] ?? [] ),
			'provenance' => 'live_elementor_runtime',
		];
		foreach ( [ 'default', 'options', 'selectors', 'group', 'group_prefix', 'description', 'min', 'max', 'step', 'multiple', 'return_value' ] as $field ) {
			if ( array_key_exists( $field, $control ) ) {
				$normalized[ $field ] = $control[ $field ];
			}
		}
		if ( isset( $control['fields'] ) && is_array( $control['fields'] ) ) {
			$normalized['fields'] = [];
			foreach ( $control['fields'] as $field_key => $field ) {
				if ( is_array( $field ) ) {
					$normalized['fields'][ (string) $field_key ] = self::normalize_control( (string) $field_key, $field );
				}
			}
		}
		return $normalized;
	}

	private static function scalar_string( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * @param array<string, array<string, mixed>> $controls Controls by key.
	 * @return list<array{id:string,tab:string,controls:list<string>}>
	 */
	private static function sections( array $controls ): array {
		$sections = [];
		foreach ( $controls as $key => $control ) {
			$id = (string) ( $control['section'] ?? 'unknown' );
			if ( '' === $id ) {
				$id = 'unknown';
			}
			if ( ! isset( $sections[ $id ] ) ) {
				$sections[ $id ] = [ 'id' => $id, 'tab' => (string) ( $control['tab'] ?? '' ), 'controls' => [] ];
			}
			$sections[ $id ]['controls'][] = $key;
		}
		return array_values( $sections );
	}

	private static function semantic_role( string $widget_type ): string {
		return match ( $widget_type ) {
			'button', 'call-to-action', 'woocommerce-product-add-to-cart', 'wc-add-to-cart' => 'action',
			'heading', 'theme-post-title', 'theme-page-title', 'theme-archive-title' => 'heading',
			'image', 'image-gallery', 'gallery', 'image-carousel', 'video' => 'media',
			'nav-menu', 'menu-anchor' => 'navigation',
			'form', 'login', 'search' => 'input',
			default => 'component',
		};
	}

	/**
	 * @return array{plugin:string,version:string}
	 */
	private static function source( object $widget ): array {
		$file = '';
		try {
			$file = (string) ( new \ReflectionClass( $widget ) )->getFileName();
		} catch ( \ReflectionException $exception ) {
			unset( $exception );
		}
		if ( function_exists( 'get_plugins' ) && defined( 'WP_PLUGIN_DIR' ) && '' !== $file ) {
			$plugin_dir = wp_normalize_path( (string) constant( 'WP_PLUGIN_DIR' ) ) . '/';
			$normalized = wp_normalize_path( $file );
			if ( str_starts_with( $normalized, $plugin_dir ) ) {
				$relative = substr( $normalized, strlen( $plugin_dir ) );
				foreach ( get_plugins() as $plugin_file => $metadata ) {
					$folder = dirname( (string) $plugin_file );
					if ( '.' !== $folder && str_starts_with( $relative, $folder . '/' ) ) {
						return [ 'plugin' => (string) $plugin_file, 'version' => (string) ( $metadata['Version'] ?? '' ) ];
					}
				}
			}
		}
		$class = get_class( $widget );
		if ( str_contains( $class, '@anonymous' ) ) {
			$class = 'anonymous-widget';
		}
		return [ 'plugin' => 'runtime:' . $class, 'version' => '' ];
	}

	private static function canonicalize( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( ! array_is_list( $value ) ) {
			ksort( $value );
		}
		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::canonicalize( $item );
		}
		return $value;
	}
}
