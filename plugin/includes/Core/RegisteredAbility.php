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
	 * Execute after normalising null adapter input to the empty object.
	 *
	 * @param mixed $input Input parameters. Some MCP adapter paths pass null for empty args.
	 * @return mixed|\WP_Error
	 */
	public function execute( $input = [] ) {
		return parent::execute( self::normalise_adapter_input( $input ) );
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
}
