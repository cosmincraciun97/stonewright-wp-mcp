<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Schema;

/**
 * Validates only the settings delta while preserving untouched legacy/runtime
 * controls. A touched invalid path is an error; an untouched legacy violation
 * is an explicit warning and is never silently normalized away.
 */
final class PatchValidator {

	/** @return array{settings:array<string,mixed>,schema_hash:string,warnings:list<array<string,mixed>>,changed_paths:list<string>}|\WP_Error */
	public static function widget( string $widget_type, array $before, array $patch, string $mode = 'merge' ): array|\WP_Error {
		$identity_error = self::validate_repeater_identity( $patch );
		if ( $identity_error instanceof \WP_Error ) {
			return $identity_error;
		}
		$repeater_validation = self::repeater_validation_patch( $widget_type, $before, $patch );
		if ( $repeater_validation instanceof \WP_Error ) {
			return $repeater_validation;
		}
		$patch_result = SettingsValidator::validate( $widget_type, $repeater_validation['validation_patch'], false, true, true );
		if ( $patch_result instanceof \WP_Error ) {
			return self::with_patch_context( $patch_result, $patch );
		}
		$warnings = array_merge( $patch_result['warnings'], $repeater_validation['warnings'] );
		$normalized_patch = $patch_result['settings'];
		foreach ( $repeater_validation['repeater_keys'] as $repeater_key ) {
			// Unknown legacy/plugin fields were intentionally omitted only from the
			// validation projection. Persist the caller's byte-equivalent rows.
			$normalized_patch[ $repeater_key ] = $patch[ $repeater_key ];
		}
		$unknown_error = self::new_unknown_touched_error( $warnings, $before, $patch );
		if ( $unknown_error instanceof \WP_Error ) {
			return $unknown_error;
		}
		$settings = 'replace' === $mode ? $normalized_patch : self::merge_settings( $before, $normalized_patch );
		$changed_paths = self::changed_paths( $before, $settings );
		$full     = SettingsValidator::validate( $widget_type, $settings, false, false, true );
		$legacy_warnings = [];
		if ( $full instanceof \WP_Error ) {
			$data = (array) $full->get_error_data();
			$violations = isset( $data['violations'] ) && is_array( $data['violations'] ) ? $data['violations'] : [];
			foreach ( $violations as $violation ) {
				$path = is_array( $violation ) ? (string) ( $violation['path'] ?? '' ) : '';
				if ( self::path_touched( $path, $changed_paths ) ) {
					return self::with_patch_context( $full, $patch );
				}
				$legacy_warnings[] = [
					'code'      => 'untouched_legacy_violation',
					'path'      => $path,
					'original'  => is_array( $violation ) ? $violation : [],
					'policy'    => 'preserved_without_normalization',
				];
			}
		}
		return [
			'settings'       => $settings,
			'schema_hash'    => (string) $patch_result['schema_hash'],
			'warnings'       => array_merge( $warnings, $legacy_warnings ),
			'changed_paths'  => $changed_paths,
		];
	}

	/**
	 * Validate only known repeater fields while proving every unknown field is
	 * byte-equivalent to the corresponding live row. This prevents legacy or
	 * third-party fields from blocking a sibling patch without letting callers
	 * create/change unknown repeater debt.
	 *
	 * @return array{validation_patch:array<string,mixed>,repeater_keys:list<string>,warnings:list<array<string,mixed>>}|\WP_Error
	 */
	private static function repeater_validation_patch( string $widget_type, array $before, array $patch ): array|\WP_Error {
		$schema = WidgetSchemaRepository::get( $widget_type );
		if ( $schema instanceof \WP_Error ) {
			return $schema;
		}
		$controls = is_array( $schema['controls'] ?? null ) ? $schema['controls'] : [];
		$validation_patch = $patch;
		$repeater_keys = [];
		$warnings = [];
		foreach ( $patch as $key => $value ) {
			$control = is_array( $controls[ (string) $key ] ?? null ) ? $controls[ (string) $key ] : [];
			if ( ! isset( $control['fields'] ) || ! is_array( $control['fields'] ) || ! is_array( $value ) || ! array_is_list( $value ) ) {
				continue;
			}
			$repeater_keys[] = (string) $key;
			$projected_rows = [];
			$before_rows = is_array( $before[ (string) $key ] ?? null ) ? $before[ (string) $key ] : [];
			foreach ( $value as $index => $row ) {
				if ( ! is_array( $row ) ) {
					$projected_rows[] = $row;
					continue;
				}
				$before_row = self::matching_repeater_row( $before_rows, $row, (int) $index );
				$projected = [];
				foreach ( $row as $field_key => $field_value ) {
					$field_key = (string) $field_key;
					if ( '_id' === $field_key || isset( $control['fields'][ $field_key ] ) ) {
						$projected[ $field_key ] = $field_value;
						continue;
					}
					if ( ! array_key_exists( $field_key, $before_row ) || $before_row[ $field_key ] !== $field_value ) {
						return new \WP_Error(
							'stonewright_elementor_settings_invalid',
							__( 'A repeater patch cannot add or change a field absent from the live schema.', 'stonewright' ),
							[
								'status' => 400,
								'violations' => [ [ 'path' => 'settings.' . (string) $key . '.' . $index . '.' . $field_key, 'code' => 'unknown_repeater_field_changed' ] ],
								'patch_validation' => true,
							]
						);
					}
					$warnings[] = [
						'path'   => 'settings.' . (string) $key . '.' . $index . '.' . $field_key,
						'code'   => 'unknown_repeater_field_preserved',
						'policy' => 'byte_equivalent_preservation',
					];
				}
				$projected_rows[] = $projected;
			}
			$validation_patch[ (string) $key ] = $projected_rows;
		}
		return [ 'validation_patch' => $validation_patch, 'repeater_keys' => $repeater_keys, 'warnings' => $warnings ];
	}

