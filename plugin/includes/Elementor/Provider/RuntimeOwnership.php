<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Provider;

/** Resolves runtime classes to installed plugin ownership without exposing paths. */
final class RuntimeOwnership {

	/** @return array{source_plugin:string,source_version:string,runtime_class:string,ownership:string,provider_id:string,provenance:array<string,string>} */
	public static function describe( object $instance ): array {
		$runtime_class = get_class( $instance );
		$runtime_class = str_contains( $runtime_class, '@anonymous' ) ? 'anonymous-runtime-class' : $runtime_class;
		$file          = self::class_file( $instance );
		$source        = self::plugin_source( $file );
		$plugin        = '' !== $source['plugin'] ? $source['plugin'] : 'runtime:' . $runtime_class;
		$provider      = self::provider_id( $plugin );

		return [
			'source_plugin'  => $plugin,
			'source_version' => $source['version'],
			'runtime_class'  => $runtime_class,
			'ownership'      => self::ownership( $provider ),
			'provider_id'    => $provider,
			'provenance'     => [
				'ownership' => '' !== $source['plugin'] ? 'runtime_reflection_and_wordpress_plugin_metadata' : 'runtime_reflection_only',
			],
		];
	}

	public static function provider_id( string $plugin ): string {
		$normalized = strtolower( trim( $plugin ) );
		if ( 'elementor/elementor.php' === $normalized ) {
			return 'elementor-core';
		}
		if ( 'elementor-pro/elementor-pro.php' === $normalized || str_starts_with( $normalized, 'pro-elements/' ) ) {
			return 'elementor-pro';
		}
		if ( '' === $normalized || str_starts_with( $normalized, 'runtime:' ) ) {
			return 'unknown';
		}
		$directory = dirname( $normalized );
		$slug      = '.' === $directory ? pathinfo( $normalized, PATHINFO_FILENAME ) : $directory;
		$slug      = sanitize_key( str_replace( '/', '-', $slug ) );
		return '' === $slug ? 'unknown' : 'plugin:' . $slug;
	}

	private static function ownership( string $provider ): string {
		if ( in_array( $provider, [ 'elementor-core', 'elementor-pro' ], true ) ) {
			return 'official';
		}
		return str_starts_with( $provider, 'plugin:' ) ? 'third-party' : 'unknown';
	}

	private static function class_file( object $instance ): string {
		try {
			$file = ( new \ReflectionClass( $instance ) )->getFileName();
			return is_string( $file ) ? $file : '';
		} catch ( \ReflectionException $error ) {
			unset( $error );
			return '';
		}
	}

	/** @return array{plugin:string,version:string} */
	private static function plugin_source( string $file ): array {
		if ( '' !== $file && function_exists( 'get_plugins' ) && defined( 'WP_PLUGIN_DIR' ) ) {
			$root       = trailingslashit( wp_normalize_path( (string) constant( 'WP_PLUGIN_DIR' ) ) );
			$normalized = wp_normalize_path( $file );
			if ( str_starts_with( $normalized, $root ) ) {
				$relative = substr( $normalized, strlen( $root ) );
				foreach ( get_plugins() as $plugin_file => $metadata ) {
					$plugin_file = (string) $plugin_file;
					$folder      = dirname( $plugin_file );
					$matches     = '.' === $folder ? $relative === $plugin_file : str_starts_with( $relative, $folder . '/' );
					if ( $matches ) {
						return [ 'plugin' => $plugin_file, 'version' => (string) ( $metadata['Version'] ?? '' ) ];
					}
				}
			}
		}
		return [ 'plugin' => '', 'version' => '' ];
	}
}
