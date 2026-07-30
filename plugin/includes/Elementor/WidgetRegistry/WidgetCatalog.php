<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\WidgetRegistry;

/**
 * Read-only access to the lazy Stonewright widget catalog.
 *
 * Loads a compact PHP index once per request, then includes only requested
 * per-widget PHP shards. It exposes lookups for the per-widget
 * abilities, the WidgetIntentResolver, the knowledge-base
 * `elementor-describe-widget` ability, and any caller that needs the
 * canonical Elementor setting metadata.
 *
 * Full widget controls and harvested knowledge are never loaded as one giant
 * runtime structure.
 *
 * The class is final and intentionally has no constructor — every method
 * is static so subclasses, mocks, and call sites all see the same loaded
 * manifest.
 */
final class WidgetCatalog {

	/** @var array<string, mixed>|null */
	private static ?array $manifest = null;

	/** @var array<string, array<string, mixed>> */
	private static array $entry_cache = [];

	/**
	 * Path to the manifest file. Overridable in tests via
	 * {@see self::set_manifest_path()}.
	 *
	 * @var string|null
	 */
	private static ?string $manifest_path = null;

	/** Return the absolute path the catalog will (or did) load. */
	public static function manifest_path(): string {
		if ( self::$manifest_path === null ) {
			self::$manifest_path = __DIR__ . '/catalog/index.php';
		}
		return self::$manifest_path;
	}

	/**
	 * Override the manifest path. Resets the in-memory cache.
	 * Intended for tests; production should leave this untouched.
	 */
	public static function set_manifest_path( ?string $path ): void {
		self::$manifest_path = $path;
		self::$manifest      = null;
		self::$entry_cache   = [];
	}

	/**
	 * Reset the in-memory cache (forces the next call to re-read from disk).
	 * Intended for tests.
	 */
	public static function reset_cache(): void {
		self::$manifest    = null;
		self::$entry_cache = [];
	}

	/**
	 * Returns the entire manifest. Loaded lazily.
	 *
	 * @return array<string, mixed>
	 */
	public static function manifest(): array {
		if ( self::$manifest !== null ) {
			return self::$manifest;
		}

		$path = self::manifest_path();
		if ( ! is_file( $path ) ) {
			self::$manifest = [
				'version'  => '0.0.0',
				'widgets'  => [],
				'totals'   => [],
			];
			return self::$manifest;
		}
		$loaded = include $path;
		self::$manifest = is_array( $loaded ) ? $loaded : [
			'version' => '0.0.0',
			'widgets' => [],
			'totals'  => [],
		];
		return self::$manifest;
	}

	/**
	 * Returns the widget map (slug => entry).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function widgets(): array {
		$out = [];
		foreach ( self::slugs() as $slug ) {
			$out[ $slug ] = self::entry( $slug );
		}
		return $out;
	}

	/** List of widget slugs known to the catalog. */
	public static function slugs(): array {
		$widgets = self::manifest()['widgets'] ?? [];
		return is_array( $widgets ) ? array_keys( $widgets ) : [];
	}

	/**
	 * Look up the manifest entry for a single widget slug.
	 *
	 * Returns a guaranteed structure even if the slug is missing: a
	 * stub entry with all keys set to safe defaults so callers can
	 * destructure without `isset()` ceremony.
	 *
	 * @return array<string, mixed>
	 */
	public static function entry( string $slug ): array {
		if ( isset( self::$entry_cache[ $slug ] ) ) {
			return self::$entry_cache[ $slug ];
		}
		$widgets = self::manifest()['widgets'] ?? [];
		$meta    = is_array( $widgets ) ? ( $widgets[ $slug ] ?? null ) : null;
		if ( ! is_array( $meta ) || ! isset( $meta['shard'] ) || ! is_string( $meta['shard'] ) ) {
			return self::stub_entry( $slug );
		}
		$relative = str_replace( '\\', '/', $meta['shard'] );
		if ( str_contains( $relative, '..' ) || str_starts_with( $relative, '/' ) ) {
			return self::stub_entry( $slug );
		}
		$path   = dirname( self::manifest_path() ) . '/' . $relative;
		$loaded = is_file( $path ) ? include $path : null;
		if ( ! is_array( $loaded ) ) {
			return self::stub_entry( $slug );
		}
		self::$entry_cache[ $slug ] = $loaded + self::stub_entry( $slug );
		return self::$entry_cache[ $slug ];
	}

	/** Whether the catalog has an entry for this slug. */
	public static function has( string $slug ): bool {
		$widgets = self::manifest()['widgets'] ?? [];
		return isset( $widgets[ $slug ] );
	}

	/**
	 * Filter widgets by source ('free' | 'pro' | 'wc').
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function by_source( string $source ): array {
		$out = [];
		$widgets = self::manifest()['widgets'] ?? [];
		foreach ( is_array( $widgets ) ? $widgets : [] as $slug => $meta ) {
			if ( ( $meta['source'] ?? null ) === $source ) {
				$out[ $slug ] = self::entry( (string) $slug );
			}
		}
		return $out;
	}

	/**
	 * Group activator map for a widget — `<prefix>_<group_base>` => `<value>`.
	 *
	 * @return array<string, string>
	 */
	public static function group_activators( string $slug ): array {
		$entry = self::entry( $slug );
		$g     = $entry['group_activators'] ?? [];
		return is_array( $g ) ? $g : [];
	}

	/**
	 * Settings index for a widget — { key => { section, type, default, group, group_prefix, responsive, condition } }.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function settings_index( string $slug ): array {
		$entry = self::entry( $slug );
		$s     = $entry['settings_index'] ?? [];
		return is_array( $s ) ? $s : [];
	}

	/**
	 * Required-for-render keys for a widget.
	 *
	 * @return array<int, string>
	 */
	public static function required_for_render( string $slug ): array {
		$entry = self::entry( $slug );
		$r     = $entry['required_for_render'] ?? [];
		return is_array( $r ) ? array_values( array_filter( $r, 'is_string' ) ) : [];
	}

	/**
	 * Build the structure callers can fall back to when a slug is missing.
	 *
	 * @return array<string, mixed>
	 */
	private static function stub_entry( string $slug ): array {
		return [
			'slug'                => $slug,
			'source'              => null,
			'widget_type'         => $slug,
			'title'               => $slug,
			'icon'                => null,
			'categories'          => [],
			'keywords'            => [],
			'file'                => null,
			'intent'              => null,
			'use_cases'           => [],
			'settings_highlights' => [],
			'limits'              => [],
			'sections'            => [],
			'group_controls'      => [],
			'repeaters'           => [],
			'settings_index'      => [],
			'group_activators'    => [],
			'required_for_render' => [],
			'knowledge_sources'   => [],
			'control_count'       => 0,
		];
	}
}
