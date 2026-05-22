<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Common;

use Stonewright\WpMcp\Security\ConfirmationToken;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Reusable confirmation-token gate for non-sandbox destructive abilities.
 *
 * Usage: add `use ConfirmationGuard;` to an AbilityKernel subclass, then call
 * `$this->confirmation_token_error( $args, $verify_args )` at the top of
 * execute() and short-circuit on non-null.
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
		if ( ! Permissions::is_production_safe() ) {
			return null;
		}

		$token = isset( $args['confirmation_token'] ) ? (string) $args['confirmation_token'] : '';
		if ( '' === $token ) {
			return new \WP_Error(
				'stonewright_confirmation_required',
				__( 'Production-safe mode requires a confirmation_token.', 'stonewright' ),
				[ 'status' => 403 ]
			);
		}

		$result = ConfirmationToken::verify_or_error( $token, $this->name(), $verify_args );
		if ( $result instanceof \WP_Error ) {
			return $result;
		}

		return null;
	}
}
