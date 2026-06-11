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
	 * @param array<string, mixed> $input Input parameters.
	 * @return bool|\WP_Error Whether execution is allowed.
	 */
	public function has_permission( array $input = [] ) {
		$normalized_input = $input;

		if ( method_exists( $this, 'normalize_input' ) ) {
			$normalized_input = $this->normalize_input( $input );
		}

		if ( method_exists( $this, 'validate_input' ) ) {
			$is_valid = $this->validate_input( $normalized_input );
			if ( is_wp_error( $is_valid ) ) {
				return $is_valid;
			}
		}

		if ( method_exists( $this, 'check_permissions' ) ) {
			return $this->check_permissions( $normalized_input );
		}

		if ( ! is_callable( $this->permission_callback ) ) {
			return true;
		}

		return call_user_func( $this->permission_callback, $input );
	}
}
