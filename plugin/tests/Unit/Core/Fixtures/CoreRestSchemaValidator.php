<?php
/**
 * Test double of WordPress core's REST schema validator.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * Derived from WordPress, https://github.com/WordPress/wordpress-develop,
 * src/wp-includes/rest-api.php at version 7.1.2: rest_validate_value_from_schema() and
 * the type, object, array, anyOf and oneOf checks it calls, with core's error codes and
 * messages. Copyright WordPress contributors, GPL-2.0-or-later. Modifications: rewritten
 * as one class covering the rules ability schemas use; string and number bounds, patterns,
 * formats, patternProperties and uniqueItems are left out, and an anyOf or oneOf that no
 * branch matches reports rest_no_matching_schema instead of one branch's error.
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core\Fixtures;

/**
 * The rules of rest_validate_value_from_schema() that ability schemas rely on, for tests that need
 * the refusals core gives rather than the bootstrap's accept-all stand-in: anyOf and oneOf, type
 * lists resolved to the best type, null, boolean, integer, number, string, object (required in both
 * schema versions, properties, additionalProperties, minProperties, maxProperties), array (items,
 * minItems, maxItems) and enum.
 *
 * Install with `$GLOBALS['stonewright_test_rest_validator'] = [ CoreRestSchemaValidator::class, 'validate' ];`.
 */
final class CoreRestSchemaValidator {

	/**
	 * @param mixed $value  Value to check.
	 * @param mixed $schema Schema, as core receives it.
	 * @param string $param Name of the value, used in error messages.
	 * @return true|\WP_Error
	 */
	public static function validate( mixed $value, mixed $schema, string $param = '' ): bool|\WP_Error {
		if ( ! is_array( $schema ) ) {
			return true;
		}

		foreach ( [ 'anyOf' => false, 'oneOf' => true ] as $keyword => $exactly_one ) {
			if ( ! isset( $schema[ $keyword ] ) || ! is_array( $schema[ $keyword ] ) ) {
				continue;
			}

			$matches = [];
			foreach ( $schema[ $keyword ] as $branch ) {
				if ( ! is_array( $branch ) ) {
					continue;
				}
				if ( ! isset( $branch['type'] ) && isset( $schema['type'] ) ) {
					$branch['type'] = $schema['type'];
				}
				if ( true === self::validate( $value, $branch, $param ) ) {
					$matches[] = $branch;
				}
			}

			if ( [] === $matches ) {
				return new \WP_Error( 'rest_no_matching_schema', sprintf( '%s does not match any of the expected formats.', $param ) );
			}
			if ( $exactly_one && count( $matches ) > 1 ) {
				return new \WP_Error( 'rest_one_of_multiple_matches', sprintf( '%s matches more than one of the expected formats.', $param ) );
			}
			if ( ! isset( $schema['type'] ) && isset( $matches[0]['type'] ) ) {
				$schema['type'] = $matches[0]['type'];
			}
		}

		$type = $schema['type'] ?? null;
		if ( is_array( $type ) ) {
			$type = self::best_type( $value, $type );
			if ( '' === $type ) {
				return new \WP_Error(
					'rest_invalid_type',
					sprintf( '%1$s is not of type %2$s.', $param, implode( ',', $schema['type'] ) ),
					[ 'param' => $param ]
				);
			}
		}

		$valid = match ( $type ) {
			'null'    => null === $value ? true : self::type_error( $param, 'null' ),
			'boolean' => self::is_boolean( $value ) ? true : self::type_error( $param, 'boolean' ),
			'integer' => self::is_integer( $value ) ? true : self::type_error( $param, 'integer' ),
			'number'  => is_numeric( $value ) ? true : self::type_error( $param, 'number' ),
			'string'  => is_string( $value ) ? true : self::type_error( $param, 'string' ),
			'object'  => self::validate_object( $value, $schema, $param ),
			'array'   => self::validate_array( $value, $schema, $param ),
			default   => true,
		};
		if ( true !== $valid ) {
			return $valid;
		}

		if ( ! empty( $schema['enum'] ) && is_array( $schema['enum'] ) && ! in_array( $value, $schema['enum'], true ) ) {
			return new \WP_Error( 'rest_not_in_enum', sprintf( '%s is not one of the allowed values.', $param ) );
		}

		return true;
	}

