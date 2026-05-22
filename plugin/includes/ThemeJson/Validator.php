<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\ThemeJson;

/**
 * Validates theme.json payloads against the bundled subset schema.
 *
 * Implements a minimal JSON Schema validator (type, required, enum,
 * properties, additionalProperties, minimum, maximum) without pulling in
 * a full JSON Schema library. Patterns the same approach as DesignSpec\Validator.
 */
final class Validator {

	public const SCHEMA_PATH = STONEWRIGHT_DIR . 'schemas/theme-json.schema.json';

	/**
	 * Validate and return a canonical (filtered) copy of the theme.json payload.
	 *
	 * On success returns the sanitized array — top-level keys not present in the
	 * schema's allowed list are stripped, as are unknown sub-keys inside
	 * settings and styles.  On failure returns a WP_Error.
	 *
	 * @param array<string, mixed> $theme_json
	 * @return array<string, mixed>|\WP_Error Sanitized array on success; WP_Error with code
	 *                                         stonewright_theme_json_invalid on failure.
	 */
	public static function validate( array $theme_json ): array|\WP_Error {
		$schema = self::load_schema();
		if ( null === $schema ) {
			return new \WP_Error(
				'stonewright_theme_json_invalid',
				__( 'theme.json schema file could not be loaded.', 'stonewright' ),
				[ 'errors' => [ [ 'keyword' => 'schema', 'message' => 'Schema file missing or unreadable.', 'path' => [] ] ] ]
			);
		}

		$errors = [];
		self::validate_node( $theme_json, $schema, [], $errors );

		if ( ! empty( $errors ) ) {
			return new \WP_Error(
				'stonewright_theme_json_invalid',
				__( 'theme.json failed validation.', 'stonewright' ),
				[ 'errors' => $errors ]
			);
		}

		return self::filter_canonical( $theme_json, $schema );
	}

