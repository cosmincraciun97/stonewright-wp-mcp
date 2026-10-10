<?php
declare( strict_types=1 );

/**
 * Synthetic WordPress core ability class for lifecycle tests.
 *
 * It models the documented call order of WP_Ability::execute() on cores that run the
 * lifecycle filters: wp_ability_invoked, wp_pre_execute_ability, wp_ability_normalize_input,
 * wp_ability_validate_input, wp_ability_permission_result, wp_before_execute_ability,
 * wp_ability_execute_result, wp_ability_validate_output and wp_after_execute_ability.
 * Load it before anything that needs WP_Ability, in a separate process.
 */
class WP_Ability {

	protected $name;
	protected $label;
	protected $description;
	protected $category;
	protected $input_schema = [];
	protected $output_schema = [];
	protected $execute_callback;
	protected $permission_callback;
	protected $meta;

	public function __construct( string $name, array $args ) {
		$this->name = $name;
		foreach ( $args as $key => $value ) {
			if ( property_exists( $this, $key ) ) {
				$this->$key = $value;
			}
		}
		$this->meta = $args['meta'] ?? [];
	}

	public function get_name(): string {
		return $this->name;
	}

	public function get_input_schema(): array {
		return $this->input_schema;
	}

	public function get_output_schema(): array {
		return $this->output_schema;
	}

	public function get_meta(): array {
		return $this->meta;
	}

	public function normalize_input( $input = null ) {
		if ( null === $input && array_key_exists( 'default', $this->input_schema ) ) {
			$input = $this->input_schema['default'];
		}
		return apply_filters( 'wp_ability_normalize_input', $input, $this->name, $this );
	}

	public function validate_input( $input = null ) {
		$is_valid = true;
		if ( empty( $this->input_schema ) ) {
			if ( null !== $input ) {
				$is_valid = new WP_Error( 'ability_missing_input_schema', 'No input schema.' );
			}
		} else {
			$checked = rest_validate_value_from_schema( $input, $this->input_schema, 'input' );
			if ( is_wp_error( $checked ) ) {
				$is_valid = new WP_Error( 'ability_invalid_input', $checked->get_error_message() );
			}
		}
		$verdict = apply_filters( 'wp_ability_validate_input', $is_valid, $input, $this->name );
		if ( false === $verdict ) {
			return new WP_Error( 'ability_invalid_input', 'Invalid input.' );
		}
		if ( is_wp_error( $verdict ) && $verdict->has_errors() ) {
			return $verdict;
		}
		return true;
	}

	protected function invoke_callback( callable $callback, $input = null ) {
		try {
			return $callback( ...( empty( $this->input_schema ) ? [] : [ $input ] ) );
		} catch ( Throwable $thrown ) {
			return new WP_Error( 'ability_callback_exception', $thrown->getMessage() );
		}
	}

	public function check_permissions( $input = null ) {
		if ( ! is_callable( $this->permission_callback ) ) {
			return new WP_Error( 'ability_invalid_permission_callback', 'No permission callback.' );
		}
		$permission = $this->invoke_callback( $this->permission_callback, $input );
		$result     = apply_filters( 'wp_ability_permission_result', $permission, $this->name, $input, $this );
		return is_bool( $result ) || is_wp_error( $result ) ? $result : false;
	}

	protected function do_execute( $input = null ) {
		$result = is_callable( $this->execute_callback )
			? $this->invoke_callback( $this->execute_callback, $input )
			: new WP_Error( 'ability_invalid_execute_callback', 'No execute callback.' );
		return apply_filters( 'wp_ability_execute_result', $result, $this->name, $input, $this );
	}

	protected function validate_output( $output ) {
		$is_valid = true;
		if ( ! empty( $this->output_schema ) ) {
			$checked = rest_validate_value_from_schema( $output, $this->output_schema, 'output' );
			if ( is_wp_error( $checked ) ) {
				$is_valid = new WP_Error( 'ability_invalid_output', $checked->get_error_message() );
			}
		}
		$verdict = apply_filters( 'wp_ability_validate_output', $is_valid, $output, $this->name );
		if ( false === $verdict ) {
			return new WP_Error( 'ability_invalid_output', 'Invalid output.' );
		}
		if ( is_wp_error( $verdict ) && $verdict->has_errors() ) {
			return $verdict;
		}
		return true;
	}

	public function execute( $input = null ) {
		do_action( 'wp_ability_invoked', $this->name, $input, $this );

		$sentinel = new stdClass();
		$pre      = apply_filters( 'wp_pre_execute_ability', $sentinel, $this->name, $input, $this );
		if ( $pre !== $sentinel ) {
			return $pre;
		}

		$input = $this->normalize_input( $input );
		if ( is_wp_error( $input ) ) {
			return $input;
		}

		$is_valid = $this->validate_input( $input );
		if ( is_wp_error( $is_valid ) ) {
			return $is_valid;
		}

		if ( true !== $this->check_permissions( $input ) ) {
			return new WP_Error( 'ability_invalid_permissions', 'Ability does not have necessary permission.' );
		}

		do_action( 'wp_before_execute_ability', $this->name, $input, $this );

		$result = $this->do_execute( $input );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$is_valid = $this->validate_output( $result );
		if ( is_wp_error( $is_valid ) ) {
			return $is_valid;
		}

		do_action( 'wp_after_execute_ability', $this->name, $input, $result, $this );

		return $result;
	}
}