	/** @param array<int,mixed> $rows @param array<string,mixed> $candidate @return array<string,mixed> */
	private static function matching_repeater_row( array $rows, array $candidate, int $fallback_index ): array {
		foreach ( [ 'custom_id', '_id' ] as $identity_key ) {
			if ( ! is_scalar( $candidate[ $identity_key ] ?? null ) || '' === trim( (string) $candidate[ $identity_key ] ) ) {
				continue;
			}
			$value = trim( (string) $candidate[ $identity_key ] );
			foreach ( $rows as $row ) {
				if ( is_array( $row ) && is_scalar( $row[ $identity_key ] ?? null ) && hash_equals( $value, trim( (string) $row[ $identity_key ] ) ) ) {
					return $row;
				}
			}
		}
		return is_array( $rows[ $fallback_index ] ?? null ) ? $rows[ $fallback_index ] : [];
	}

	/** @return array{settings:array<string,mixed>,schema_hash:string,warnings:list<array<string,mixed>>,changed_paths:list<string>}|\WP_Error */
	public static function container( array $before, array $patch, string $element_type = 'container', string $mode = 'merge' ): array|\WP_Error {
		$identity_error = self::validate_repeater_identity( $patch );
		if ( $identity_error instanceof \WP_Error ) {
			return $identity_error;
		}
		$patch_result = SettingsValidator::validate_container( $patch, $element_type, true, true );
		if ( $patch_result instanceof \WP_Error ) {
			return self::with_patch_context( $patch_result, $patch );
		}
		$unknown_error = self::new_unknown_touched_error( $patch_result['warnings'], $before, $patch );
		if ( $unknown_error instanceof \WP_Error ) {
			return $unknown_error;
		}
		$settings = 'replace' === $mode ? $patch_result['settings'] : self::merge_settings( $before, $patch_result['settings'] );
		$changed_paths = self::changed_paths( $before, $settings );
		$full = SettingsValidator::validate_container( $settings, $element_type, false, true );
		$legacy_warnings = [];
		if ( $full instanceof \WP_Error ) {
			$data = (array) $full->get_error_data();
			$violations = isset( $data['violations'] ) && is_array( $data['violations'] ) ? $data['violations'] : [];
			foreach ( $violations as $violation ) {
				$path = is_array( $violation ) ? (string) ( $violation['path'] ?? '' ) : '';
				if ( self::path_touched( $path, $changed_paths ) ) {
					return self::with_patch_context( $full, $patch );
				}
				$legacy_warnings[] = [ 'code' => 'untouched_legacy_violation', 'path' => $path, 'original' => is_array( $violation ) ? $violation : [], 'policy' => 'preserved_without_normalization' ];
			}
		}
		return [
			'settings'      => $settings,
			'schema_hash'   => (string) $patch_result['schema_hash'],
			'warnings'      => array_merge( $patch_result['warnings'], $legacy_warnings ),
			'changed_paths' => $changed_paths,
		];
	}

	/** @param list<array<string,mixed>> $warnings */
	private static function new_unknown_touched_error( array $warnings, array $before, array $patch ): ?\WP_Error {
		foreach ( $warnings as $warning ) {
			if ( 'unknown_setting_preserved' !== (string) ( $warning['code'] ?? '' ) ) {
				continue;
			}
			$path = (string) ( $warning['path'] ?? '' );
			$relative = preg_replace( '/^settings\./', '', $path ) ?? $path;
			if ( ! self::has_path( $before, $relative ) && self::top_level_path( $path ) !== '' ) {
				return new \WP_Error(
					'stonewright_elementor_settings_invalid',
					__( 'A new Elementor setting is not present in the live schema.', 'stonewright' ),
					[ 'status' => 400, 'violations' => [ $warning ], 'retryable' => true, 'patch_validation' => true ]
				);
			}
		}
		return null;
	}

