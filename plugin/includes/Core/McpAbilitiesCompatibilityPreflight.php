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
		$ability_class = (string) ( $classes['ability'] ?? 'WP_Ability' );
		$adapter  = self::inspect_symbol( 'adapter', (string) ( $classes['adapter'] ?? $adapter_class ), $ability_class, $autoloaders, $package_roots, $explicit_roots );
		$registry = self::inspect_symbol( 'abilities_registry', (string) ( $classes['abilities_registry'] ?? 'WP_Abilities_Registry' ), $ability_class, $autoloaders, $package_roots, $explicit_roots );
		$ability  = self::inspect_symbol( 'ability', $ability_class, $ability_class, $autoloaders, $package_roots, $explicit_roots );

		$blocking = [];
		foreach ( [ 'adapter' => $adapter, 'abilities_registry' => $registry, 'ability' => $ability ] as $role => $symbol ) {
			if ( 'conflict' === ( $symbol['status'] ?? '' ) ) {
				$blocking[] = $role . '_multiple_owners';
			} elseif ( 'unavailable' === ( $symbol['status'] ?? '' ) || 'unavailable' === ( $symbol['abi']['status'] ?? '' ) ) {
				$blocking[] = $role . '_unavailable';
			} elseif ( 'incompatible' === ( $symbol['abi']['status'] ?? '' ) ) {
				$blocking[] = $role . '_abi_incompatible';
			}
		}
		self::$current = [
			'compatible'       => [] === $blocking,
			'adapter'          => $adapter,
			'abilities'        => [ 'registry' => $registry, 'ability' => $ability ],
			'blocking_reasons' => array_values( array_unique( $blocking ) ),
			'warnings'         => [],
			'remediation'      => [] === $blocking ? 'No action required.' : 'Review each blocked symbol below, deactivate or update only the incompatible active owner, reload WordPress, and rerun Troubleshoot.',
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
	private static function inspect_symbol( string $role, string $class, string $ability_class, array $autoloaders, array $package_roots, bool $explicit_roots ): array {
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

		$core_owned = 'adapter' !== $role && ( self::core_abilities_available() || self::contains_core_path( $paths ) );
		$candidates = [];
		$active_paths = [];
		$owner_details = [];
		foreach ( $paths as $path ) {
			$package = self::package_for_path( $path, $packages );
			$owner = null === $package ? self::owner( $path ) : (string) $package['owner'];
			$guarded = $core_owned && null !== $package && true === ( $package['guarded_fallback'] ?? false );
			$state = $guarded ? 'guarded_fallback' : ( 'wordpress-core' === $owner ? 'core_loaded' : 'autoloadable' );
			$candidates[] = [ 'owner' => $owner, 'fingerprint' => hash( 'sha256', $path ), 'state' => $state ];
			if ( ! $guarded ) {
				$active_paths[] = $path;
				$owner_details[] = [
					'owner'   => $owner,
					'version' => 'wordpress-core' === $owner ? self::core_version() : (string) ( $package['version'] ?? '' ),
					'state'   => $state,
				];
			}
		}
		if ( $core_owned && ! in_array( 'wordpress-core', array_column( $owner_details, 'owner' ), true ) ) {
			$owner_details[] = [ 'owner' => 'wordpress-core', 'version' => self::core_version(), 'state' => 'core_owned' ];
		}
		$owner_details = self::unique_owner_details( $owner_details );
		$owners = array_values( array_unique( array_column( $owner_details, 'owner' ) ) );
		sort( $owners );
		$active_paths = array_values( array_unique( $active_paths ) );
		$status = count( $active_paths ) > 1 ? 'conflict' : ( $loaded ? 'loaded' : ( 1 === count( $active_paths ) || $core_owned ? 'available' : 'unavailable' ) );
		$abi = 'conflict' === $status ? [ 'status' => 'not_checked', 'issues' => [ 'multiple_owners' ], 'version' => '' ] : self::inspect_abi( $role, $class, $ability_class, $packages, $core_owned );
		$reason = 'conflict' === $status ? 'multiple_incompatible_class_owners' : ( 'unavailable' === $status || 'unavailable' === $abi['status'] ? 'required_symbol_unavailable' : ( 'incompatible' === $abi['status'] ? 'runtime_abi_incompatible' : null ) );
		$remediation = null;
		if ( 'conflict' === $status ) {
			$remediation = 'Deactivate all but one active plugin that loads this symbol, then reload WordPress and rerun diagnostics.';
		} elseif ( 'unavailable' === $status || 'unavailable' === $abi['status'] ) {
			$remediation = 'Install or activate the package that provides this required symbol, reload WordPress, and rerun diagnostics.';
		} elseif ( 'incompatible' === $abi['status'] ) {
			$remediation = 'Update or deactivate the incompatible active owner; do not bypass the ABI check. Reload WordPress and rerun diagnostics.';
		}
		return [
			'class'         => $class,
			'status'        => $status,
			'loaded'        => class_exists( $class, false ),
			'owners'        => $owners,
			'owner_details' => $owner_details,
			'candidates'    => $candidates,
			'packages'      => array_map(
				static fn( array $package ): array => [
					'owner'            => $package['owner'],
					'name'             => $package['name'],
					'version'          => $package['version'],
					'jetpack_manifest' => $package['jetpack_manifest'],
					'state'            => $core_owned && true === ( $package['guarded_fallback'] ?? false ) ? 'guarded_fallback' : 'active',
				],
				$packages
			),
			'abi'         => $abi,
			'reason'      => $reason,
			'remediation' => $remediation,
		];
	}

	/** @param list<array<string,mixed>> $packages @return array{status:string,issues:list<string>,version:string} */
	private static function inspect_abi( string $role, string $class, string $ability_class, array $packages, bool $core_owned ): array {
		if ( ! class_exists( $class ) ) {
			return [
				'status'  => [] === $packages && ! $core_owned ? 'unavailable' : 'incompatible',
				'issues'  => [ 'class_not_loaded' ],
				'version' => $core_owned ? self::core_version() : self::package_version( $packages ),
			];
		}
		$reflection = new \ReflectionClass( $class );
		$issues = [];
		$version = $core_owned && 'adapter' !== $role ? self::core_version() : self::package_version( $packages );
		if ( $reflection->getName() !== ltrim( $class, '\\' ) ) {
			$issues[] = 'unexpected_class_or_namespace';
		}
		if ( 'adapter' === $role ) {
			foreach ( $packages as $package ) {
				if ( true !== ( $package['jetpack_manifest'] ?? false ) ) {
					$issues[] = 'jetpack_manifest_missing';
				}
			}
			$constant_version = self::public_semver_constant( $reflection, 'VERSION', $issues );
			self::require_exact_method( $reflection, 'instance', true, 0, 0, [], [ ltrim( $class, '\\' ) ], $issues, 'missing_public_static_instance' );
			self::require_exact_method(
				$reflection,
				'create_server',
				false,
				8,
				13,
				[ 'string', 'string', 'string', 'string', 'string', 'string', 'array', '?string', '?string', 'array', 'array', 'array', '?callable' ],
				[ '' ],
				$issues,
				'incompatible_create_server_signature'
			);
			$constructor = $reflection->getConstructor();
			if ( $constructor instanceof \ReflectionMethod && ( $constructor->isStatic() || 0 !== $constructor->getNumberOfRequiredParameters() || 0 !== $constructor->getNumberOfParameters() ) ) {
				$issues[] = 'incompatible_constructor_signature';
			}
			if ( '' === $version ) {
				$version = $constant_version;
			}
		} elseif ( 'abilities_registry' === $role ) {
			self::require_exact_method( $reflection, 'get_instance', true, 0, 0, [], [ ( $core_owned ? '?' : '' ) . ltrim( $class, '\\' ) ], $issues, 'missing_public_static_get_instance' );
			self::require_exact_method( $reflection, 'register', false, $core_owned ? 2 : 1, 2, [ 'string', 'array' ], [ '?' . ltrim( $ability_class, '\\' ) ], $issues, 'incompatible_register_signature' );
		} else {
			$constructor = $reflection->getConstructor();
			if ( ! $constructor instanceof \ReflectionMethod || ! $constructor->isPublic() || $constructor->isStatic() || 2 !== $constructor->getNumberOfRequiredParameters() || 2 !== $constructor->getNumberOfParameters() || [ 'string', 'array' ] !== self::parameter_types( $constructor ) ) {
				$issues[] = 'incompatible_constructor_signature';
			}
			$methods = [
				'get_name'          => 'string',
				'get_label'         => 'string',
				'get_description'   => 'string',
				'get_meta'          => 'array',
				'get_input_schema'  => 'array',
				'get_output_schema' => 'array',
			];
			if ( $core_owned ) {
				$methods['get_category'] = 'string';
			}
			foreach ( $methods as $method => $return_type ) {
				self::require_exact_method( $reflection, $method, false, 0, 0, [], [ $return_type ], $issues, 'missing_public_' . $method );
			}
		}
		if ( '' !== $version && ! self::is_semver( $version ) ) {
			$issues[] = 'invalid_package_version';
		}
		$minimum_version = [
			'adapter'            => '0.3.0',
			'abilities_registry' => $core_owned ? '6.9.0' : '0.1.1',
			'ability'            => $core_owned ? '6.9.0' : '0.1.1',
		][ $role ] ?? '';
		if ( '' !== $version && self::is_semver( $version ) && '' !== $minimum_version && version_compare( $version, $minimum_version, '<' ) ) {
			$issues[] = 'unsupported_package_version';
		}
		return [ 'status' => [] === $issues ? 'compatible' : 'incompatible', 'issues' => array_values( array_unique( $issues ) ), 'version' => $version ];
	}

	/** @param list<string> $parameter_types @param list<string> $return_types @param list<string> $issues */
	private static function require_exact_method( \ReflectionClass $class, string $name, bool $static, int $required, int $total, array $parameter_types, array $return_types, array &$issues, string $issue ): void {
		if ( ! $class->hasMethod( $name ) ) {
			$issues[] = $issue;
			return;
		}
		$method = $class->getMethod( $name );
		$return_type = self::type_name( $method->getReturnType() );
		if ( ! $method->isPublic() || $method->isStatic() !== $static || $required !== $method->getNumberOfRequiredParameters() || $total !== $method->getNumberOfParameters() || $parameter_types !== self::parameter_types( $method ) || ! in_array( $return_type, $return_types, true ) ) {
			$issues[] = $issue;
		}
	}

	/** @return list<string> */
	private static function parameter_types( \ReflectionFunctionAbstract $function ): array {
		return array_map( static fn( \ReflectionParameter $parameter ): string => self::type_name( $parameter->getType() ), $function->getParameters() );
	}

	private static function type_name( ?\ReflectionType $type ): string {
		if ( null === $type ) {
			return '';
		}
		if ( $type instanceof \ReflectionNamedType ) {
			$name = ltrim( $type->getName(), '\\' );
			return $type->allowsNull() && ! in_array( strtolower( $name ), [ 'mixed', 'null' ], true ) ? '?' . $name : $name;
		}
		if ( $type instanceof \ReflectionUnionType ) {
			$names = array_map( static fn( \ReflectionType $part ): string => ltrim( (string) $part, '\\?' ), $type->getTypes() );
			sort( $names );
			if ( 2 === count( $names ) && in_array( 'null', $names, true ) ) {
				return '?' . (string) ( 'null' === $names[0] ? $names[1] : $names[0] );
			}
			return implode( '|', $names );
		}
		return (string) $type;
	}

	/** @param list<string> $issues */
	private static function public_semver_constant( \ReflectionClass $class, string $name, array &$issues ): string {
		if ( ! $class->hasConstant( $name ) ) {
			$issues[] = 'missing_or_invalid_version_constant';
			return '';
		}
		$constant = $class->getReflectionConstant( $name );
		$value = $class->getConstant( $name );
		$version = is_string( $value ) ? ltrim( $value, 'v' ) : '';
		if ( ! $constant instanceof \ReflectionClassConstant || ! $constant->isPublic() || ! self::is_semver( $version ) ) {
			$issues[] = 'missing_or_invalid_version_constant';
		}
		return $version;
	}

	/** @param list<array<string,mixed>> $packages @return array<string,mixed>|null */
	private static function package_for_path( string $path, array $packages ): ?array {
		foreach ( $packages as $package ) {
			if ( self::normalize_path( (string) $package['_path'] ) === $path ) {
				return $package;
			}
		}
		return null;
	}

	/** @param list<array{owner:string,version:string,state:string}> $details @return list<array{owner:string,version:string,state:string}> */
	private static function unique_owner_details( array $details ): array {
		$unique = [];
		foreach ( $details as $detail ) {
			$key = $detail['owner'] . '|' . $detail['version'] . '|' . $detail['state'];
			$unique[ $key ] = $detail;
		}
		ksort( $unique );
		return array_values( $unique );
	}

	/** @param list<array<string,mixed>> $packages */
	private static function package_version( array $packages ): string {
		$active = array_values( array_filter( $packages, static fn( array $package ): bool => true !== ( $package['guarded_fallback'] ?? false ) || ! self::core_abilities_available() ) );
		return 1 === count( $active ) ? (string) ( $active[0]['version'] ?? '' ) : '';
	}

	/** @param list<string> $paths */
	private static function contains_core_path( array $paths ): bool {
		foreach ( $paths as $path ) {
			if ( 'wordpress-core' === self::owner( $path ) ) {
				return true;
			}
		}
		return false;
	}

	private static function core_abilities_available(): bool {
		$version = self::core_version();
		return self::is_semver( $version ) && version_compare( $version, '6.9.0', '>=' );
	}

	private static function core_version(): string {
		$version = $GLOBALS['wp_version'] ?? '';
		return is_string( $version ) ? ltrim( $version, 'v' ) : '';
	}

	private static function guarded_fallback( string $root, string $role ): bool {
		if ( 'adapter' === $role ) {
			return false;
		}
		$bootstrap = $root . '/vendor/wordpress/abilities-api/includes/bootstrap.php';
		if ( ! is_file( $bootstrap ) ) {
			return false;
		}
		$source = (string) file_get_contents( $bootstrap );
		return str_contains( $source, "class_exists( 'WP_Ability'" ) && str_contains( $source, "class_exists( 'WP_Abilities_Registry'" );
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
				$result[] = [
					'_path'            => $candidate,
					'owner'            => 'plugin:' . sanitize_key( basename( $root ) ),
					'name'             => $package_name,
					'version'          => ltrim( (string) ( $package['version'] ?? '' ), 'v' ),
					'jetpack_manifest' => 'adapter' !== $role || str_contains( $jetpack_source, 'WP\\\\MCP\\\\' ),
					'guarded_fallback' => self::guarded_fallback( $root, $role ),
				];
			}
		}
		return $result;
	}

	/** @return list<string> */
	private static function default_package_roots(): array {
		$roots = [ dirname( __DIR__, 2 ) ];
		if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
			return array_values( array_unique( array_map( [ self::class, 'normalize_path' ], $roots ) ) );
		}
		$active = function_exists( 'get_option' ) ? get_option( 'active_plugins', [] ) : [];
		$active = is_array( $active ) ? $active : [];
		if ( function_exists( 'get_site_option' ) ) {
			$network = get_site_option( 'active_sitewide_plugins', [] );
			if ( is_array( $network ) ) {
				$active = array_merge( $active, array_keys( $network ) );
			}
		}
		$plugin_dir = rtrim( self::normalize_path( (string) constant( 'WP_PLUGIN_DIR' ) ), '/' );
		foreach ( array_unique( array_filter( $active, 'is_string' ) ) as $plugin_file ) {
			$main_file = self::normalize_path( $plugin_dir . '/' . ltrim( $plugin_file, '/' ) );
			if ( ! str_starts_with( $main_file . '/', $plugin_dir . '/' ) ) {
				continue;
			}
			$roots[] = dirname( $main_file );
		}
		return array_values( array_unique( array_map( [ self::class, 'normalize_path' ], $roots ) ) );
	}

	private static function is_semver( string $version ): bool {
		return 1 === preg_match( '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/', $version );
	}

	private static function normalize_path( string $path ): string {
		$real = realpath( $path );
		return wp_normalize_path( false === $real ? $path : $real );
	}

	private static function owner( string $path ): string {
		if ( preg_match( '#/wp-includes/abilities-api/#i', $path ) ) {
			return 'wordpress-core';
		}
		if ( preg_match( '#/wp-content/plugins/([^/]+)/#i', $path, $matches ) ) {
			return 'plugin:' . sanitize_key( (string) $matches[1] );
		}
		if ( preg_match( '#/vendor/([^/]+/[^/]+)/#i', $path, $matches ) ) {
			return 'package:' . strtolower( (string) $matches[1] );
		}
		return 'runtime:' . sanitize_key( pathinfo( $path, PATHINFO_FILENAME ) );
	}
}