	/**
	 * @param array<array-key, mixed> $schema
	 * @return true|\WP_Error
	 */
	private static function validate_object( mixed $value, array $schema, string $param ): bool|\WP_Error {
		if ( '' === $value ) {
			$value = [];
		}
		if ( $value instanceof \stdClass ) {
			$value = (array) $value;
		}
		if ( ! is_array( $value ) ) {
			return self::type_error( $param, 'object' );
		}

		if ( isset( $schema['required'] ) && is_array( $schema['required'] ) ) {
			foreach ( $schema['required'] as $name ) {
				if ( ! array_key_exists( $name, $value ) ) {
					return new \WP_Error( 'rest_property_required', sprintf( '%1$s is a required property of %2$s.', $name, $param ) );
				}
			}
		} elseif ( isset( $schema['properties'] ) && is_array( $schema['properties'] ) ) {
			foreach ( $schema['properties'] as $name => $property ) {
				if ( is_array( $property ) && true === ( $property['required'] ?? null ) && ! array_key_exists( $name, $value ) ) {
					return new \WP_Error( 'rest_property_required', sprintf( '%1$s is a required property of %2$s.', $name, $param ) );
				}
			}
		}

		foreach ( $value as $property => $item ) {
			if ( isset( $schema['properties'][ $property ] ) ) {
				$valid = self::validate( $item, $schema['properties'][ $property ], $param . '[' . $property . ']' );
			} elseif ( false === ( $schema['additionalProperties'] ?? null ) ) {
				return new \WP_Error( 'rest_additional_properties_forbidden', sprintf( '%1$s is not a valid property of Object.', $property ) );
			} elseif ( is_array( $schema['additionalProperties'] ?? null ) ) {
				$valid = self::validate( $item, $schema['additionalProperties'], $param . '[' . $property . ']' );
			} else {
				continue;
			}

			if ( true !== $valid ) {
				return $valid;
			}
		}

		if ( isset( $schema['minProperties'] ) && count( $value ) < $schema['minProperties'] ) {
			return new \WP_Error( 'rest_too_few_properties', sprintf( '%1$s must contain at least %2$s properties.', $param, $schema['minProperties'] ) );
		}
		if ( isset( $schema['maxProperties'] ) && count( $value ) > $schema['maxProperties'] ) {
			return new \WP_Error( 'rest_too_many_properties', sprintf( '%1$s must contain at most %2$s properties.', $param, $schema['maxProperties'] ) );
		}

		return true;
	}

	/**
	 * @param array<array-key, mixed> $schema
	 * @return true|\WP_Error
	 */
	private static function validate_array( mixed $value, array $schema, string $param ): bool|\WP_Error {
		if ( ! self::is_array( $value ) ) {
			return self::type_error( $param, 'array' );
		}

		$value = array_values( $value );

		if ( isset( $schema['items'] ) ) {
			foreach ( $value as $index => $item ) {
				$valid = self::validate( $item, $schema['items'], $param . '[' . $index . ']' );
				if ( true !== $valid ) {
					return $valid;
				}
			}
		}

		if ( isset( $schema['minItems'] ) && count( $value ) < $schema['minItems'] ) {
			return new \WP_Error( 'rest_too_few_items', sprintf( '%1$s must contain at least %2$s items.', $param, $schema['minItems'] ) );
		}
		if ( isset( $schema['maxItems'] ) && count( $value ) > $schema['maxItems'] ) {
			return new \WP_Error( 'rest_too_many_items', sprintf( '%1$s must contain at most %2$s items.', $param, $schema['maxItems'] ) );
		}

		return true;
	}

	/**
	 * The first type of the list that the value has, as rest_get_best_type_for_value() picks it.
	 *
	 * @param list<mixed> $types
	 */
	private static function best_type( mixed $value, array $types ): string {
		if ( '' === $value && in_array( 'string', $types, true ) ) {
			return 'string';
		}

		foreach ( $types as $type ) {
			$fits = match ( $type ) {
				'array'   => self::is_array( $value ),
				'object'  => '' === $value || is_array( $value ) || $value instanceof \stdClass,
				'integer' => self::is_integer( $value ),
				'number'  => is_numeric( $value ),
				'boolean' => self::is_boolean( $value ),
				'string'  => is_string( $value ),
				'null'    => null === $value,
				default   => false,
			};
			if ( $fits ) {
				return (string) $type;
			}
		}

		return '';
	}

	/** An array without string keys, as wp_is_numeric_array() reads it. */
	private static function is_array( mixed $value ): bool {
		return is_array( $value ) && [] === array_filter( array_keys( $value ), 'is_string' );
	}

	private static function is_integer( mixed $value ): bool {
		if ( is_int( $value ) ) {
			return true;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^\s*[+-]?[0-9]+\s*$/', $value ) ) {
			return true;
		}

		return is_numeric( $value ) && floor( (float) $value ) === (float) $value;
	}

	private static function is_boolean( mixed $value ): bool {
		if ( is_bool( $value ) ) {
			return true;
		}
		if ( is_string( $value ) ) {
			return in_array( strtolower( $value ), [ 'false', 'true', '0', '1' ], true );
		}

		return is_int( $value ) && in_array( $value, [ 0, 1 ], true );
	}

	private static function type_error( string $param, string $type ): \WP_Error {
		return new \WP_Error( 'rest_invalid_type', sprintf( '%1$s is not of type %2$s.', $param, $type ), [ 'param' => $param ] );
	}
}
