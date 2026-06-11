<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\WidgetRegistry;

/**
 * Read-only access to the Stonewright widget manifest.
 *
 * Loads `manifest.json` (produced by `plugin/bin/manifest-synthesize.php`)
 * once per request and exposes per-widget lookups for the per-widget
 * abilities, the WidgetIntentResolver, the knowledge-base
 * `elementor-describe-widget` ability, and any caller that needs the
 * canonical Elementor setting metadata.
 *
 * The manifest is intentionally large (full control schema + harvested
 * help-article prose per widget). To keep memory bounded the data is held
 * in a single static array and returned by reference where possible.
 *
 * The class is final and intentionally has no constructor — every method
 * is static so subclasses, mocks, and call sites all see the same loaded
 * manifest.
 */
final class WidgetCatalog {

	/** @var array<string, mixed>|null */
	private static ?array $manifest = null;

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
			self::$manifest_path = __DIR__ . '/manifest.json';
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
	}

	/**
	 * Reset the in-memory cache (forces the next call to re-read from disk).
	 * Intended for tests.
	 */
	public static function reset_cache(): void {
		self::$manifest = null;
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

		// Try loading pre-compiled PHP array first (much faster + OPcache-friendly)
		$php_path = dirname( self::manifest_path() ) . '/manifest.php';
		if ( is_file( $php_path ) ) {
			$loaded = include $php_path;
			if ( is_array( $loaded ) ) {
				self::$manifest = $loaded;
				return self::$manifest;
			}
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
		$raw = (string) file_get_contents( $path );
		if ( substr( $raw, 0, 3 ) === "\xEF\xBB\xBF" ) {
			$raw = substr( $raw, 3 );
		}
		$decoded = json_decode( $raw, true );
		self::$manifest = is_array( $decoded ) ? $decoded : [
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
		$m = self::manifest();
		$w = $m['widgets'] ?? [];
		return is_array( $w ) ? $w : [];
	}

	/** List of widget slugs known to the catalog. */
	public static function slugs(): array {
		return array_keys( self::widgets() );
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
		$widgets = self::widgets();
		$entry   = $widgets[ $slug ] ?? null;
		if ( ! is_array( $entry ) ) {
			return self::stub_entry( $slug );
		}
		return $entry + self::stub_entry( $slug );
	}

	/** Whether the catalog has an entry for this slug. */
	public static function has( string $slug ): bool {
		$widgets = self::widgets();
		return isset( $widgets[ $slug ] );
	}

	/**
	 * Filter widgets by source ('free' | 'pro' | 'wc').
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function by_source( string $source ): array {
		$out = [];
		foreach ( self::widgets() as $slug => $entry ) {
			if ( ( $entry['source'] ?? null ) === $source ) {
				$out[ $slug ] = $entry;
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
		$entry = self::widgets()[ $slug ] ?? [];
		$g     = $entry['group_activators'] ?? [];
		return is_array( $g ) ? $g : [];
	}

	/**
	 * Settings index for a widget — { key => { section, type, default, group, group_prefix, responsive, condition } }.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function settings_index( string $slug ): array {
		$entry = self::widgets()[ $slug ] ?? [];
		$s     = $entry['settings_index'] ?? [];
		return is_array( $s ) ? $s : [];
	}

	/**
	 * Required-for-render keys for a widget.
	 *
	 * @return array<int, string>
	 */
	public static function required_for_render( string $slug ): array {
		$entry = self::widgets()[ $slug ] ?? [];
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
