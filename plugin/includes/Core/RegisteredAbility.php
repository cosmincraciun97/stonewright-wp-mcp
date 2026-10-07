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
 *
 * The class replaces input validation, the permission check and output validation, so that
 * Stonewright's schema placeholders and the MCP adapter's null input work. Each replacement
 * applies the lifecycle filter that WordPress core applies inside the method it replaces:
 * `wp_ability_validate_input`, `wp_ability_permission_result` and `wp_ability_validate_output`.
 * Site policy and security plugins therefore govern a Stonewright ability like any other.
 * A filter can add a refusal or replace one, never turn a refusal that Stonewright issued
 * into approval: a failed schema check, a denied permission callback or a failed output
 * check stays a refusal whatever the filters return. The other lifecycle filters
 * (`wp_ability_normalize_input`, `wp_pre_execute_ability`, `wp_ability_execute_result`) and
 * actions run inside the core methods this class does not replace.
 */
final class RegisteredAbility extends \WP_Ability {

	private const PERMISSIVE_REST_SCHEMA = [
		'type' => [ 'array', 'object', 'string', 'number', 'integer', 'boolean', 'null' ],
	];

	/**
	 * Calls of execute() in progress on this object.
	 *
	 * @var int
	 */
	private int $executing = 0;

	/**
	 * The input that passed validate_input() during execute() and has not had its permission
	 * check yet, so the check does not normalise and validate the same input a second time.
	 *
	 * @var array{0: mixed}|null
	 */
	private ?array $validated = null;

	/**
	 * Compatibility permission check for the standalone REST run controller.
	 *
	 * Direct callers, such as the MCP adapter and that controller, have not normalised or
	 * validated the input, so the check does both before the permission callback runs.
	 *
	 * @param mixed $input Input parameters. Some MCP adapter paths pass null for empty args.
	 * @return bool|\WP_Error Whether execution is allowed.
	 */
	public function has_permission( $input = [] ) {
		$input = self::normalise_adapter_input( $input );

		if ( null !== $this->validated && $this->validated[0] === $input ) {
			// execute() normalised and validated exactly this input a moment ago.
			$this->validated = null;
		} else {
			if ( method_exists( $this, 'normalize_input' ) ) {
				$input = $this->normalize_input( $input );
				if ( is_wp_error( $input ) ) {
					return $input;
				}
			}

			$is_valid = $this->validate_input( $input );
			if ( is_wp_error( $is_valid ) ) {
				return $is_valid;
			}
			$this->validated = null;
		}

		return $this->filtered_permission( $this->run_permission_callback( $input ), $input );
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
		$is_valid     = true;
		$input_schema = self::schema_for_rest_validation( $this->get_input_schema() );
		if ( ! empty( $input_schema ) ) {
			$checked = rest_validate_value_from_schema( $input, $input_schema, 'input' );
			if ( is_wp_error( $checked ) ) {
				$is_valid = new \WP_Error(
					'ability_invalid_input',
					sprintf(
						/* translators: %1$s ability name, %2$s error message. */
						__( 'Ability "%1$s" has invalid input. Reason: %2$s' ),
						esc_html( $this->name ),
						$checked->get_error_message()
					)
				);
			}
		}

		$verdict = $this->filtered_validity( 'wp_ability_validate_input', $is_valid, $input, 'ability_invalid_input', __( 'Invalid input.' ) );
		if ( true === $verdict && $this->executing > 0 ) {
			$this->validated = [ $input ];
		}

		return $verdict;
	}

	/**
	 * Execute after normalising null adapter input to the empty object.
	 *
	 * @param mixed $input Input parameters. Some MCP adapter paths pass null for empty args.
	 * @return mixed|\WP_Error
	 */
	public function execute( $input = [] ) {
		++$this->executing;
		try {
			return parent::execute( self::normalise_adapter_input( $input ) );
		} finally {
			--$this->executing;
			$this->validated = null;
		}
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
		$is_valid      = true;
		$output_schema = self::schema_for_rest_validation( $this->get_output_schema() );
		if ( ! empty( $output_schema ) ) {
			$checked = rest_validate_value_from_schema( $output, $output_schema, 'output' );
			if ( is_wp_error( $checked ) ) {
				$is_valid = new \WP_Error(
					'ability_invalid_output',
					sprintf(
						/* translators: %1$s ability name, %2$s error message. */
						__( 'Ability "%1$s" has invalid output. Reason: %2$s' ),
						esc_html( $this->name ),
						$checked->get_error_message()
					)
				);
			}
		}

		return $this->filtered_validity( 'wp_ability_validate_output', $is_valid, $output, 'ability_invalid_output', __( 'Invalid output.' ) );
	}

	/**
	 * Apply a validation filter to a validation result.
	 *
	 * The filter can add a refusal to a valid result and can replace a refusal with another
	 * one. A result that Stonewright refused stays refused whatever the filter returns.
	 *
	 * @param string         $hook     Filter name.
	 * @param true|\WP_Error $is_valid Result of Stonewright's own validation.
	 * @param mixed          $value    Value that was validated.
	 * @param string         $code     Error code for a filter that returns false.
	 * @param string         $message  Error message for a filter that returns false.
	 * @return true|\WP_Error
	 */
	private function filtered_validity( string $hook, bool|\WP_Error $is_valid, mixed $value, string $code, string $message ): bool|\WP_Error {
		$verdict = apply_filters( $hook, $is_valid, $value, $this->name );

		if ( is_wp_error( $verdict ) && $verdict->has_errors() ) {
			return $verdict;
		}
		if ( is_wp_error( $is_valid ) ) {
			return $is_valid;
		}

		return false === $verdict ? new \WP_Error( $code, $message ) : true;
	}

	/**
	 * Run the permission callback. A missing callback, an exception or a result that is neither
	 * true nor an error refuses the call.
	 *
	 * @param mixed $input Normalised and validated input.
	 * @return bool|\WP_Error
	 */
	private function run_permission_callback( mixed $input ): bool|\WP_Error {
		if ( ! is_callable( $this->permission_callback ) ) {
			return new \WP_Error(
				'ability_invalid_permission_callback',
				sprintf(
					/* translators: %s ability name. */
					__( 'Ability "%s" does not have a valid permission callback.' ),
					$this->name
				)
			);
		}

		try {
			/** @var mixed $permission A callback can return anything, whatever its declared type. */
			$permission = call_user_func( $this->permission_callback, $input );
		} catch ( \Throwable $thrown ) {
			return new \WP_Error(
				'ability_callback_exception',
				sprintf(
					/* translators: 1: Ability name, 2: Exception message. */
					__( 'Ability "%1$s" callback threw an exception: %2$s' ),
					$this->name,
					esc_html( $thrown->getMessage() )
				)
			);
		}

		return is_bool( $permission ) || is_wp_error( $permission ) ? $permission : false;
	}

	/**
	 * Apply the permission-result filter to the permission callback's answer.
	 *
	 * The filter can withdraw a grant or replace a refusal with another one. It cannot grant a
	 * call that the ability's own permission check refused.
	 *
	 * @param bool|\WP_Error $permission The permission callback's answer.
	 * @param mixed          $input      Normalised and validated input.
	 * @return bool|\WP_Error
	 */
	private function filtered_permission( bool|\WP_Error $permission, mixed $input ): bool|\WP_Error {
		$result = apply_filters( 'wp_ability_permission_result', $permission, $this->name, $input, $this );

		if ( true !== $permission ) {
			return is_wp_error( $result ) ? $result : $permission;
		}

		return true === $result || is_wp_error( $result ) ? $result : false;
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
