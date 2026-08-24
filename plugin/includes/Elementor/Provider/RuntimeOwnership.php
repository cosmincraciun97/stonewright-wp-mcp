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
		if ( 'elementor-pro/elementor-pro.php' === $normalized || str_starts_with( $normalized, 'pro-elements/' ) ) {
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
		if ( '' !== $file && defined( 'WP_PLUGIN_DIR' ) ) {
			$root       = trailingslashit( wp_normalize_path( (string) constant( 'WP_PLUGIN_DIR' ) ) );
			$normalized = wp_normalize_path( $file );
			if ( str_starts_with( $normalized, $root ) ) {
				$relative = substr( $normalized, strlen( $root ) );
				$plugins  = function_exists( 'get_plugins' ) ? get_plugins() : [];
				foreach ( $plugins as $plugin_file => $metadata ) {
					$plugin_file = (string) $plugin_file;
					$folder      = dirname( $plugin_file );
					$matches     = '.' === $folder ? $relative === $plugin_file : str_starts_with( $relative, $folder . '/' );
					if ( $matches ) {
						return [
							'plugin'   => $plugin_file,
							'version'  => (string) ( $metadata['Version'] ?? '' ),
							'evidence' => 'registration_callback_and_wordpress_plugin_metadata',
						];
					}
				}
				$folder = strtok( $relative, '/' );
				if ( is_string( $folder ) && '' !== $folder ) {
					$main_file = $folder . '/' . $folder . '.php';
					if ( in_array( $main_file, [ 'elementor/elementor.php', 'elementor-pro/elementor-pro.php' ], true ) || is_file( $root . $main_file ) ) {
						$version = 'elementor/elementor.php' === $main_file && defined( 'ELEMENTOR_VERSION' ) ? (string) constant( 'ELEMENTOR_VERSION' ) : '';
						$version = 'elementor-pro/elementor-pro.php' === $main_file && defined( 'ELEMENTOR_PRO_VERSION' ) ? (string) constant( 'ELEMENTOR_PRO_VERSION' ) : $version;
						return [
							'plugin'   => $main_file,
							'version'  => $version,
							'evidence' => 'registration_callback_and_plugin_boundary',
						];
					}
				}
			}
		}
		return [ 'plugin' => '', 'version' => '', 'evidence' => 'registration_callback' ];
	}
}
