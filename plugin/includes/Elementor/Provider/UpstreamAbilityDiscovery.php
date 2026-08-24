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
			$plugin    = is_string( $meta['source_plugin'] ?? null ) && '' !== trim( (string) $meta['source_plugin'] ) ? (string) $meta['source_plugin'] : $ownership['source_plugin'];
			$version   = is_string( $meta['source_version'] ?? null ) ? (string) $meta['source_version'] : $ownership['source_version'];
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
					'ownership' => is_string( $meta['source_plugin'] ?? null ) && '' !== trim( (string) $meta['source_plugin'] ) ? 'explicit_registration_metadata' : $ownership['provenance']['ownership'],
				],
				'runtime_contract' => $runtime_contract,
			];
		}
		usort( $out, static fn( array $left, array $right ): int => strcmp( (string) $left['name'], (string) $right['name'] ) );
		return $out;
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
