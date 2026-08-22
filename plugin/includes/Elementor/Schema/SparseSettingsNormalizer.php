<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Schema;

/**
 * Strips empty Elementor default objects from NEW write payloads.
 *
 * Runs after schema/evidence validation. Unknown keys are left in place so
 * SettingsValidator remains the rejection gate. Existing live document keys
 * are never migrated; only the agent-supplied patch is sparsified.
 */
final class SparseSettingsNormalizer {

	/**
	 * @param array<string, mixed>                $settings Validated settings.
	 * @param array<string, array<string, mixed>> $controls Live schema controls.
	 * @param array<string, mixed>                $supplied Agent-supplied settings.
	 * @param list<string>                        $required Keys Elementor requires.
	 * @param bool                                $strip_schema_defaults Remove known widget defaults.
	 * @return array<string, mixed>
	 */
	public static function normalize( array $settings, array $controls, array $supplied, array $required = [], bool $strip_schema_defaults = false ): array {
		$out = [];
		foreach ( $settings as $key => $value ) {
			$key = (string) $key;
			if ( in_array( $key, [ '__dynamic__', '__globals__' ], true ) ) {
				$out[ $key ] = $value;
				continue;
			}
			if ( ! array_key_exists( $key, $supplied ) ) {
				continue;
			}
			if ( ! self::is_allowlisted( $key, $controls ) ) {
				$out[ $key ] = $value;
				continue;
			}
			if ( ! in_array( $key, $required, true ) ) {
				$control = self::control_for_key( $key, $controls );
				if ( self::is_empty_default_object( $value )
					|| ( $strip_schema_defaults && is_array( $control ) && array_key_exists( 'default', $control ) && $value === $control['default'] )
				) {
					continue;
				}
			}
			$out[ $key ] = self::canonicalize_numbers( $value );
		}
		return $out;
	}

	/**
	 * Collapse binary float noise (1.0800000000000001 → 1.08) without changing integers.
	 */
	private static function canonicalize_numbers( mixed $value ): mixed {
		if ( is_float( $value ) && is_finite( $value ) ) {
			return round( $value, 8 );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::canonicalize_numbers( $item );
			}
		}
		return $value;
	}

	/**
	 * Sparse a new widget/container payload (no live document merge).
	 *
	 * @param array<string, mixed> $validated
	 * @param array<string, mixed> $supplied
	 * @return array<string, mixed>
	 */
	public static function for_new_write( array $validated, string $widget_or_element_type, array $supplied ): array {
		$schema = self::schema( $widget_or_element_type );
		if ( $schema instanceof \WP_Error ) {
			return $validated;
		}
		return self::normalize(
			$validated,
			(array) ( $schema['controls'] ?? [] ),
			$supplied,
			array_map( 'strval', (array) ( $schema['required_for_render'] ?? [] ) ),
			! in_array( $widget_or_element_type, [ 'container', 'section', 'column' ], true )
		);
	}

	/**
	 * Sparse only the incoming patch, then merge onto the live element settings.
	 *
	 * @param array<string, mixed> $merged_validated Validated merge result.
	 * @param array<string, mixed> $supplied         Original agent patch.
	 * @param array<string, mixed> $existing         Live element settings.
	 * @return array<string, mixed>
	 */
	public static function for_write( array $merged_validated, array $controls, array $supplied, array $existing, array $required = [] ): array {
		$patch = self::normalize( $merged_validated, $controls, $supplied, $required );
		return array_merge( $existing, $patch );
	}

	/**
	 * @param array<string, array<string, mixed>> $controls
	 */
	private static function is_allowlisted( string $key, array $controls ): bool {
		return null !== self::control_for_key( $key, $controls );
	}

	/**
	 * @param array<string, array<string, mixed>> $controls
	 * @return array<string, mixed>|null
	 */
	private static function control_for_key( string $key, array $controls ): ?array {
		if ( isset( $controls[ $key ] ) ) {
			return $controls[ $key ];
		}
		foreach ( [ '_widescreen', '_laptop', '_tablet_extra', '_tablet', '_mobile_extra', '_mobile' ] as $suffix ) {
			if ( ! str_ends_with( $key, $suffix ) ) {
				continue;
			}
			$base = substr( $key, 0, -strlen( $suffix ) );
			if ( isset( $controls[ $base ] ) ) {
				return $controls[ $base ];
			}
		}
		return null;
	}

	private static function is_empty_default_object( mixed $value ): bool {
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			return false;
		}
		if ( array_key_exists( 'size', $value ) ) {
			$size        = $value['size'];
			$sizes       = $value['sizes'] ?? [];
			$empty_size  = '' === $size || null === $size;
			$empty_sizes = [] === $sizes;
			$extra       = array_diff_key( $value, array_flip( [ 'size', 'unit', 'sizes' ] ) );
			return $empty_size && $empty_sizes && [] === $extra;
		}
		$sides = [ 'top', 'right', 'bottom', 'left' ];
		if ( [] === array_diff( $sides, array_keys( $value ) ) ) {
			foreach ( $sides as $side ) {
				$side_value = $value[ $side ];
				if ( ! ( '' === $side_value || null === $side_value ) ) {
					return false;
				}
			}
			$extra = array_diff_key( $value, array_flip( array_merge( $sides, [ 'unit', 'isLinked' ] ) ) );
			return [] === $extra;
		}
		return false;
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function schema( string $widget_or_element_type ): array|\WP_Error {
		if ( in_array( $widget_or_element_type, [ 'container', 'section', 'column' ], true ) ) {
			return ContainerSchemaRepository::get( $widget_or_element_type );
		}
		return WidgetSchemaRepository::get( $widget_or_element_type );
	}
}
