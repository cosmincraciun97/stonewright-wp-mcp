<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Common;

/**
 * Reusable confirmation-token gate for non-sandbox destructive abilities.
 *
 * Usage: add `use ConfirmationGuard;` to an AbilityKernel subclass, then call
 * `$this->confirmation_token_error( $args, $verify_args )` at the top of
 * execute() and short-circuit on non-null. Canonical implementation lives on
 * AbilityKernel::require_production_safe_token().
 */
trait ConfirmationGuard {

	/**
	 * Returns null when the token check is satisfied (or mode is not production-safe).
	 * Returns a WP_Error when the token is missing or invalid.
	 *
	 * @param array<string, mixed> $args        Full ability args (used to extract confirmation_token).
	 * @param array<string, mixed> $verify_args The args this ability signed over when the token was issued.
	 * @return \WP_Error|null
	 */
	protected function confirmation_token_error( array $args, array $verify_args ): ?\WP_Error {
		return $this->require_production_safe_token( $args, $verify_args );
	}
}