	/**
	 * Strip keys not defined in the schema's properties.
	 *
	 * Recursively filters object nodes that have an explicit "properties" list in
	 * the schema. Arrays and scalar values are returned as-is.
	 *
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $schema_node
	 * @return array<string, mixed>
	 */
	private static function filter_canonical( array $data, array $schema_node ): array {
		// Only filter object nodes that declare properties.
		$is_object = ( $schema_node['type'] ?? '' ) === 'object' || isset( $schema_node['properties'] );
		if ( ! $is_object || ! isset( $schema_node['properties'] ) || ! is_array( $schema_node['properties'] ) ) {
			return $data;
		}

		$allowed = $schema_node['properties'];
		$filtered = [];

		foreach ( $data as $key => $value ) {
			$key = (string) $key;
			if ( ! array_key_exists( $key, $allowed ) ) {
				continue; // Strip unknown key.
			}

			$prop_schema = $allowed[ $key ];
			if ( is_array( $value ) && is_array( $prop_schema ) ) {
				$value = self::filter_canonical( $value, $prop_schema );
			}

			$filtered[ $key ] = $value;
		}

		return $filtered;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function load_schema(): ?array {
		if ( ! file_exists( self::SCHEMA_PATH ) ) {
			return null;
		}
		$raw = file_get_contents( self::SCHEMA_PATH );
		if ( false === $raw ) {
			return null;
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Recursively validate $data against $schema_node, appending errors.
	 *
	 * @param mixed                            $data
	 * @param array<string, mixed>             $schema_node
	 * @param array<int, string|int>           $path
	 * @param array<int, array<string, mixed>> $errors
	 */
	private static function validate_node( mixed $data, array $schema_node, array $path, array &$errors ): void {
		// ── type check ───────────────────────────────────────────────────────
		if ( isset( $schema_node['type'] ) ) {
			if ( ! self::matches_type( $data, (string) $schema_node['type'] ) ) {
				$errors[] = [
					'keyword' => 'type',
					'message' => sprintf( 'Expected type %s, got %s.', $schema_node['type'], gettype( $data ) ),
					'path'    => $path,
				];
				return; // Further checks would be unreliable.
			}
		}

		// ── enum ────────────────────────────────────────────────────────────
		if ( isset( $schema_node['enum'] ) && is_array( $schema_node['enum'] ) ) {
			if ( ! in_array( $data, $schema_node['enum'], true ) ) {
				$errors[] = [
					'keyword' => 'enum',
					'message' => sprintf( 'Value must be one of [%s].', implode( ', ', array_map( 'strval', $schema_node['enum'] ) ) ),
					'path'    => $path,
				];
			}
		}

		// ── minimum / maximum (integers/numbers) ─────────────────────────────
		if ( isset( $schema_node['minimum'] ) && is_numeric( $data ) ) {
			if ( $data < $schema_node['minimum'] ) {
				$errors[] = [
					'keyword' => 'minimum',
					'message' => sprintf( 'Value %s is below minimum %s.', $data, $schema_node['minimum'] ),
					'path'    => $path,
				];
			}
		}
		if ( isset( $schema_node['maximum'] ) && is_numeric( $data ) ) {
			if ( $data > $schema_node['maximum'] ) {
				$errors[] = [
					'keyword' => 'maximum',
					'message' => sprintf( 'Value %s exceeds maximum %s.', $data, $schema_node['maximum'] ),
					'path'    => $path,
				];
			}
		}

		// ── object-specific ──────────────────────────────────────────────────
		if ( is_array( $data ) && ( ( $schema_node['type'] ?? '' ) === 'object' || isset( $schema_node['properties'] ) ) ) {
			// required
			if ( isset( $schema_node['required'] ) && is_array( $schema_node['required'] ) ) {
				foreach ( $schema_node['required'] as $req ) {
					if ( ! array_key_exists( $req, $data ) ) {
						$errors[] = [
							'keyword' => 'required',
							'message' => sprintf( 'Missing required property "%s".', $req ),
							'path'    => array_merge( $path, [ (string) $req ] ),
						];
					}
				}
			}

			// additionalProperties: false
			$additional_ok = ! ( isset( $schema_node['additionalProperties'] ) && false === $schema_node['additionalProperties'] );
			$defined_props = isset( $schema_node['properties'] ) && is_array( $schema_node['properties'] )
				? array_keys( $schema_node['properties'] )
				: [];

			foreach ( array_keys( $data ) as $key ) {
				$key = (string) $key;

				if ( ! $additional_ok && ! in_array( $key, $defined_props, true ) ) {
					$errors[] = [
						'keyword' => 'additionalProperties',
						'message' => sprintf( 'Additional property "%s" is not allowed.', $key ),
						'path'    => array_merge( $path, [ $key ] ),
					];
					continue;
				}

				if ( isset( $schema_node['properties'][ $key ] ) && is_array( $schema_node['properties'][ $key ] ) ) {
					self::validate_node( $data[ $key ], $schema_node['properties'][ $key ], array_merge( $path, [ $key ] ), $errors );
				}
			}
		}

		// ── array-specific ──────────────────────────────────────────────────
		if ( is_array( $data ) && ( $schema_node['type'] ?? '' ) === 'array' ) {
			if ( isset( $schema_node['items'] ) && is_array( $schema_node['items'] ) ) {
				foreach ( $data as $i => $item ) {
					self::validate_node( $item, $schema_node['items'], array_merge( $path, [ (int) $i ] ), $errors );
				}
			}
		}
	}

	private static function matches_type( mixed $value, string $type ): bool {
		return match ( $type ) {
			'string'  => is_string( $value ),
			'integer' => is_int( $value ),
			'number'  => is_int( $value ) || is_float( $value ),
			'boolean' => is_bool( $value ),
			'array'   => is_array( $value ) && ( array_keys( $value ) === array_keys( array_values( $value ) ) || [] === $value ),
			'object'  => is_array( $value ),
			'null'    => null === $value,
			default   => false,
		};
	}
}