	private static function with_patch_context( \WP_Error $error, array $patch ): \WP_Error {
		$data = (array) $error->get_error_data();
		$data['patch_validation'] = true;
		$data['changed_paths'] = self::top_level_keys( $patch );
		return new \WP_Error( $error->get_error_code(), $error->get_error_message(), $data );
	}

	private static function validate_repeater_identity( array $settings ): ?\WP_Error {
		$walk = static function ( mixed $value, string $path ) use ( &$walk ): ?\WP_Error {
			if ( ! is_array( $value ) ) {
				return null;
			}
			if ( array_is_list( $value ) ) {
				$seen = [];
				$has_identity = false;
				foreach ( $value as $index => $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					$key = isset( $row['custom_id'] ) ? 'custom_id' : ( isset( $row['_id'] ) ? '_id' : '' );
					if ( '' === $key ) {
						continue;
					}
					$has_identity = true;
					$id = is_scalar( $row[ $key ] ) ? trim( (string) $row[ $key ] ) : '';
					if ( '' === $id || isset( $seen[ $id ] ) ) {
						return new \WP_Error(
							'stonewright_elementor_repeater_identity_invalid',
							__( 'Elementor repeater selector IDs must be non-empty and unique.', 'stonewright' ),
							[ 'status' => 400, 'path' => $path . '.' . (string) $index . '.' . $key, 'selector' => $key, 'id' => $id ]
						);
					}
					$seen[ $id ] = true;
				}
				if ( ! $has_identity ) {
					foreach ( $value as $index => $row ) {
						$error = $walk( $row, $path . '.' . (string) $index );
						if ( $error instanceof \WP_Error ) {
							return $error;
						}
					}
				}
				return null;
			}
			foreach ( $value as $key => $child ) {
				$error = $walk( $child, $path . '.' . (string) $key );
				if ( $error instanceof \WP_Error ) {
					return $error;
				}
			}
			return null;
		};
		return $walk( $settings, 'settings' );
	}

	/** @return list<string> */
	private static function top_level_keys( array $settings ): array {
		return array_values( array_map( 'strval', array_keys( $settings ) ) );
	}

	private static function top_level_path( string $path ): string {
		$path = preg_replace( '/^settings\./', '', $path ) ?? $path;
		return (string) ( explode( '.', $path )[0] ?? '' );
	}

	/** @param list<string> $changed_paths */
	private static function path_touched( string $violation_path, array $changed_paths ): bool {
		$violation_path = preg_replace( '/^settings\./', '', $violation_path ) ?? $violation_path;
		foreach ( $changed_paths as $changed_path ) {
			if ( $violation_path === $changed_path || str_starts_with( $violation_path, $changed_path . '.' ) || str_starts_with( $changed_path, $violation_path . '.' ) ) {
				return true;
			}
		}
		return false;
	}

	private static function has_path( array $array, string $path ): bool {
		$current = $array;
		foreach ( explode( '.', $path ) as $part ) {
			if ( '' === $part ) {
				continue;
			}
			if ( ! is_array( $current ) || ! array_key_exists( $part, $current ) ) {
				return false;
			}
			$current = $current[ $part ];
		}
		return true;
	}

	/** @param array<string,mixed> $before @param array<string,mixed> $patch @return array<string,mixed> */
	private static function merge_settings( array $before, array $patch ): array {
		$out = $before;
		foreach ( $patch as $key => $value ) {
			if ( is_array( $value ) && is_array( $out[ $key ] ?? null ) && ! array_is_list( $value ) && ! array_is_list( $out[ $key ] ) ) {
				$out[ $key ] = self::merge_settings( $out[ $key ], $value );
				continue;
			}
			$out[ $key ] = $value;
		}
		return $out;
	}

	/** @return list<string> */
	private static function changed_paths( array $before, array $after, string $prefix = '' ): array {
		$paths = [];
		$keys = array_values( array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) ) );
		foreach ( $keys as $key ) {
			$name = '' === $prefix ? (string) $key : $prefix . '.' . (string) $key;
			$has_before = array_key_exists( $key, $before );
			$has_after  = array_key_exists( $key, $after );
			if ( ! $has_before || ! $has_after ) {
				$paths[] = $name;
				continue;
			}
			if ( is_array( $before[ $key ] ) && is_array( $after[ $key ] ) ) {
				$paths = array_merge( $paths, self::changed_paths( $before[ $key ], $after[ $key ], $name ) );
			} elseif ( $before[ $key ] !== $after[ $key ] ) {
				$paths[] = $name;
			}
		}
		return $paths;
	}
}
