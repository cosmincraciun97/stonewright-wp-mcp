<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Core;

/** Verifies MCP and Abilities package ownership and ABI before adapter instantiation. */
final class McpAbilitiesCompatibilityPreflight {
	/** @var array<string,mixed>|null */
	private static ?array $current = null;

	/** @param list<mixed>|null $autoloaders @param list<string>|null $package_roots @return array<string,mixed> */
	public static function inspect( ?array $autoloaders = null, string $adapter_class = 'WP\\MCP\\Core\\McpAdapter', ?array $package_roots = null ): array {
		$classes = apply_filters( 'stonewright_compatibility_class_names', [ 'adapter' => $adapter_class, 'abilities_registry' => 'WP_Abilities_Registry', 'ability' => 'WP_Ability' ] );
		$classes = is_array( $classes ) ? $classes : [];
		$autoloaders ??= spl_autoload_functions() ?: [];
		$explicit_roots = null !== $package_roots;
		$package_roots ??= self::default_package_roots();
		$adapter  = self::inspect_symbol( 'adapter', (string) ( $classes['adapter'] ?? $adapter_class ), $autoloaders, $package_roots, $explicit_roots );
		$registry = self::inspect_symbol( 'abilities_registry', (string) ( $classes['abilities_registry'] ?? 'WP_Abilities_Registry' ), $autoloaders, $package_roots, $explicit_roots );
		$ability  = self::inspect_symbol( 'ability', (string) ( $classes['ability'] ?? 'WP_Ability' ), $autoloaders, $package_roots, $explicit_roots );

		$blocking = [];
		foreach ( [ 'adapter' => $adapter, 'abilities_registry' => $registry, 'ability' => $ability ] as $role => $symbol ) {
			if ( 'conflict' === ( $symbol['status'] ?? '' ) ) {
				$blocking[] = $role . '_multiple_owners';
			}
			if ( 'incompatible' === ( $symbol['abi']['status'] ?? '' ) ) {
				$blocking[] = $role . '_abi_incompatible';
			}
		}
		self::$current = [
			'compatible' => [] === $blocking,
			'adapter' => $adapter,
			'abilities' => [ 'registry' => $registry, 'ability' => $ability ],
			'blocking_reasons' => array_values( array_unique( $blocking ) ),
			'warnings' => [],
			'remediation' => [] === $blocking ? 'No action required.' : 'Disable the conflicting MCP or Abilities plugin, keep one compatible package owner, reload WordPress, and rerun Troubleshoot.',
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

	/** @param list<mixed> $autoloaders @param list<string> $package_roots @return array<string,mixed> */
	private static function inspect_symbol( string $role, string $class, array $autoloaders, array $package_roots, bool $explicit_roots ): array {
		$canonical = [ 'adapter' => 'WP\\MCP\\Core\\McpAdapter', 'abilities_registry' => 'WP_Abilities_Registry', 'ability' => 'WP_Ability' ];
		$packages = ( $explicit_roots || ( $canonical[ $role ] ?? '' ) === $class ) ? self::manifest_candidates( $role, $package_roots ) : [];
		$paths = array_map( static fn( array $package ): string => (string) $package['_path'], $packages );
		$loaded = class_exists( $class, false );
		if ( $loaded && ! $explicit_roots ) {
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
		$owners = [];
		$candidates = [];
		foreach ( $paths as $path ) {
			$owner = self::owner( $path );
			foreach ( $packages as $package ) {
				if ( self::normalize_path( (string) $package['_path'] ) === $path ) {
					$owner = (string) $package['owner'];
					break;
				}
			}
			$owners[] = $owner;
			$candidates[] = [ 'owner' => $owner, 'fingerprint' => hash( 'sha256', $path ) ];
		}
		$owners = array_values( array_unique( $owners ) );
		sort( $owners );
		$status = count( $paths ) > 1 ? 'conflict' : ( $loaded ? 'loaded' : ( 1 === count( $paths ) ? 'available' : 'unavailable' ) );
		$abi = 'conflict' === $status ? [ 'status' => 'not_checked', 'issues' => [ 'multiple_owners' ], 'version' => '' ] : self::inspect_abi( $role, $class, $packages );
		return [
			'class' => $class,
			'status' => $status,
			'loaded' => class_exists( $class, false ),
			'owners' => $owners,
			'candidates' => $candidates,
			'packages' => array_map( static fn( array $package ): array => [ 'owner' => $package['owner'], 'name' => $package['name'], 'version' => $package['version'], 'jetpack_manifest' => $package['jetpack_manifest'] ], $packages ),
			'abi' => $abi,
			'reason' => 'conflict' === $status ? 'multiple_incompatible_class_owners' : ( 'incompatible' === $abi['status'] ? 'runtime_abi_incompatible' : null ),
		];
	}

	/** @param list<array<string,mixed>> $packages @return array{status:string,issues:list<string>,version:string} */
	private static function inspect_abi( string $role, string $class, array $packages ): array {
		if ( ! class_exists( $class ) ) {
			return [
				'status'  => [] === $packages ? 'unavailable' : 'incompatible',
				'issues'  => [ 'class_not_loaded' ],
				'version' => self::package_version( $packages ),
			];
		}
		$reflection = new \ReflectionClass( $class );
		$issues = [];
		$version = self::package_version( $packages );
		if ( 'adapter' === $role ) {
			foreach ( $packages as $package ) {
				if ( true !== ( $package['jetpack_manifest'] ?? false ) ) {
					$issues[] = 'jetpack_manifest_missing';
				}
			}
			$constant_version = $reflection->hasConstant( 'VERSION' ) ? $reflection->getConstant( 'VERSION' ) : '';
			$constant_version = is_string( $constant_version ) ? ltrim( $constant_version, 'v' ) : '';
			if ( ! self::is_semver( $constant_version ) ) {
				$issues[] = 'missing_or_invalid_version_constant';
			}
			self::require_method( $reflection, 'instance', true, 0, 0, $issues, 'missing_public_static_instance' );
			self::require_method( $reflection, 'create_server', false, 0, 12, $issues, 'incompatible_create_server_signature' );
			$constructor = $reflection->getConstructor();
			if ( $constructor instanceof \ReflectionMethod && $constructor->getNumberOfRequiredParameters() > 0 ) {
				$issues[] = 'constructor_requires_arguments';
			}
			if ( '' === $version ) {
				$version = $constant_version;
			}
		} elseif ( 'abilities_registry' === $role ) {
			self::require_method( $reflection, 'get_instance', true, 0, 0, $issues, 'missing_public_static_get_instance' );
			self::require_method( $reflection, 'register', false, 1, 2, $issues, 'incompatible_register_signature' );
		} else {
			$constructor = $reflection->getConstructor();
			if ( ! $constructor instanceof \ReflectionMethod || ! $constructor->isPublic() || 2 !== $constructor->getNumberOfRequiredParameters() ) {
				$issues[] = 'incompatible_constructor_signature';
			}
			foreach ( [ 'get_name', 'get_meta', 'get_input_schema', 'get_output_schema' ] as $method ) {
				self::require_method( $reflection, $method, false, 0, 0, $issues, 'missing_public_' . $method );
			}
		}
		if ( '' !== $version && ! self::is_semver( $version ) ) {
			$issues[] = 'invalid_package_version';
		}
		$minimum_version = [
			'adapter'            => '0.3.0',
			'abilities_registry' => '0.1.1',
			'ability'            => '0.1.1',
		][ $role ] ?? '';
		if ( '' !== $version && self::is_semver( $version ) && '' !== $minimum_version && version_compare( $version, $minimum_version, '<' ) ) {
			$issues[] = 'unsupported_package_version';
		}
		return [ 'status' => [] === $issues ? 'compatible' : 'incompatible', 'issues' => array_values( array_unique( $issues ) ), 'version' => $version ];
	}

	/** @param list<string> $issues */
	private static function require_method( \ReflectionClass $class, string $name, bool $static, int $minimum_required, int $minimum_total, array &$issues, string $issue ): void {
		if ( ! $class->hasMethod( $name ) ) {
			$issues[] = $issue;
			return;
		}
		$method = $class->getMethod( $name );
		if ( ! $method->isPublic() || $method->isStatic() !== $static || $method->getNumberOfRequiredParameters() < $minimum_required || $method->getNumberOfParameters() < $minimum_total ) {
			$issues[] = $issue;
		}
	}

	/** @param list<string> $roots @return list<array<string,mixed>> */
	private static function manifest_candidates( string $role, array $roots ): array {
		$package_name = 'adapter' === $role ? 'wordpress/mcp-adapter' : 'wordpress/abilities-api';
		$relative = match ( $role ) {
			'adapter' => 'vendor/wordpress/mcp-adapter/includes/Core/McpAdapter.php',
			'abilities_registry' => 'vendor/wordpress/abilities-api/includes/abilities-api/class-wp-abilities-registry.php',
			default => 'vendor/wordpress/abilities-api/includes/abilities-api/class-wp-ability.php',
		};
		$result = [];
		foreach ( $roots as $root ) {
			$root = rtrim( self::normalize_path( $root ), '/' );
			$manifest = $root . '/vendor/composer/installed.json';
			$candidate = $root . '/' . $relative;
			if ( ! is_file( $manifest ) || ! is_file( $candidate ) ) {
				continue;
			}
			$decoded = json_decode( (string) file_get_contents( $manifest ), true );
			$rows = is_array( $decoded['packages'] ?? null ) ? $decoded['packages'] : ( is_array( $decoded ) ? $decoded : [] );
			foreach ( $rows as $package ) {
				if ( ! is_array( $package ) || $package_name !== ( $package['name'] ?? null ) ) {
					continue;
				}
				$jetpack = $root . '/vendor/composer/jetpack_autoload_psr4.php';
				$jetpack_source = is_file( $jetpack ) ? (string) file_get_contents( $jetpack ) : '';
				$result[] = [ '_path' => $candidate, 'owner' => 'plugin:' . sanitize_key( basename( $root ) ), 'name' => $package_name, 'version' => ltrim( (string) ( $package['version'] ?? '' ), 'v' ), 'jetpack_manifest' => 'adapter' !== $role || str_contains( $jetpack_source, 'WP\\\\MCP\\\\' ) ];
			}
		}
		return $result;
	}

	/** @return list<string> */
	private static function default_package_roots(): array {
		$roots = [ dirname( __DIR__, 2 ) ];
		if ( defined( 'WP_PLUGIN_DIR' ) ) {
			foreach ( glob( trailingslashit( (string) constant( 'WP_PLUGIN_DIR' ) ) . '*/vendor/composer/installed.json' ) ?: [] as $manifest ) {
				$roots[] = dirname( $manifest, 3 );
			}
		}
		return array_values( array_unique( array_map( [ self::class, 'normalize_path' ], $roots ) ) );
	}

	/** @param list<array<string,mixed>> $packages */
	private static function package_version( array $packages ): string {
		return 1 === count( $packages ) ? (string) ( $packages[0]['version'] ?? '' ) : '';
	}

	private static function is_semver( string $version ): bool {
		return 1 === preg_match( '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/', $version );
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
