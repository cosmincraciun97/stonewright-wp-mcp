<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Provider;

/** Resolves runtime classes to installed plugin ownership without exposing paths. */
final class RuntimeOwnership {

	/** @return array{source_plugin:string,source_version:string,runtime_class:string,ownership:string,provider_id:string,provenance:array<string,string>} */
	public static function describe( object $instance ): array {
		return self::describe_class( get_class( $instance ) );
	}

	/** @return array{source_plugin:string,source_version:string,runtime_class:string,ownership:string,provider_id:string,provenance:array<string,string>} */
	public static function describe_callable( mixed $callback ): array {
		if ( is_array( $callback ) && isset( $callback[0] ) && ( is_object( $callback[0] ) || is_string( $callback[0] ) ) ) {
			return self::describe_class( is_object( $callback[0] ) ? get_class( $callback[0] ) : $callback[0] );
		}
		if ( $callback instanceof \Closure ) {
			try {
				$reflection = new \ReflectionFunction( $callback );
				$scope      = $reflection->getClosureScopeClass();
				if ( $scope instanceof \ReflectionClass ) {
					return self::describe_class( $scope->getName() );
				}
			} catch ( \ReflectionException $error ) {
				unset( $error );
			}
		}
		return self::unknown();
	}

	/** @return array{source_plugin:string,source_version:string,runtime_class:string,ownership:string,provider_id:string,provenance:array<string,string>} */
	private static function describe_class( string $runtime_class ): array {
		$runtime_class = str_contains( $runtime_class, '@anonymous' ) ? 'anonymous-runtime-class' : $runtime_class;
		$file          = self::class_file( $runtime_class );
		$source        = self::plugin_source( $file );
		$plugin        = '' !== $source['plugin'] ? $source['plugin'] : 'unknown';
		$provider      = self::provider_id( $plugin );

		return [
			'source_plugin'  => $plugin,
			'source_version' => $source['version'],
			'runtime_class'  => $runtime_class,
			'ownership'      => self::ownership( $provider ),
			'provider_id'    => $provider,
			'provenance'     => [ 'ownership' => $source['evidence'] ],
		];
	}

	/** @return array{source_plugin:string,source_version:string,runtime_class:string,ownership:string,provider_id:string,provenance:array<string,string>} */
	private static function unknown(): array {
		return [
			'source_plugin' => 'unknown',
			'source_version' => '',
			'runtime_class' => 'unknown',
			'ownership' => 'unknown',
			'provider_id' => 'unknown',
			'provenance' => [ 'ownership' => 'unavailable' ],
		];
	}

	public static function provider_id( string $plugin ): string {
		$normalized = strtolower( trim( $plugin ) );
		if ( 'elementor/elementor.php' === $normalized ) {
			return 'elementor-core';
		}
		if ( 'elementor-pro/elementor-pro.php' === $normalized ) {
			return 'elementor-pro';
		}
		if ( '' === $normalized || 'unknown' === $normalized || str_starts_with( $normalized, 'runtime:' ) ) {
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

	private static function class_file( string $class ): string {
		try {
			$file = ( new \ReflectionClass( $class ) )->getFileName();
			return is_string( $file ) ? $file : '';
		} catch ( \ReflectionException $error ) {
			unset( $error );
			return '';
		}
	}

	/** @return array{plugin:string,version:string,evidence:string} */
	private static function plugin_source( string $file ): array {
		if ( '' === $file || ! defined( 'WP_PLUGIN_DIR' ) ) {
			return [ 'plugin' => '', 'version' => '', 'evidence' => 'registration_callback' ];
		}
		$root = trailingslashit( self::normalize_path( (string) constant( 'WP_PLUGIN_DIR' ) ) );
		$normalized = self::normalize_path( $file );
		if ( ! str_starts_with( $normalized, $root ) ) {
			return [ 'plugin' => '', 'version' => '', 'evidence' => 'registration_callback' ];
		}

		$matches = [];
		foreach ( self::active_plugin_files() as $plugin_file ) {
			$main_file = self::normalize_path( $root . ltrim( $plugin_file, '/' ) );
			if ( ! str_starts_with( $main_file, $root ) ) {
				continue;
			}
			$boundary = trailingslashit( dirname( $main_file ) );
			$root_level = '.' === dirname( $plugin_file );
			if ( ( $root_level && $normalized !== $main_file ) || ( ! $root_level && $normalized !== $main_file && ! str_starts_with( $normalized, $boundary ) ) ) {
				continue;
			}
			$matches[ strlen( $boundary ) ] = [ $plugin_file, $main_file ];
		}
		if ( [] !== $matches ) {
			krsort( $matches );
			[ $plugin_file, $main_file ] = reset( $matches );
			$version = self::plugin_version( (string) $main_file );
			return [
				'plugin'   => (string) $plugin_file,
				'version'  => $version,
				'evidence' => '' === $version ? 'active_plugin_boundary' : 'active_plugin_header',
			];
		}
		return [ 'plugin' => '', 'version' => '', 'evidence' => 'registration_callback' ];
	}

	/** @return list<string> */
	private static function active_plugin_files(): array {
		$active = function_exists( 'get_option' ) ? get_option( 'active_plugins', [] ) : [];
		$active = is_array( $active ) ? $active : [];
		if ( function_exists( 'get_site_option' ) ) {
			$network = get_site_option( 'active_sitewide_plugins', [] );
			if ( is_array( $network ) ) {
				$active = array_merge( $active, array_keys( $network ) );
			}
		}
		return array_values( array_unique( array_filter( array_map( 'strval', $active ) ) ) );
	}

	private static function plugin_version( string $main_file ): string {
		if ( ! is_file( $main_file ) || ! is_readable( $main_file ) ) {
			return '';
		}
		if ( function_exists( 'get_file_data' ) ) {
			$headers = get_file_data( $main_file, [ 'Version' => 'Version' ], 'plugin' );
			if ( is_array( $headers ) && is_string( $headers['Version'] ?? null ) ) {
				return trim( $headers['Version'] );
			}
		}
		$source = file_get_contents( $main_file, false, null, 0, 8192 );
		if ( ! is_string( $source ) || 1 !== preg_match( '/^[ \t\/*#@]*Version:\s*(.+)$/mi', $source, $matches ) ) {
			return '';
		}
		return trim( (string) $matches[1] );
	}

	private static function normalize_path( string $path ): string {
		$real = realpath( $path );
		return wp_normalize_path( false === $real ? $path : $real );
	}
}
