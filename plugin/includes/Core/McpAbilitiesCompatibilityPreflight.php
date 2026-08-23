<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Core;

/** Detects competing MCP/Abilities class owners before booting an adapter. */
final class McpAbilitiesCompatibilityPreflight {

	/** @var array<string,mixed>|null */
	private static ?array $current = null;

	/** @param list<mixed>|null $autoloaders @return array<string,mixed> */
	public static function inspect( ?array $autoloaders = null, string $adapter_class = 'WP\\MCP\\Core\\McpAdapter' ): array {
		$classes = apply_filters(
			'stonewright_compatibility_class_names',
			[
				'adapter'            => $adapter_class,
				'abilities_registry' => 'WP_Abilities_Registry',
				'ability'            => 'WP_Ability',
			]
		);
		$classes = is_array( $classes ) ? $classes : [];
		$autoloaders ??= spl_autoload_functions() ?: [];

		$adapter = self::inspect_class( (string) ( $classes['adapter'] ?? $adapter_class ), $autoloaders );
		$registry = self::inspect_class( (string) ( $classes['abilities_registry'] ?? 'WP_Abilities_Registry' ), $autoloaders );
		$ability  = self::inspect_class( (string) ( $classes['ability'] ?? 'WP_Ability' ), $autoloaders );
		$warnings = [];
		if ( 'conflict' === $registry['status'] ) {
			$warnings[] = 'abilities_registry_multiple_owners';
		}
		if ( 'conflict' === $ability['status'] ) {
			$warnings[] = 'ability_class_multiple_owners';
		}
		self::$current = [
			'compatible' => 'conflict' !== $adapter['status'],
			'adapter'    => $adapter,
			'abilities'  => [ 'registry' => $registry, 'ability' => $ability ],
			'warnings'   => $warnings,
		];
		return self::$current;
	}

	/** @return array<string,mixed>|null */
	public static function current(): ?array {
		return self::$current;
	}

	/** @internal */
	public static function reset_for_tests(): void {
		self::$current = null;
	}

	/** @param list<mixed> $autoloaders @return array<string,mixed> */
	private static function inspect_class( string $class, array $autoloaders ): array {
		$paths  = [];
		$loaded = class_exists( $class, false );
		if ( $loaded ) {
			try {
				$file = ( new \ReflectionClass( $class ) )->getFileName();
				if ( is_string( $file ) && '' !== $file ) {
					$paths[] = $file;
				}
			} catch ( \ReflectionException $error ) {
				unset( $error );
			}
		}
		foreach ( $autoloaders as $autoloader ) {
			$loader = is_array( $autoloader ) ? ( $autoloader[0] ?? null ) : $autoloader;
			if ( ! is_object( $loader ) || ! method_exists( $loader, 'findFile' ) ) {
				continue;
			}
			try {
				$file = $loader->findFile( $class );
				if ( is_string( $file ) && '' !== $file ) {
					$paths[] = $file;
				}
			} catch ( \Throwable $error ) {
				unset( $error );
			}
		}
		$paths = apply_filters( 'stonewright_compatibility_class_candidates', $paths, $class );
		$paths = is_array( $paths ) ? array_values( array_unique( array_map( [ self::class, 'normalize_path' ], array_filter( $paths, 'is_string' ) ) ) ) : [];
		sort( $paths );
		$candidates = [];
		$owners     = [];
		foreach ( $paths as $path ) {
			$owner = self::owner( $path );
			$owners[] = $owner;
			$candidates[] = [ 'owner' => $owner, 'fingerprint' => hash( 'sha256', $path ) ];
		}
		$owners = array_values( array_unique( $owners ) );
		sort( $owners );
		$status = count( $paths ) > 1 ? 'conflict' : ( $loaded ? 'loaded' : ( 1 === count( $paths ) ? 'available' : 'unavailable' ) );
		return [
			'class'      => $class,
			'status'     => $status,
			'loaded'     => $loaded,
			'owners'     => $owners,
			'candidates' => $candidates,
			'reason'     => 'conflict' === $status ? 'multiple_incompatible_class_owners' : null,
		];
	}

	private static function normalize_path( string $path ): string {
		$real = realpath( $path );
		return wp_normalize_path( false === $real ? $path : $real );
	}

	private static function owner( string $path ): string {
		if ( preg_match( '#/wp-content/plugins/([^/]+)/#i', $path, $matches ) ) {
			return 'plugin:' . sanitize_key( (string) $matches[1] );
		}
		if ( preg_match( '#/vendor/([^/]+/[^/]+)/#i', $path, $matches ) ) {
			return 'package:' . strtolower( (string) $matches[1] );
		}
		return 'runtime:' . sanitize_key( pathinfo( $path, PATHINFO_FILENAME ) );
	}
}
