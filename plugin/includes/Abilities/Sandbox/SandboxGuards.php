<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Sandbox;

use Stonewright\WpMcp\Security\ConfirmationToken;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Mixin that adds the two extra envelopes every sandbox-mutating ability must
 * pass before doing real work: DISALLOW_FILE_MODS short-circuit and the
 * production-safe confirmation-token check.
 *
 * Used as a trait on AbilityKernel subclasses so the policy lives in one
 * place.
 */
trait SandboxGuards {

	/**
	 * Returns a WP_Error if WordPress' DISALLOW_FILE_MODS constant is on, else
	 * null. Mutating sandbox abilities (activate, deactivate, edit, write,
	 * toggle, delete) must short-circuit when this returns non-null.
	 */
	protected function file_mods_disabled_error(): ?\WP_Error {
		if ( Permissions::file_mods_allowed() ) {
			return null;
		}

		return new \WP_Error(
			'stonewright_sandbox_file_mods_disabled',
			__( 'Sandbox mutations are blocked because DISALLOW_FILE_MODS is set in wp-config.php.', 'stonewright' )
		);
	}

	/**
	 * When the plugin is running in production-safe mode, every sandbox
	 * mutation must include a valid confirmation_token. Returns null when the
	 * mode is permissive or the token verifies; returns a WP_Error otherwise.
	 *
	 * Error codes emitted match the ConfirmationToken::verify_or_error() contract:
	 *   - stonewright_confirmation_required  (token missing)
	 *   - stonewright_confirmation_invalid   (parse/HMAC failure)
	 *   - stonewright_confirmation_expired
	 *   - stonewright_confirmation_replayed
	 *   - stonewright_confirmation_args_mismatch
	 *   - stonewright_confirmation_ability_mismatch
	 *   - stonewright_confirmation_user_mismatch
	 *
	 * @param array<string, mixed> $args        The full ability args (used to
	 *                                          extract `confirmation_token`).
	 * @param array<string, mixed> $verify_args The args this ability signed
	 *                                          when the token was issued.
	 */
	protected function production_safe_token_error( array $args, array $verify_args ): ?\WP_Error {
		if ( ! Permissions::is_production_safe() ) {
			return null;
		}

		$token = isset( $args['confirmation_token'] ) ? (string) $args['confirmation_token'] : '';
		if ( '' === $token ) {
			return new \WP_Error(
				'stonewright_confirmation_required',
				__( 'Production-safe mode requires confirmation_token.', 'stonewright' ),
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
