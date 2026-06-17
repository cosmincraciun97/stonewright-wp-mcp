<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Core;

/**
 * Stonewright's registered Abilities API object.
 *
 * WordPress core 6.9+ exposes `check_permissions()`, while the standalone
 * Abilities API REST controller bundled for older cores still calls
 * `has_permission()`. Registering abilities with this class keeps both
 * runtimes callable without overriding WordPress core classes.
 */
final class RegisteredAbility extends \WP_Ability {

	private const PERMISSIVE_REST_SCHEMA = [
		'type' => [ 'array', 'object', 'string', 'number', 'integer', 'boolean', 'null' ],
	];

	/**
	 * Compatibility permission check for the standalone REST run controller.
	 *
	 * @param mixed $input Input parameters. Some MCP adapter paths pass null for empty args.
	 * @return bool|\WP_Error Whether execution is allowed.
	 */
	public function has_permission( $input = [] ) {
		$normalized_input = self::normalise_adapter_input( $input );

		if ( method_exists( $this, 'normalize_input' ) ) {
			$normalized_input = $this->normalize_input( $normalized_input );
		}

		if ( method_exists( $this, 'validate_input' ) ) {
			$is_valid = $this->validate_input( $normalized_input );
			if ( is_wp_error( $is_valid ) ) {
				return $is_valid;
			}
		}

		if ( ! is_callable( $this->permission_callback ) ) {
			return true;
		}

		return call_user_func( $this->permission_callback, $normalized_input );
	}

	/**
	 * Compatibility permission check for WordPress core's MCP adapter.
	 *
	 * @param mixed $input Input parameters. Some MCP adapter paths pass null for empty args.
	 * @return bool|\WP_Error Whether execution is allowed.
	 */
	public function check_permissions( $input = [] ) {
		return $this->has_permission( $input );
	}

	/**
	 * Validate input with schema placeholders converted for WordPress REST.
	 *
	 * @param mixed $input Input data.
	 * @return true|\WP_Error
	 */
	public function validate_input( $input = null ) {
		$input_schema = self::schema_for_rest_validation( $this->get_input_schema() );
		if ( empty( $input_schema ) ) {
			return true;
		}

		$valid_input = rest_validate_value_from_schema( $input, $input_schema, 'input' );
		if ( is_wp_error( $valid_input ) ) {
			return new \WP_Error(
				'ability_invalid_input',
				sprintf(
					/* translators: %1$s ability name, %2$s error message. */
					__( 'Ability "%1$s" has invalid input. Reason: %2$s' ),
					esc_html( $this->name ),
					$valid_input->get_error_message()
				)
			);
		}

		return true;
	}

	/**
	 * Execute after normalising null adapter input to the empty object.
	 *
	 * @param mixed $input Input parameters. Some MCP adapter paths pass null for empty args.
	 * @return mixed|\WP_Error
	 */
	public function execute( $input = [] ) {
		return parent::execute( self::normalise_adapter_input( $input ) );
	}

	/**
	 * Validate output with schema placeholders converted for WordPress REST.
	 *
	 * Ability schemas keep stdClass placeholders so MCP tool discovery encodes
	 * permissive fragments as JSON objects. WordPress's REST schema validator
	 * expects PHP arrays, so runtime validation needs an array-only view.
	 *
	 * @param mixed $output Output data.
	 * @return true|\WP_Error
	 */
	protected function validate_output( $output ) {
		$output_schema = self::schema_for_rest_validation( $this->get_output_schema() );
		if ( empty( $output_schema ) ) {
			return true;
		}

		$valid_output = rest_validate_value_from_schema( $output, $output_schema, 'output' );
		if ( is_wp_error( $valid_output ) ) {
			return new \WP_Error(
				'ability_invalid_output',
				sprintf(
					/* translators: %1$s ability name, %2$s error message. */
					__( 'Ability "%1$s" has invalid output. Reason: %2$s' ),
					esc_html( $this->name ),
					$valid_output->get_error_message()
				)
			);
		}

		return true;
	}

	/**
	 * Normalize MCP adapter input before it reaches strict Abilities callbacks.
	 *
	 * @param mixed $input Raw adapter input.
	 * @return array<string, mixed>
	 */
	private static function normalise_adapter_input( $input ): array {
		if ( is_array( $input ) ) {
			return $input;
		}

		if ( $input instanceof \stdClass ) {
			return (array) $input;
		}

		return [];
	}

	/**
	 * Convert public JSON Schema placeholders into REST-validator-safe schemas.
	 *
	 * @param mixed  $schema Schema fragment.
	 * @param string $parent_key Parent schema key.
	 * @return mixed
	 */
	private static function schema_for_rest_validation( mixed $schema, string $parent_key = '' ): mixed {
		if ( $schema instanceof \stdClass ) {
			return self::is_schema_object_map_key( $parent_key ) ? [] : self::PERMISSIVE_REST_SCHEMA;
		}

		if ( ! is_array( $schema ) ) {
			return $schema;
		}

		foreach ( $schema as $key => $value ) {
			$schema[ $key ] = self::schema_for_rest_validation( $value, (string) $key );
		}

		return $schema;
	}

	private static function is_schema_object_map_key( string $key ): bool {
		return in_array( $key, [ '$defs', 'definitions', 'dependentSchemas', 'patternProperties', 'properties' ], true );
	}
}
