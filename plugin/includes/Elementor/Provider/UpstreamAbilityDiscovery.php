<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Provider;

/** Reads Elementor's registered ability metadata and schemas as upstream truth. */
final class UpstreamAbilityDiscovery {

	/** @return list<array<string,mixed>> */
	public static function all(): array {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return [];
		}
		return self::from_abilities( wp_get_abilities() );
	}

	/** @param iterable<mixed> $abilities @return list<array<string,mixed>> */
	public static function from_abilities( iterable $abilities ): array {
		$out = [];
		foreach ( $abilities as $ability ) {
			if ( ! is_object( $ability ) ) {
				continue;
			}
			try {
				$name = method_exists( $ability, 'get_name' ) ? trim( (string) $ability->get_name() ) : '';
			} catch ( \Throwable $error ) {
				unset( $error );
				continue;
			}
			if ( ! str_starts_with( $name, 'elementor/' ) ) {
				continue;
			}
			try {
				$callback  = self::execution_callback( $ability );
				$ownership = RuntimeOwnership::describe_callable( $callback );
				$raw_meta  = method_exists( $ability, 'get_meta' ) ? $ability->get_meta() : [];
				$meta      = is_array( $raw_meta ) ? $raw_meta : [];
				$label     = method_exists( $ability, 'get_label' ) ? (string) $ability->get_label() : $name;
				$description = method_exists( $ability, 'get_description' ) ? (string) $ability->get_description() : '';
				$input_schema = self::schema( $ability, 'get_input_schema' );
				$output_schema = self::schema( $ability, 'get_output_schema' );
			} catch ( \Throwable $error ) {
				unset( $error );
				continue;
			}
			$plugin    = $ownership['source_plugin'];
			$version   = $ownership['source_version'];
			$runtime_contract = self::runtime_contract( $callback );
			$out[] = [
				'name'           => $name,
				'label'          => $label,
				'description'    => $description,
				'input_schema'   => $input_schema,
				'output_schema'  => $output_schema,
				'meta'           => $meta,
				'source_plugin'  => $plugin,
				'source_version' => $version,
				'runtime_class'  => $ownership['runtime_class'],
				'provenance'     => [
					'metadata' => 'upstream_registered_ability',
					'schema'   => 'upstream_registered_ability',
					'ownership' => $ownership['provenance']['ownership'],
				],
				'runtime_contract' => $runtime_contract,
			];
		}
		usort( $out, static fn( array $left, array $right ): int => strcmp( (string) $left['name'], (string) $right['name'] ) );
		return $out;
	}

	/**
	 * Read-only snapshot of what Elementor's own MCP module needs and whether this runtime meets it.
	 *
	 * @return array{elementor:array{installed:bool,version:string},mcp_module:array<string,mixed>}
	 */
	public static function native_environment(): array {
		return self::summarize_environment( self::environment_facts() );
	}

	/**
	 * Normalizes raw runtime facts into the bounded environment report.
	 *
	 * @param array<string,mixed> $facts
	 * @return array{elementor:array{installed:bool,version:string},mcp_module:array<string,mixed>}
	 */
	public static function summarize_environment( array $facts ): array {
		$version      = self::bounded_text( $facts['elementor_version'] ?? '' );
		$requirements = [
			'abilities_api' => [ 'met' => (bool) ( $facts['abilities_api'] ?? false ) ],
			'mcp_adapter'   => [
				'met'         => (bool) ( $facts['mcp_adapter'] ?? false ),
				'version'     => self::bounded_text( $facts['mcp_adapter_version'] ?? '' ),
				'provider_id' => self::bounded_text( $facts['mcp_adapter_provider'] ?? '' ),
			],
			'mcp_composer'  => [
				'met'     => (bool) ( $facts['mcp_composer'] ?? false ),
				'version' => self::bounded_text( $facts['mcp_composer_version'] ?? '' ),
			],
		];
		$missing = [];
		foreach ( $requirements as $name => $requirement ) {
			if ( ! $requirement['met'] ) {
				$missing[] = $name;
			}
		}
		$present = (bool) ( $facts['module_present'] ?? false );
		return [
			'elementor'  => [
				'installed' => '' !== $version || (bool) ( $facts['elementor_installed'] ?? false ),
				'version'   => $version,
			],
			'mcp_module' => [
				'present'               => $present,
				'active'                => $present && (bool) ( $facts['module_active'] ?? false ) && [] === $missing,
				'site_exposure_enabled' => (bool) ( $facts['site_exposure_enabled'] ?? false ),
				'atomic_editor_active'  => (bool) ( $facts['atomic_editor_active'] ?? false ),
				'requirements'          => $requirements,
				'missing'               => $missing,
			],
		];
	}

	/** @return array<string,mixed> */
	private static function environment_facts(): array {
		$module   = 'Elementor\\Modules\\Mcp\\Module';
		$adapter  = 'WP\\MCP\\Core\\McpAdapter';
		$composer = 'Elementor\\MCP\\Composer\\Mcp\\Registry';
		$settings = 'Elementor\\MCP\\Composer\\Admin\\McpSettingsController';
		$facts    = [
			'elementor_version'    => defined( 'ELEMENTOR_VERSION' ) ? (string) constant( 'ELEMENTOR_VERSION' ) : '',
			'elementor_installed'  => class_exists( 'Elementor\\Plugin', false ),
			'module_present'       => class_exists( $module ),
			'abilities_api'        => function_exists( 'wp_register_ability' ),
			'mcp_adapter'          => class_exists( $adapter ),
			'mcp_composer'         => class_exists( $composer ),
			'mcp_composer_version' => defined( 'ELEMENTOR_MCP_COMPOSER_VERSION' ) ? (string) constant( 'ELEMENTOR_MCP_COMPOSER_VERSION' ) : '',
		];
		try {
			$facts['module_active'] = $facts['module_present'] && is_callable( [ $module, 'is_active' ] ) && (bool) call_user_func( [ $module, 'is_active' ] );
			if ( $facts['mcp_adapter'] ) {
				$facts['mcp_adapter_version']  = defined( $adapter . '::VERSION' ) ? (string) constant( $adapter . '::VERSION' ) : '';
				$facts['mcp_adapter_provider'] = RuntimeOwnership::describe_callable( [ $adapter, 'instance' ] )['provider_id'];
			}
			$facts['site_exposure_enabled'] = class_exists( $settings ) && is_callable( [ $settings, 'is_enabled' ] ) && (bool) call_user_func( [ $settings, 'is_enabled' ] );
			$facts['atomic_editor_active']  = self::atomic_editor_active();
		} catch ( \Throwable $error ) {
			unset( $error );
		}
		return $facts;
	}

	private static function atomic_editor_active(): bool {
		if ( ! class_exists( \Elementor\Plugin::class, false ) || ! is_object( \Elementor\Plugin::$instance ) || ! is_object( \Elementor\Plugin::$instance->experiments ?? null ) ) {
			return false;
		}
		$experiments = \Elementor\Plugin::$instance->experiments;
		return method_exists( $experiments, 'is_feature_active' ) && (bool) $experiments->is_feature_active( 'e_atomic_elements' );
	}

	private static function bounded_text( mixed $value ): string {
		return is_scalar( $value ) ? substr( (string) $value, 0, 100 ) : '';
	}

	private static function execution_callback( object $ability ): mixed {
		$reader = \Closure::bind(
			static function ( object $target ): mixed {
				return property_exists( $target, 'execute_callback' ) ? $target->execute_callback : null;
			},
			null,
			get_class( $ability )
		);
		if ( ! $reader instanceof \Closure ) {
			return null;
		}
		try {
			return $reader( $ability );
		} catch ( \Throwable $error ) {
			unset( $error );
			return null;
		}
	}

	/** @return array<string,mixed> */
	private static function runtime_contract( mixed $callback ): array {
		$class = is_array( $callback ) && isset( $callback[0] )
			? ( is_object( $callback[0] ) ? get_class( $callback[0] ) : ( is_string( $callback[0] ) ? $callback[0] : '' ) )
			: '';
		if ( '' === $class || ! class_exists( $class, false ) ) {
			return [];
		}
		try {
			$reflection = new \ReflectionClass( $class );
			if ( ! $reflection->hasConstant( 'MAX_BATCH_SIZE' ) ) {
				return [];
			}
			$limit = $reflection->getConstant( 'MAX_BATCH_SIZE' );
			if ( ! is_int( $limit ) ) {
				return [];
			}
			$contract = [ 'runtime_operation_limit' => $limit ];
			if ( $reflection->hasConstant( 'CLASS_TYPE' ) ) {
				$class_type = $reflection->getConstant( 'CLASS_TYPE' );
				if ( is_string( $class_type ) ) {
					$contract['class_type'] = $class_type;
				}
			}
			return $contract;
		} catch ( \ReflectionException $error ) {
			unset( $error );
			return [];
		}
	}

	/** @return array<string,mixed> */
	private static function schema( object $ability, string $method ): array {
		if ( ! method_exists( $ability, $method ) ) {
			return [];
		}
		$schema = $ability->{$method}();
		return is_array( $schema ) ? $schema : [];
	}
}
