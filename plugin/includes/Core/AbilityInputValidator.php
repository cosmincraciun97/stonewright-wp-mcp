<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Core;

use Stonewright\WpMcp\Abilities\Ability;

/**
 * Checks an input object against the input schema an ability publishes, with the REST schema
 * validator that the Abilities API run path uses.
 */
final class AbilityInputValidator {

	private const PERMISSIVE_REST_SCHEMA = [
		'type' => [ 'array', 'object', 'string', 'number', 'integer', 'boolean', 'null' ],
	];

	/**
	 * Validate input for an ability the way a direct run of it is validated: bounds, enums, patterns,
	 * types and unknown properties all count. The task context token is left to the context guard,
	 * which answers a missing one with its own error and instructions.
	 *
	 * @param array<string, mixed> $input
	 * @return true|\WP_Error An `ability_invalid_input` error with the reason.
	 */
	public static function validate( Ability $ability, array $input ): bool|\WP_Error {
		$schema = AbilityRegistry::input_schema_for_ability( $ability );
		if ( isset( $schema['required'] ) && is_array( $schema['required'] ) ) {
			$schema['required'] = array_values(
				array_filter(
					$schema['required'],
					static fn( mixed $key ): bool => 'stonewright_context_token' !== $key
				)
			);
		}

		$schema = self::rest_schema( $schema );
		if ( ! is_array( $schema ) || [] === $schema ) {
			return true;
		}

		$checked = rest_validate_value_from_schema( $input, $schema, 'input' );
		if ( $checked instanceof \WP_Error ) {
			return new \WP_Error(
				'ability_invalid_input',
				sprintf(
					/* translators: %1$s: ability name, %2$s: reason */
					__( 'Ability "%1$s" has invalid input. Reason: %2$s', 'stonewright' ),
					$ability->name(),
					$checked->get_error_message()
				),
				[ 'status' => 400, 'ability' => $ability->name() ]
			);
		}

		return true;
	}

	/**
	 * Convert public JSON Schema placeholders into REST-validator-safe schemas.
	 *
	 * Ability schemas keep stdClass placeholders so MCP tool discovery encodes permissive fragments
	 * as JSON objects; the REST validator expects PHP arrays.
	 *
	 * @param mixed  $schema     Schema fragment.
	 * @param string $parent_key Parent schema key.
	 * @return mixed
	 */
	public static function rest_schema( mixed $schema, string $parent_key = '' ): mixed {
		if ( $schema instanceof \stdClass ) {
			return self::is_schema_object_map_key( $parent_key ) ? [] : self::PERMISSIVE_REST_SCHEMA;
		}

		if ( ! is_array( $schema ) ) {
			return $schema;
		}

		foreach ( $schema as $key => $value ) {
			$schema[ $key ] = self::rest_schema( $value, (string) $key );
		}

		return $schema;
	}

	private static function is_schema_object_map_key( string $key ): bool {
		return in_array( $key, [ '$defs', 'definitions', 'dependentSchemas', 'patternProperties', 'properties' ], true );
	}
}
