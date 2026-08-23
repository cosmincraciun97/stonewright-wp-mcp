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
				$ownership = RuntimeOwnership::describe( $ability );
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
			$plugin    = is_string( $meta['source_plugin'] ?? null ) ? (string) $meta['source_plugin'] : $ownership['source_plugin'];
			$version   = is_string( $meta['source_version'] ?? null ) ? (string) $meta['source_version'] : $ownership['source_version'];
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
				],
			];
		}
		usort( $out, static fn( array $left, array $right ): int => strcmp( (string) $left['name'], (string) $right['name'] ) );
		return $out;
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
